<?php
declare(strict_types=1);
/**
 * TOTALXPEDIENT - DIAGNOSTICO READ-ONLY S41 / CANCUN / IRRA Y RAFAEL
 * Fecha: 12-OCT-2026. Eliminar este archivo despues del diagnostico.
 * Ubicacion temporal: /plataforma/detalle/diagnostico_s41_cancun.php
 * NO hace INSERT/UPDATE/DELETE ni modifica motores ni rankings.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
session_start();
if (empty($_SESSION['usuario']) || !in_array(strtolower(trim((string)($_SESSION['rol'] ?? ''))), ['admin', 'director_regional'], true)) {
    http_response_code(403);
    exit('Acceso restringido a Administrador / Director Regional.');
}
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/../includes/motor_hc.php';
if (!isset($conexion) || !$conexion instanceof mysqli) {
    http_response_code(500);
    exit('Conexion TalIA no disponible.');
}

function ds_h($x): string { return htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ds_norm($x): string {
    $s=RankingAtribucionServiceV06::norm((string)$x);
    $s=strtr($s,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}
function ds_coach($s): ?string {
    $n=ds_norm($s);
    if (preg_match('/\bIRRA\b/u', $n) && preg_match('/\bVAZQUEZ\b/u',$n)) return 'IRRA';
    if (preg_match('/\bRAFAEL\b/u',$n) && preg_match('/\bCARMONA\b/u',$n)) return 'RAFAEL';
    return null;
}
function ds_in_scope(array $r): bool {
    // Misma condicion que usa motor_hc.php/cargarEventos().
    return in_array(strtoupper(trim((string)($r['origen_prospecto']??''))), ['CAMBACEO','PUNTO DE VENTA','PDV'], true);
}
function ds_mismo_coach(array $r, string $k, array $coachesPos): bool {
    $lid=(string)($r['lider_id']??'');
    $co=(string)($r['coach_id']??'');
    return str_starts_with($lid,'CANCUN|') && $co!=='' && isset($coachesPos[$k][$co]);
}

$inicio='2026-10-05'; $fin='2026-10-11'; $finExcl='2026-10-12';
$objetivos=[
    'IRRA'=>['nombre'=>'IRRA VAZQUEZ ALBERTO','sf'=>32,'tx_captura'=>28,
             'sf_dias'=>[1,7,2,5,4,6,7]],
    'RAFAEL'=>['nombre'=>'CARMONA MARTINEZ RAFAEL','sf'=>30,'tx_captura'=>26,
             'sf_dias'=>[7,4,6,7,3,1,2]],
];
$datos=[]; $grupos=['IRRA'=>[],'RAFAEL'=>[]]; $stats=[]; $coachesPos=['IRRA'=>[],'RAFAEL'=>[]];
$bdDiaFilas=[]; $bdDiaCuentas=[]; $motorDia=[];
$error=''; $fotoUsada='—'; $versionMotor='—';
try {
    // Escaneo semanal restringido por fecha + nombre; incluye origenes fuera del filtro HC.
    $sql="SELECT i.cuenta,i.fecha,i.folio_empleado,i.lider,i.coach,i.origen_prospecto,i.subcanal
          FROM instalaciones i
          WHERE i.fecha>=? AND i.fecha<?
            AND (UPPER(COALESCE(i.coach,'')) LIKE '%IRRA%'
              OR UPPER(COALESCE(i.coach,'')) LIKE '%CARMONA%')
          ORDER BY i.fecha,i.cuenta";
    $st=$conexion->prepare($sql);
    $st->bind_param('ss',$inicio,$finExcl);
    $st->execute();
    $rs=$st->get_result();
    $i=0;
    while ($r=$rs->fetch_assoc()) {
        $k=ds_coach($r['coach']??'');
        if ($k===null) continue;
        $i++;
        $cuenta=trim((string)($r['cuenta']??''));
        $index=$cuenta!==''?$cuenta:'__VACIA__'.$i;
        $grupos[$k][$index][]=$r;
        $fechaDia=substr((string)($r['fecha']??''),0,10);
        $bdDiaFilas[$k][$fechaDia]=($bdDiaFilas[$k][$fechaDia]??0)+1;
        if ($cuenta!=='') $bdDiaCuentas[$k][$fechaDia][$cuenta]=true;
    }
    $st->close();

    // El MISMO motor que utiliza ranking_productividad.php; NO replica su logica.
    $motor=new RankingAtribucionServiceV06($conexion);
    $res=$motor->obtenerInstalacionesJerarquia($inicio,$fin,null,null,null,[1,2,3,4,5,6,7]);
    $versionMotor=(string)($res['version']??'SIN VERSION');
    $cert=[];$pend=[];
    foreach (($res['cuentas']??[]) as $x) $cert[(string)$x['cuenta']]=$x;
    foreach (($res['auditoria_pendientes']??[]) as $x) $pend[(string)$x['cuenta']]=$x;
    $hc=$motor->cargarHc($inicio,$fin);
    $foto=null;
    foreach ($hc['fotos'] as $p) if ($p!==null) $foto=$p;
    if ($foto!==null) {
        $fotoUsada=implode('-',$foto);
        foreach (($hc['datos'][$fotoUsada]??[]) as $h) {
            if (RankingAtribucionServiceV06::normalizarDistrito((string)($h['distrito']??''))!=='CANCUN') continue;
            if (stripos((string)($h['puesto_lr']??''),'LIDER')===false) continue;
            $k=ds_coach($h['nombre_colaborador']??'');
            if ($k===null) continue;
            $pos=trim((string)($h['id_posicion']??''));
            if ($pos!=='') $coachesPos[$k][$pos]=true;
        }
    }
    foreach ($objetivos as $k=>$o) {
        $enCoach=0;
        foreach ($cert as $x) {
            if (!ds_mismo_coach($x,$k,$coachesPos)) continue;
            $enCoach++;
            $fechaDia=substr((string)($x['fecha']??''),0,10);
            $motorDia[$k][$fechaDia]=($motorDia[$k][$fechaDia]??0)+1;
        }
        $stats[$k]=['registros'=>0,'unicas'=>0,'elegibles'=>0,'sin_cuenta'=>0,'duplicadas'=>0,
          'en_coach_motor'=>$enCoach,'cert_en_coach'=>0,'pendientes'=>0,'otro_coach'=>0,
          'fuera_origen'=>0,'no_en_motor'=>0,'motivos'=>[]];
        foreach ($grupos[$k] as $index=>$evs) {
            $s=&$stats[$k];
            $s['registros']+=count($evs);
            $s['duplicadas']+=max(0,count($evs)-1);
            $c=trim((string)($evs[0]['cuenta']??''));
            $motorRow=null;
            if ($c==='') {
                $s['sin_cuenta']+=count($evs);
                $estado='SIN_CUENTA';
                $motorRow=null;
            } else {
                $s['unicas']++;
                $esScope=false;
                foreach ($evs as $ev) if (ds_in_scope($ev)) $esScope=true;
                if (!$esScope) {
                    $estado='FUERA_FILTRO_ORIGEN'; $s['fuera_origen']++;
                } else {
                    $s['elegibles']++;
                    if (isset($cert[$c])) {
                        $motorRow=$cert[$c];
                        if (ds_mismo_coach($motorRow,$k,$coachesPos)) {
                            $estado='CERTIFICADA_EN_COACH'; $s['cert_en_coach']++;
                        } else {
                            $estado='CERTIFICADA_OTRO_COACH'; $s['otro_coach']++;
                        }
                    } elseif (isset($pend[$c])) {
                        $motorRow=$pend[$c];
                        $estado=(string)($motorRow['estado']??'PENDIENTE_HC');
                        $s['pendientes']++;
                    } else {
                        $motorRow=null;
                        $estado='NO_ENCONTRADA_EN_MOTOR'; $s['no_en_motor']++;
                    }
                }
            }
            $s['motivos'][$estado]=($s['motivos'][$estado]??0)+1;
            $motorRow=$motorRow??($cert[$c]??($pend[$c]??null));
            $datos[]=['coach'=>$k,'cuenta'=>$c,'filas'=>count($evs),
                'fechas'=>implode(' / ',array_unique(array_map(static fn($v)=>substr((string)($v['fecha']??''),0,10),$evs))),
                'folios'=>implode(' / ',array_unique(array_map(static fn($v)=>(string)($v['folio_empleado']??''),$evs))),
                'lideres'=>implode(' / ',array_unique(array_map(static fn($v)=>(string)($v['lider']??''),$evs))),
                'origenes'=>implode(' / ',array_unique(array_map(static fn($v)=>(string)($v['origen_prospecto']??''),$evs))),
                'subcanales'=>implode(' / ',array_unique(array_map(static fn($v)=>(string)($v['subcanal']??''),$evs))),
                'estado'=>$estado,'lider_hc'=>$motorRow['lider_id']??'',
                'coach_hc'=>$motorRow['coach_id']??'',
                'foto_hc'=>$motorRow['hc_foto']??'',
                'cuenta_evento'=>$evs[0]['coach']??'',
            ];
            unset($s);
        }
    }
    usort($datos, static function($a,$b){
        $ca=($a['estado']==='CERTIFICADA_EN_COACH')?1:0;
        $cb=($b['estado']==='CERTIFICADA_EN_COACH')?1:0;
        return strcmp($a['coach'],$b['coach']) ?: ($ca<=>$cb) ?: strcmp($a['fechas'],$b['fechas']);
    });
} catch (Throwable $e) {
    $error=$e->getMessage();
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico S41 | Cancún | SF vs TalIA</title>
<style>
:root{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#18283f;background:#f5f7fb}
body{margin:0;padding:24px}main{max-width:1500px;margin:auto}h1{font-size:24px}p{line-height:1.6}
small,.muted{color:#66738b}.card{background:#fff;border:1px solid #dce2eb;border-radius:12px;padding:16px;margin-bottom:18px;box-shadow:0 2px 10px #12224a08}
.scroll{overflow:auto}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border-bottom:1px solid #e6eaf1;padding:8px;text-align:left;vertical-align:top;white-space:nowrap}thead th{background:#edf2fa;position:sticky;top:0}
.bad{color:#aa2233;font-weight:700}.ok{color:#16814a;font-weight:650}.warn{color:#9b6500;font-weight:700}
.pill{display:inline-block;background:#eaf0ff;padding:4px 8px;border-radius:10px}code{background:#eef3f9;padding:2px 4px;border-radius:4px}
</style></head><body><main>
<h1>Conciliación Salesforce vs Motor HC · Cancún · Semana 41 (2026)</h1>
<p>Periodo <strong>05 al 11 de octubre</strong> · Motor <code><?=ds_h($versionMotor)?></code> · Fotografía HC <code><?=ds_h($fotoUsada)?></code> · Ejecución <?=ds_h(date('Y-m-d H:i:s'))?></p>
<p><strong>Solo lectura.</strong> No modifica <code>ranking_productividad.php</code>, <code>motor_hc.php</code> ni bases de datos. Reporte Salesforce de referencia: <strong>IRRA 32</strong>, <strong>RAFAEL 30</strong>.</p>
<?php if ($error!==''): ?>
<section class="card bad"><strong>ERROR:</strong> <?=ds_h($error)?></section>
<?php else: ?>
<section class="card scroll"><h2>1. Conciliación por coach</h2>
<table><thead><tr><th>Coach</th><th>SF (registros)</th><th>BD (filas)</th><th>BD (cuentas únicas)</th><th>BD elegibles Cambaceo/PDV</th><th>Motor HC (coach)</th><th>Motor HC (captura)</th><th>SF − Motor</th><th>Filas duplicadas en BD</th><th>Pendientes HC</th><th>Fuera de origen</th><th>Atribuidas a otro coach</th><th>Posiciones coach HC</th></tr></thead><tbody>
<?php foreach($objetivos as $k=>$o): $s=$stats[$k]; ?>
<tr><td><strong><?=ds_h($o['nombre'])?></strong></td><td><?=ds_h($o['sf'])?></td><td><?=ds_h($s['registros'])?></td><td><?=ds_h($s['unicas'])?></td><td><?=ds_h($s['elegibles'])?></td><td><strong><?=ds_h($s['en_coach_motor'])?></strong></td><td><?=ds_h($o['tx_captura'])?></td><td class="bad"><?=ds_h($o['sf']-$s['en_coach_motor'])?></td><td><?=ds_h($s['duplicadas'])?></td><td><?=ds_h($s['pendientes'])?></td><td><?=ds_h($s['fuera_origen'])?></td><td><?=ds_h($s['otro_coach'])?></td><td><?=ds_h(implode(', ',array_keys($coachesPos[$k])))?></td></tr>
<?php endforeach; ?></tbody></table>
<p class="muted">SF cuenta <em>registros</em>; el Motor HC cuenta <em>cuentas distintas certificadas</em>. Los universos solo son directamente comparables si coinciden filtros, estatus y fecha. “BD elegibles” = cuentas únicas del coach cuyo origen coincide con la regla del motor.</p>
</section>
<section class="card scroll"><h2>2. Validación por día (la captura de Salesforce ya incluye esos totales)</h2>
<table><thead><tr><th>Coach</th><th>Fecha</th><th>SF (registros)</th><th>BD (filas)</th><th>BD (cuentas)</th><th>Motor HC (cuentas)</th><th>SF − BD filas</th><th>SF − Motor HC</th></tr></thead><tbody>
<?php foreach($objetivos as $k=>$o) foreach($o['sf_dias'] as $idx=>$sfDia):
    $f=sprintf('2026-10-%02d',$idx+5);
    $bdFilas=$bdDiaFilas[$k][$f]??0;
    $bdDistintas=count($bdDiaCuentas[$k][$f]??[]);
    $motorCont=$motorDia[$k][$f]??0;
?><tr><td><?=ds_h($o['nombre'])?></td><td><?=ds_h($f)?></td><td><?=ds_h($sfDia)?></td><td><?=ds_h($bdFilas)?></td><td><?=ds_h($bdDistintas)?></td><td><?=ds_h($motorCont)?></td>
<td class="<?=($sfDia===$bdFilas?'ok':'bad')?>"><?=ds_h($sfDia-$bdFilas)?></td><td class="<?=($sfDia===$motorCont?'ok':'bad')?>"><?=ds_h($sfDia-$motorCont)?></td></tr>
<?php endforeach; ?></tbody></table>
<p class="muted">Si SF vs BD filas presenta diferencias, comparar los registros de Salesforce y la carga del importador. Si SF = BD filas pero BD cuentas &lt; BD filas, revisar oportunidades/cuentas repetidas. Si BD elegibles &gt; Motor HC, examinar estados de certificación.</p>
</section>
<section class="card scroll"><h2>3. Distribución de estados de cuentas</h2>
<table><thead><tr><th>Coach</th><th>Estado</th><th>Cuentas distintas</th><th>Significado</th></tr></thead><tbody>
<?php foreach($objetivos as $k=>$o) foreach(($stats[$k]['motivos']??[]) as $estado=>$numero):
    $exp = match($estado){
        'CERTIFICADA_EN_COACH'=>'Motor HC sí la certifica en el coach esperado',
        'CERTIFICADA_OTRO_COACH'=>'Motor HC sí la certifica, pero para otra posición de coach/líder',
        'FUERA_FILTRO_ORIGEN'=>'Su origen_prospecto no cumple el filtro CAMBACEO / PUNTO DE VENTA / PDV',
        'SIN_CUENTA'=>'Fila sin cuenta; el Motor HC la excluye',
        'NO_ENCONTRADA_EN_MOTOR'=>'Coincide con filtro del origen, pero no figura en resultado ni auditoría',
        'SIN_ASOCIACION_HC'=>'Folio sin cadena HC unívoca vendedor → coach → líder',
        'SIN_FOLIO_UNICO'=>'La cuenta carece de folio único verificable',
        'HIC_AMBIGUA'=>'Equivalencias HIC con conflicto',
        'HC_JERARQUIA_AMBIGUA'=>'Más de una jerarquía candidata',
        'SIN_FOTO_HC'=>'No existe fotografía histórica HC aplicable',
        'CUENTA_SEMANAS_MULTIPLES'=>'Misma cuenta registrada en semanas distintas',
        default=>'Pendiente / condición reportada por Motor HC'
    };
?><tr><td><?=ds_h($o['nombre'])?></td><td class="<?=($estado==='CERTIFICADA_EN_COACH'?'ok':'warn')?>"><?=ds_h($estado)?></td><td><?=ds_h($numero)?></td><td><?=ds_h($exp)?></td></tr><?php endforeach;?></tbody></table>
</section>
<section class="card scroll"><h2>4. Evidencia cuenta por cuenta (prioridad: no conciliadas)</h2>
<p class="muted">La columna “Filas” permite detectar duplicados. Si las filas de BD son menores a los registros SF, será necesario exportar el detalle de Salesforce para ubicar las cuentas ausentes por su identificador.</p>
<table><thead><tr><th>Coach SF</th><th>Cuenta</th><th>Filas BD</th><th>Fecha(s)</th><th>Folio(s)</th><th>Origen(es)</th><th>Subcanal(es)</th><th>Coach evento</th><th>Líder evento</th><th>Resultado</th><th>Líder HC</th><th>Coach posición HC</th><th>Foto HC</th></tr></thead><tbody>
<?php foreach($datos as $x): ?>
<tr><td><?=ds_h($x['coach'])?></td><td><?=ds_h($x['cuenta'])?></td><td><?=ds_h($x['filas'])?></td><td><?=ds_h($x['fechas'])?></td><td><?=ds_h($x['folios'])?></td><td><?=ds_h($x['origenes'])?></td><td><?=ds_h($x['subcanales'])?></td><td><?=ds_h($x['cuenta_evento'])?></td><td><?=ds_h($x['lideres'])?></td><td class="<?=($x['estado']==='CERTIFICADA_EN_COACH'?'ok':'bad')?>"><?=ds_h($x['estado'])?></td><td><?=ds_h($x['lider_hc'])?></td><td><?=ds_h($x['coach_hc'])?></td><td><?=ds_h($x['foto_hc'])?></td></tr>
<?php endforeach; ?></tbody></table>
</section>
<?php endif; ?>
<p class="muted">Archivo de diagnóstico temporal: eliminar <code>/plataforma/detalle/diagnostico_s41_cancun.php</code> después de obtener resultados. Esta página solo está disponible con sesión admin/director_regional.</p>
</main></body></html>
