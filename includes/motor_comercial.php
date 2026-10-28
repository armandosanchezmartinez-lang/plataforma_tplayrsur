<?php
declare(strict_types=1);
/**
 * TalIA / TotalXpedient · MOTOR COMERCIAL v0.2.2 · ARPU PRONTO PAGO
 * ============================================================================
 * Ubicación: /plataforma/includes/motor_comercial.php
 * Contrato: MotorComercial(mysqli $conexion).
 * Fuentes: instalaciones y catalogo_paquetes.
 * Escritura en BD: ninguna. Solo SELECT y SHOW COLUMNS.
 *
 * RESPONSABILIDADES
 * - El Motor HC es la única autoridad de cuenta, identidad, coach y líder.
 *   Este motor NO amplía ni modifica el universo de cuentas certificadas.
 * - resumirVendedores() clasifica todas las cuentas HC certificadas, sin
 *   filtrar por origen: Play (2P/3P), oferta (Residencial/Negocios), Bundle y
 *   ARPU por Precio Pronto Pago (sin dividir entre 1.16); los paquetes PYME permanecen incluidos.
 * - Las clasificaciones proceden exclusivamente de catalogo_paquetes mediante
 *   coincidencia exacta normalizada; ausencias/conflictos se auditan sin
 *   inventar atributos. Se conserva 'Sin clasificar' cuando corresponde.
 * - REGLA CANÓNICA ARPU: SUM(precio_pronto_pago) / N de instalaciones con
 *   precio pronto pago válido, sin dividir entre 1.16 ni sustituir por lista.
 * - Prioridad de precio: instalaciones.precio_pronto_pago (si la columna existe);
 *   si no consta, catalogo_paquetes.precio_pronto_pago del plan exacto.
 * - El precio del catálogo es ACTUAL; su uso para períodos históricos es un
 *   respaldo auditado, NO una garantía de que sea el precio histórico vendido.
 * - No se inventa precio cuando falta o es ambiguo: se excluye solo del
 *   denominador de ARPU, sin excluir la instalación de volumen/Play/Oferta/Bundle.
 * - Las claves públicas arpu_ingreso_neto y arpu_cuentas se mantienen por
 *   compatibilidad; arpu_ingreso_neto pasa a reflejar la SUMA de pronto pago
 *   (nombre heredado, NO equivale a ingreso contable neto de IVA).
 * - Registros duplicados de una cuenta se revisan en el período consultado;
 *   divergencias de plan o precio no se resuelven de forma arbitraria.
 * - Indicadores gerenciales por REGISTRO preservan su propia semántica,
 *   filtros y series mensuales, semanales y diarias.
 *
 * CAMBIO v0.2.1 (13-OCT-2026)
 * - Se retiró el filtro Cambaceo/PDV en los tres lectores comerciales de
 *   cuentas ya certificadas por Motor HC (vendedores, diario y semanal).
 * - Se conservan deduplicación, clasificaciones, ARPU y controles de auditoría.
 *
 * CAMBIO v0.2.2 (28-OCT-2026)
 * - ARPU usa Precio Pronto Pago directo, sin ajuste por IVA; no utiliza precio lista.
 * - Se actualizan resumen HC, tendencias y series gerenciales diarias/semanales.
 * - Se mantienen los nombres de los métodos y el esquema de respuesta existente.
 * - Se incorpora sumatoria explícita arpu_suma_pronto_pago, con alias heredado.
 * - La fuente CATALOGO_ACTUAL queda auditada para evitar confundirla con precio histórico.
 *
 * Contrato público:
 *   resumirVendedores($cuentasCertificadas,$desde,$hasta,$diasIso=[]);
 *   indicadoresDiariosHc($eventos,$desde,$hasta);
 *   indicadoresSemanalesHc($eventos,$desde,$hasta,$diasIso=[]);
 *   indicadoresInstalacionesPorCatalogo(...), evolucionPorCatalogo(...),
 *   seriesSemanalesPorCatalogo(...), seriesDiariasPorCatalogo(...).
 */
