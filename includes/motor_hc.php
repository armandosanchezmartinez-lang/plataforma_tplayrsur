<?php
declare(strict_types=1);
/**
 * TalIA / TotalXpedient · MOTOR HC v0.6.6 · STANDBY TOTAL + REGLA 40%
 * ============================================================================
 * Ubicación: /plataforma/includes/motor_hc.php
 * Contrato: RankingAtribucionServiceV06(mysqli $conexion).
 * Fuentes: instalaciones, hc, hc_standby, historial_identidad_colaborador (HIC).
 * Escritura en BD: ninguna. Solo consultas de lectura.
 *
 * REGLAS DE ATRIBUCIÓN
 * - Universo: todos los orígenes de instalaciones, sin exclusión por canal.
 * - Se certifica UNA cuenta por período solo cuando existe una cadena única
 *   vendedor (folio / HIC) -> coach (posición HC) -> líder (plaza HC).
 * - Los folios vacíos, '-' y 'VACANTE' no representan identidades; si hay dos
 *   folios reales no equivalentes, la cuenta se deja pendiente de auditoría.
 * - HIC homologa cambios documentados de número de talento sin asociar personas
 *   diferentes por compartir una posición. Bifurcaciones y ciclos quedan
 *   pendientes, nunca se elige un candidato de manera arbitraria.
 * - Cada instalación toma su fotografía semanal HC; cuando no existe, se usa
 *   exclusivamente la última fotografía ANTERIOR disponible, nunca una futura.
 * - La estructura se valida por posiciones (distrito|líder, coach, vendedor).
 *   Se mantiene la normalización de distrito de v0.6.1 y la resolución
 *   controlada de autorreferencias de coach de v0.6.2.
 * - Los campos de coach/líder registrados en instalaciones son diagnósticos,
 *   no sustituyen la autoridad jerárquica de Capital Humano.
 *
 * CAMBIO v0.6.6 (21-OCT-2026)
 * - Productividad conserva jornadas-persona reales disponibles por período.
 * - HC SIN INS incorpora elegibilidad centralizada: si STANDBY TOTAL (unión de
 *   INCAPACIDAD + VACACIONES) cubre más del 40% de los días operativos del período,
 *   el vendedor no cuenta como HC SIN INS.
 * - 40% exacto permanece elegible; los traslapes se cuentan una sola vez.
 * - HC Activo/Vacante no cambia por esta regla.
 *
 * CAMBIO v0.6.5 (20-OCT-2026)
 * - hc_standby es la fuente precisa por FECHA para VACACIONES e INCAPACIDAD.
 * - El Motor expone, por período, días operativos, días en standby y eventos que
 *   se cruzan con el rango consultado. Fechas futuras de término son válidas.
 * - La indisponibilidad se calcula como unión de fechas: eventos superpuestos
 *   nunca descuentan dos veces el mismo día.
 * - Domingos y días inhábiles no se descuentan de nuevo porque no forman parte
 *   de las jornadas operativas del período.
 * - HIC se aplica también al cruce con hc_standby para conservar identidad.
 * - STATUS permanece como fuente de auditoría/fallback cuando no existe intervalo
 *   preciso; no modifica atribución, identidad ni jerarquía.
 *
 * CAMBIO v0.6.4 (17-OCT-2026)
 * - HC incorpora status y fecha_alta en el contrato de fotografía.
 * - Se agrega clasificación canónica de estado operativo SOLO para nivel vendedor.
 * - INCAPACIDAD/VACACIONES conservan HC total/ocupado; en v0.6.5 su impacto
 *   operativo exacto se resuelve por hc_standby y STATUS queda como fallback.
 *
 * CAMBIO v0.6.3 (13-OCT-2026)
 * - Atribución de todos los orígenes comerciales a la jerarquía HC certificada.
 * - Marcadores de folio inválido excluidos del conjunto de identidades.
 * - Se preserva cuenta única, auditoría, HIC, jerarquía y contratos públicos.
 *
 * Entrada: obtenerInstalacionesJerarquia($desde,$hasta,$idLider=null,
 *          $idCoach=null,$idVendedor=null,$diasIso=[],$equivalenciasTalento=[]).
 * Salida: cuentas, conciliacion, conciliacion_global,
 *         conciliacion_comercial, auditoria_pendientes, fotografias_hc.
 * Ámbito: CANCUN, COATZA-MINA, MERIDA, TUXTLA y VILLAHERMOSA.
 */
final class RankingAtribucionServiceV06
{
    private mysqli $db;
    private const DISTRITOS = ['CANCUN', 'COATZA-MINA', 'MERIDA', 'TUXTLA', 'VILLAHERMOSA'];

    public function __construct(mysqli $db)
    {
        $this->db=$db;
    }

    /**
     * Regla canónica HC SIN INS v0.6.6.
     * - El umbral aplica al STANDBY TOTAL = unión de INCAPACIDAD + VACACIONES.
     * - Más de 40% excluye; 40% exacto sigue elegible.
     * - Solapamientos se cuentan una sola vez porque se usa dias_standby.
     * - Cero días disponibles excluye por ausencia total de oportunidad operativa.
     */
    private const UMBRAL_STANDBY_HC_SIN_INS = 40.0;

