<?php
/**
 * TotalXpedient | Penetración de Clústeres v1.7.0 | 2026-11-12
 * Ubicación: /plataforma/detalle/penetracion_clusters.php
 * Solo lectura. Requiere Clusters_BDU y Clusters_BDU_Catalogo (esquema v2).
 * Restricción SQL de distrito basada en sesión + HC para Director Distrital.
 */
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (empty($_SESSION['usuario'])) {
    header('Location: /plataforma/login.php');
    exit;
}
$rol = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (!in_array($rol, ['admin', 'director_regional', 'director_distrital'], true)) {
    http_response_code(403);
    exit('Acceso restringido: módulo de Penetración disponible para ADMIN, DIRECTOR REGIONAL y DIRECTOR DISTRITAL.');
}
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/motor_clusters.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(500);
    exit('Conexión de base de datos no disponible.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->set_charset('utf8mb4');

function pcStr(string $key, string $fallback=''): string {
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $fallback;
}
function pcHtml(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pcNormDistrito(string $str): string {
    $str = strtoupper(trim(strtr($str, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N'
    ])));
    $str = preg_replace('/\s+/u', ' ', $str) ?? $str;
    $str = preg_replace('/\s*\/\s*/', '/', $str) ?? $str;
    return match ($str) {
        'CANCUN' => 'CANCUN',
        'MERIDA' => 'MERIDA',
        'TUXTLA', 'TUXTLA GUTIERREZ' => 'TUXTLA',
        'VILLAHERMOSA' => 'VILLAHERMOSA',
        'COATZA/MINA','COATZA MINA','COATZA-MINA','COATZACOALCOS-MINATITLAN','COATZACOALCOS/MINATITLAN' => 'COATZA/MINA',
        default => '', // fail closed: nunca tomar un distrito sin equivalencia segura
    };
}
function pcQuery(mysqli $db, string $sql, string $types='', array $args=[]): array {
    $stmt = $db->prepare($sql);
    try {
        if ($types !== '') $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}
function pcDistritoAutorizado(mysqli $db): string {
    // Iguala la prioridad de identidad de index.php: Talento GS y, como respaldo, ID posición.
    $talento = trim((string)($_SESSION['numero_talento_gs'] ?? ''));
    $posicion = trim((string)($_SESSION['id_posicion'] ?? ''));
    if ($talento !== '') {
        $rows = pcQuery($db, "SELECT distrito FROM hc WHERE numero_talento_gs = ?
                 AND nombre_colaborador IS NOT NULL AND nombre_colaborador NOT LIKE '%VACANTE%'
                 ORDER BY anio DESC, semana DESC LIMIT 1", 's', [$talento]);
        if ($rows) return pcNormDistrito((string)($rows[0]['distrito'] ?? ''));
    }
    if ($posicion !== '') {
        $rows = pcQuery($db, "SELECT distrito FROM hc WHERE id_posicion = ?
                 AND nombre_colaborador IS NOT NULL AND nombre_colaborador NOT LIKE '%VACANTE%'
                 ORDER BY anio DESC, semana DESC LIMIT 1", 's', [$posicion]);
        if ($rows) return pcNormDistrito((string)($rows[0]['distrito'] ?? ''));
    }
    return '';
}
function pcFechaCorte(int $anio, string $tipo, int $numero): string {
    if ($tipo === 'MENSUAL') {
        return sprintf('%04d-%02d-%02d', $anio, $numero, (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $numero))));
    }
    // Semanas ISO lunes-domingo. Sólo para ordenar cortes (no afirma fecha oficial Qlik).
    return (new DateTimeImmutable())->setISODate($anio, $numero, 7)->format('Y-m-d');
}
function pcMesNombre(int $n): string {
    $m = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
          7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $m[$n] ?? (string)$n;
}
/** Enlaces de navegación temporal: sólo cortes existentes, mismos filtros, sin fechas diarias. */
function pcCutUrl(array $cut): string {
    $query = [];
    foreach (['distrito','municipio','padre','tipo_cluster','nivel','a_0_12_pen','a_0_12_vel','a_12_24_pen','a_12_24_vel','m_24_60_pen','m_24_60_vel','m_60_plus_pen','m_60_plus_vel','min_hp'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key]) && strlen($_GET[$key]) <= 250) {
            $query[$key] = $_GET[$key];
        }
    }
    $query['periodo'] = (string)$cut['periodo'];
    $query['corte'] = (string)$cut['key'];
    return 'penetracion_clusters.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function pcCutButtonLabel(array $cut): string {
    return $cut['periodo'] === 'SEMANAL'
        ? 'Semana '.$cut['numero_periodo']
        : pcMesNombre((int)$cut['numero_periodo']);
}
function pcRows(mysqli $db, int $anio, string $periodo, int $numero, ?string $distrito): array {
    $sql = "SELECT b.distrito, b.cluster_padre, b.cluster_hijo, b.tipo,
                   b.base_usuarios, b.casas_pasadas, b.penetracion, b.velocidad_penetracion_anual,
                   b.antiguedad_anios, b.cancelaciones_mes, b.adiciones_netas_ultimo_trimestre,
                   c.plaza_municipio_localidad AS municipio, c.fecha_liberacion AS fecha_liberacion
            FROM Clusters_BDU AS b
            LEFT JOIN Clusters_BDU_Catalogo AS c
              ON c.distrito = b.distrito AND c.cluster_hijo = b.cluster_hijo
            WHERE b.anio = ? AND b.periodo = ? AND b.numero_periodo = ?";
    $args = [$anio, $periodo, $numero];
    $types = 'isi';
    if ($distrito !== null) {
        $sql .= ' AND b.distrito = ?';
        $types .= 's';
        $args[] = $distrito;
    }
    $sql .= ' ORDER BY b.distrito, b.cluster_padre, b.tipo, b.cluster_hijo';
    return pcQuery($db, $sql, $types, $args);
}
function pcFiltered(array $rows, string $municipio, string $padre, string $tipoCluster=''): array {
    return array_values(array_filter($rows, static function(array $r) use ($municipio,$padre,$tipoCluster): bool {
        $loc = trim((string)($r['municipio'] ?? ''));
        if ($municipio === '__PENDIENTE__' && $loc !== '') return false;
        if ($municipio !== '' && $municipio !== '__PENDIENTE__' && $municipio !== $loc) return false;
        if ($padre !== '' && $padre !== (string)$r['cluster_padre']) return false;
        $tipo = (string)($r['tipo_gestion'] ?? $r['tipo'] ?? '');
        if ($tipoCluster !== '' && $tipoCluster !== $tipo) return false;
        return true;
    }));
}
/** GENERAL = combinación de metas de ambos tipos por edad, no objetivo fijo. */
function pcObjetivo(array $rows, string $segmento, array $params): array {
    $hp = 0; $usuarios = 0; $bruto = 0.0; $faltanEdad = 0; $faltanSinCompensar=0;
    foreach ($rows as $r) {
        $tipo = (string)($r['tipo_gestion'] ?? $r['tipo']);
        if ($segmento !== 'GENERAL' && $segmento !== $tipo) continue;
        $h = ($r['casas_pasadas'] ?? null) === null ? 0 : (int)$r['casas_pasadas'];
        if ($h <= 0 || ($r['base_usuarios'] ?? null) === null) continue;
        $hp += $h;
        $usuarios += (int)$r['base_usuarios'];
        $meta = $r['meta_pen_gestion'] ?? null;
        if ($meta === null) { $faltanEdad++; continue; }
        $bruto += $h * (float)$meta / 100.0;
        $faltanSinCompensar += MotorClusters::faltaSuscriptores($h,(int)$r['base_usuarios'],(float)$meta);
    }
    $completo = $hp > 0 && $faltanEdad === 0;
    $metaUsuarios = $completo ? (int)ceil($bruto - 1e-9) : null;
    $penetracion = $hp > 0 ? 100.0 * $usuarios / $hp : null;
    $metaPct = $completo ? 100.0 * $bruto / $hp : null;
    return [
        'hp'=>$hp, 'usuarios'=>$usuarios,'meta'=>$metaUsuarios,
        'alcance'=>$metaUsuarios !== null && $metaUsuarios > 0 ? 100.0*$usuarios/$metaUsuarios : null,
        'faltan'=>$metaUsuarios === null ? null : $faltanSinCompensar,
        'brecha'=>$metaPct===null || $penetracion===null ? null : max(0.0,$metaPct-$penetracion),
        'metaPct'=>$metaPct,'pen'=>$penetracion,'faltanEdad'=>$faltanEdad,
    ];
}

