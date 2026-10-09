<?php
declare(strict_types=1);
/**
 * TALIA / MOTOR HC v0.6.0 - STAGING (NO PRODUCTIVO)
 * =====================================================
 * OBJETIVO: asociar una instalacion unica (cuenta) al vendedor, coach y
 * posicion de lider que acredita Capital Humano (HC). No modifica la BD.
 *
 * ENTRADA / CONSTRUCTOR:
 *   new RankingAtribucionServiceV06(mysqli $conexion)
 * ENTRADA / METODO PRINCIPAL:
 *   obtenerInstalacionesJerarquia(string $desde, string $hasta,
 *       ?string $idLider=null, ?string $idCoach=null,
 *       ?string $idVendedor=null, array $diasIso=[],
 *       array $equivalenciasTalento=[]): array
 *   - Fechas inclusivas YYYY-MM-DD; desde <= hasta.
 *   - idLider: identificador canonico DISTRITO|ID_POSICION; null = todos.
 *   - idCoach: id_posicion del coach; null = todos.
 *   - idVendedor: talento normalizado HIC; null = todos.
 *   - diasIso: 1=lunes ... 7=domingo; vacio = todos.
 *   - equivalenciasTalento: argumento compatible; sus relaciones adicionales
 *     no alteran la resolucion canonica en esta implementacion.
 *
 * FUENTES Y COLUMNAS:
 *   instalaciones: cuenta, fecha, folio_empleado, lider, coach,
 *       origen_prospecto y subcanal.
 *   hc: anio, semana, distrito, numero_talento_gs, nombre_colaborador,
 *       id_posicion, posicion_lr, nombre_linea_reporte, puesto_lr.
 *   historial_identidad_colaborador: numero_talento_anterior/nuevo.
 *
 * PROCESO:
 *   1) Lee instalaciones Cambaceo/PDV en el periodo y dias seleccionados.
 *   2) Agrupa por cuenta para evitar duplicacion de instalaciones.
 *   3) Obtiene HC de cada semana ISO; si falta, usa ultima foto ANTERIOR.
 *   4) Resuelve identidad de PERSONA por talento/HIC; no por posicion.
 *   5) Descubre plazas de lider y relacion coach/vendedor por posiciones HC.
 *   6) Certifica solo cadenas HC unicas; conserva casos ambiguos pendientes.
 *   7) Filtra la salida certificada por lider/coach/vendedor solicitados.
 *
 * SALIDA / ARRAY:
 *   version: 0.6.0-hc-position-staging.
 *   cuentas: filas certificadas (una por cuenta), incluyendo cuenta, fecha,
 *       lider_id, lider_posicion, coach_id, vendedor_id, fuente, estado, hc_foto.
 *   conciliacion: sumatorias de las cuentas filtradas.
 *   conciliacion_global: sumatorias de todas las cuentas procesadas.
 *   conciliacion_comercial: total Cambaceo/PDV, certificadas SUR, pendientes,
 *       motivos y bandera verificada.
 *   auditoria_pendientes: filas sin jerarquia HC certificada.
 *   fotografias_hc: mapa de semana consultada a foto real utilizada.
 *
 * ALCANCE: Region SUR (CANCUN, COATZA-MINA, MERIDA, TUXTLA,
 * VILLAHERMOSA); reconoce variantes ortograficas de distrito.
 * Las posiciones identifican estructura; la foto HC identifica ocupante.
 * La fuente oficial es HC, incluso cuando la operacion comunica cambios antes
 * de que Capital Humano los refleje en sus archivos.
 *
 * LIMITES: semana HC no expresa movimientos de mitad de semana; los nombres
 * de las personas NO se fijan en PHP; los casos no univocos no se inventan.
 * El catalogo dinamico se limita a plazas observables en HC y a reglas
 * de subordinacion; cambios organizacionales reales pueden variar resultados
 * respecto a v0.5.1 y deben contrastarse antes de certificarse.
 * NO comprende 2P/3P, Negocios, Bundle, ARPU ni calculos de productividad.
 *
 * INTEGRACION: /plataforma/includes/motor_hc_STAGING.php; es exclusivo
 * de pruebas y NO reemplaza /plataforma/includes/motor_hc.php.
 */
final class RankingAtribucionServiceV06
{
    private mysqli $db;
    private const DISTRITOS = ['CANCUN', 'COATZA-MINA', 'MERIDA', 'TUXTLA', 'VILLAHERMOSA'];

    public function __construct(mysqli $db)
    {
        $this->db=$db;
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

    /** Última fotografía <= semana de la FECHA del evento; nunca futura. */
    private function fotografia(string $fecha): ?array
    {
        [$a,$s] = self::isoWeek($fecha);
        $r = $this->query('SELECT anio, semana FROM hc WHERE anio < ? OR (anio = ? AND semana <= ?) GROUP BY anio, semana ORDER BY anio DESC, semana DESC LIMIT 1','iii',[$a,$a,$s]);
        return $r ? [(int)$r[0]['anio'], (int)$r[0]['semana']] : null;
    }

    /** Carga SOLO Cambaceo/PDV sin filtro previo de líder; el HC certifica el alcance. */
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
                AND UPPER(TRIM(i.origen_prospecto)) IN ('CAMBACEO','PUNTO DE VENTA','PDV')
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
            $rows=$this->query("SELECT anio,semana,distrito,numero_talento_gs,nombre_colaborador,id_posicion,posicion_lr,nombre_linea_reporte,puesto_lr FROM hc WHERE anio=? AND semana=?",'ii',[$a,$s]);
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
                $lid=$district.'|'.$superPos;
                if (!isset($plazas[$lid])) continue;
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
                if ($f!=='' && $f!=='VACANTE') $folios[$f]=true;
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
                    $district=(string)$h['distrito'];
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
        // Sólo consultar HIC si hay eventos; ante error se interrumpe, sin fabricar ceros.
        $hic=$eventos ? self::resolverEquivalenciasHic($this->cargarHic()) : ['equivalencias'=>[],'canonicos'=>[],'estados'=>[]];
        $map=$hic['equivalencias'];
        foreach ($equivalenciasTalento as $folio=>$aliases) {
            if (isset($hic['estados'][(string)$folio]) && $hic['estados'][(string)$folio]==='HIC_AMBIGUA') continue;
            $map[(string)$folio]=array_values(array_unique(array_merge($map[(string)$folio]??[],(array)$aliases)));
        }
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
            'version'=>'0.6.0-hc-position-staging',
            'fuente_identidad'=>'historial_identidad_colaborador',
            'cuentas'=>$seleccion,
            'conciliacion'=>self::conciliar($seleccion),
            'conciliacion_global'=>self::conciliar($filas),
            'conciliacion_comercial'=>[
                'total_cambaceo_pdv'=>count($filas),
                'certificadas_region_sur'=>count($certificadas),
                'pendientes_hc_o_fuera_scope'=>count($pendientes),
                'verificada'=>count($certificadas)+count($pendientes)===count($filas),
                'motivos_pendientes'=>self::conciliar($pendientes)['estados_no_ok'],
            ],
            'auditoria_pendientes'=>$pendientes,
            'fotografias_hc'=>$hc['fotos'],
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