    public static function evaluarElegibilidadHcSinIns(
        int $diasPeriodo,
        int $diasStandby,
        int $diasDisponibles
    ): array {
        $diasPeriodo=max(1,$diasPeriodo);
        $diasStandby=max(0,min($diasPeriodo,$diasStandby));
        $diasDisponibles=max(0,min($diasPeriodo,$diasDisponibles));
        $pctStandby=($diasStandby/$diasPeriodo)*100.0;

        if ($pctStandby > self::UMBRAL_STANDBY_HC_SIN_INS) {
            return [
                'elegible'=>false,
                'motivo'=>'STANDBY_MAYOR_40',
                'pct_standby'=>$pctStandby,
                'umbral'=>self::UMBRAL_STANDBY_HC_SIN_INS,
            ];
        }

        if ($diasDisponibles <= 0) {
            return [
                'elegible'=>false,
                'motivo'=>'SIN_DIAS_DISPONIBLES',
                'pct_standby'=>$pctStandby,
                'umbral'=>self::UMBRAL_STANDBY_HC_SIN_INS,
            ];
        }

        return [
            'elegible'=>true,
            'motivo'=>'',
            'pct_standby'=>$pctStandby,
            'umbral'=>self::UMBRAL_STANDBY_HC_SIN_INS,
        ];
    }

    /** Homologación de distrito (misma semántica para HC y otros motores). */
    public static function normalizarDistrito(?string $valor): ?string
    {
        $n=self::norm($valor);
        $n=strtr($n,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);
        $compact=preg_replace('/[^A-Z0-9]/','',$n);
        $equiv=[
            'CANCUN'=>'CANCUN',
            'COATZAMINA'=>'COATZA-MINA',
            'COATZACOALCOSMINATITLAN'=>'COATZA-MINA',
            'MERIDA'=>'MERIDA',
            'TUXTLA'=>'TUXTLA',
            'TUXTLAGUTIERREZ'=>'TUXTLA',
            'VILLAHERMOSA'=>'VILLAHERMOSA',
        ];
        return $equiv[$compact]??null;
    }

    /** Registro de posiciones de líder de una fotografía, no nombres predefinidos. */
    private static function plazasLideres(array $rows): array
    {
        $porPos=[]; $referencias=[];
        foreach ($rows as $r) {
            $d=self::normalizarDistrito($r['distrito']??null);
            $pos=trim((string)($r['id_posicion']??''));
            if (!$d || $pos==='') continue;
            $porPos[$d][$pos][]=$r;
            if (stripos((string)($r['puesto_lr']??''),'LIDER')!==false) {
                $super=trim((string)($r['posicion_lr']??''));
                if ($super!=='') $referencias[$d][$super]=true;
            }
        }
        $out=[];
        foreach ($referencias as $d=>$posiciones) foreach ($posiciones as $pos=>$_) {
            $asientos=$porPos[$d][$pos]??[];
            // Un solo registro para la plaza; una plaza sin fila o duplicada no se certifica.
            if (count($asientos)!==1) continue;
            $r=$asientos[0];
            // Una posición de líder debe reportar directamente a Dirección Distrital;
            // si el dato está vacío/vacante, se conserva la plaza si tiene subordinados.
            $sup=self::norm($r['puesto_lr']??'');
            if ($sup!=='' && !str_contains($sup,'DIRECTOR DISTRITAL')) continue;
            $nombre=self::norm($r['nombre_colaborador']??'');
            $id=$d.'|'.$pos;
            $out[$id]=[
                'distrito'=>$d,'hc'=>$d,'nombre'=>($nombre===''?'VACANTE':$nombre),
                'aliases'=>[$nombre], 'plaza'=>(string)$pos,
                'ocupante_talento'=>trim((string)($r['numero_talento_gs']??'')),
            ];
        }
        return $out;
    }

    /** Catálogo visible en Ranking: todas las plazas de ambas fechas, con etiqueta de la última fotografía. */
    public function catalogoLideres(string $desde, string $hasta): array
    {
        $hc=$this->cargarHc($desde,$hasta);
        $out=[];
        foreach ($hc['datos'] as $foto=>$rows) {
            foreach (self::plazasLideres($rows) as $id=>$info) {
                if (!isset($out[$id])) $out[$id]=$info;
                else {
                    $out[$id]['aliases']=array_values(array_unique(array_merge($out[$id]['aliases'],$info['aliases'])));
                    // Cada foto procesada por orden temporal ascendente, última etiqueta gana.
                    $out[$id]['nombre']=$info['nombre'];
                    $out[$id]['ocupante_talento']=$info['ocupante_talento'];
                }
            }
        }
        ksort($out);
        return $out;
    }

    public static function norm(?string $s): string
    {
        return preg_replace('/\s+/u', ' ', (function_exists('mb_strtoupper') ? mb_strtoupper(trim((string)$s), 'UTF-8') : strtoupper(trim((string)$s)))) ?? '';
    }

