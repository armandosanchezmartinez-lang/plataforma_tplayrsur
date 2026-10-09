<?php
declare(strict_types=1);
/**
 * TalIA Ranking — motor canónico v0.5 (HC autoridad jerárquica / SOLO LECTURA).
 * Basado en STAGING v1.1: eventos por cuenta; HC fotografía <= semana;
 * HC determina líder y coach por folio y posición; evento solo diagnóstico/fallback explícito.
 * NO sustituye ni modifica ranking_productividad.php.
 */
final class RankingAtribucionService
{
    private mysqli $db;
    private array $lideres;

    public function __construct(mysqli $db, ?array $lideres = null)
    {
        $this->db = $db;
        $this->lideres = $lideres ?? self::lideresStaging();
        $aliases = [];
        foreach ($this->lideres as $id => $v) {
            foreach ($v['aliases'] as $alias) {
                $key = self::norm($alias);
                if (isset($aliases[$key]) && $aliases[$key] !== $id) {
                    throw new LogicException('Alias de líder ambiguo: '.$alias);
                }
                $aliases[$key] = $id;
            }
        }
    }

    /** Identidades de alcance tomadas del catálogo vigente de STAGING v1.1.
     *  Las claves son de REPORTE (NO identificadores permanentes de persona).
     *  Deben migrar a un catálogo de identidad/plaza validado cuando exista.
     */
    public static function lideresStaging(): array
    {
        return [
            'CANCUN|COTO FELIX ERICK DANIEL'=>['distrito'=>'CANCUN','hc'=>'CANCUN','nombre'=>'COTO FELIX ERICK DANIEL','aliases'=>['COTO FELIX ERICK DANIEL'],'plaza'=>null],
            'CANCUN|GAMBOA LARA LUIS ANTONIO'=>['distrito'=>'CANCUN','hc'=>'CANCUN','nombre'=>'GAMBOA LARA LUIS ANTONIO','aliases'=>['GAMBOA LARA LUIS ANTONIO'],'plaza'=>null],
            'COATZA-MINA|HECTOR ANDRES PALMA HERNANDEZ'=>['distrito'=>'COATZA-MINA','hc'=>'COATZA MINA','nombre'=>'HECTOR ANDRES PALMA HERNANDEZ','aliases'=>['HECTOR ANDRES PALMA HERNANDEZ'],'plaza'=>null],
            'MERIDA|ESTRADA MEDINA MERCY GUADALUPE'=>['distrito'=>'MERIDA','hc'=>'MERIDA','nombre'=>'ESTRADA MEDINA MERCY GUADALUPE','aliases'=>['JOVANY DAMIAN PARAMO AVILA','MERCY GUADALUPE ESTRADA MEDINA'],'plaza'=>'1739397'],
            'MERIDA|PAREDES ROCHEL MARIA JOSE'=>['distrito'=>'MERIDA','hc'=>'MERIDA','nombre'=>'PAREDES ROCHEL MARIA JOSE','aliases'=>['PAREDES ROCHEL MARIA JOSE'],'plaza'=>null],
            'TUXTLA|LOPEZ MANCILLA JOSE ALBERTO'=>['distrito'=>'TUXTLA','hc'=>'TUXTLA','nombre'=>'LOPEZ MANCILLA JOSE ALBERTO','aliases'=>['JOSE ALBERTO LOPEZ MANCILLA'],'plaza'=>null],
            'TUXTLA|SANCHEZ SANCHEZ CHRISTIANNE MIGUEL'=>['distrito'=>'TUXTLA','hc'=>'TUXTLA','nombre'=>'SANCHEZ SANCHEZ CHRISTIANNE MIGUEL','aliases'=>['CHRISTIANNE MIGUEL SANCHEZ SANCHEZ'],'plaza'=>null],
            'VILLAHERMOSA|HERNANDEZ PALMA MIRIAN GABRIELA'=>['distrito'=>'VILLAHERMOSA','hc'=>'VILLAHERMOSA','nombre'=>'HERNANDEZ PALMA MIRIAN GABRIELA','aliases'=>['MIRIAN GABRIELA HERNANDEZ PALMA'],'plaza'=>null],
        ];
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
        $map=[];
        foreach ($this->lideres as $id=>$l) foreach ($l['aliases'] as $a) $map[self::norm($a)]=$id;
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
            $r['lider_id']=$map[self::norm($r['lider'])] ?? null;
            $out[]=$r;
        }
        return $out;
    }

    /** HC resuelto por fotografía histórica. No enlaza colaboradores por plaza. */
    public function cargarHc(string $desde, string $hasta): array
    {
        $photos=[];
        $day=new DateTimeImmutable($desde);
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
            $positions=[]; $byFolio=[]; $coachSeats=[]; $leaderCandidates=[];
            foreach ($this->lideres as $lid=>$def) {
                foreach ($def['aliases'] as $alias) $leaderCandidates[$def['hc']][self::nameKey($alias)][$lid]=true;
                $leaderCandidates[$def['hc']][self::nameKey($def['nombre'])][$lid]=true;
            }
            foreach ($rows as $row) {
                $district=(string)($row['distrito']??'');
                $pos=trim((string)($row['id_posicion']??''));
                if ($pos!=='') $positions[$district][$pos][]=$row;
                $tal=trim((string)($row['numero_talento_gs']??''));
                if ($tal!=='' && $tal!=='VACANTE' && self::norm($row['nombre_colaborador']??'')!=='VACANTE') {
                    $person=($estadosHic[$tal]??'')==='HIC_AMBIGUA' ? 'AMBIGUA|'.$tal : ($canonicosTalento[$tal]??$tal);
                    $byFolio[$person][$district.'|'.$pos]=$row;
                }
            }
            foreach ($rows as $coach) {
                if (stripos((string)($coach['puesto_lr']??''),'LIDER')===false) continue;
                $district=(string)($coach['distrito']??'');
                $coachPos=trim((string)($coach['id_posicion']??''));
                if ($coachPos==='') continue;
                $possible=[];
                $superPos=trim((string)($coach['posicion_lr']??''));
                foreach ($this->lideres as $lid=>$def) {
                    if ($district!==$def['hc']) continue;
                    if ($def['plaza']!==null && $superPos===(string)$def['plaza']) $possible[$lid]=true;
                }
                $nameKey=self::nameKey((string)($coach['nombre_linea_reporte']??''));
                foreach ($leaderCandidates[$district][$nameKey]??[] as $lid=>$_) $possible[$lid]=true;
                // La identificación de líder exige correspondencia única; no adivinar por posición compartida.
                if (count($possible)===1) {
                    $lid=(string)array_key_first($possible);
                    $coachSeats[$district][$coachPos]=['lider_id'=>$lid,'coach_id'=>$coachPos,'coach_nombre'=>self::norm($coach['nombre_colaborador']??'')];
                }
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
            $fecha=substr((string)$evs[0]['fecha'],0,10);
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
            if ($seller!==null && !$hicAmbiguo && $fotoKey!==null) {
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
                if ($liderOriginal!==null && $liderOriginal!==$lid) $estado='CAMBIO_ESTRUCTURA_LIDER';
                // Diferencia de nombre se conserva como señal, no invalida relación por posición.
                elseif ($coachOriginal!==null && self::nameKey($coachNombre)!==$coachOriginal) $estado='CAMBIO_ESTRUCTURA_COACH';
            } else {
                // HC no certificado: la cuenta permanece en AUDITORIA, jamás bajo
                // un líder comercial inferido ni como venta certificada.
                $lid=null;
                $coachNombre=null;
                $coachId=null;
                $fuente='PENDIENTE_HC';
                $estado=$hicAmbiguo?'HIC_AMBIGUA':($seller===null?'SIN_FOLIO_UNICO':($fotoKey===null?'SIN_FOTO_HC':(count($candidatos)>1?'HC_JERARQUIA_AMBIGUA':'SIN_ASOCIACION_HC')));
            }
            // No sobrescribir el motivo específico de ausencia de HC.
            $out[]=[
                'cuenta'=>(string)$cuenta,'fecha'=>$fecha,
                'origen_prospecto'=>$evs[0]['origen_prospecto']??'',
                'subcanal'=>$evs[0]['subcanal']??'',
                'lider_evento_original'=>$evs[0]['lider']??'',
                'coach_evento_texto'=>$evs[0]['coach']??'',
                'lider_id'=>$lid,'coach_id'=>$coachId,'vendedor_id'=>$seller,
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
            throw new LogicException('Conciliacion v0.5 incongruente');
        }
        return [
            'version'=>'0.5.0-hc-certified',
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