/** En KPI: mismo universo con base_usuarios conocida y casas_pasadas > 0. */
function pcAgg(array $rows): array {
    $sumHP = 0; $sumU = 0; $comp = 0; $plotRows = 0; $sumVelHP = 0.0; $velHP = 0;
    $parents=[]; $ages=0.0; $agesN=0; $missingCatalog=0;
    foreach ($rows as $r) {
        $parents[$r['distrito'].'|'.$r['cluster_padre']] = true;
        if (trim((string)($r['municipio'] ?? '')) === '') $missingCatalog++;
        if (($r['edad_gestion'] ?? null) !== null) {
            $ages += (float)$r['edad_gestion']; $agesN++;
        }
        if ($r['casas_pasadas'] === null || (int)$r['casas_pasadas'] <= 0 || $r['base_usuarios'] === null) continue;
        $hp = (int)$r['casas_pasadas']; $u = (int)$r['base_usuarios'];
        $sumHP += $hp; $sumU += $u; $comp++;
        if ($r['velocidad_penetracion_anual'] !== null) {
            $sumVelHP += $hp * (float)$r['velocidad_penetracion_anual']; $velHP += $hp;
            $plotRows++;
        }
    }
    return [
        'hijos'=>count($rows), 'padres'=>count($parents), 'comparables'=>$comp,
        'hp'=>$sumHP, 'usuarios'=>$sumU,
        'pen'=>$sumHP > 0 ? 100.0*$sumU/$sumHP : null,
        'vel'=>$velHP > 0 ? 100.0*$sumVelHP/$velHP : null,
        'antiguedad'=>$agesN > 0 ? $ages/$agesN : null,
        'graficables'=>$plotRows, 'sin_catalogo'=>$missingCatalog
    ];
}
function pcBubbles(array $rows, string $nivel, float $minHP, array $limits): array {
    $groups = [];
    foreach ($rows as $r) {
        $hp = $r['casas_pasadas'] === null ? 0 : (int)$r['casas_pasadas'];
        if ($hp <= 0 || $r['base_usuarios'] === null || $r['velocidad_penetracion_anual'] === null) continue;
        $tipo = (string)($r['tipo_gestion'] ?? $r['tipo']);
        if (!isset($limits[$tipo])) continue;
        $key = $r['distrito'].'|'.$tipo.'|'.$r['cluster_padre'].($nivel==='HIJO'?'|'.$r['cluster_hijo']:'');
        if (!isset($groups[$key])) $groups[$key] = [
            'label'=>$nivel==='PADRE'?$r['cluster_padre']:$r['cluster_hijo'],
            'padre'=>$r['cluster_padre'], 'distrito'=>$r['distrito'], 'tipo'=>$tipo,
            'hp'=>0, 'usuarios'=>0, 'velSum'=>0.0, 'targetPenSum'=>0.0,
            'targetVelSum'=>0.0,'targetMissing'=>false,'nHijos'=>0,'locs'=>[],
        ];
        $g = &$groups[$key];
        $g['hp'] += $hp; $g['usuarios'] += (int)$r['base_usuarios'];
        $g['velSum'] += $hp * (float)$r['velocidad_penetracion_anual'];
        $g['nHijos']++;
        $g['locs'][] = trim((string)($r['municipio'] ?? ''));
        if (($r['meta_pen_gestion'] ?? null) === null || ($r['meta_vel_gestion'] ?? null) === null) {
            $g['targetMissing'] = true;
        } else {
            $g['targetPenSum'] += $hp * (float)$r['meta_pen_gestion'];
            $g['targetVelSum'] += $hp * (float)$r['meta_vel_gestion'];
        }
        unset($g);
    }
    $out = ['NORMAL'=>[], 'CLUSTER_A'=>[]];
    foreach ($groups as $g) {
        if ($g['hp'] < $minHP) continue;
        $pen = 100.0*$g['usuarios']/$g['hp'];
        $vel = 100.0*$g['velSum']/$g['hp'];
        $targetPen = !$g['targetMissing'] ? $g['targetPenSum']/$g['hp'] : null;
        $targetVel = !$g['targetMissing'] ? $g['targetVelSum']/$g['hp'] : null;
        // El cuadrante corresponde a las líneas FIJAS trazadas (20/7 A, 30/5 Maduros),
        // mientras que la meta individual por edad figura aparte en tooltip y ranking.
        $quadX=(float)$limits[$g['tipo']]['pen'];
        $quadY=(float)$limits[$g['tipo']]['vel'];
        $quadrant=$pen >= $quadX
            ? ($vel >= $quadY ? 'Liderazgo en expansión' : 'Crecimiento limitado')
            : ($vel >= $quadY ? 'Oportunidad de crecimiento' : 'Rezago en mercado');
        $locs = array_unique(array_filter($g['locs'], static fn($v) => $v !== ''));
        $out[$g['tipo']][] = [
            'label'=>$g['label'], 'padre'=>$g['padre'], 'distrito'=>$g['distrito'],
            'tipo'=>$g['tipo'], 'hp'=>$g['hp'], 'usuarios'=>$g['usuarios'],
            'pen'=>round($pen,3), 'vel'=>round($vel,3),
            'metaPen'=>$targetPen===null?null:round($targetPen,3),
            'metaVel'=>$targetVel===null?null:round($targetVel,3),
            'hijos'=>$g['nHijos'], 'municipio'=>count($locs)===1?reset($locs):(count($locs)>1?'Varias localidades':'Sin catalogar'),
            'cuadrante'=>$quadrant,
        ];
    }
    foreach ($out as &$items) usort($items,static fn($a,$b)=>$b['hp']<=>$a['hp']);
    unset($items);
    return $out;
}
/** Metas y brechas consolidadas por PADRE o HIJO a partir de metas canónicas del Motor. */
function pcTargets(array $rows, string $nivel, array $params): array {
    $groups = [];
    foreach ($rows as $r) {
        $tipo = (string)($r['tipo_gestion'] ?? $r['tipo'] ?? '');
        if (!in_array($tipo,['NORMAL','CLUSTER_A'],true)) continue;
        $name = $nivel==='HIJO' ? (string)$r['cluster_hijo'] : (string)$r['cluster_padre'];
        $key=(string)$r['distrito'].'|'.$tipo.'|'.(string)$r['cluster_padre'];
        if ($nivel==='HIJO') $key.='|'.(string)$r['cluster_hijo'];
        if (!isset($groups[$key])) $groups[$key] = [
            'nombre'=>$name, 'padre'=>(string)$r['cluster_padre'],
            'distrito'=>(string)$r['distrito'], 'tipo'=>$tipo,
            'hijos'=>0,'hp'=>0,'usuarios'=>0,'comp'=>0,'hpVel'=>0,'sumVel'=>0.0,
            'targetVelSum'=>0.0,'targetVelMissing'=>false,'hpAge'=>0,'sumAge'=>0.0,
            'targetBase'=>0.0,'metaMissing'=>false,'faltanHijos'=>0,'municipios'=>[],
        ];
        $g = &$groups[$key];
        $g['hijos']++;
        $loc=trim((string)($r['municipio']??''));
        if ($loc!=='') $g['municipios'][$loc]=true;
        $hp=$r['casas_pasadas']===null ? 0 : (int)$r['casas_pasadas'];
        $user=$r['base_usuarios'];
        if ($hp>0 && $user!==null) {
            $g['comp']++; $g['hp']+=$hp; $g['usuarios']+=(int)$user;
            $age=$r['edad_gestion'] ?? null;
            if ($age!==null && is_numeric($age) && (float)$age >= 0) {
                $g['hpAge']+=$hp;
                $g['sumAge']+=$hp*(float)$age;
            }
            $meta=$r['meta_pen_gestion'] ?? null;
            if ($meta===null) {
                $g['metaMissing']=true;
            } else {
                $g['targetBase'] += $hp * (float)$meta / 100.0;
                $g['faltanHijos'] += MotorClusters::faltaSuscriptores($hp,(int)$user,(float)$meta);
            }
            if ($r['velocidad_penetracion_anual'] !== null) {
                $g['sumVel'] += $hp * (float)$r['velocidad_penetracion_anual'];
                $g['hpVel'] += $hp;
                $goalVel=$r['meta_vel_gestion'] ?? null;
                if ($goalVel===null) $g['targetVelMissing']=true;
                else $g['targetVelSum'] += $hp * (float)$goalVel;
            }
        }
        unset($g);
    }
    $result=['NORMAL'=>[],'CLUSTER_A'=>[]];
    foreach ($groups as $g) {
        $hp=$g['hp'];$u=$g['usuarios'];$tipo=$g['tipo'];
        $pen=$hp>0 ? 100.0*$u/$hp : null;
        $vel=$g['hpVel']>0 ? 100.0*$g['sumVel']/$g['hpVel'] : null;
        $age=$g['hpAge']>0 ? $g['sumAge']/$g['hpAge'] : null;
        $target=$hp>0 && !$g['metaMissing'] ? 100.0*$g['targetBase']/$hp : null;
        $targetVel=$g['hpVel']>0 && !$g['targetVelMissing'] ? $g['targetVelSum']/$g['hpVel'] : null;
        $faltan=$target===null ? null : $g['faltanHijos'];
        $gapPen=$pen===null || $target===null ? null : max(0.0,$target-$pen);
        $gapVel=$vel===null || $targetVel===null ? null : max(0.0,$targetVel-$vel);
        $locs=array_keys($g['municipios']);
        $result[$tipo][] = [
            'distrito'=>$g['distrito'],'nombre'=>$g['nombre'],'padre'=>$g['padre'],
            'municipio'=>count($locs)===0?'Sin catalogar':(count($locs)===1?$locs[0]:'Varias localidades'),
            'edad'=>$age,'hijos'=>$g['hijos'],'hp'=>$hp,'usuarios'=>$u,
            'pen'=>$pen,'vel'=>$vel,'metaPen'=>$target,'metaVel'=>$targetVel,
            'gapPen'=>$gapPen,'gapVel'=>$gapVel,'faltan'=>$faltan,
            'estado'=>$hp===0?'Sin base':($target===null?'Sin antigüedad':($vel===null?'Sin velocidad':'OK')),
        ];
    }
    foreach ($result as &$items) usort($items, static function(array $a,array $b):int {
        if ($a['faltan']===null) return $b['faltan']===null?0:1;
        if ($b['faltan']===null) return -1;
        return ($b['faltan']<=>$a['faltan']) ?: ($b['hp']<=>$a['hp']);
    });
    unset($items);
    return $result;
}