    /**
     * Clasificación canónica de STATUS para NIVEL VENDEDOR.
     * No altera atribución ni estructura. Entrega únicamente metadatos HC operativos.
     *
     * Reglas:
     * - ACTIVO o blanco: HC total=1, ocupado=1, activo=1, operativo=1.
     * - VACANTE: sólo se acepta como vacante si el nombre contiene "VACANT".
     * - INCAPACIDAD / VACACIONES: HC total=1, ocupado=1, activo=0, operativo=0.
     * - Cualquier contradicción se marca INCONSISTENTE y no entra al denominador operativo.
     */
    public static function clasificarEstadoVendedor(?string $status, ?string $nombre): array
    {
        $st=self::norm($status);
        $nom=self::norm($nombre);
        if ($st==='') $st='ACTIVO';

        $base=[
            'status'=>$st,
            'estado_hc'=>$st,
            'hc_total'=>1,
            'hc_ocupado'=>1,
            'hc_activo'=>0,
            'hc_operativo'=>0,
            'es_vacante'=>false,
            'valido'=>true,
        ];

        if ($st==='ACTIVO') {
            $base['hc_activo']=1;
            $base['hc_operativo']=1;
            return $base;
        }

        if ($st==='VACANTE') {
            if (str_contains($nom,'VACANT')) {
                $base['hc_ocupado']=0;
                $base['es_vacante']=true;
                return $base;
            }
            $base['estado_hc']='INCONSISTENTE_STATUS_VACANTE';
            $base['valido']=false;
            return $base;
        }

        if ($st==='INCAPACIDAD' || $st==='VACACIONES') {
            return $base;
        }

        $base['estado_hc']='INCONSISTENTE_STATUS';
        $base['valido']=false;
        return $base;
    }

    private static function nameKey(string $name): string
    {
        $v = self::norm($name);
        $parts = array_values(array_filter(explode(' ', $v), static fn($p)=>$p!==''));
        sort($parts, SORT_STRING);
        return implode(' ', $parts);
    }

    private static function isValidName(?string $v): bool
    {
        return !in_array(self::norm($v), ['', '-', 'VACANTE', 'SIN COACH', 'NO IDENTIFICADO', 'COACH NO IDENTIFICADO'], true);
    }

    private static function isoWeek(string $date): array
    {
        $d = new DateTimeImmutable($date);
        return [(int)$d->format('o'), (int)$d->format('W')];
    }

    private function query(string $sql, string $types='', array $params=[]): array
    {
        $st = $this->db->prepare($sql);
        if ($types !== '') $st->bind_param($types, ...$params);
        $st->execute();
        $out = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $out;
    }

    /**
     * HIC es identidad de PERSONA. Nunca relacionar individuos por id_posicion.
     * Detecta bifurcaciones/ciclos y las deja expresamente sin certificación.
     * $historial: pares numero_talento_anterior/numero_talento_nuevo.
     */
    public static function resolverEquivalenciasHic(array $historial): array
    {
        $next=[]; $previous=[]; $nodes=[]; $ambiguous=[];
        foreach ($historial as $h) {
            $a=trim((string)($h['numero_talento_anterior']??''));
            $b=trim((string)($h['numero_talento_nuevo']??''));
            if ($a==='' || $b==='' || $a===$b || $a==='-' || $b==='-') continue;
            $nodes[$a]=true; $nodes[$b]=true;
            $next[$a][$b]=true;
            $previous[$b][$a]=true;
        }
        foreach ($next as $node=>$successors) if(count($successors)>1) $ambiguous[$node]=true;
        foreach ($previous as $node=>$predecessors) if(count($predecessors)>1) $ambiguous[$node]=true;
        $adj=[];
        foreach ($next as $a=>$bs) foreach($bs as $b=>$_) {
            $adj[$a][$b]=true; $adj[$b][$a]=true;
        }
        $aliases=[]; $canonical=[]; $status=[]; $visited=[];
        foreach (array_keys($nodes) as $start) {
            if (isset($visited[$start])) continue;
            $stack=[$start]; $component=[];
            while ($stack) {
                $v=array_pop($stack);
                if (isset($visited[$v])) continue;
                $visited[$v]=true; $component[$v]=true;
                foreach (array_keys($adj[$v]??[]) as $neighbor) if(!isset($visited[$neighbor])) $stack[]=$neighbor;
            }
            $ids=array_keys($component); sort($ids,SORT_STRING);
            $isAmbiguous=false; $tips=[];
            foreach ($ids as $id) {
                if (isset($ambiguous[$id])) $isAmbiguous=true;
                if (empty($next[$id])) $tips[]=$id;
            }
            if(count($tips)!==1) $isAmbiguous=true;
            $end=$isAmbiguous?null:$tips[0];
            foreach ($ids as $id) {
                $status[$id]=$isAmbiguous?'HIC_AMBIGUA':'HIC_VERIFICABLE';
                if (!$isAmbiguous) {
                    $canonical[$id]=$end;
                    $aliases[$id]=array_values(array_diff($ids,[$id]));
                }
            }
        }
        return ['equivalencias'=>$aliases,'canonicos'=>$canonical,'estados'=>$status,
                'componentes_ambiguos'=>count(array_unique(array_filter(array_map(
                    static fn($id)=>($status[$id]??'')==='HIC_AMBIGUA'?$id:null,array_keys($status)))) )];
    }

    /** Se ejecuta una única consulta HIC por selección, en modo lectura. */
    public function cargarHic(): array
    {
        return $this->query('SELECT numero_talento_anterior, numero_talento_nuevo FROM historial_identidad_colaborador');
    }

