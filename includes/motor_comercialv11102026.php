<?php
declare(strict_types=1);
/**
 * TalIA / MOTOR COMERCIAL v0.2.0 — complemento del MOTOR HC v0.6.2
 * ==================================================================
 * UBICACIÓN: /public_html/plataforma/includes/motor_comercial.php
 * CONSUMIDOR INICIAL: /plataforma/detalle/ranking_productividad_COMERCIAL_STAGING.php
 * ESCRITURA EN BD: ninguna; únicamente SELECT y SHOW COLUMNS.
 *
 * AUTORIDAD:
 * - Motor HC establece el universo certificado, una cuenta por período, el
 *   vendedor, el coach y el líder. Este motor NO resuelve identidades ni HC.
 * - Este módulo añade características comerciales de esas MISMAS cuentas.
 * - La tabla instalaciones se consulta solo por cuenta + fechas + canales del
 *   universo HC. Nunca se suman instalaciones adicionales a las recibidas.
 *
 * FUENTES:
 * - instalaciones(cuenta,fecha,plan,precio_lista,origen_prospecto)
 * - catalogo_paquetes(nombre_plan,play,tipo,bundle_app,precio_lista)
 *   El precio_lista es opcional en cada fuente; la clasificación no lo es.
 *
 * REGLAS:
 * - Match EXACTO tras mayúsculas, trim y normalización de espacios.
 * - play: DOBLE PLAY/2P -> 2P; TRIPLE PLAY/3P -> 3P.
 * - tipo: RESIDENCIAL o NEGOCIOS (PYME se conserva).
 * - bundle_app: NO -> NO BUNDLE; SI -> BUNDLE.
 * - Indefinido o ambiguo -> SIN CLASIFICAR. No usar heurísticas por texto.
 * - % de cada categoría = cuentas de categoría / TODAS las INS certificadas
 *   del vendedor en el período actual. Por ello pueden no sumar 100% si
 *   existen cuentas sin clasificar. El faltante se audita.
 * - ARPU = SUM(precio_lista / 1.16) / N cuentas con precio válido > 0.
 *   Prioridad de precio: instalaciones; respaldo: catálogo ACTUAL, auditado.
 *   No representa un precio histórico si solo existe respaldo de catálogo.
 * - Duplicados por cuenta: se contrastan SOLO dentro del período/días/canal
 *   consultados; planes contrapuestos no se seleccionan arbitrariamente.
 * - Si existen varios precios positivos contrapuestos en la misma cuenta,
 *   no se promedian ni se elige uno: esa cuenta queda sin ARPU.
 *
 * CONTRATO PÚBLICO:
 *   $m = new MotorComercial($conexion); // mysqli
 *   $resumen = $m->resumirVendedores($cuentasCertificadas,$desde,$hasta,$diasIso);
 *   // Clave: lider_id|coach_id|vendedor_id, idéntica al Ranking HC.
 *   // Resumen: total,doble_play,triple_play,residencial,negocios,
 *   //          no_bundle,bundle,arpu,arpu_ingreso_neto,arpu_cuentas,
 *   //          faltantes y contadores de auditoría.
 *
 * PRUEBAS DE ACEPTACIÓN:
 *   1) Por vendedor: total == ins_base o ins_actual del Motor HC.
 *   2) Por dimensión: total == suma(categorías) + sin_clasificar.
 *   3) BASE y ACTUAL se consultan INDEPENDIENTEMENTE con sus fechas/días.
 *   4) La vista Líder/Coach, los filtros y el Motor HC no se modifican.
 *   5) Contrastar ARPU con la metodología corporativa antes de producción.
 */
final class MotorComercial
{
    public const VERSION = '0.2.0';
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
        foreach (['nombre_plan', 'play', 'tipo', 'bundle_app'] as $col) {
            if (!isset($this->columnasCatalogo[$col])) {
                throw new RuntimeException('Motor Comercial: falta catalogo_paquetes.'.$col);
            }
        }
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
        if (isset($this->columnasCatalogo['precio_lista'])) $campos[] = 'precio_lista';
        $res = $this->db->query('SELECT '.implode(',', array_map(static fn($x) => '`'.$x.'`',$campos)).' FROM catalogo_paquetes');
        if ($res === false) throw new RuntimeException('No se pudo leer catalogo_paquetes');
        while ($r = $res->fetch_assoc()) {
            $nombre = self::normalizar($r['nombre_plan'] ?? '');
            if ($nombre === '' || $nombre === '-') continue;
            $entrada = [
                'play' => self::normalizar($r['play'] ?? ''),
                'tipo' => self::normalizar($r['tipo'] ?? ''),
                'bundle_app' => self::normalizar($r['bundle_app'] ?? ''),
                'precio_lista' => self::precioPositivo($r['precio_lista'] ?? null),
                'conflictos' => [],
            ];
            if (!isset($this->catalogo[$nombre])) {
                $this->catalogo[$nombre] = $entrada;
                continue;
            }
            foreach (['play','tipo','bundle_app','precio_lista'] as $campo) {
                $prev = $this->catalogo[$nombre][$campo];
                $nuevo = $entrada[$campo];
                if (isset($this->catalogo[$nombre]['conflictos'][$campo])) continue;
                if ($campo === 'precio_lista') {
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
            'arpu' => null, 'arpu_ingreso_neto' => 0.0, 'arpu_cuentas' => 0,
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
            $p = self::precioPositivo($r['precio_lista'] ?? null);
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
        } elseif (!$planConflicto && !$precioConflicto && !$precios && $cat !== null && $cat['precio_lista'] !== null) {
            $precio = (float)$cat['precio_lista'];
            $fuente = 'CATALOGO_ACTUAL';
        }
        return [
            'play' => $p, 'oferta' => $o, 'bundle' => $b,
            'precio_neto' => $precio !== null ? $precio / 1.16 : null,
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
        if ($c['precio_neto'] !== null) {
            $s['arpu_ingreso_neto'] += $c['precio_neto'];
            $s['arpu_cuentas']++;
            if ($c['fuente_precio'] === 'CATALOGO_ACTUAL') $s['arpu_fuente_catalogo']++;
        }
    }

    private static function finalizar(array &$s): void
    {
        $s['arpu'] = $s['arpu_cuentas'] > 0 ? round($s['arpu_ingreso_neto'] / $s['arpu_cuentas'], 2) : null;
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
        if (isset($this->columnasInst['precio_lista'])) $campos[] = 'precio_lista';
        $cols = implode(',',array_map(static fn($x)=>'`'.$x.'`',$campos));
        $hastaExclusivo = $ff->modify('+1 day')->format('Y-m-d');
        foreach (array_chunk(array_values($unicos), 300) as $chunk) {
            $ph = implode(',', array_fill(0,count($chunk),'?'));
            $sql = "SELECT {$cols} FROM instalaciones WHERE cuenta IN ({$ph}) AND fecha >= ? AND fecha < ? AND UPPER(TRIM(origen_prospecto)) IN ('CAMBACEO','PUNTO DE VENTA','PDV')";
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
}