function pcSortAttr($value): string {
    return ' data-sort-value="'.pcHtml($value===null?'':(string)$value).'"';
}
function pcGap(?float $gap): string {
    if ($gap === null) return 'ND';
    return $gap <= 0.0000001 ? 'Cumple' : number_format($gap,1,'.',',').' pp';
}
function pcGapConBrechas(?float $gap, ?int $faltan): string {
    if ($gap === null) return 'ND';
    return $gap <= 0.0000001 && $faltan !== null && $faltan>0
        ? 'Cumple promedio' : pcGap($gap);
}
/**
 * Consolida metas calculadas por los rankings de brechas visibles.
 * No invalida un distrito por clústeres sin HPs/base comparables: esos grupos
 * muestran ND en el ranking pero no pertenecen al denominador de penetración.
 * Sólo una falta real de META en una base COMPARABLE invalida el resultado.
 * Faltan suscriptores = suma de faltantes positivos por fila del ranking.
 */
function pcCombineTargetRankings(array $targetRankings): array {
    $map = [];
    foreach (['NORMAL', 'CLUSTER_A'] as $kind) {
        foreach (($targetRankings[$kind] ?? []) as $item) {
            $d = (string)($item['distrito'] ?? '');
            if ($d === '') continue;
            $hp = (int)($item['hp'] ?? 0);
            if ($hp <= 0) continue; // Sin base: no penaliza el objetivo distrital
            if (!isset($map[$d])) {
                $map[$d] = [
                    'hp'=>0, 'usuarios'=>0, 'targetUsersRaw'=>0.0,
                    'metaMissing'=>false, 'faltan'=>0, 'faltanMissing'=>false,
                    'gruposComparables'=>0, 'gruposSinMeta'=>0,
                ];
            }
            $g = &$map[$d];
            $g['hp'] += $hp;
            $g['usuarios'] += (int)($item['usuarios'] ?? 0);
            $g['gruposComparables']++;
            if (($item['metaPen'] ?? null) === null) {
                $g['metaMissing'] = true;
                $g['gruposSinMeta']++;
            } else {
                $g['targetUsersRaw'] += $hp * (float)$item['metaPen'] / 100.0;
            }
            if (($item['faltan'] ?? null) === null) {
                $g['faltanMissing'] = true;
            } else {
                $g['faltan'] += (int)$item['faltan'];
            }
            unset($g);
        }
    }
    foreach ($map as &$g) {
        $g['pen'] = $g['hp'] > 0 ? 100.0*$g['usuarios']/$g['hp'] : null;
        $g['metaPen'] = ($g['hp'] > 0 && !$g['metaMissing'])
            ? 100.0*$g['targetUsersRaw']/$g['hp'] : null;
        $g['gapPen'] = ($g['metaPen'] === null || $g['pen'] === null)
            ? null : max(0.0, $g['metaPen']-$g['pen']);
        if ($g['faltanMissing']) $g['faltan'] = null;
    }
    unset($g);
    return $map;
}
function pcCombineTargetRankingsTotal(array $byDistrict): array {
    $total = [
        'hp'=>0, 'usuarios'=>0, 'targetUsersRaw'=>0.0,
        'metaMissing'=>false, 'faltan'=>0, 'faltanMissing'=>false,
        'gruposComparables'=>0, 'gruposSinMeta'=>0,
    ];
    foreach ($byDistrict as $district) {
        $total['hp'] += (int)$district['hp'];
        $total['usuarios'] += (int)$district['usuarios'];
        $total['targetUsersRaw'] += (float)$district['targetUsersRaw'];
        $total['gruposComparables'] += (int)$district['gruposComparables'];
        $total['gruposSinMeta'] += (int)$district['gruposSinMeta'];
        $total['metaMissing'] = $total['metaMissing'] || $district['metaMissing'];
        if ($district['faltan'] === null) {
            $total['faltanMissing'] = true;
        } else {
            $total['faltan'] += (int)$district['faltan'];
        }
    }
    $total['pen'] = $total['hp'] > 0 ? 100.0*$total['usuarios']/$total['hp'] : null;
    $total['metaPen'] = ($total['hp'] > 0 && !$total['metaMissing'])
        ? 100.0*$total['targetUsersRaw']/$total['hp'] : null;
    $total['gapPen'] = ($total['metaPen'] === null || $total['pen'] === null)
        ? null : max(0.0, $total['metaPen']-$total['pen']);
    if ($total['faltanMissing']) $total['faltan'] = null;
    return $total;
}

function pcOpportunityFromRows(array $rows, string $nivel, array $params): array {
    $districts = pcCombineTargetRankings(pcTargets($rows, $nivel, $params));
    return pcCombineTargetRankingsTotal($districts);
}

function pcSemaforoClase(?float $value, string $metric): string {
    if ($value === null) return 'pc-badge-neutral';
    if ($metric === 'pen') {
        if ($value < 20.0) return 'pc-badge-danger';
        if ($value < 30.0) return 'pc-badge-warn';
        return 'pc-badge-ok';
    }
    if ($metric === 'vel') {
        if ($value < 2.0) return 'pc-badge-danger';
        if ($value < 3.0) return 'pc-badge-warn';
        return 'pc-badge-ok';
    }
    return 'pc-badge-neutral';
}

function pcNum(?float $v, int $dec=0): string {
    return $v === null ? 'ND' : number_format($v, $dec, '.', ',');
}
function pcPct(?float $v, int $dec=1): string {
    return $v === null ? 'ND' : number_format($v, $dec, '.', ',').'%';
}
function pcPp(?float $current, ?float $old): string {
    return $current === null || $old === null ? 'ND' : sprintf('%+.2f pp', $current - $old);
}