    /**
     * Fechas operativas reales del período.
     * - Domingo nunca cuenta.
     * - En semanal respeta el selector $diasIso y conserva la semántica histórica:
     *   cualquier fecha registrada en dias_inhabiles se excluye.
     * - En mensual/rango continuo se excluyen únicamente dias_inhabiles activos.
     */
    private function fechasOperativasPeriodo(string $desde, string $hasta, array $diasIso=[]): array
    {
        $ini=DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
        $fin=DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if (!$ini || !$fin || $ini>$fin) throw new InvalidArgumentException('Rango standby inválido');

        $diasIso=array_values(array_unique(array_map('intval',$diasIso)));
        foreach ($diasIso as $d) if ($d<1 || $d>7) throw new InvalidArgumentException('Día ISO standby inválido');

        $festivos=[];
        if ($diasIso) {
            // Homologa contar_dias_habiles_semana_seleccionada() de los Rankings.
            $rows=$this->query(
                'SELECT fecha FROM dias_inhabiles WHERE fecha>=? AND fecha<=?',
                'ss',[$desde,$hasta]
            );
        } else {
            // Homologa contar_dias_habiles() de los Rankings.
            $rows=$this->query(
                'SELECT fecha FROM dias_inhabiles WHERE activo=1 AND fecha>=? AND fecha<=?',
                'ss',[$desde,$hasta]
            );
        }
        foreach ($rows as $r) {
            $f=substr((string)($r['fecha']??''),0,10);
            if ($f!=='') $festivos[$f]=true;
        }

        $fechas=[];
        for ($d=$ini; $d<=$fin; $d=$d->modify('+1 day')) {
            $iso=(int)$d->format('N');
            if ($iso===7) continue;
            if ($diasIso && !in_array($iso,$diasIso,true)) continue;
            $f=$d->format('Y-m-d');
            if (isset($festivos[$f])) continue;
            $fechas[$f]=true;
        }
        return array_keys($fechas);
    }

    /**
     * Disponibilidad precisa por talento para el período.
     * Identidad del evento en BD: numero_talento_gs + tipo_standby + fecha_inicio.
     * Para cálculo se usa la UNIÓN de fechas afectadas, de modo que solapamientos
     * VACACIONES/INCAPACIDAD o múltiples eventos no descuentan doble.
     */
    public function cargarStandby(
        string $desde,
        string $hasta,
        array $diasIso=[],
        array $canonicosTalento=[]
    ): array {
        $fechasOperativas=$this->fechasOperativasPeriodo($desde,$hasta,$diasIso);
        // Compatibilidad con el comportamiento histórico del Ranking cuando el
        // selector contiene únicamente días no hábiles.
        $diasPeriodo=max(1,count($fechasOperativas));

        $rows=$this->query(
            "SELECT numero_talento_gs,nombre_colaborador,puesto,tipo_standby,
                    fecha_inicio,fecha_fin,responsable_nombre,responsable_rol,
                    sociedad,ceco,area,semana_carga,anio_carga
             FROM hc_standby
             WHERE fecha_inicio<=?
               AND (fecha_fin IS NULL OR fecha_fin>=?)
               AND tipo_standby IN ('VACACIONES','INCAPACIDAD')
             ORDER BY numero_talento_gs,fecha_inicio,tipo_standby",
            'ss',[$hasta,$desde]
        );

        $porTalento=[];
        foreach ($rows as $r) {
            $tal=trim((string)($r['numero_talento_gs']??''));
            if ($tal==='' || self::norm($tal)==='VACANTE') continue;
            $persona=(string)($canonicosTalento[$tal]??$tal);

            $fi=substr((string)($r['fecha_inicio']??''),0,10);
            $ffRaw=trim((string)($r['fecha_fin']??''));
            $ff=$ffRaw!=='' ? substr($ffRaw,0,10) : null;
            if ($fi==='') continue;

            $overIni=max($desde,$fi);
            $overFin=min($hasta,$ff??$hasta);
            if ($overIni>$overFin) continue;

            $tipo=self::norm($r['tipo_standby']??'');
            $evento=[
                'tipo_standby'=>$tipo,
                'fecha_inicio'=>$fi,
                'fecha_fin'=>$ff,
                'overlap_inicio'=>$overIni,
                'overlap_fin'=>$overFin,
                'responsable_nombre'=>(string)($r['responsable_nombre']??''),
                'responsable_rol'=>(string)($r['responsable_rol']??''),
                'nombre_colaborador'=>(string)($r['nombre_colaborador']??''),
                'puesto'=>(string)($r['puesto']??''),
                'semana_carga'=>(int)($r['semana_carga']??0),
                'anio_carga'=>(int)($r['anio_carga']??0),
            ];

            if (!isset($porTalento[$persona])) {
                $porTalento[$persona]=[
                    'eventos'=>[],
                    'fechas_standby'=>[],
                    'fechas_incapacidad'=>[],
                    'fechas_vacaciones'=>[],
                    'dias_standby'=>0,
                    'dias_incapacidad'=>0,
                    'dias_vacaciones'=>0,
                    'dias_disponibles'=>$diasPeriodo,
                    'dias_periodo'=>$diasPeriodo,
                    'pct_incapacidad'=>0.0,
                    'pct_standby'=>0.0,
                    'elegible_hc_sin_ins'=>true,
                    'motivo_exclusion_hc_sin_ins'=>'',
                    'tiene_intervalo'=>false,
                ];
            }
            $porTalento[$persona]['eventos'][]=$evento;
            $porTalento[$persona]['tiene_intervalo']=true;

            foreach ($fechasOperativas as $fechaOperativa) {
                if ($fechaOperativa >= $overIni && $fechaOperativa <= $overFin) {
                    // Unión general: una fecha nunca se descuenta dos veces de Productividad.
                    $porTalento[$persona]['fechas_standby'][$fechaOperativa]=true;

                    // Separación por causa para la regla HC SIN INS.
                    // Un mismo día puede pertenecer a ambos conjuntos por una anomalía/solapamiento;
                    // Productividad lo descuenta una sola vez, mientras INCAPACIDAD conserva su señal.
                    if ($tipo==='INCAPACIDAD') {
                        $porTalento[$persona]['fechas_incapacidad'][$fechaOperativa]=true;
                    } elseif ($tipo==='VACACIONES') {
                        $porTalento[$persona]['fechas_vacaciones'][$fechaOperativa]=true;
                    }
                }
            }
        }

        foreach ($porTalento as &$p) {
            usort($p['eventos'],static fn($a,$b)=>
                strcmp((string)$a['fecha_inicio'],(string)$b['fecha_inicio'])
                ?: strcmp((string)$a['tipo_standby'],(string)$b['tipo_standby'])
            );
            $p['fechas_standby']=array_keys($p['fechas_standby']);
            sort($p['fechas_standby'],SORT_STRING);
            $p['fechas_incapacidad']=array_keys($p['fechas_incapacidad']);
            sort($p['fechas_incapacidad'],SORT_STRING);
            $p['fechas_vacaciones']=array_keys($p['fechas_vacaciones']);
            sort($p['fechas_vacaciones'],SORT_STRING);

            $p['dias_standby']=count($p['fechas_standby']);
            $p['dias_incapacidad']=count($p['fechas_incapacidad']);
            $p['dias_vacaciones']=count($p['fechas_vacaciones']);
            $p['dias_disponibles']=max(0,$diasPeriodo-$p['dias_standby']);

            $regla=self::evaluarElegibilidadHcSinIns(
                $diasPeriodo,
                $p['dias_standby'],
                $p['dias_disponibles']
            );
            $p['pct_incapacidad']=$diasPeriodo>0 ? ((float)$p['dias_incapacidad']/$diasPeriodo)*100.0 : 0.0;
            $p['pct_standby']=(float)$regla['pct_standby'];
            $p['elegible_hc_sin_ins']=(bool)$regla['elegible'];
            $p['motivo_exclusion_hc_sin_ins']=(string)$regla['motivo'];
        }
        unset($p);

        return [
            'desde'=>$desde,
            'hasta'=>$hasta,
            'dias_periodo'=>$diasPeriodo,
            'fechas_operativas'=>$fechasOperativas,
            'por_talento'=>$porTalento,
            'eventos_fuente'=>count($rows),
            'umbral_standby_hc_sin_ins'=>self::UMBRAL_STANDBY_HC_SIN_INS,
        ];
    }