final class MotorComercial
{
    public const VERSION = '0.2.2-all-channels-arpu-pronto-pago';
    public const ARPU_REGLA_CANONICA = 'PRECIO_PRONTO_PAGO_DIRECTO';
    private mysqli $db;
    private array $columnasInst;
    private array $columnasCatalogo;
    private array $catalogo = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->columnasInst = $this->columnas('instalaciones');
        $this->columnasCatalogo = $this->columnas('catalogo_paquetes');
        foreach (['cuenta', 'fecha', 'plan', 'origen_prospecto'] as $col) {
            if (!isset($this->columnasInst[$col])) {
                throw new RuntimeException('Motor Comercial: falta instalaciones.'.$col);
            }
        }
        foreach (['nombre_plan', 'play', 'tipo', 'bundle_app', 'precio_pronto_pago'] as $col) {
            if (!isset($this->columnasCatalogo[$col])) {
                throw new RuntimeException('Motor Comercial: falta catalogo_paquetes.'.$col);
            }
        }
        // Sin el campo pronto pago no se permiten resultados ARPU ficticios.
        // Catálogo vigente es la única fuente base garantizada en esta arquitectura.
        $this->cargarCatalogo();
    }

    private function columnas(string $tabla): array
    {
        $res = $this->db->query('SHOW COLUMNS FROM `'.$tabla.'`');
        if ($res === false) throw new RuntimeException('No se pudo consultar estructura: '.$tabla);
        $columnas = [];
        while ($r = $res->fetch_assoc()) $columnas[(string)$r['Field']] = true;
        $res->free();
        return $columnas;
    }

    private static function normalizar($valor): string
    {
        $s = trim((string)$valor);
        $s = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    private static function precioPositivo($valor): ?float
    {
        if (!is_numeric($valor) || (float)$valor <= 0) return null;
        return (float)$valor;
    }

    /** Las duplicidades de catálogo se resuelven POR ATRIBUTO, no por orden de fila.
     * Una ambigüedad de precio no debe impedir clasificar Play si Play es único.
     */
    private function cargarCatalogo(): void
    {
        $campos = ['nombre_plan','play','tipo','bundle_app'];
        if (isset($this->columnasCatalogo['precio_pronto_pago'])) $campos[] = 'precio_pronto_pago';
        $res = $this->db->query('SELECT '.implode(',', array_map(static fn($x) => '`'.$x.'`',$campos)).' FROM catalogo_paquetes');
        if ($res === false) throw new RuntimeException('No se pudo leer catalogo_paquetes');
        while ($r = $res->fetch_assoc()) {
            $nombre = self::normalizar($r['nombre_plan'] ?? '');
            if ($nombre === '' || $nombre === '-') continue;
            $entrada = [
                'play' => self::normalizar($r['play'] ?? ''),
                'tipo' => self::normalizar($r['tipo'] ?? ''),
                'bundle_app' => self::normalizar($r['bundle_app'] ?? ''),
                'precio_pronto_pago' => self::precioPositivo($r['precio_pronto_pago'] ?? null),
                'conflictos' => [],
            ];
            if (!isset($this->catalogo[$nombre])) {
                $this->catalogo[$nombre] = $entrada;
                continue;
            }
            foreach (['play','tipo','bundle_app','precio_pronto_pago'] as $campo) {
                $prev = $this->catalogo[$nombre][$campo];
                $nuevo = $entrada[$campo];
                if (isset($this->catalogo[$nombre]['conflictos'][$campo])) continue;
                if ($campo === 'precio_pronto_pago') {
                    // Un precio ausente no contradice uno presente.
                    if ($prev === null && $nuevo !== null) $this->catalogo[$nombre][$campo] = $nuevo;
                    elseif ($prev !== null && $nuevo !== null && abs($prev - $nuevo) > .005) {
                        $this->catalogo[$nombre][$campo] = null;
                        $this->catalogo[$nombre]['conflictos'][$campo] = true;
                    }
                } elseif ($prev !== $nuevo) {
                    $this->catalogo[$nombre][$campo] = '';
                    $this->catalogo[$nombre]['conflictos'][$campo] = true;
                }
            }
        }
        $res->free();
    }

    public static function resumenVacio(): array
    {
        return [
            'total' => 0,
            'doble_play' => 0, 'triple_play' => 0, 'play_sin_clasificar' => 0,
            'residencial' => 0, 'negocios' => 0, 'oferta_sin_clasificar' => 0,
            'no_bundle' => 0, 'bundle' => 0, 'bundle_sin_clasificar' => 0,
            'arpu' => null, 'arpu_ingreso_neto' => 0.0, 'arpu_suma_pronto_pago' => 0.0, 'arpu_cuentas' => 0,
            'arpu_fuente_catalogo' => 0, 'arpu_precio_ambiguo' => 0,
            'instalacion_no_encontrada' => 0, 'instalacion_plan_ambiguo' => 0,
            'catalogo_sin_match' => 0, 'catalogo_ambiguo' => 0,
        ];
    }

    private static function clasificarCuenta(array $filas, array $catalogo): array
    {
        $planes = []; $precios = [];
        foreach ($filas as $r) {
            $plan = self::normalizar($r['plan'] ?? '');
            if ($plan !== '') $planes[$plan] = true;
            $p = self::precioPositivo($r['precio_pronto_pago'] ?? null);
            if ($p !== null) $precios[number_format($p, 2, '.', '')] = $p;
        }
        $planConflicto = count($planes) > 1;
        $precioConflicto = count($precios) > 1;
        $plan = count($planes) === 1 ? (string)array_key_first($planes) : '';
        $cat = ($plan !== '' && !$planConflicto) ? ($catalogo[$plan] ?? null) : null;

        $play = $cat['play'] ?? '';
        $tipo = $cat['tipo'] ?? '';
        $bundle = $cat['bundle_app'] ?? '';
        $p = match($play) {
            'DOBLE PLAY','2P','2 PLAY' => 'doble_play',
            'TRIPLE PLAY','3P','3 PLAY' => 'triple_play',
            default => 'play_sin_clasificar',
        };
        $o = match($tipo) {
            'RESIDENCIAL','RES' => 'residencial',
            'NEGOCIOS','NEGOCIO','NEG' => 'negocios',
            default => 'oferta_sin_clasificar',
        };
        $b = match($bundle) {
            'NO' => 'no_bundle',
            'SI','SÍ' => 'bundle',
            default => 'bundle_sin_clasificar',
        };

        $precio = null;
        $fuente = 'SIN_PRECIO';
        if (!$planConflicto && !$precioConflicto && count($precios) === 1) {
            $precio = (float)array_values($precios)[0];
            $fuente = 'INSTALACION';
        } elseif (!$planConflicto && !$precioConflicto && !$precios && $cat !== null && $cat['precio_pronto_pago'] !== null) {
            $precio = (float)$cat['precio_pronto_pago'];
            $fuente = 'CATALOGO_ACTUAL';
        }
        return [
            'play' => $p, 'oferta' => $o, 'bundle' => $b,
            'precio_arpu' => $precio, // pronto pago original, sin dividir por IVA ni aplicar otros ajustes
            'fuente_precio' => $fuente,
            'plan_ambiguo' => $planConflicto,
            'precio_ambiguo' => $precioConflicto,
            'catalogo_estado' => !$filas ? 'SIN_INSTALACION' : ($planConflicto ? 'PLAN_AMBIGUO' : ($cat === null ? 'SIN_MATCH' : (!empty($cat['conflictos']) ? 'AMBIGUO' : 'OK'))),
        ];
    }

    private static function acumular(array &$s, array $c): void
    {
        $s['total']++;
        $s[$c['play']]++;
        $s[$c['oferta']]++;
        $s[$c['bundle']]++;
        if ($c['catalogo_estado'] === 'SIN_INSTALACION') $s['instalacion_no_encontrada']++;
        if ($c['plan_ambiguo']) $s['instalacion_plan_ambiguo']++;
        if ($c['catalogo_estado'] === 'SIN_MATCH') $s['catalogo_sin_match']++;
        if ($c['catalogo_estado'] === 'AMBIGUO') $s['catalogo_ambiguo']++;
        if ($c['precio_ambiguo']) $s['arpu_precio_ambiguo']++;
        if ($c['precio_arpu'] !== null) {
            $s['arpu_ingreso_neto'] += $c['precio_arpu']; // alias heredado
            $s['arpu_suma_pronto_pago'] += $c['precio_arpu'];
            $s['arpu_cuentas']++;
            if ($c['fuente_precio'] === 'CATALOGO_ACTUAL') $s['arpu_fuente_catalogo']++;
        }
    }

    private static function finalizar(array &$s): void
    {
        $s['arpu'] = $s['arpu_cuentas'] > 0 ? round($s['arpu_suma_pronto_pago'] / $s['arpu_cuentas'], 2) : null;
        foreach ([
            ['doble_play','triple_play','play_sin_clasificar'],
            ['residencial','negocios','oferta_sin_clasificar'],
            ['no_bundle','bundle','bundle_sin_clasificar'],
        ] as $campos) {
            $cuenta = (int)$s[$campos[0]] + (int)$s[$campos[1]] + (int)$s[$campos[2]];
            if ($cuenta !== (int)$s['total']) throw new LogicException('Motor Comercial: pérdida de universo en taxonomía');
        }
    }

    /**
     * @param array $eventos Filas certificadas del Motor HC; sin duplicados por cuenta.
     * @param string $desde Fecha inicial INCLUSIVA YYYY-MM-DD.
     * @param string $hasta Fecha final INCLUSIVA YYYY-MM-DD.
     * @param int[] $diasIso 1=lunes ... 7=domingo; [] incluye todos los días.
     * @return array<string,array> Resúmenes indexados por líder|coach|vendedor.
     */
    public function resumirVendedores(array $eventos, string $desde, string $hasta, array $diasIso=[]): array
    {
        $fi = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
        $ff = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
        if (!$fi || !$ff || $fi->format('Y-m-d') !== $desde || $ff->format('Y-m-d') !== $hasta || $fi > $ff) {
            throw new InvalidArgumentException('Motor Comercial: fechas inválidas');
        }
        foreach ($diasIso as $dia) {
            if (!is_int($dia) || $dia < 1 || $dia > 7) throw new InvalidArgumentException('Día ISO inválido');
        }
        if (!$eventos) return [];
        $unicos = []; $grupos = [];
        foreach ($eventos as $e) {
            $cuenta = trim((string)($e['cuenta'] ?? ''));
            if ($cuenta === '') throw new LogicException('Motor Comercial recibió cuenta vacía');
            $k = 'k'.$cuenta;
            if (isset($unicos[$k])) throw new LogicException('Motor Comercial recibió cuenta duplicada: '.$cuenta);
            if (!isset($e['lider_id'],$e['coach_id'],$e['vendedor_id'])) {
                throw new LogicException('Motor Comercial requiere atribución completa del Motor HC');
            }
            $fechaEvento = substr((string)($e['fecha'] ?? ''), 0, 10);
            if ($fechaEvento < $desde || $fechaEvento > $hasta) {
                throw new LogicException('Cuenta HC fuera del período solicitado: '.$cuenta);
            }
            if ($diasIso && !in_array((int)(new DateTimeImmutable($fechaEvento))->format('N'),$diasIso,true)) {
                throw new LogicException('Cuenta HC fuera de los días solicitados: '.$cuenta);
            }
            $unicos[$k] = $cuenta;
            $grupos[$k] = (string)$e['lider_id'].'|'.(string)$e['coach_id'].'|'.(string)$e['vendedor_id'];
        }

        $porCuenta = [];
        $campos = ['cuenta','fecha','plan'];
        if (isset($this->columnasInst['precio_pronto_pago'])) $campos[] = 'precio_pronto_pago';
        $cols = implode(',',array_map(static fn($x)=>'`'.$x.'`',$campos));
        $hastaExclusivo = $ff->modify('+1 day')->format('Y-m-d');
        foreach (array_chunk(array_values($unicos), 300) as $chunk) {
            $ph = implode(',', array_fill(0,count($chunk),'?'));
            $sql = "SELECT {$cols} FROM instalaciones WHERE cuenta IN ({$ph}) AND fecha >= ? AND fecha < ?";
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) throw new RuntimeException('Motor Comercial: error consultando instalaciones');
            $args = array_merge(array_map('strval',$chunk),[$desde,$hastaExclusivo]);
            $stmt->bind_param(str_repeat('s',count($args)), ...$args);
            if (!$stmt->execute()) throw new RuntimeException('Motor Comercial: consulta de instalaciones falló');
            $res = $stmt->get_result();
            if ($res === false) throw new RuntimeException('Motor Comercial: no se pudieron leer instalaciones');
            while ($r = $res->fetch_assoc()) {
                if ($diasIso && !in_array((int)(new DateTimeImmutable(substr((string)$r['fecha'],0,10)))->format('N'),$diasIso,true)) continue;
                $k = 'k'.trim((string)$r['cuenta']);
                if (array_key_exists($k,$unicos)) $porCuenta[$k][] = $r;
            }
            $res->free();
            $stmt->close();
        }

        $out = [];
        foreach ($unicos as $k=>$cuenta) {
            $grupo = $grupos[$k];
            $out[$grupo] ??= self::resumenVacio();
            self::acumular($out[$grupo], self::clasificarCuenta($porCuenta[$k] ?? [], $this->catalogo));
        }
        foreach ($out as &$s) self::finalizar($s);
        unset($s);
        return $out;
    }


    /**
     * Alcance reutilizable para analítica comercial. La autorización del usuario
     * se resuelve en el controlador; este método solo aplica filtros recibidos.
     * REGION, DISTRITO, DISTRITOS, FOLIOS y VENDEDOR. Un grupo puede refinarse
     * adicionalmente por canales concretos (intersección, nunca unión).
     */
    private function filtrosGerenciales(string $origen, array $scope, array &$args): string
    {
        if (!in_array($origen, ['ventas','instalaciones'], true)) {
            throw new InvalidArgumentException('Motor Comercial: origen desconocido');
        }
        $tipo = strtoupper(trim((string)($scope['tipo'] ?? '')));
        if (!in_array($tipo, ['REGION','DISTRITO','DISTRITOS','FOLIOS','VENDEDOR'], true)) {
            throw new InvalidArgumentException('Motor Comercial: alcance no autorizado');
        }
        $sql = '';
        $map = [
            'CANCUN'=>['CANCUN'], 'MERIDA'=>['MERIDA'],
            'TUXTLA'=>['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'],
            'VILLAHERMOSA'=>['VILLAHERMOSA'],
            'COATZAMINA'=>['COATZA MINA','COATZA/MINA','COATZA / MINA','COATZA-MINA','COATZACOALCOS MINATITLAN','COATZACOALCOS - MINATITLAN','COATZACOALCOS - MINATITLÁN'],
            'COATZACOALCOSMINATITLAN'=>['COATZA MINA','COATZA/MINA','COATZA / MINA','COATZA-MINA','COATZACOALCOS MINATITLAN','COATZACOALCOS - MINATITLAN','COATZACOALCOS - MINATITLÁN'],
        ];
        if ($tipo === 'DISTRITO' || $tipo === 'DISTRITOS') {
            $solicitados = $tipo === 'DISTRITO' ? [(string)($scope['distrito'] ?? '')] : (array)($scope['distritos'] ?? []);
            if (!$solicitados) throw new InvalidArgumentException('Motor Comercial: no se indicaron distritos');
            $opciones = [];
            foreach ($solicitados as $d) {
                $normal = self::normalizar((string)$d);
                $compacto = preg_replace('/[^A-Z0-9]/', '', strtr($normal, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']));
                if (!isset($map[$compacto])) throw new InvalidArgumentException('Motor Comercial: distrito desconocido');
                foreach ($map[$compacto] as $alias) $opciones[self::normalizar($alias)] = true;
            }
            $valores = array_keys($opciones);
            $sql .= ' AND UPPER(TRIM(distrito)) IN ('.implode(',',array_fill(0,count($valores),'?')).')';
            array_push($args, ...$valores);
        }
        if ($tipo === 'FOLIOS' || $tipo === 'VENDEDOR') {
            $valores = $tipo === 'VENDEDOR'
                ? [(string)($scope['folio'] ?? '')]
                : (array)($scope['folios'] ?? []);
            $folios = array_values(array_unique(array_filter(array_map('trim',array_map('strval',$valores)), static fn($v)=>$v!=='')));
            if (!$folios) return $sql.' AND 1=0'; // sin folios nunca se convierte en REGIÓN
            $sql .= ' AND folio_empleado IN ('.implode(',',array_fill(0,count($folios),'?')).')';
            array_push($args, ...$folios);
        }
        // Filtro opcional por uno o varios canales; una lista explícita vacía
        // devuelve cero, evitando un ensanchamiento accidental del alcance.
        if (array_key_exists('canales',$scope)) {
            $canales = array_values(array_unique(array_filter(array_map(
                static fn($v)=>self::normalizar($v), (array)$scope['canales']
            ),static fn($v)=>$v!=='')));
            if (!$canales) return $sql.' AND 1=0';
            $col = $origen === 'ventas' ? 'canal_venta' : 'origen_prospecto';
            $sql .= ' AND UPPER(TRIM(`'.$col.'`)) IN ('.implode(',',array_fill(0,count($canales),'?')).')';
            array_push($args, ...$canales);
        }
        return $sql;
    }

    /**
     * Todos los canales en el alcance recibido. Cuenta REGISTROS de instalaciones,
     * igual que Evolución (no deduplica cuentas para evitar perder volumen).
     * Calcula simultáneamente ARPU ponderado, 2P/3P, RES/NEG y Bundle.
     * Usa clasificación exacta del mismo catálogo y Precio Pronto Pago directo.
     * Devuelve valores mensuales junto con auditoría de precios/clasificación.
     */
    public function indicadoresInstalacionesPorCatalogo(
        string $desde, string $hasta, string $modo, int $diaInicio, int $diaFin, array $scope, ?string $fechaInicioRango = null
    ): array {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
        if (!$inicio || !$fin || $inicio->format('Y-m-d') !== $desde || $fin->format('Y-m-d') !== $hasta || $inicio > $fin) {
            throw new InvalidArgumentException('Indicadores Comerciales: fechas inválidas');
        }
        if (!in_array($modo,['completo','mtd','custom'],true) || $diaInicio<1 || $diaInicio>31 || $diaFin<$diaInicio || $diaFin>31) {
            throw new InvalidArgumentException('Indicadores Comerciales: rango diario inválido');
        }
        // Selección mensual cruzada: períodos históricos anteriores completos;
        // desde el mes inicial del rango, usar solamente fechas reales seleccionadas.
        if ($fechaInicioRango !== null) {
            $recorte = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicioRango);
            if (!$recorte || $recorte->format('Y-m-d') !== $fechaInicioRango || $recorte < $inicio || $recorte > $fin) {
                throw new InvalidArgumentException('Indicadores Comerciales: fecha inicial cruzada inválida');
            }
            if ($modo !== 'completo') throw new InvalidArgumentException('Indicadores Comerciales: recorte requiere modo completo');
        }
        $out = ['meses'=>[], 'datos'=>[]];
        $cursor = $inicio->modify('first day of this month');
        $ultimo = $fin->modify('first day of this month');
        while ($cursor <= $ultimo) {
            $ym = $cursor->format('Y-m');
            $out['meses'][] = $ym;
            $out['datos'][$ym] = self::resumenVacio();
            $cursor = $cursor->modify('+1 month');
        }
        $tienePrecio = isset($this->columnasInst['precio_pronto_pago']);
        $precioSelect = $tienePrecio ? '`precio_pronto_pago`' : 'NULL';
        $sql = "SELECT DATE_FORMAT(`fecha`,'%Y-%m') AS ym, `plan`, {$precioSelect} AS precio, COUNT(*) AS n
                FROM `instalaciones` WHERE `fecha` >= ? AND `fecha` <= ? AND `origen_prospecto` <> '-'";
        $args = [$desde,$hasta];
        if ($modo !== 'completo') {
            $sql .= ' AND DAY(`fecha`) BETWEEN ? AND ?';
            $args[] = $diaInicio; $args[] = $diaFin;
        }
        if ($fechaInicioRango !== null) {
            $inicioMesRango = substr($fechaInicioRango,0,7).'-01';
            $sql .= ' AND (`fecha` < ? OR `fecha` >= ?)';
            $args[] = $inicioMesRango; $args[] = $fechaInicioRango;
        }
        $sql .= $this->filtrosGerenciales('instalaciones',$scope,$args);
        $sql .= " GROUP BY DATE_FORMAT(`fecha`,'%Y-%m'), `plan`".($tienePrecio?', `precio_pronto_pago`':'');
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Indicadores Comerciales: no se pudo preparar instalaciones');
        $stmt->bind_param(str_repeat('s',count($args)), ...$args);
        if (!$stmt->execute()) throw new RuntimeException('Indicadores Comerciales: falló consulta instalaciones');
        $rs = $stmt->get_result();
        if (!$rs) throw new RuntimeException('Indicadores Comerciales: falló lectura instalaciones');
        while ($row = $rs->fetch_assoc()) {
            $ym = (string)($row['ym'] ?? '');
            if (!isset($out['datos'][$ym])) continue;
            $n = (int)$row['n'];
            if ($n <= 0) continue;
            $c = self::clasificarCuenta([['plan'=>$row['plan'] ?? '', 'precio_pronto_pago'=>$row['precio'] ?? null]],$this->catalogo);
            $s = &$out['datos'][$ym];
            $s['total'] += $n;
            $s[$c['play']] += $n;
            $s[$c['oferta']] += $n;
            $s[$c['bundle']] += $n;
            if ($c['catalogo_estado'] === 'SIN_MATCH') $s['catalogo_sin_match'] += $n;
            if ($c['catalogo_estado'] === 'AMBIGUO') $s['catalogo_ambiguo'] += $n;
            if ($c['plan_ambiguo']) $s['instalacion_plan_ambiguo'] += $n;
            if ($c['precio_ambiguo']) $s['arpu_precio_ambiguo'] += $n;
            if ($c['precio_arpu'] !== null) {
                $s['arpu_ingreso_neto'] += $c['precio_arpu']*$n; // alias heredado
                $s['arpu_suma_pronto_pago'] += $c['precio_arpu']*$n;
                $s['arpu_cuentas'] += $n;
                if ($c['fuente_precio'] === 'CATALOGO_ACTUAL') $s['arpu_fuente_catalogo'] += $n;
            }
            unset($s);
        }
        $rs->free(); $stmt->close();
        foreach ($out['datos'] as &$s) self::finalizar($s);
        unset($s);
        return $out;
    }

    /**
     * TalIA v0.2.1+evolucion: agrupa por mes y clasificación del catálogo.
     * A diferencia de resumirVendedores(), aquí NO se asegura pertenencia al universo
     * certificado HC. Es un reporte gerencial de TODOS LOS CANALES que conserva las
     * mismas filas que la evolución del índice (ventas e instalaciones).
     *
     * El motor sigue siendo la autoridad de taxonomía comercial; la jerarquía/scope
     * son filtros de lectura recibidos desde el consumidor ya autorizado.
     *
     * $scope: ['tipo'=>'REGION'] | ['tipo'=>'DISTRITO','distrito'=>'...'] |
     *         ['tipo'=>'FOLIOS','folios'=>['...', ...]]. Un scope sin folios es vacío.
     * $modo: completo | mtd | custom. El período termina en $hasta, nunca futuro.
     */
    public function evolucionPorCatalogo(
        string $desde,
        string $hasta,
        string $modo,
        int $diaInicio,
        int $diaFin,
        array $scope,
        ?string $fechaInicioRango = null
    ): array {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
        if (!$inicio || !$fin || $inicio->format('Y-m-d') !== $desde || $fin->format('Y-m-d') !== $hasta || $inicio > $fin) {
            throw new InvalidArgumentException('Evolución Comercial: fechas inválidas');
        }
        if (!in_array($modo, ['completo','mtd','custom'], true) || $diaInicio < 1 || $diaInicio > 31 || $diaFin < $diaInicio || $diaFin > 31) {
            throw new InvalidArgumentException('Evolución Comercial: selección de días inválida');
        }
        if ($fechaInicioRango !== null) {
            $recorte = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicioRango);
            if (!$recorte || $recorte->format('Y-m-d') !== $fechaInicioRango || $recorte < $inicio || $recorte > $fin) {
                throw new InvalidArgumentException('Evolución Comercial: fecha inicial cruzada inválida');
            }
            if ($modo !== 'completo') throw new InvalidArgumentException('Evolución Comercial: recorte requiere modo completo');
        }
        $labels = [];
        $d = $inicio->modify('first day of this month');
        $last = $fin->modify('first day of this month');
        while ($d <= $last) {
            $labels[] = $d->format('Y-m');
            $d = $d->modify('+1 month');
        }
        $dimensiones = [
            'oferta'=>['Residencial','Negocios','Sin clasificar'],
            'play'=>['2P','3P','Sin clasificar'],
            'bundle'=>['Bundle','No Bundle','Sin clasificar'],
        ];
        $plantilla = [];
        foreach ($dimensiones as $dimension=>$categorias) {
            foreach (['ventas','instalaciones'] as $origen) {
                foreach ($categorias as $categoria) $plantilla[$dimension][$origen][$categoria] = array_fill_keys($labels,0);
            }
        }
        $out = ['meses'=>$labels,'datos'=>$plantilla,'totales'=>['ventas'=>array_fill_keys($labels,0),'instalaciones'=>array_fill_keys($labels,0)]];
        foreach (['ventas','instalaciones'] as $origen) {
            $tabla = $origen === 'ventas' ? 'ventas' : 'instalaciones';
            $fecha = $origen === 'ventas' ? 'fecha_cierre' : 'fecha';
            $plan = $origen === 'ventas' ? 'nombre_plan' : 'plan';
            $sql = "SELECT DATE_FORMAT(`{$fecha}`, '%Y-%m') AS ym, `{$plan}` AS plan, COUNT(*) AS n FROM `{$tabla}` WHERE `{$fecha}` >= ? AND `{$fecha}` <= ?";
            $args = [$desde, $hasta];
            if ($origen === 'instalaciones') $sql .= " AND origen_prospecto <> '-'";
            if ($modo !== 'completo') {
                $sql .= " AND DAY(`{$fecha}`) BETWEEN ? AND ?";
                $args[] = $diaInicio;
                $args[] = $diaFin;
            }
            if ($fechaInicioRango !== null) {
                $inicioMesRango = substr($fechaInicioRango,0,7).'-01';
                $sql .= " AND (`{$fecha}` < ? OR `{$fecha}` >= ?)";
                $args[] = $inicioMesRango; $args[] = $fechaInicioRango;
            }
            $sql .= $this->filtrosGerenciales($origen,$scope,$args);
            $sql .= " GROUP BY DATE_FORMAT(`{$fecha}`, '%Y-%m'), `{$plan}`";
            $st = $this->db->prepare($sql);
            if (!$st) throw new RuntimeException('Evolución Comercial: consulta no preparada de '.$tabla);
            $st->bind_param(str_repeat('s',count($args)), ...$args);
            if (!$st->execute()) throw new RuntimeException('Evolución Comercial: consulta no ejecutada de '.$tabla);
            $rs = $st->get_result();
            if (!$rs) throw new RuntimeException('Evolución Comercial: lectura fallida de '.$tabla);
            while ($r = $rs->fetch_assoc()) {
                $ym = (string)($r['ym'] ?? '');
                if (!isset($out['totales'][$origen][$ym])) continue;
                $n = (int)$r['n'];
                $out['totales'][$origen][$ym] += $n;
                // Misma función de clasificación que usa el motor sobre cada cuenta.
                // En evolución se cuenta por registro para conciliar con Canal de Venta.
                $clas = self::clasificarCuenta([['plan'=>$r['plan'] ?? '']],$this->catalogo);
                $oferta = match($clas['oferta']) {
                    'residencial'=>'Residencial', 'negocios'=>'Negocios', default=>'Sin clasificar',
                };
                $play = match($clas['play']) {
                    'doble_play'=>'2P', 'triple_play'=>'3P', default=>'Sin clasificar',
                };
                $bundle = match($clas['bundle']) {
                    'bundle'=>'Bundle', 'no_bundle'=>'No Bundle', default=>'Sin clasificar',
                };
                $out['datos']['oferta'][$origen][$oferta][$ym] += $n;
                $out['datos']['play'][$origen][$play][$ym] += $n;
                $out['datos']['bundle'][$origen][$bundle][$ym] += $n;
            }
            $rs->free();
            $st->close();
        }
        foreach (['oferta','play','bundle'] as $dimension) foreach (['ventas','instalaciones'] as $origen) foreach ($labels as $ym) {
            $suma=0;
            foreach ($out['datos'][$dimension][$origen] as $arr) $suma += $arr[$ym];
            if ($suma !== $out['totales'][$origen][$ym]) throw new LogicException('Evolución Comercial: no concilia '.$dimension.' '.$origen.' '.$ym);
        }
        return $out;
    }


    /**
     * TalIA / Series semanales ISO del universo gerencial, sin consultas por semana.
     * Semana 01 de 2026 inicia 2025-12-29; YEARWEEK(,3) no trunca el año natural.
     * Reutiliza EXACTAMENTE clasificarCuenta() y el catálogo de este motor.
     * $diasIso permite que la selección LUN-SÁB se aplique a todas las semanas.
     * Devuelve una serie conjunta ARPU, Canal, Oferta, Plays y Bundle (ventas/INS).
     */
    public function seriesSemanalesPorCatalogo(
        string $desde, string $hasta, array $scope, array $diasIso = []
    ): array {
        $fi=DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
        $ff=DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if (!$fi || !$ff || $fi->format('Y-m-d')!==$desde || $ff->format('Y-m-d')!==$hasta || $fi>$ff) {
            throw new InvalidArgumentException('Series ISO: rango de fechas inválido');
        }
        foreach ($diasIso as $dia) {
            if (!is_int($dia) || $dia<1 || $dia>7) throw new InvalidArgumentException('Series ISO: día inválido');
        }
        $primera = $fi->modify('-'.((int)$fi->format('N')-1).' days');
        $ultima  = $ff->modify('-'.((int)$ff->format('N')-1).' days');
        $keys=[]; $labels=[]; $rangos=[];
        for($lunes=$primera;$lunes<=$ultima;$lunes=$lunes->modify('+7 days')) {
            $k=$lunes->format('oW');
            $keys[]=$k;
            $domingo=$lunes->modify('+6 days');
            $rangos[$k]=['desde'=>$lunes->format('Y-m-d'),'hasta'=>$domingo->format('Y-m-d')];
            $etiquetaCorta=($lunes->format('m')===$domingo->format('m'))
                ? $lunes->format('d').'–'.$domingo->format('d/m')
                : $lunes->format('d/m').'–'.$domingo->format('d/m');
            $labels[$k]='S'.$lunes->format('W').' · '.$etiquetaCorta;
        }
        $dimensiones=[
            'oferta'=>['Residencial','Negocios','Sin clasificar'],
            'play'=>['2P','3P','Sin clasificar'],
            'bundle'=>['Bundle','No Bundle','Sin clasificar'],
        ];
        $datos=[];
        foreach(['ventas','instalaciones'] as $origen) {
            foreach ($dimensiones as $dim=>$categorias) {
                foreach($categorias as $cat) $datos[$dim][$origen][$cat]=array_fill_keys($keys,0);
            }
            $datos['canal'][$origen]=[];
        }
        $out=[
            'semanas'=>$keys,'labels'=>$labels,'rangos'=>$rangos,'datos'=>$datos,
            'totales'=>['ventas'=>array_fill_keys($keys,0),'instalaciones'=>array_fill_keys($keys,0)],
            'arpu'=>array_fill_keys($keys,null),
            'arpu_cuentas'=>array_fill_keys($keys,0),
        ];
        $ingresos=array_fill_keys($keys,0.0);
        foreach(['ventas','instalaciones'] as $origen) {
            $tabla=$origen==='ventas' ? 'ventas':'instalaciones';
            $fecha=$origen==='ventas' ? 'fecha_cierre':'fecha';
            $plan=$origen==='ventas' ? 'nombre_plan':'plan';
            $canal=$origen==='ventas' ? 'canal_venta':'origen_prospecto';
            $precio=($origen==='instalaciones' && isset($this->columnasInst['precio_pronto_pago'])) ? '`precio_pronto_pago`' : 'NULL';
            $sql="SELECT YEARWEEK(`{$fecha}`,3) AS semana_iso, `{$plan}` AS plan, `{$canal}` AS canal, {$precio} AS precio, COUNT(*) AS n
                FROM `{$tabla}` WHERE `{$fecha}` >= ? AND `{$fecha}` <= ?";
            $args=[$desde,$hasta];
            if($origen==='instalaciones') $sql.=" AND `origen_prospecto` <> '-'";
            if($diasIso) {
                $mysqlDias=array_map(static fn(int $d):int=>$d===7?1:$d+1,$diasIso);
                $sql.=' AND DAYOFWEEK(`'.$fecha.'`) IN ('.implode(',',array_map('intval',$mysqlDias)).')';
            }
            $sql.=$this->filtrosGerenciales($origen,$scope,$args);
            $sql.=" GROUP BY YEARWEEK(`{$fecha}`,3), `{$plan}`, `{$canal}`";
            if($origen==='instalaciones' && isset($this->columnasInst['precio_pronto_pago'])) $sql.=', `precio_pronto_pago`';
            $st=$this->db->prepare($sql);
            if (!$st) throw new RuntimeException('Series ISO: no pudo preparar '.$tabla);
            $st->bind_param(str_repeat('s',count($args)),...$args);
            if (!$st->execute()) throw new RuntimeException('Series ISO: consulta falló para '.$tabla);
            $rs=$st->get_result();
            if (!$rs) throw new RuntimeException('Series ISO: resultados no disponibles '.$tabla);
            while($r=$rs->fetch_assoc()) {
                $k=(string)($r['semana_iso']??'');
                if(!array_key_exists($k,$out['totales'][$origen])) continue;
                $n=(int)$r['n'];
                if($n<=0) continue;
                $out['totales'][$origen][$k]+=$n;
                $nombreCanal=trim((string)($r['canal']??''));
                if($nombreCanal==='') $nombreCanal='OTROS';
                if(!isset($out['datos']['canal'][$origen][$nombreCanal])) {
                    $out['datos']['canal'][$origen][$nombreCanal]=array_fill_keys($keys,0);
                }
                $out['datos']['canal'][$origen][$nombreCanal][$k]+=$n;
                $cl=self::clasificarCuenta([['plan'=>$r['plan']??'','precio_pronto_pago'=>$r['precio']??null]],$this->catalogo);
                $oferta=match($cl['oferta']) {'residencial'=>'Residencial','negocios'=>'Negocios',default=>'Sin clasificar'};
                $play=match($cl['play']) {'doble_play'=>'2P','triple_play'=>'3P',default=>'Sin clasificar'};
                $bundle=match($cl['bundle']) {'bundle'=>'Bundle','no_bundle'=>'No Bundle',default=>'Sin clasificar'};
                $out['datos']['oferta'][$origen][$oferta][$k]+=$n;
                $out['datos']['play'][$origen][$play][$k]+=$n;
                $out['datos']['bundle'][$origen][$bundle][$k]+=$n;
                if($origen==='instalaciones' && $cl['precio_arpu']!==null) {
                    $ingresos[$k]+=(float)$cl['precio_arpu']*$n;
                    $out['arpu_cuentas'][$k]+=$n;
                }
            }
            $rs->free();$st->close();
        }
        foreach($keys as $key) {
            $n=(int)$out['arpu_cuentas'][$key];
            if($n>0) $out['arpu'][$key]=round($ingresos[$key]/$n,2);
            foreach(['ventas','instalaciones'] as $origen) foreach(['canal','oferta','play','bundle'] as $dim) {
                $suma=0;
                foreach($out['datos'][$dim][$origen] as $cat=>$serie) $suma+=(int)($serie[$key]??0);
                if($suma!==(int)$out['totales'][$origen][$key]) {
                    throw new LogicException('Series ISO: sin conciliación '.$origen.' '.$dim.' '.$key);
                }
            }
        }
        return $out;
    }


    /**
     * Series diarias de un rango personalizado, por REGISTRO y todos los canales
     * del scope AUTORIZADO. Sin período histórico añadido. La agrupación se hace
     * en SQL por fecha/plan/canal/precio y mantiene 2P/3P, RES/NEG y Bundle del catálogo.
     */
    public function seriesDiariasPorCatalogo(string $desde, string $hasta, array $scope): array
    {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if (!$inicio || !$fin || $inicio->format('Y-m-d')!==$desde || $fin->format('Y-m-d')!==$hasta || $inicio>$fin) {
            throw new InvalidArgumentException('Serie diaria: fechas inválidas');
        }
        $dias=[];
        for ($d=$inicio; $d<=$fin; $d=$d->modify('+1 day')) $dias[]=$d->format('Y-m-d');
        $dimensiones=[
            'oferta'=>['Residencial','Negocios','Sin clasificar'],
            'play'=>['2P','3P','Sin clasificar'],
            'bundle'=>['Bundle','No Bundle','Sin clasificar'],
        ];
        $datos=[];
        foreach (['ventas','instalaciones'] as $origen) {
            foreach ($dimensiones as $dim=>$categorias) {
                foreach ($categorias as $cat) $datos[$dim][$origen][$cat]=array_fill_keys($dias,0);
            }
            $datos['canal'][$origen]=[];
        }
        $out=[
            'dias'=>$dias, 'datos'=>$datos,
            'totales'=>['ventas'=>array_fill_keys($dias,0),'instalaciones'=>array_fill_keys($dias,0)],
            'arpu'=>array_fill_keys($dias,null),'arpu_cuentas'=>array_fill_keys($dias,0),
        ];
        $ingresos=array_fill_keys($dias,0.0);
        foreach (['ventas','instalaciones'] as $origen) {
            $tabla=$origen==='ventas'?'ventas':'instalaciones';
            $fecha=$origen==='ventas'?'fecha_cierre':'fecha';
            $plan=$origen==='ventas'?'nombre_plan':'plan';
            $canal=$origen==='ventas'?'canal_venta':'origen_prospecto';
            $precio=($origen==='instalaciones' && isset($this->columnasInst['precio_pronto_pago'])) ? '`precio_pronto_pago`' : 'NULL';
            $sql="SELECT `{$fecha}` AS dia, `{$plan}` AS plan, `{$canal}` AS canal, {$precio} AS precio, COUNT(*) AS n
                  FROM `{$tabla}` WHERE `{$fecha}` >= ? AND `{$fecha}` <= ?";
            $args=[$desde,$hasta];
            if($origen==='instalaciones') $sql.=" AND `origen_prospecto` <> '-'";
            $sql.=$this->filtrosGerenciales($origen,$scope,$args);
            $sql.=" GROUP BY `{$fecha}`, `{$plan}`, `{$canal}`";
            if($origen==='instalaciones' && isset($this->columnasInst['precio_pronto_pago'])) $sql.=', `precio_pronto_pago`';
            $st=$this->db->prepare($sql);
            if (!$st) throw new RuntimeException('Serie diaria: no se pudo preparar '.$tabla);
            $st->bind_param(str_repeat('s',count($args)),...$args);
            if (!$st->execute()) throw new RuntimeException('Serie diaria: consulta falló para '.$tabla);
            $rs=$st->get_result();
            if (!$rs) throw new RuntimeException('Serie diaria: sin resultados '.$tabla);
            while($r=$rs->fetch_assoc()) {
                $dia=substr((string)($r['dia']??''),0,10);
                if (!array_key_exists($dia,$out['totales'][$origen])) continue;
                $n=(int)($r['n']??0);
                if($n<=0) continue;
                $out['totales'][$origen][$dia]+=$n;
                $nombreCanal=trim((string)($r['canal']??''));
                if($nombreCanal==='') $nombreCanal='OTROS';
                if(!isset($out['datos']['canal'][$origen][$nombreCanal])) {
                    $out['datos']['canal'][$origen][$nombreCanal]=array_fill_keys($dias,0);
                }
                $out['datos']['canal'][$origen][$nombreCanal][$dia]+=$n;
                $c=self::clasificarCuenta([['plan'=>$r['plan']??'','precio_pronto_pago'=>$r['precio']??null]],$this->catalogo);
                $oferta=match($c['oferta']) {'residencial'=>'Residencial','negocios'=>'Negocios',default=>'Sin clasificar'};
                $play=match($c['play']) {'doble_play'=>'2P','triple_play'=>'3P',default=>'Sin clasificar'};
                $bundle=match($c['bundle']) {'bundle'=>'Bundle','no_bundle'=>'No Bundle',default=>'Sin clasificar'};
                $out['datos']['oferta'][$origen][$oferta][$dia]+=$n;
                $out['datos']['play'][$origen][$play][$dia]+=$n;
                $out['datos']['bundle'][$origen][$bundle][$dia]+=$n;
                if($origen==='instalaciones' && $c['precio_arpu']!==null) {
                    $ingresos[$dia]+=(float)$c['precio_arpu']*$n;
                    $out['arpu_cuentas'][$dia]+=$n;
                }
            }
            $rs->free();$st->close();
        }
        foreach($dias as $dia) {
            $n=(int)$out['arpu_cuentas'][$dia];
            if($n>0) $out['arpu'][$dia]=round($ingresos[$dia]/$n,2);
            foreach(['ventas','instalaciones'] as $origen) foreach(['canal','oferta','play','bundle'] as $dim) {
                $total=0;
                foreach($out['datos'][$dim][$origen] as $serie) $total+=(int)($serie[$dia]??0);
                if($total!==(int)$out['totales'][$origen][$dia]) {
                    throw new LogicException('Serie diaria: no concilia '.$origen.' '.$dim.' '.$dia);
                }
            }
        }
        return $out;
    }

    /** Para ARPU diario de Líder/Coach se limita a eventos certificados por Motor HC. */
    public function indicadoresDiariosHc(array $eventos,string $desde,string $hasta): array
    {
        $ini=DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
        $fin=DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if(!$ini || !$fin || $ini->format('Y-m-d')!==$desde || $fin->format('Y-m-d')!==$hasta || $ini>$fin) {
            throw new InvalidArgumentException('ARPU diario HC: rango inválido');
        }
        $dias=[];
        for($d=$ini;$d<=$fin;$d=$d->modify('+1 day')) $dias[$d->format('Y-m-d')]=self::resumenVacio();
        $cuentas=[];$fechaCuenta=[];
        foreach($eventos as $e) {
            $cuenta=trim((string)($e['cuenta']??''));
            $fecha=substr((string)($e['fecha']??''),0,10);
            if($cuenta===''||$fecha<$desde||$fecha>$hasta) continue;
            if(isset($cuentas[$cuenta])) throw new LogicException('ARPU diario HC: cuenta duplicada '.$cuenta);
            $cuentas[$cuenta]=true;$fechaCuenta[$cuenta]=$fecha;
        }
        $filas=[];
        if($cuentas) {
            $columnas='`cuenta`,`fecha`,`plan`'.(isset($this->columnasInst['precio_pronto_pago'])?',`precio_pronto_pago`':'');
            foreach(array_chunk(array_keys($cuentas),300) as $lote) {
                $ph=implode(',',array_fill(0,count($lote),'?'));
                $sql="SELECT {$columnas} FROM `instalaciones` WHERE `cuenta` IN ({$ph})
                    AND `fecha` >= ? AND `fecha` <= ?";
                $st=$this->db->prepare($sql);
                if(!$st) throw new RuntimeException('ARPU diario HC: lectura no preparada');
                $args=array_merge($lote,[$desde,$hasta]);
                $st->bind_param(str_repeat('s',count($args)),...$args);
                if(!$st->execute()) throw new RuntimeException('ARPU diario HC: consulta fallida');
                $rs=$st->get_result();
                if(!$rs) throw new RuntimeException('ARPU diario HC: lectura fallida');
                while($r=$rs->fetch_assoc()) {
                    $id=trim((string)($r['cuenta']??''));
                    if(isset($cuentas[$id])) $filas[$id][]=$r;
                }
                $rs->free();$st->close();
            }
        }
        foreach($fechaCuenta as $cuenta=>$fecha) {
            if(!isset($dias[$fecha])) throw new LogicException('ARPU diario HC: evento fuera de rango');
            self::acumular($dias[$fecha], self::clasificarCuenta($filas[$cuenta]??[], $this->catalogo));
        }
        foreach($dias as &$row) self::finalizar($row);
        unset($row);
        return $dias;
    }

    /** Series HC certificadas, una lectura por lotes de instalaciones para toda la ventana ISO.
     * Recibe exclusivamente cuentas previamente autorizadas por Motor HC.
     */
    public function indicadoresSemanalesHc(array $eventos, string $desde, string $hasta, array $diasIso=[]): array
    {
        $inicio=DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
        $fin=DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if(!$inicio || !$fin || $inicio->format('Y-m-d')!==$desde || $fin->format('Y-m-d')!==$hasta || $inicio>$fin) {
            throw new InvalidArgumentException('Series HC semanales: fecha inválida');
        }
        foreach($diasIso as $d) if(!is_int($d)||$d<1||$d>7) throw new InvalidArgumentException('Series HC: día ISO inválido');
        $semanas=[]; $lunes=$inicio->modify('-'.((int)$inicio->format('N')-1).' days');
        for($d=$lunes;$d<=$fin;$d=$d->modify('+7 days')) $semanas[$d->format('oW')]=self::resumenVacio();
        $ids=[];$fechaPorCuenta=[];
        foreach($eventos as $e) {
            $cuenta=trim((string)($e['cuenta']??''));
            $f=substr((string)($e['fecha']??''),0,10);
            if($cuenta==='' || $f<$desde || $f>$hasta) continue;
            if($diasIso && !in_array((int)(new DateTimeImmutable($f))->format('N'),$diasIso,true)) continue;
            if(isset($ids[$cuenta])) throw new LogicException('Serie HC: cuenta duplicada '.$cuenta);
            $ids[$cuenta]=true;
            $fechaPorCuenta[$cuenta]=$f;
        }
        $filas=[];
        if($ids) {
            $cols='`cuenta`, `fecha`, `plan`'.(isset($this->columnasInst['precio_pronto_pago'])?', `precio_pronto_pago`':'');
            $finExc=$fin->modify('+1 day')->format('Y-m-d');
            foreach(array_chunk(array_keys($ids),300) as $chunk) {
                $ph=implode(',',array_fill(0,count($chunk),'?'));
                $sql="SELECT {$cols} FROM `instalaciones` WHERE `cuenta` IN ({$ph}) AND `fecha` >= ? AND `fecha` < ?";
                $st=$this->db->prepare($sql);
                if(!$st) throw new RuntimeException('Serie HC: no pudo preparar lectura');
                $args=array_merge($chunk,[$desde,$finExc]);
                $st->bind_param(str_repeat('s',count($args)),...$args);
                if(!$st->execute()) throw new RuntimeException('Serie HC: consulta fallida');
                $rs=$st->get_result();
                if(!$rs) throw new RuntimeException('Serie HC: sin resultados');
                while($r=$rs->fetch_assoc()) {
                    $key=trim((string)($r['cuenta']??''));
                    if(!isset($ids[$key])) continue;
                    $fechaFila=substr((string)($r['fecha']??''),0,10);
                    if($diasIso && !in_array((int)(new DateTimeImmutable($fechaFila))->format('N'),$diasIso,true)) continue;
                    $filas[$key][]=$r;
                }
                $rs->free();$st->close();
            }
        }
        foreach($fechaPorCuenta as $cuenta=>$f) {
            $week=(new DateTimeImmutable($f))->format('oW');
            if(!isset($semanas[$week])) throw new LogicException('Serie HC: semana fuera del rango '.$week);
            self::acumular($semanas[$week],self::clasificarCuenta($filas[$cuenta]??[],$this->catalogo));
        }
        foreach($semanas as &$r) self::finalizar($r);
        unset($r);
        return $semanas;
    }

}