try {
    $scopeDistrict = null;
    $districts = ['CANCUN','COATZA/MINA','MERIDA','TUXTLA','VILLAHERMOSA'];
    if ($rol === 'director_distrital') {
        $scopeDistrict = pcDistritoAutorizado($conexion);
        if ($scopeDistrict === '' || !in_array($scopeDistrict, $districts, true)) {
            http_response_code(403);
            exit('No fue posible identificar de forma segura el distrito de tu usuario en HC. Solicita validar tu Talento GS/posición antes de acceder.');
        }
    } else {
        $requestedDistrict = pcNormDistrito(pcStr('distrito'));
        $scopeDistrict = $requestedDistrict !== '' ? $requestedDistrict : null;
    }

    $cutSql = 'SELECT DISTINCT anio, periodo, numero_periodo FROM Clusters_BDU';
    $cuts = $scopeDistrict === null
        ? pcQuery($conexion, $cutSql)
        : pcQuery($conexion, $cutSql.' WHERE distrito = ?', 's', [$scopeDistrict]);
    foreach ($cuts as &$cut) {
        $cut['anio'] = (int)$cut['anio']; $cut['numero_periodo'] = (int)$cut['numero_periodo'];
        $cut['key'] = $cut['anio'].'|'.$cut['periodo'].'|'.$cut['numero_periodo'];
        $cut['fecha'] = pcFechaCorte($cut['anio'], $cut['periodo'], $cut['numero_periodo']);
        $cut['label'] = ($cut['periodo'] === 'SEMANAL' ? 'Semana '.$cut['numero_periodo'] : pcMesNombre($cut['numero_periodo'])).' · '.$cut['anio'];
    }
    unset($cut);
    usort($cuts, static fn($a,$b) => strcmp($b['fecha'],$a['fecha']) ?: strcmp($b['key'],$a['key']));

    $tipoRequest = strtoupper(pcStr('periodo'));
    if (!in_array($tipoRequest, ['SEMANAL','MENSUAL'], true)) $tipoRequest = '';
    $cutRequest = pcStr('corte');
    $selected = null;
    foreach ($cuts as $cut) {
        if ($cut['key'] === $cutRequest && ($tipoRequest === '' || $cut['periodo'] === $tipoRequest)) {
            $selected=$cut; break;
        }
    }
    if ($selected === null) {
        foreach ($cuts as $cut) {
            if ($tipoRequest === '' || $cut['periodo'] === $tipoRequest) { $selected=$cut; break; }
        }
    }
    if ($selected !== null) $tipoRequest = $selected['periodo'];

    // Navegación canónica: separados SEMANAL y MENSUAL, únicamente cortes importados.
    // Se ordena por el año/número del período, sin calendarios ni días comparables.
    $navCuts = ['SEMANAL'=>[], 'MENSUAL'=>[]];
    foreach ($cuts as $cut) $navCuts[$cut['periodo']][] = $cut;
    foreach ($navCuts as &$navRows) {
        usort($navRows, static fn($a,$b) => ($b['anio'] <=> $a['anio']) ?: ($b['numero_periodo'] <=> $a['numero_periodo']));
    }
    unset($navRows);
    $navPrevious = null; // anterior: corte más antiguo disponible, no necesariamente semana consecutiva
    $navNext = null;     // siguiente: corte más reciente disponible
    if ($selected !== null) {
        foreach ($navCuts[$selected['periodo']] as $idx=>$cut) {
            if ($cut['key'] === $selected['key']) {
                $navNext = $navCuts[$selected['periodo']][$idx-1] ?? null;
                $navPrevious = $navCuts[$selected['periodo']][$idx+1] ?? null;
                break;
            }
        }
    }

    $selectedDistrict = $scopeDistrict ?? '';
    $params = MotorClusters::parametros($_GET); // leer/validar antes de enriquecer
    $rawRows = $selected === null ? [] : MotorClusters::enriquecer(pcRows($conexion,$selected['anio'],$selected['periodo'],$selected['numero_periodo'],$scopeDistrict),$selected['fecha'],$params);
    $munis = []; $parents = [];
    foreach ($rawRows as $r) {
        $loc = trim((string)($r['municipio'] ?? ''));
        if ($loc !== '') $munis[$loc] = true;
    }
    $municipio = pcStr('municipio');
    if ($municipio !== '__PENDIENTE__' && !isset($munis[$municipio])) $municipio='';
    foreach ($rawRows as $r) {
        $loc = trim((string)($r['municipio'] ?? ''));
        if ($municipio === '__PENDIENTE__' && $loc !== '') continue;
        if ($municipio !== '' && $municipio !== '__PENDIENTE__' && $municipio !== $loc) continue;
        $parents[$r['cluster_padre']] = true;
    }
    $parent = pcStr('padre');
    if (!isset($parents[$parent])) $parent='';
    $typeCluster = strtoupper(pcStr('tipo_cluster'));
    if (!in_array($typeCluster,['NORMAL','CLUSTER_A'], true)) $typeCluster='';
    $rows = pcFiltered($rawRows,$municipio,$parent,$typeCluster);
    $nivel = strtoupper(pcStr('nivel','PADRE'));
    if (!in_array($nivel,['PADRE','HIJO'],true)) $nivel='PADRE';
    // Motor único de reglas; validación estricta servidor (sin recortes silenciosos).
    // Las líneas de cuadrantes son referencias por etapa, NO metas únicas por gráfico.
    $limits = [
        'NORMAL'=>[
            'pen'=>$params['m_24_60_pen'], 'vel'=>$params['m_24_60_vel'],
            'penMax'=>$params['m_60_plus_pen'], 'velMax'=>$params['m_60_plus_vel'],
        ],
        'CLUSTER_A'=>[
            'pen'=>$params['a_0_12_pen'], 'vel'=>$params['a_0_12_vel'],
            'penMax'=>$params['a_12_24_pen'], 'velMax'=>$params['a_12_24_vel'],
        ],
    ];
    $minHP = is_numeric(pcStr('min_hp')) ? max(0,min(500000, (int)pcStr('min_hp'))) : 0;

    $summary = pcAgg($rows);
    $mature = pcAgg(array_values(array_filter($rows, static fn($r)=>($r['tipo_gestion'] ?? $r['tipo'])==='NORMAL')));
    $young = pcAgg(array_values(array_filter($rows, static fn($r)=>($r['tipo_gestion'] ?? $r['tipo'])==='CLUSTER_A')));
    $bubbles = pcBubbles($rows, $nivel, $minHP, $limits);
    $targetRankings = pcTargets($rows, $nivel, $params);
    $districtOpportunities = pcCombineTargetRankings($targetRankings);
    $regionOpportunityTotal = pcCombineTargetRankingsTotal($districtOpportunities);
    $objectiveCards = [
        'GENERAL'=>pcObjetivo($rows,'GENERAL',$params),
        'NORMAL'=>pcObjetivo($rows,'NORMAL',$params),
        'CLUSTER_A'=>pcObjetivo($rows,'CLUSTER_A',$params),
    ];
    $byDistrict = [];
    foreach ($rows as $r) $byDistrict[$r['distrito']][] = $r;
    ksort($byDistrict);

    $previous = null;
    $prevAgg = null;
    if ($selected !== null) {
        $sameKind = array_values(array_filter($cuts, static fn($cut)=>$cut['periodo']===$selected['periodo']));
        foreach ($sameKind as $i=>$cut) {
            if ($cut['key'] === $selected['key'] && isset($sameKind[$i+1])) {
                $previous = $sameKind[$i+1];
                break;
            }
        }
        if ($previous !== null) {
            $oldRows = MotorClusters::enriquecer(pcRows($conexion, $previous['anio'],$previous['periodo'],$previous['numero_periodo'],$scopeDistrict),$previous['fecha'],$params);
            $prevAgg = pcAgg(pcFiltered($oldRows,$municipio,$parent,$typeCluster));
        }
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    exit('<!doctype html><html lang="es"><meta charset="utf-8"><title>Parámetros inválidos</title><body style="font-family:Poppins,Arial,sans-serif;padding:3rem;background:#f4f0ff;color:#252544"><h2>Parámetros de penetración incongruentes</h2><p>'.pcHtml($e->getMessage()).'</p><a href="penetracion_clusters.php">Restablecer parámetros predeterminados</a></body></html>');
} catch (Throwable $e) {
    error_log('penetracion_clusters.php: '.$e->getMessage());
    http_response_code(500);
    exit('No fue posible consultar el tablero. Verifica que el esquema Clusters_BDU v2 esté instalado y consulta el registro de errores PHP.');
}

ksort($munis, SORT_NATURAL | SORT_FLAG_CASE);
ksort($parents, SORT_NATURAL | SORT_FLAG_CASE);
$chartJson = json_encode([
    'NORMAL'=>$bubbles['NORMAL'],'CLUSTER_A'=>$bubbles['CLUSTER_A'],
    'umbrales'=>$limits,'nivel'=>$nivel,
    'canDistrict'=>$rol !== 'director_distrital',
    'corte'=>$selected['key'] ?? '', 'periodo'=>$selected['periodo'] ?? '', 'tipoCluster'=>$typeCluster,
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
$current_page = 'penetracion';
$displayScope = $scopeDistrict ?? 'REGIÓN SUR';
$roleLabel = ['admin'=>'ADMIN','director_regional'=>'DIRECCIÓN REGIONAL','director_distrital'=>'DIRECCIÓN DISTRITAL'][$rol];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Penetración de Clústeres | TotalXpedient</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=160">
    <link rel="stylesheet" href="../assets/css/penetracion_clusters.css?v=1.7.0">
    <style>
        .pc-badge{display:inline-flex;align-items:center;justify-content:center;min-width:72px;padding:4px 10px;border-radius:999px;font-weight:700;font-size:.92rem;line-height:1.1;white-space:nowrap;}
        .pc-badge-ok{background:#dff4df;color:#1f7a3e;}
        .pc-badge-warn{background:#ffe7c2;color:#b86116;}
        .pc-badge-danger{background:#ffd8d8;color:#b23838;}
        .pc-badge-neutral{background:#eceff4;color:#607089;}
        .pc-summary-table tfoot th,.pc-summary-table tfoot td{font-weight:800;background:#f3eefc;border-top:2px solid rgba(111,79,255,.22);}
    </style>
</head>
<body class="page-dashboard pc-page">
<div class="tx-layout">
    <?php
    // Esta pantalla reside en /detalle/. El sidebar requiere tratar 'penetracion' como vista detalle; ver guía de integración.
    // Nunca se sobrescribe el sidebar productivo desde este paquete.
    if (is_file(__DIR__ . '/../includes/sidebar.php')) {
        include __DIR__ . '/../includes/sidebar.php';
    }
    ?>
    <main class="tx-main">
        <div class="tx-container">
            <header class="pc-header">
                <div>
                    <p class="pc-eyebrow">TOTALXPEDIENT · INTELIGENCIA COMERCIAL · REGIÓN SUR</p>
                    <h1>Penetración de Clústeres</h1>
                    <p class="pc-subtitle">Situación actual y mapa de oportunidades · Fuente: Qlik / Clusters_BDU</p>
                </div>
                <div class="pc-heading-meta">
                    <span class="pc-pill"><?=pcHtml($roleLabel)?></span>
                    <span class="pc-user"><?=pcHtml((string)($_SESSION['usuario'] ?? ''))?></span>
                    <a href="../index.php" class="pc-return">← Volver al dashboard</a>
                </div>
            </header>

            <!-- Navegación temporal estándar de TotalXpedient: SIN calendario diario. -->
            <nav class="pc-period-nav" aria-label="Navegación de períodos disponibles">
                <div class="pc-period-context">
                    <strong><?= $selected !== null ? pcHtml($selected['label']) : 'Sin cortes disponibles' ?></strong>
                    <span>Fotografía Qlik · cortes semanales y mensuales, sin selección de días</span>
                </div>
                <div class="pc-period-controls">
                    <div class="pc-period-tabs" role="group" aria-label="Tipo de período">
                        <?php foreach (['SEMANAL'=>'Semanal','MENSUAL'=>'Mensual'] as $kind=>$kindLabel): ?>
                            <?php if (!empty($navCuts[$kind])): $recent=$navCuts[$kind][0]; ?>
                                <a href="<?=pcHtml(pcCutUrl($recent))?>" class="pc-period-btn<?= ($selected!==null && $selected['periodo']===$kind) ? ' is-active' : '' ?>" <?= ($selected!==null && $selected['periodo']===$kind) ? 'aria-current="true"' : '' ?>><?=pcHtml($kindLabel)?></a>
                            <?php else: ?>
                                <span class="pc-period-btn is-disabled" aria-disabled="true" title="Sin cortes <?=pcHtml(strtolower($kindLabel))?> importados"><?=pcHtml($kindLabel)?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="pc-period-steps" role="group" aria-label="Corte anterior, actual y siguiente">
                        <?php if ($navPrevious !== null): ?>
                            <a class="pc-period-btn pc-period-arrow" href="<?=pcHtml(pcCutUrl($navPrevious))?>" title="Corte anterior disponible">← <?=pcHtml(pcCutButtonLabel($navPrevious))?></a>
                        <?php else: ?>
                            <span class="pc-period-btn pc-period-arrow is-disabled" aria-disabled="true">← Anterior</span>
                        <?php endif; ?>
                        <span class="pc-period-btn pc-period-current" aria-current="date"><?= $selected !== null ? pcHtml(pcCutButtonLabel($selected)) : 'Sin datos' ?></span>
                        <?php if ($navNext !== null): ?>
                            <a class="pc-period-btn pc-period-arrow" href="<?=pcHtml(pcCutUrl($navNext))?>" title="Corte siguiente disponible"><?=pcHtml(pcCutButtonLabel($navNext))?> →</a>
                        <?php else: ?>
                            <span class="pc-period-btn pc-period-arrow is-disabled" aria-disabled="true">Siguiente →</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($selected !== null && count($navCuts[$selected['periodo']])>1): ?>
                    <details class="pc-period-history">
                        <summary class="pc-period-btn pc-period-history-toggle">Otros cortes <span aria-hidden="true">⌄</span></summary>
                        <div class="pc-period-history-menu" role="group" aria-label="Cortes importados de <?=pcHtml(strtolower($selected['periodo']))?>">
                            <?php foreach ($navCuts[$selected['periodo']] as $historyCut): ?>
                                <a href="<?=pcHtml(pcCutUrl($historyCut))?>" class="<?= $selected['key']===$historyCut['key'] ? 'is-current' : '' ?>" <?= $selected['key']===$historyCut['key'] ? 'aria-current="true"' : '' ?>><?=pcHtml($historyCut['label'])?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </nav>

            <form class="pc-filters" id="filters" method="get" autocomplete="off">
                <?php if ($selected !== null): ?>
                    <input type="hidden" name="periodo" value="<?=pcHtml($selected['periodo'])?>">
                    <input type="hidden" name="corte" value="<?=pcHtml($selected['key'])?>">
                <?php endif; ?>
                <div class="pc-filter-title"><span>Filtros de análisis</span><small>Vista autorizada: <?=pcHtml($displayScope)?></small></div>
                <div class="pc-filter-grid">
                    <?php if ($rol !== 'director_distrital'): ?>
                    <label>Distrito
                        <select name="distrito" id="distrito">
                            <option value="">Región SUR (5 distritos)</option>
                            <?php foreach ($districts as $district): ?>
                            <option value="<?=pcHtml($district)?>" <?=$scopeDistrict===$district?'selected':''?>><?=pcHtml($district)?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php else: ?>
                    <label>Distrito asignado
                        <input type="text" readonly value="<?=pcHtml((string)$scopeDistrict)?>" aria-label="Distrito restringido por tu perfil">
                    </label>
                    <?php endif; ?>
                    <label>Municipio / localidad
                        <select name="municipio" id="municipio">
                            <option value="">Todos</option>
                            <option value="__PENDIENTE__" <?=$municipio==='__PENDIENTE__'?'selected':''?>>Sin catalogar</option>
                            <?php foreach ($munis as $muni=>$unused): ?>
                            <option value="<?=pcHtml($muni)?>" <?=$municipio===$muni?'selected':''?>><?=pcHtml($muni)?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($parent !== ''): ?>
                    <input type="hidden" name="padre" value="<?=pcHtml($parent)?>">
                    <?php endif; ?>
                    <label>Tipo clúster
                        <select name="tipo_cluster" id="tipo_cluster">
                            <option value="">Todos</option>
                            <option value="NORMAL" <?=$typeCluster==='NORMAL'?'selected':''?>>Clústeres maduros</option>
                            <option value="CLUSTER_A" <?=$typeCluster==='CLUSTER_A'?'selected':''?>>Clústeres_A</option>
                        </select>
                    </label>
                    <label>Nivel de burbuja
                        <select name="nivel" id="nivel">
                            <option value="PADRE" <?=$nivel==='PADRE'?'selected':''?>>Clúster padre (consolidado)</option>
                            <option value="HIJO" <?=$nivel==='HIJO'?'selected':''?>>Clúster hijo (detalle)</option>
                        </select>
                    </label>
                     <div class="pc-threshold-settings" role="group" aria-label="Metas por antigüedad de clústeres A">
                         <span class="pc-threshold-title pc-threshold-a">● Clústeres_A · Hasta 2 años</span>
                         <label>Al cumplir 1 año · Penetración objetivo (%)
                             <input name="a_0_12_pen" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['a_0_12_pen'])?>">
                         </label>
                         <label>0 a 1 año · Velocidad anual referencia (%)
                             <input name="a_0_12_vel" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['a_0_12_vel'])?>">
                         </label>
                         <label>Al cumplir 2 años (antes de Maduro) · Penetración (%)
                             <input name="a_12_24_pen" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['a_12_24_pen'])?>">
                         </label>
                         <label>Al cumplir 2 años (antes de Maduro) · Velocidad anual (%)
                             <input name="a_12_24_vel" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['a_12_24_vel'])?>">
                         </label>
                     </div>
                     <div class="pc-threshold-settings" role="group" aria-label="Metas por antigüedad de clústeres maduros">
                         <span class="pc-threshold-title pc-threshold-normal">● Clústeres maduros · NORMAL</span>
                         <label>Al cumplir 2 años · Penetración (%)
                             <input name="m_24_60_pen" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['m_24_60_pen'])?>">
                         </label>
                         <label>Al cumplir 2 años · Velocidad anual (%)
                             <input name="m_24_60_vel" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['m_24_60_vel'])?>">
                         </label>
                         <label>Al cumplir 5 años y más · Penetración (%)
                             <input name="m_60_plus_pen" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['m_60_plus_pen'])?>">
                         </label>
                         <label>Al cumplir 5 años y más · Velocidad anual (%)
                             <input name="m_60_plus_vel" type="number" min="0" max="100" step="0.1" required value="<?=pcHtml((string)$params['m_60_plus_vel'])?>">
                         </label>
                     </div>
                     <div id="pc-filter-error" class="pc-filter-error" role="alert" hidden></div>
                    <label>HPs mínimos por burbuja
                        <input name="min_hp" type="number" min="0" max="500000" step="1" value="<?=pcHtml((string)$minHP)?>">
                    </label>
                </div>
                <div class="pc-filter-footer">
                    <button type="submit" class="pc-primary">Aplicar filtros</button>
                    <a class="pc-secondary" href="penetracion_clusters.php">Restablecer</a>
                    <span>Metas por antigüedad: Clústeres_A 0–1 año 15% / 7% y 1–2 años 19.9% / 4.9%. Maduros 2–5 años 20% / 5% y más de 5 años 30% / 3%. Evolución gradual interpolada y validación anti-incongruencias.</span>
                </div>
            </form>

            <?php if ($selected===null): ?>
                <section class="pc-empty">Aún no hay cortes de Clusters_BDU disponibles para tu perfil. Revisa la importación.</section>
            <?php else: ?>
            <section class="pc-scope-row">
                <div><strong><?=pcHtml($displayScope)?></strong> · <?=pcHtml($selected['label'])?> · <span class="pc-muted"><?=pcHtml($nivel==='PADRE'?'Burbujas por clúster padre':'Burbujas por clúster hijo')?><?= $typeCluster==='' ? '' : ' · '.pcHtml($typeCluster==='CLUSTER_A' ? 'Filtro: Clústeres_A' : 'Filtro: Clústeres maduros') ?></span></div>
                <?php if ($previous !== null): ?>
                <div class="pc-comparison">Comparativo homogéneo: <?=pcHtml($previous['label'])?> · Δ Penetración <strong><?=pcHtml(pcPp($summary['pen'],$prevAgg['pen']))?></strong></div>
                <?php else: ?>
                <div class="pc-comparison">Comparativo: ND · Falta corte anterior <?=pcHtml(strtolower($selected['periodo']))?></div>
                <?php endif; ?>
            </section>

            <!-- Tarjetas ejecutivas: 3 segmentos, 3 indicadores cada uno -->
            <div class="pc-section-title"><h2>Situación actual · Penetración por segmento</h2><p>Indicadores ponderados sobre bases comparables. La antigüedad es promedio por clúster hijo.</p></div>

            <section class="pc-segment-cards" aria-label="Resumen general y segmentos">
                <?php
                $segmentCards = [
                    ['GENERAL','General · Filtro actual',$summary,'pc-segment-general'],
                ];
                if ($typeCluster === '' || $typeCluster === 'CLUSTER_A') $segmentCards[] = ['CLUSTER_A','Clústeres_A',$young,'pc-segment-a'];
                if ($typeCluster === '' || $typeCluster === 'NORMAL') $segmentCards[] = ['NORMAL','Clústeres maduros',$mature,'pc-segment-normal'];
                foreach ($segmentCards as [$tipoCard,$labelCard,$aggCard,$cardCss]): ?>
                <article class="pc-segment-card <?=pcHtml($cardCss)?>">
                    <h3><?=pcHtml($labelCard)?></h3>
                    <div class="pc-segment-values">
                        <div><span>Penetración</span><strong><?=pcHtml(pcPct($aggCard['pen']))?></strong></div>
                        <div><span>Velocidad anual</span><strong><?=pcHtml(pcPct($aggCard['vel']))?></strong></div>
                        <div><span>Antigüedad prom.</span><strong><?=$aggCard['antiguedad']===null?'ND':pcHtml(pcNum($aggCard['antiguedad'],1).' años')?></strong></div>
                    </div>
                    <small><?=pcHtml(pcNum((float)$aggCard['hp']))?> HPs comparables · <?=$aggCard['padres']?> padres · <?=$aggCard['hijos']?> hijos</small>
                </article>
                <?php endforeach; ?>
            </section>

            <div class="pc-section-title"><h2>Objetivos y brechas de penetración</h2><p>El objetivo general se calcula según antigüedad y consolida Clústeres_A + maduros. Por segmento, los faltantes son la suma de déficits positivos por clúster hijo, sin compensar excedentes.</p></div>
            <section class="pc-objective-cards" aria-label="Objetivos ejecutivos por segmento">
                <?php
                $goalCards = [
                    ['GENERAL','Objetivo general · según antigüedad','pc-segment-general'],
                ];
                if ($typeCluster === '' || $typeCluster === 'CLUSTER_A') $goalCards[] = ['CLUSTER_A','Objetivo Clústeres_A · según antigüedad','pc-segment-a'];
                if ($typeCluster === '' || $typeCluster === 'NORMAL') $goalCards[] = ['NORMAL','Objetivo maduros · según antigüedad','pc-segment-normal'];
                foreach ($goalCards as [$goalKey,$goalLabel,$goalCss]): $goal=$objectiveCards[$goalKey]; ?>
                <article class="pc-objective-card <?=pcHtml($goalCss)?>">
                    <h3><?=pcHtml($goalLabel)?></h3>
                    <div class="pc-obj-grid">
                        <div><span>HPs comparables</span><strong><?=pcHtml(pcNum((float)$goal['hp']))?></strong></div>
                        <div><span>Suscriptores actuales</span><strong><?=pcHtml(pcNum((float)$goal['usuarios']))?></strong></div>
                        <div><span>Suscriptores objetivo</span><strong><?=$goal['meta']===null?'ND':pcHtml(pcNum((float)$goal['meta']))?></strong></div>
                        <div><span>% Alcance</span><strong><?=pcHtml(pcPct($goal['alcance']))?></strong></div>
                        <div class="pc-objective-highlight"><span>Faltan suscriptores</span><strong><?=$goal['faltan']===null?'ND':pcHtml(pcNum((float)$goal['faltan']))?></strong></div>
                        <div><span>Brecha de penetración</span><strong><?=pcHtml(pcGap($goal['brecha']))?></strong></div>
                    </div>
                    <small>Meta ponderada: <?=pcHtml(pcPct($goal['metaPct']))?> · <?=$goalKey==='GENERAL'?'Consolidado real de metas por antigüedad de todos los clústeres':'Suma de déficits positivos por hijo, sin compensaciones'?><?= ($goal['faltanEdad'] ?? 0)>0 ? ' · '.(int)($goal['faltanEdad'] ?? 0).' grupo(s) comparables sin meta válida' : '' ?></small>
                </article>
                <?php endforeach; ?>
            </section>
            <div class="pc-quality-line">Datos completos: <?=count($rows)?> clústeres hijo · <?= $summary['comparables'] ?> con HPs y suscriptores válidos · <strong><?= (int)$summary['sin_catalogo'] ?></strong> municipios pendientes. <span>Fuente Qlik: tipo histórico sin modificar; tipo de gestión basado en la edad real al corte; metas calculadas en motor_clusters.php.</span></div>

            <?php if (count($byDistrict)>0): ?>
            <section class="pc-table-card" aria-label="Desglose jerárquico por distrito">
                <div class="pc-section-head"><div><h2><?= $scopeDistrict===null ? 'Comparativo regional por distrito' : 'Desglose distrital por municipio' ?></h2><p>Despliega cada distrito con la flecha para ver sus municipios; selecciona «Explorar» para consultar el detalle.</p></div></div>
                <div class="pc-table-scroll"><table class="pc-summary-table pc-regional-table" data-sortable="true" id="pc-regional-table" aria-label="Comparativo regional por distrito ordenable">
                    <thead><tr>
                        <?php foreach ([
                            ['distrito','Distrito','text'],
                            ['hp','HPs comparables','number'],
                            ['usuarios','Suscriptores','number'],
                            ['pen','Penetración','number'],
                            ['vel','Velocidad anual','number'],
                            ['metaPen','Meta pen.','number'],
                            ['gapPen','Faltan pen. (pp)','number'],
                            ['faltan','Faltan suscriptores','number'],
                        ] as [$sortKey,$sortLabel,$sortType]): ?>
                            <th scope="col" aria-sort="none"><button type="button" class="pc-sort-btn" data-sort-key="<?=pcHtml($sortKey)?>" data-sort-type="<?=pcHtml($sortType)?>" title="Ordenar por <?=pcHtml($sortLabel)?>">
                                <?=pcHtml($sortLabel)?> <span class="pc-sort-indicator" aria-hidden="true">↕</span>
                            </button></th>
                        <?php endforeach; ?>
                        <th scope="col">Detalle</th>
                    </tr></thead>

                    <tbody>
                    <?php foreach ($byDistrict as $districtName => $districtRows):
                        $ds = pcAgg($districtRows);
                        $districtGoal = $districtOpportunities[$districtName] ?? ['metaPen'=>null,'gapPen'=>null,'faltan'=>null];
                        $districtParams = $_GET;
                        $districtParams['distrito'] = $districtName;
                        $districtParams['periodo'] = $selected['periodo'];
                        $districtParams['corte'] = $selected['key'];
                        unset($districtParams['municipio'], $districtParams['padre']);
                        $districtURL = 'penetracion_clusters.php?'.http_build_query($districtParams,'','&',PHP_QUERY_RFC3986);
                        $municipalityGroups = [];
                        foreach ($districtRows as $dr) {
                            $mkey = trim((string)($dr['municipio'] ?? ''));
                            if ($mkey === '') $mkey = 'Sin catalogar';
                            $municipalityGroups[$mkey][] = $dr;
                        }
                        ksort($municipalityGroups, SORT_NATURAL | SORT_FLAG_CASE);
                        $municipioId = 'pc-muni-'.array_search($districtName, $districts, true);
                    ?>
                    <tr class="pc-district-row">
                        <th<?=pcSortAttr($districtName)?>>
                            <button type="button" class="pc-inline-toggle" aria-expanded="false" aria-controls="<?=pcHtml($municipioId)?>" data-target="<?=pcHtml($municipioId)?>" title="Desplegar municipios de <?=pcHtml($districtName)?>">
                                <span class="pc-chevron" aria-hidden="true">▶</span> <?=pcHtml($districtName)?>
                            </button>
                        </th>
                        <td<?=pcSortAttr($ds['hp'])?>><?=pcHtml(pcNum((float)$ds['hp']))?></td>
                        <td<?=pcSortAttr($ds['usuarios'])?>><?=pcHtml(pcNum((float)$ds['usuarios']))?></td>
                        <td<?=pcSortAttr($ds['pen'])?>><span class="pc-badge <?=pcHtml(pcSemaforoClase($ds['pen'],'pen'))?>"><?=pcHtml(pcPct($ds['pen']))?></span></td>
                        <td<?=pcSortAttr($ds['vel'])?>><span class="pc-badge <?=pcHtml(pcSemaforoClase($ds['vel'],'vel'))?>"><?=pcHtml(pcPct($ds['vel']))?></span></td>
                        <td<?=pcSortAttr($districtGoal['metaPen'] ?? null)?>><?=pcHtml(pcPct($districtGoal['metaPen'] ?? null))?></td>
                        <td<?=pcSortAttr($districtGoal['gapPen'] ?? null)?> class="<?= (($districtGoal['gapPen'] ?? 0) > 0.00001) ? 'pc-gap-alert' : 'pc-gap-ok' ?>"><?=pcHtml(pcGapConBrechas($districtGoal['gapPen'] ?? null,$districtGoal['faltan'] ?? null))?></td>
                        <td<?=pcSortAttr($districtGoal['faltan'] ?? null)?> class="<?= (($districtGoal['faltan'] ?? 0) > 0) ? 'pc-gap-alert' : 'pc-gap-ok' ?>"><strong><?= ($districtGoal['faltan'] ?? null) === null ? 'ND' : (($districtGoal['faltan'] ?? 0) === 0 ? 'Cumple' : pcHtml(pcNum((float)$districtGoal['faltan']))) ?></strong></td>
                        <td><a class="pc-drill-link" href="<?=pcHtml($districtURL)?>">Explorar distrito →</a></td>
                    </tr>
                    <tr class="pc-muni-detail-row" id="<?=pcHtml($municipioId)?>" hidden>
                        <td colspan="9">
                            <div class="pc-muni-list">
                                <table class="pc-muni-table" aria-label="Municipios de <?=pcHtml($districtName)?>">
                                    <thead><tr><th>Municipio / localidad</th><th>HPs</th><th>Suscriptores</th><th>Penetración</th><th>Velocidad anual</th><th>Meta pen.</th><th>Faltan pen. (pp)</th><th>Faltan suscriptores</th><th>Detalle</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($municipalityGroups as $muniName => $muniRows):
                                        $ms = pcAgg($muniRows);
                                        $mGoal = pcOpportunityFromRows($muniRows, $nivel, $params);
                                        $muniParams = $districtParams;
                                        $muniParams['municipio'] = $muniName === 'Sin catalogar' ? '__PENDIENTE__' : $muniName;
                                        $muniUrl = 'penetracion_clusters.php?'.http_build_query($muniParams,'','&',PHP_QUERY_RFC3986);
                                    ?>
                                        <tr>
                                            <th><?=pcHtml($muniName)?></th>
                                            <td><?=pcHtml(pcNum((float)$ms['hp']))?></td>
                                            <td><?=pcHtml(pcNum((float)$ms['usuarios']))?></td>
                                            <td><?=pcHtml(pcPct($ms['pen']))?></td>
                                            <td><?=pcHtml(pcPct($ms['vel']))?></td>
                                            <td><?=pcHtml(pcPct($mGoal['metaPen'] ?? null))?></td>
                                            <td><?=pcHtml(pcGapConBrechas($mGoal['gapPen'] ?? null,$mGoal['faltan'] ?? null))?></td>
                                            <td><?= ($mGoal['faltan'] ?? null) === null ? 'ND' : (($mGoal['faltan'] ?? 0) === 0 ? 'Cumple' : pcHtml(pcNum((float)$mGoal['faltan']))) ?></td>
                                            <td><a class="pc-drill-link" href="<?=pcHtml($muniUrl)?>">Explorar →</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th><?= $scopeDistrict === null ? 'REGIÓN SUR' : pcHtml($scopeDistrict).' · TOTAL' ?></th>
                            <td><?=pcHtml(pcNum((float)$summary['hp']))?></td>
                            <td><?=pcHtml(pcNum((float)$summary['usuarios']))?></td>
                            <td><span class="pc-badge <?=pcHtml(pcSemaforoClase($summary['pen'],'pen'))?>"><?=pcHtml(pcPct($summary['pen']))?></span></td>
                            <td><span class="pc-badge <?=pcHtml(pcSemaforoClase($summary['vel'],'vel'))?>"><?=pcHtml(pcPct($summary['vel']))?></span></td>
                            <td><?=pcHtml(pcPct($regionOpportunityTotal['metaPen'] ?? null))?></td>
                            <td class="<?= (($regionOpportunityTotal['gapPen'] ?? 0) > 0.00001) ? 'pc-gap-alert' : 'pc-gap-ok' ?>"><?=pcHtml(pcGapConBrechas($regionOpportunityTotal['gapPen'] ?? null,$regionOpportunityTotal['faltan'] ?? null))?></td>
                            <td class="<?= (($regionOpportunityTotal['faltan'] ?? 0) > 0) ? 'pc-gap-alert' : 'pc-gap-ok' ?>"><strong><?= ($regionOpportunityTotal['faltan'] ?? null) === null ? 'ND' : (($regionOpportunityTotal['faltan'] ?? 0) === 0 ? 'Cumple' : pcHtml(pcNum((float)$regionOpportunityTotal['faltan']))) ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table></div>
                <p class="pc-fineprint">Meta pen. = Σ(HPs × meta individual por antigüedad) / ΣHPs comparables, para el tipo de clúster seleccionado. Faltan pen. (pp) mide la brecha neta del promedio; «Cumple promedio» puede coexistir con brechas individuales. Faltan suscriptores = suma de déficits positivos por clúster hijo, sin compensar excedentes; concilia entre Región, Distrito y rankings PADRE/HIJO. Clústeres sin HP/base válidos se muestran en detalle, pero no afectan el denominador. Si hay base comparable sin edad validada, la meta se muestra ND.</p>
            </section>
            <?php endif; ?>

            <section class="pc-charts <?= $typeCluster==='' ? '' : 'pc-one-chart' ?>" aria-label="Cuadrantes de penetración y crecimiento">
                <?php if ($typeCluster === '' || $typeCluster === 'CLUSTER_A'): ?>
                <article class="pc-chart-card pc-chart-card-a">
                    <header><div><h2>Clústeres_A</h2><p>Por <?=$nivel==='PADRE'?'padre':'hijo'?> · 0–1 año: X <?=pcHtml(pcPct($limits['CLUSTER_A']['pen']))?> / Y <?=pcHtml(pcPct($limits['CLUSTER_A']['vel']))?> · 1–2 años: X <?=pcHtml(pcPct($limits['CLUSTER_A']['penMax']))?> / Y <?=pcHtml(pcPct($limits['CLUSTER_A']['velMax']))?></p></div><span class="pc-dot" style="--dot:#7A2BFF"></span></header>
                    <div class="pc-svg-area" id="chart-a" role="img" aria-label="Cuadrantes de clústeres A"></div>
                    <p class="pc-chart-caption"><b><?=count($bubbles['CLUSTER_A'])?></b> burbujas graficables · meta individual en tooltip y ranking.</p>
                </article>
                <?php endif; ?>
                <?php if ($typeCluster === '' || $typeCluster === 'NORMAL'): ?>
                <article class="pc-chart-card">
                    <header><div><h2>Clústeres maduros</h2><p>Por <?=$nivel==='PADRE'?'padre':'hijo'?> · 2–5 años: X <?=pcHtml(pcPct($limits['NORMAL']['pen']))?> / Y <?=pcHtml(pcPct($limits['NORMAL']['vel']))?> · &gt;5 años: X <?=pcHtml(pcPct($limits['NORMAL']['penMax']))?> / Y <?=pcHtml(pcPct($limits['NORMAL']['velMax']))?></p></div><span class="pc-dot" style="--dot:#0057FF"></span></header>
                    <div class="pc-svg-area" id="chart-normal" role="img" aria-label="Cuadrantes de clústeres maduros"></div>
                    <p class="pc-chart-caption"><b><?=count($bubbles['NORMAL'])?></b> burbujas graficables · meta individual en tooltip y ranking.</p>
                </article>
                <?php endif; ?>
            </section>
            <div class="pc-legend"><span><i class="pc-legend-bubble"></i> Tamaño de burbuja = HPs válidos</span><span> Eje X = penetración ponderada</span><span> Eje Y = velocidad anual ponderada</span><label><input id="show-labels" type="checkbox" checked> Mostrar etiquetas</label></div>

            <!-- Rankings de brechas independientes, ordenables como Ranking Productividad. -->
            <?php
            $rankingCards = [];
            if ($typeCluster === '' || $typeCluster === 'CLUSTER_A') $rankingCards[] = ['CLUSTER_A','Ranking de brechas · Clústeres_A','Metas progresivas: 15% / 7% en el primer año; 19.9% / 4.9% antes de los 2 años.'];
            if ($typeCluster === '' || $typeCluster === 'NORMAL') $rankingCards[] = ['NORMAL','Ranking de brechas · Clústeres maduros','Metas progresivas: 20% / 5% entre 2 y 5 años; 30% / 3% después de 5 años.'];
            foreach ($rankingCards as [$rankingType,$rankingTitle,$rankingNote]): ?>
            <section class="pc-table-card pc-rank-card" aria-label="<?=pcHtml($rankingTitle)?>">
                <div class="pc-section-head pc-rank-head">
                    <div><h2><?=pcHtml($rankingTitle)?></h2><p><?=pcHtml($rankingNote)?></p></div>
                    <span class="pc-rank-counter"><?=count($targetRankings[$rankingType])?> clústeres <?= $nivel==='PADRE'?'padre':'hijo' ?></span>
                </div>
                <div class="pc-table-scroll">
                <table class="pc-rank-table" data-sortable="true" data-sort-default="faltan" aria-label="Brechas de <?=pcHtml($rankingType)?>">
                    <thead><tr>
                        <?php foreach ([
                            ['distrito','Distrito','text'],['nombre','Clúster','text'],['municipio','Municipio / localidad','text'],
                            ['edad','Antigüedad (años)','number'],['hp','HPs','number'],['usuarios','Suscriptores','number'],
                            ['pen','Penetración','number'],['metaPen','Meta pen.','number'],
                            ['gapPen','Faltan pen. (pp)','number'],['faltan','Faltan suscriptores','number'],
                            ['vel','Vel. anual','number'],['metaVel','Meta vel. anual','number'],
                            ['gapVel','Faltan vel. (pp)','number'],
                        ] as [$key,$label,$kind]): ?>
                        <th scope="col" aria-sort="none"><button type="button" class="pc-sort-btn" data-sort-key="<?=pcHtml($key)?>" data-sort-type="<?=pcHtml($kind)?>" title="Ordenar por <?=pcHtml($label)?>">
                            <?=pcHtml($label)?> <span class="pc-sort-indicator" aria-hidden="true">↕</span>
                        </button></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($targetRankings[$rankingType] as $item):
                        $jump = $_GET;
                        $jump['periodo']=$selected['periodo'];$jump['corte']=$selected['key'];
                        $jump['nivel']='HIJO';$jump['padre']=$item['padre'];
                        if ($rol !== 'director_distrital') $jump['distrito']=$item['distrito'];
                        unset($jump['municipio']);
                        $jumpUrl='penetracion_clusters.php?'.http_build_query($jump,'','&',PHP_QUERY_RFC3986);
                    ?>
                    <tr>
                        <td<?=pcSortAttr($item['distrito'])?>><?=pcHtml($item['distrito'])?></td>
                        <td<?=pcSortAttr($item['nombre'])?>><a class="pc-drill-link" href="<?=pcHtml($jumpUrl)?>" title="Ver hijos del padre <?=pcHtml($item['padre'])?>"><?=pcHtml($item['nombre'])?></a></td>
                        <td<?=pcSortAttr($item['municipio'])?>><?=pcHtml($item['municipio'])?></td>
                        <td<?=pcSortAttr($item['edad'])?>><?= $item['edad']===null?'ND':pcHtml(pcNum($item['edad'],1)) ?></td>
                        <td<?=pcSortAttr($item['hp'])?>><?=pcHtml(pcNum((float)$item['hp']))?></td>
                        <td<?=pcSortAttr($item['usuarios'])?>><?=pcHtml(pcNum((float)$item['usuarios']))?></td>
                        <td<?=pcSortAttr($item['pen'])?>><?=pcHtml(pcPct($item['pen']))?></td>
                        <td<?=pcSortAttr($item['metaPen'])?>><?=pcHtml(pcPct($item['metaPen']))?></td>
                        <td<?=pcSortAttr($item['gapPen'])?> class="<?=($item['gapPen']??0)>0.00001?'pc-gap-alert':'pc-gap-ok'?>"><?=pcHtml(pcGapConBrechas($item['gapPen'],$item['faltan']))?></td>
                        <td<?=pcSortAttr($item['faltan'])?> class="<?=($item['faltan']??0)>0?'pc-gap-alert':'pc-gap-ok'?>"><strong><?= $item['faltan']===null?'ND':($item['faltan']===0?'Cumple':pcHtml(pcNum((float)$item['faltan'])))?></strong></td>
                        <td<?=pcSortAttr($item['vel'])?>><?=pcHtml(pcPct($item['vel']))?></td>
                        <td<?=pcSortAttr($item['metaVel'])?>><?=pcHtml(pcPct($item['metaVel']))?></td>
                        <td<?=pcSortAttr($item['gapVel'])?> class="<?=($item['gapVel']??0)>0.00001?'pc-gap-alert':'pc-gap-ok'?>"><?=pcHtml(pcGap($item['gapVel']))?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="pc-fineprint">
                    <?php if ($rankingType==='CLUSTER_A'): ?>
                    Meta por hijo: penetración progresa de 0% al <?=pcHtml(pcPct($params['a_0_12_pen']))?> en los primeros 12 meses y hasta <?=pcHtml(pcPct($params['a_12_24_pen']))?> antes de 24 meses. Velocidad se mantiene en <?=pcHtml(pcPct($params['a_0_12_vel']))?> hasta 12 meses y decrece linealmente hasta <?=pcHtml(pcPct($params['a_12_24_vel']))?> antes de 24 meses.
                    <?php else: ?>
                    Penetración aumenta linealmente de <?=pcHtml(pcPct($params['m_24_60_pen']))?> a <?=pcHtml(pcPct($params['m_60_plus_pen']))?> entre 2 y 5 años; velocidad anual baja de <?=pcHtml(pcPct($params['m_24_60_vel']))?> a <?=pcHtml(pcPct($params['m_60_plus_vel']))?>. Desde 5 años, las metas quedan constantes.
                    <?php endif; ?>
                    Faltantes: suma de brechas por hijo sin compensación entre clústeres. Registros sin HPs/base, antigüedad o velocidad aparecen como ND; hacer clic en los encabezados permite ordenar ascendente/descendente.
                </p>
            </section>
            <?php endforeach; ?>

            <section class="pc-table-card" aria-label="Resumen por segmento">
                <div class="pc-section-head"><div><h2>Resumen ejecutivo</h2><p>Agregados sobre bases comparables; El tipo histórico Qlik se conserva; la gestión se asigna por edad validada (A <24 meses, Maduro ≥24 meses).</p></div></div>
                <div class="pc-table-scroll"><table class="pc-summary-table">
                    <thead><tr><th>Segmento</th><th>HPs comparables</th><th>Suscriptores</th><th>Penetración</th><th>Velocidad anual</th><th>Padres</th><th>Hijos</th><th>Antigüedad prom.</th></tr></thead>
                    <tbody>
                    <?php
                    $summaryCards = [['Región / distrito (filtro actual)',$summary]];
                    if ($typeCluster === '' || $typeCluster === 'CLUSTER_A') $summaryCards[] = ['CLUSTER_A',$young];
                    if ($typeCluster === '' || $typeCluster === 'NORMAL') $summaryCards[] = ['Maduros (NORMAL)',$mature];
                    foreach ($summaryCards as [$label,$s]): ?>
                    <tr><th><?=pcHtml($label)?></th><td><?=pcHtml(pcNum((float)$s['hp']))?></td><td><?=pcHtml(pcNum((float)$s['usuarios']))?></td><td><?=pcHtml(pcPct($s['pen']))?></td><td><?=pcHtml(pcPct($s['vel']))?></td><td><?=$s['padres']?></td><td><?=$s['hijos']?></td><td><?= $s['antiguedad']===null ? 'ND' : pcHtml(pcNum($s['antiguedad'],1).' años') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="pc-fineprint">ARPU BDU no se muestra porque ese indicador no existe en Clusters_BDU v2. La velocidad es el dato de Qlik ponderado por HPs, no un crecimiento calculado entre dos snapshots. La clasificación de gestión usa la edad validada; se conserva por separado el tipo original de Qlik y se auditan las diferencias. Para el agregado de penetración se usa Σ suscriptores / Σ HPs únicamente donde ambas bases son válidas.</p>
            </section>

            <section class="pc-table-card" aria-label="Detalle por clúster">
                <div class="pc-section-head"><div><h2>Detalle de clústeres hijo</h2><p>Drill-down Región → Distrito → Padre → Hijo, con catálogo geográfico independiente.</p></div><div class="pc-search"><input type="search" id="pc-search" placeholder="Buscar distrito, localidad o clúster" aria-label="Buscar clúster"></div></div>
                <div class="pc-table-scroll"><table class="pc-detail-table" id="pc-detail-table">
                    <thead><tr><th>Distrito</th><th>Municipio / localidad</th><th>Clúster padre</th><th>Clúster hijo</th><th>Tipo gestión / Qlik</th><th>Liberación</th><th>Edad (meses)</th><th>HPs</th><th>Suscriptores</th><th>Penetración</th><th>Meta pen.</th><th>Vel. anual</th><th>Meta vel. anual</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $hp = $r['casas_pasadas']!==null ? (int)$r['casas_pasadas'] : null;
                        $u = $r['base_usuarios']!==null ? (int)$r['base_usuarios'] : null;
                        $valid = ($hp !== null && $hp > 0 && $u !== null);
                        $hasV = $r['velocidad_penetracion_anual']!==null;
                        $loc = trim((string)($r['municipio']??''));
                    ?>
                    <tr><td><?=pcHtml((string)$r['distrito'])?></td>
                        <td class="<?= $loc===''?'pc-null':'' ?>"><?= $loc===''?'Sin catalogar':pcHtml($loc) ?></td>
                        <td><?=pcHtml((string)$r['cluster_padre'])?></td><td><b><?=pcHtml((string)$r['cluster_hijo'])?></b></td>
                        <td><span class="pc-tag <?=($r['tipo_gestion']??$r['tipo'])==='NORMAL'?'pc-tag-normal':'pc-tag-a'?>"><?=pcHtml((string)($r['tipo_gestion']??$r['tipo']))?></span><?php if ($r['tipo']!==($r['tipo_gestion']??$r['tipo'])): ?><small class="pc-source-note">Qlik: <?=pcHtml((string)$r['tipo'])?></small><?php endif; ?></td>
                        <td><?=pcHtml((string)($r['fecha_liberacion']??'')) ?: 'ND' ?></td>
                        <td title="<?=pcHtml((string)($r['etapa_etiqueta']??''))?>"><?=$r['edad_meses_gestion']===null?'ND':pcHtml(pcNum($r['edad_meses_gestion'],1))?></td>
                        <td><?=$hp===null?'ND':pcHtml(pcNum((float)$hp))?></td><td><?=$u===null?'ND':pcHtml(pcNum((float)$u))?></td>
                        <td><?=$valid?pcHtml(pcPct(100.0*$u/$hp)):'ND'?></td>
                        <td><?=pcHtml(pcPct($r['meta_pen_gestion'] ?? null))?></td>
                        <td><?=$hasV?pcHtml(pcPct(100.0*(float)$r['velocidad_penetracion_anual'])):'ND'?></td>
                        <td><?=pcHtml(pcPct($r['meta_vel_gestion'] ?? null))?></td>
                        <td><?=!$valid?'Sin base':(!$hasV?'Sin velocidad':'Comparable')?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="pc-table-footer"><span id="pc-table-count"><?=count($rows)?> registros</span><span>Los registros sin base, sin velocidad o sin antigüedad permanecen visibles; las metas por edad se muestran como ND cuando no pueden certificarse.</span></div>
            </section>
            <?php endif; ?>
            <footer class="pc-footer">TotalXpedient · Penetración de Clústeres v1.7.0 · Datos Qlik por fotografía semanal o mensual · Acceso jerárquico por rol.</footer>
        </div>
    </main>
</div>
<?php if ($selected!==null): ?>
<script id="pc-chart-data" type="application/json"><?=$chartJson?></script>
<script src="../assets/js/penetracion_clusters.js?v=1.7.0" defer></script>
<?php endif; ?>
</body>
</html>