    /** Última fotografía <= semana de la FECHA del evento; nunca futura. */
    private function fotografia(string $fecha): ?array
    {
        [$a,$s] = self::isoWeek($fecha);
        $r = $this->query('SELECT anio, semana FROM hc WHERE anio < ? OR (anio = ? AND semana <= ?) GROUP BY anio, semana ORDER BY anio DESC, semana DESC LIMIT 1','iii',[$a,$a,$s]);
        return $r ? [(int)$r[0]['anio'], (int)$r[0]['semana']] : null;
    }

    /** Todos los orígenes: HC/HIC certifican la jerarquía sin inferencias por canal. */
    public function cargarEventos(string $desde, string $hasta, array $diasIso=[]): array
    {
        $ini = DateTimeImmutable::createFromFormat('!Y-m-d',$desde);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if (!$ini || !$fin || $ini->format('Y-m-d')!==$desde || $fin->format('Y-m-d')!==$hasta || $ini>$fin) {
            throw new InvalidArgumentException('Rango de fechas inválido');
        }
        foreach ($diasIso as $d) if (!is_int($d) || $d<1 || $d>7) throw new InvalidArgumentException('Día ISO inválido');
        $sql="SELECT i.cuenta,i.fecha,i.folio_empleado,i.lider,i.coach,i.origen_prospecto,i.subcanal
              FROM instalaciones i
              WHERE i.fecha>=? AND i.fecha<?
                AND i.cuenta IS NOT NULL AND TRIM(i.cuenta)<>''";
        $hastaExcl=$fin->modify('+1 day')->format('Y-m-d');
        $rows=$this->query($sql,'ss',[$desde,$hastaExcl]);
        $out=[];
        foreach ($rows as $r) {
            if ($diasIso && !in_array((int)(new DateTimeImmutable(substr($r['fecha'],0,10)))->format('N'),$diasIso,true)) continue;
            $r['lider_id']=null; // El texto del evento es diagnóstico, no autoridad.
            $out[]=$r;
        }
        return $out;
    }

    /** HC resuelto por fotografía histórica. No enlaza colaboradores por plaza. */
    public function cargarHc(string $desde, string $hasta): array
    {
        $photos=[];
        // Recorre SEMANAS ISO intersectadas, no saltos de 7 dias desde el dia inicial.
        // Ej. 2026-10-01..07 incluye S40 y S41 (antes omitía S41).
        $day=(new DateTimeImmutable($desde))->modify('monday this week');
        $end=new DateTimeImmutable($hasta);
        while ($day <= $end) {
            [$a,$s]=self::isoWeek($day->format('Y-m-d'));
            $k="$a-$s";
            if (!array_key_exists($k,$photos)) $photos[$k]=$this->fotografia($day->format('Y-m-d'));
            $day=$day->modify('+7 days');
        }
        $unique=[];
        foreach ($photos as $photo) if ($photo) $unique[implode('-',$photo)]=$photo;
        $hc=[];
        foreach ($unique as [$a,$s]) {
            $rows=$this->query("SELECT anio,semana,distrito,numero_talento_gs,nombre_colaborador,id_posicion,posicion_lr,nombre_linea_reporte,puesto_lr,status,fecha_alta FROM hc WHERE anio=? AND semana=?",'ii',[$a,$s]);
            $hc["$a-$s"]=$rows;
        }
        return ['fotos'=>$photos,'datos'=>$hc];
    }

    /**
     * Resuelve sin escribir DB; devuelve una fila por cuenta dentro del alcance.
     * $equivalenciasTalento: solo relaciones HIC previamente validadas de la MISMA persona.
     * Si hay conflicto real, nunca lo resuelve alfabéticamente.
     */
    public function resolverFilas(array $eventos, array $hc, array $equivalenciasTalento=[], array $canonicosTalento=[], array $estadosHic=[]): array
    {
        // Índices POR fotografía, no por fotografía actual global: jamás usar HC futuro.
        $indices=[];
        foreach ($hc['datos'] as $foto=>$rows) {
            $byFolio=[]; $coachSeats=[]; $coachDuplicado=[];
            $plazas=self::plazasLideres($rows);
            // Indice EXCLUSIVAMENTE de ocupantes de plazas de lider certificadas
            // en la fotografia. Permite resolver autorreferencias de coach sin
            // catalogos de personas fijos y sin consultar nombres del evento.
            // Una misma firma de nombre puede corresponder a varias plazas:
            // nunca elegir una de forma arbitraria.
            $plazasPorNombre=[];
            foreach ($plazas as $liderId=>$liderInfo) {
                $nombreLider=(string)($liderInfo['nombre']??'');
                $distritoLider=(string)($liderInfo['distrito']??'');
                if (!self::isValidName($nombreLider) || $distritoLider==='') continue;
                $plazasPorNombre[$distritoLider][self::nameKey($nombreLider)][$liderId]=true;
            }
            foreach ($rows as $row) {
                $district=self::normalizarDistrito($row['distrito']??null);
                if (!$district) continue;
                $pos=trim((string)($row['id_posicion']??''));
                $tal=trim((string)($row['numero_talento_gs']??''));
                if ($pos!=='' && $tal!=='' && $tal!=='VACANTE' && self::norm($row['nombre_colaborador']??'')!=='VACANTE') {
                    $person=($estadosHic[$tal]??'')==='HIC_AMBIGUA' ? 'AMBIGUA|'.$tal : ($canonicosTalento[$tal]??$tal);
                    // Identidad en más de una posición permanece como varios candidatos.
                    $byFolio[$person][$district.'|'.$pos]=$row;
                }
            }
            foreach ($rows as $coach) {
                if (stripos((string)($coach['puesto_lr']??''),'LIDER')===false) continue;
                $district=self::normalizarDistrito($coach['distrito']??null);
                if (!$district) continue;
                $coachPos=trim((string)($coach['id_posicion']??''));
                $superPos=trim((string)($coach['posicion_lr']??''));
                if ($coachPos==='' || $superPos==='') continue;
                if ($superPos===$coachPos) {
                    // Anomalia HC: posicion_lr apunta a la MISMA plaza del coach.
                    // Fallback controlado: nombre del SUPERIOR contenido en HC,
                    // no el nombre del coach ni el texto de instalaciones.
                    // Unicamente si existe un ocupante de lider univoco y
                    // validado por posicion en la misma foto y distrito.
                    $superiorNombre=(string)($coach['nombre_linea_reporte']??'');
                    if (!self::isValidName($superiorNombre)) continue;
                    $posibles=array_keys($plazasPorNombre[$district][self::nameKey($superiorNombre)]??[]);
                    if (count($posibles)!==1) continue;
                    $lid=(string)$posibles[0];
                    if (!isset($plazas[$lid]) || (string)$plazas[$lid]['plaza']===$coachPos) continue;
                } else {
                    // Regla ordinaria: la posicion superior determina el lider.
                    // Un nombre solo NUNCA puede invalidar/cambiar este vinculo.
                    $lid=$district.'|'.$superPos;
                    if (!isset($plazas[$lid])) continue;
                }
                // Dos filas diferentes para la misma posición de coach => no asignar.
                if (array_key_exists($coachPos,$coachSeats[$district]??[])) {
                    $coachDuplicado[$district][$coachPos]=true;
                    $coachSeats[$district][$coachPos]=null;
                    continue;
                }
                if (isset($coachDuplicado[$district][$coachPos])) continue;
                $coachSeats[$district][$coachPos]=[
                    'lider_id'=>$lid,'coach_id'=>$coachPos,
                    'coach_nombre'=>self::norm($coach['nombre_colaborador']??''),
                ];
            }
            $indices[$foto]=['personas'=>$byFolio,'coaches'=>$coachSeats];
        }
        $groups=[];
        foreach ($eventos as $event) {
            $cuenta=trim((string)($event['cuenta']??''));
            if ($cuenta!=='') $groups[$cuenta][]=$event;
        }
        $out=[];
        foreach ($groups as $cuenta=>$evs) {
            $fechasSemanas=[];
            foreach ($evs as $ev) {
                $ef=substr((string)($ev['fecha']??''),0,10);
                $fechasSemanas[implode('-',self::isoWeek($ef))]=true;
            }
            $fecha=substr((string)$evs[0]['fecha'],0,10);
            $fechaAmbigua=count($fechasSemanas)>1;
            $foto=$hc['fotos'][implode('-',self::isoWeek($fecha))]??null;
            $fotoKey=$foto?implode('-',$foto):null;
            $folios=[]; $eventLeaders=[]; $eventCoaches=[];
            foreach ($evs as $e) {
                if (($e['lider_id']??null)!==null) $eventLeaders[(string)$e['lider_id']]=true;
                if (self::isValidName($e['coach']??null)) $eventCoaches[self::nameKey((string)$e['coach'])]=self::norm($e['coach']);
                $f=trim((string)($e['folio_empleado']??''));
                // Un marcador de folio (p. ej. Call Center sin folio) no es otro vendedor.
                // Solo los folios reales participan en la identidad HC/HIC. Dos
                // folios reales distintos siguen siendo ambiguos y no se certifican.
                if (!in_array(self::norm($f), ['', '-', 'VACANTE'], true)) $folios[$f]=true;
            }
            $personas=[]; $hicAmbiguo=false;
            foreach (array_keys($folios) as $f) {
                if (($estadosHic[$f]??'')==='HIC_AMBIGUA') $hicAmbiguo=true;
                $personas[(($estadosHic[$f]??'')==='HIC_AMBIGUA')?'AMBIGUA|'.$f:($canonicosTalento[$f]??$f)]=true;
            }
            $seller=count($personas)===1?(string)array_key_first($personas):null;
            $original=count($folios)===1?(string)array_key_first($folios):null;
            $liderOriginal=count($eventLeaders)===1?(string)array_key_first($eventLeaders):null;
            $coachOriginal=count($eventCoaches)===1?(string)array_key_first($eventCoaches):null;
            $candidatos=[];
            if ($seller!==null && !$hicAmbiguo && !$fechaAmbigua && $fotoKey!==null) {
                foreach ($indices[$fotoKey]['personas'][$seller]??[] as $h) {
                    // CRITICO: las plazas/los coaches se indexaron por distrito CANONICO.
                    // La fila HC puede decir COATZA MINA / COATZA-MINA / COATZA/MINA.
                    // Consultar con el texto crudo provocaba perder TODA la atribucion
                    // de Coatza-Mina pese a existir vendedor, coach y lider en HC.
                    $district=self::normalizarDistrito($h['distrito']??null);
                    if ($district===null) continue;
                    $coachPos=trim((string)($h['posicion_lr']??''));
                    $coach=$indices[$fotoKey]['coaches'][$district][$coachPos]??null;
                    if (!$coach) continue;
                    $key=$coach['lider_id'].'|'.$coachPos;
                    $candidatos[$key]=$coach;
                }
            }
            $estado='OK'; $fuente='HC_FOTO'; $lid=null; $coachId=null; $coachNombre=null;
            if (count($candidatos)===1) {
                $c=array_values($candidatos)[0];
                $lid=$c['lider_id']; $coachId=$c['coach_id']; $coachNombre=$c['coach_nombre'];
                // Evento líder es informativo: una posición nunca se invalida por nombre.
                if ($coachOriginal!==null && self::nameKey($coachNombre)!==$coachOriginal) $estado='CAMBIO_ESTRUCTURA_COACH';
            } else {
                // HC no certificado: la cuenta permanece en AUDITORIA, jamás bajo
                // un líder comercial inferido ni como venta certificada.
                $lid=null;
                $coachNombre=null;
                $coachId=null;
                $fuente='PENDIENTE_HC';
                $estado=$fechaAmbigua?'CUENTA_SEMANAS_MULTIPLES':($hicAmbiguo?'HIC_AMBIGUA':($seller===null?'SIN_FOLIO_UNICO':($fotoKey===null?'SIN_FOTO_HC':(count($candidatos)>1?'HC_JERARQUIA_AMBIGUA':'SIN_ASOCIACION_HC'))));
            }
            // No sobrescribir el motivo específico de ausencia de HC.
            $out[]=[
                'cuenta'=>(string)$cuenta,'fecha'=>$fecha,
                'origen_prospecto'=>$evs[0]['origen_prospecto']??'',
                'subcanal'=>$evs[0]['subcanal']??'',
                'lider_evento_original'=>$evs[0]['lider']??'',
                'coach_evento_texto'=>$evs[0]['coach']??'',
                'lider_id'=>$lid,'lider_posicion'=>($lid!==null?substr($lid,strpos($lid,'|')+1):null),'coach_id'=>$coachId,'vendedor_id'=>$seller,
                'vendedor_folio_original'=>$original,
                'identidad_hic'=>$hicAmbiguo?'HIC_AMBIGUA':($seller!==null && $seller!==$original?'HIC_VERIFICABLE':'DIRECTA_O_SIN_HIC'),
                'coach_evento'=>$coachNombre,'lider_evento_id'=>$liderOriginal,
                'coach_evento_original'=>$coachOriginal,'fuente'=>$fuente,
                'estado'=>$estado,'hc_foto'=>$fotoKey,'filas_origen'=>count($evs)
            ];
        }
        return $out;
    }

    /**
     * Punto de entrada del motor. Carga una vez, resuelve una vez y luego filtra.
     * $equivalenciasTalento debe contener únicamente identidades HIC certificadas
     * por misma persona; no se infiere identidad por plaza compartida.
     */
    public function obtenerInstalacionesJerarquia(
        string $desde,
        string $hasta,
        ?string $idLider = null,
        ?string $idCoach = null,
        ?string $idVendedor = null,
        array $diasIso = [],
        array $equivalenciasTalento = []
    ): array {
        $eventos=$this->cargarEventos($desde,$hasta,$diasIso);
        $hc=$this->cargarHc($desde,$hasta);
        // v0.6.5: HIC se consulta siempre. Aunque el período tenga 0 instalaciones,
        // hc_standby debe homologarse contra la misma identidad canónica del HC.
        $hic=self::resolverEquivalenciasHic($this->cargarHic());
        $map=$hic['equivalencias'];
        foreach ($equivalenciasTalento as $folio=>$aliases) {
            if (isset($hic['estados'][(string)$folio]) && $hic['estados'][(string)$folio]==='HIC_AMBIGUA') continue;
            $map[(string)$folio]=array_values(array_unique(array_merge($map[(string)$folio]??[],(array)$aliases)));
        }
        $standby=$this->cargarStandby($desde,$hasta,$diasIso,$hic['canonicos']);
        $filas=$this->resolverFilas($eventos,$hc,$map,$hic['canonicos'],$hic['estados']);
        // Los candidatos pueden ser de otra región o no existir en HC.
        // Únicamente las cuentas HC con jerarquía ÚNICA suman en Ranking.
        $certificadas=array_values(array_filter($filas,static fn($r)=>
            ($r['fuente']??'')==='HC_FOTO' && ($r['lider_id']??null)!==null
            && ($r['coach_id']??null)!==null
        ));
        $pendientes=array_values(array_filter($filas,static fn($r)=>
            ($r['fuente']??'')!=='HC_FOTO'
        ));
        $seleccion=self::filtrar($certificadas,$idLider,$idCoach,$idVendedor);
        if (count($certificadas)+count($pendientes)!==count($filas)) {
            throw new LogicException('Conciliación v0.6 incongruente');
        }
        return [
            'version'=>'0.6.6-hc-standby40-all-channels',
            'fuente_identidad'=>'historial_identidad_colaborador',
            'cuentas'=>$seleccion,
            'conciliacion'=>self::conciliar($seleccion),
            'conciliacion_global'=>self::conciliar($filas),
            'conciliacion_comercial'=>[
                'total_todos_origenes'=>count($filas),
                'certificadas_region_sur'=>count($certificadas),
                'pendientes_hc_o_fuera_scope'=>count($pendientes),
                'verificada'=>count($certificadas)+count($pendientes)===count($filas),
                'motivos_pendientes'=>self::conciliar($pendientes)['estados_no_ok'],
            ],
            'auditoria_pendientes'=>$pendientes,
            'fotografias_hc'=>$hc['fotos'],
            'standby'=>$standby,
        ];
    }

    /** La consulta por jerarquía filtra el MISMO universo; null = nivel no filtrado. */
    public static function filtrar(array $cuentas, ?string $idLider=null, ?string $idCoach=null, ?string $idVendedor=null): array
    {
        return array_values(array_filter($cuentas, static fn($r) =>
            ($idLider===null || $r['lider_id']===$idLider)
            && ($idCoach===null || $r['coach_id']===$idCoach)
            && ($idVendedor===null || $r['vendedor_id']===$idVendedor)
        ));
    }

    public static function conciliar(array $filas): array
    {
        $total=count($filas);$porLider=[];$conflictos=[];
        foreach ($filas as $r) {
            $lid=$r['lider_id']??'SIN_LIDER';
            $coach=$r['coach_id']??'SIN_COACH';
            $vend=$r['vendedor_id']??'SIN_VENDEDOR';
            $porLider[$lid]['total']=($porLider[$lid]['total']??0)+1;
            $porLider[$lid]['coaches'][$coach]=($porLider[$lid]['coaches'][$coach]??0)+1;
            $porLider[$lid]['vendedores'][$vend]=($porLider[$lid]['vendedores'][$vend]??0)+1;
            if (($r['estado']??'OK')!=='OK') $conflictos[$r['estado']]=($conflictos[$r['estado']]??0)+1;
        }
        foreach ($porLider as &$l) {
            $l['coincide_coaches']=array_sum($l['coaches'])===$l['total'];
            $l['coincide_vendedores']=array_sum($l['vendedores'])===$l['total'];
        }
        unset($l);
        return ['total_cuentas'=>$total,'lideres'=>$porLider,'estados_no_ok'=>$conflictos];
    }
}
