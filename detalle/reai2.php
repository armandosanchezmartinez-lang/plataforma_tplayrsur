<?php
ini_set('display_errors', 0);
error_reporting(0);
header("Cache-Control: no-cache, no-store, must-revalidate");
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

include '../conexion.php';

$rol              = $_SESSION['rol'] ?? 'vendedor';
$id_posicion      = $_SESSION['id_posicion'] ?? '';
$talento_gs_coach = $_SESSION['numero_talento_gs'] ?? '';
$puestos_comerciales = ['PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'];
$puestos_in = "'" . implode("','", $puestos_comerciales) . "'";
$puede_capturar = in_array($rol, ['admin','director_regional','director_distrital','lider','coach'], true);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_num($v, $d = 0) { return number_format((float)($v ?? 0), $d); }
function fmt_prod($v) { return number_format((float)($v ?? 0), 2); }
function fmt_meta($v) { return number_format((float)($v ?? 0), 0); }
function table_exists($conexion, $table) {
    $table = mysqli_real_escape_string($conexion, $table);
    $res = mysqli_query($conexion, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}
function contar_dias_habiles($conexion, $fecha_inicio, $fecha_fin) {
    if (!$fecha_inicio || !$fecha_fin) return 1;
    $inicio = new DateTime($fecha_inicio);
    $fin    = new DateTime($fecha_fin);
    if ($inicio > $fin) { $tmp = $inicio; $inicio = $fin; $fin = $tmp; }

    $festivos = [];
    if (table_exists($conexion, 'dias_inhabiles')) {
        $fi = mysqli_real_escape_string($conexion, $inicio->format('Y-m-d'));
        $ff = mysqli_real_escape_string($conexion, $fin->format('Y-m-d'));
        $res = mysqli_query($conexion, "SELECT fecha FROM dias_inhabiles WHERE activo = 1 AND fecha BETWEEN '$fi' AND '$ff'");
        while ($res && $row = mysqli_fetch_assoc($res)) $festivos[$row['fecha']] = true;
    }

    $habiles = 0;
    for ($d = clone $inicio; $d <= $fin; $d->modify('+1 day')) {
        $fecha = $d->format('Y-m-d');
        if ((int)$d->format('N') === 7) continue; // domingo
        if (isset($festivos[$fecha])) continue;
        $habiles++;
    }
    return max(1, $habiles);
}
function fecha_iso_inicio($anio, $semana) {
    $d = new DateTime();
    $d->setISODate((int)$anio, (int)$semana, 1);
    return $d;
}
function periodo_label($periodo, $base, $actual) {
    if ($periodo === 'mensual') {
        $meses = [1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];
        return [$meses[(int)date('n', strtotime($base['inicio']))], $meses[(int)date('n', strtotime($actual['inicio']))]];
    }
    return ['SEM'.$base['semana'], 'SEM'.$actual['semana']];
}

function normaliza_key($v) {
    $v = strtoupper(trim((string)$v));
    $v = str_replace(['Á','É','Í','Ó','Ú','Ñ'], ['A','E','I','O','U','N'], $v);
    $v = preg_replace('/[^A-Z0-9]+/', ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}
function meta_mensual_estandar($distrito, $canal, $metal = 'ORO', $metas_estandar = []) {
    $d = normaliza_key($distrito);
    $c = normaliza_key($canal);
    $m = normaliza_key($metal ?: 'ORO');

    if ($c === '' || $c === 'TODOS' || $c === 'ALL' || $c === '*') $c = '*';

    // Prioridad:
    // 1) Distrito + canal exacto + metal.
    // 2) Distrito + TODOS + metal.
    // 3) DEFAULT + TODOS + metal.
    if (isset($metas_estandar[$d.'|'.$c.'|'.$m])) return (float)$metas_estandar[$d.'|'.$c.'|'.$m];
    if (isset($metas_estandar[$d.'|*|'.$m])) return (float)$metas_estandar[$d.'|*|'.$m];
    if (isset($metas_estandar['DEFAULT|*|'.$m])) return (float)$metas_estandar['DEFAULT|*|'.$m];

    // Sin meta estándar configurada en tabla: no se quema meta en código.
    return 0.0;
}

function accion_sugerida_reai($reai_total, $prod_3m, $prod_dia, $pct_alcance, $dif, $pct_dif, $inst_actual) {
    // Prioridad de evaluación:
    // 1) PROD. 3M
    // 2) PROD DÍA
    // 3) % ALCANCE
    // 4) DIF %
    // Nunca se sugiere I; Incidencia es sólo documental/informativa.
    if ((int)$reai_total > 0) return ['SEG', 'status-seg'];

    $p3m_roja      = $prod_3m < .40;
    $p3m_amarilla  = $prod_3m >= .40 && $prod_3m < .70;
    $p3m_verde     = $prod_3m >= .70;

    $pd_roja       = $prod_dia < .40;
    $pd_amarilla   = $prod_dia >= .40 && $prod_dia < .70;
    $pd_verde      = $prod_dia >= .70;

    $alcance_bajo  = $pct_alcance < 80;
    $alcance_medio = $pct_alcance >= 80 && $pct_alcance < 100;
    $alcance_ok    = $pct_alcance >= 100;

    $caida         = $dif < 0;
    $caida_fuerte  = ($pct_dif <= -50 || $dif <= -2);

    // Histórico sano: no sancionar por una mala lectura aislada.
    // Sólo se sugiere R si además hay deterioro y bajo alcance.
    if ($p3m_verde) {
        if (($pd_roja || $pd_amarilla) && $alcance_bajo && $caida) return ['R', 'status-alert'];
        return ['OK', 'status-ok'];
    }

    // Histórico medio: escalar según productividad actual y alcance.
    if ($p3m_amarilla) {
        if ($pd_roja && $alcance_bajo && ($caida || $inst_actual == 0)) return ['E', 'status-risk'];
        if (($pd_roja || $pd_amarilla || $alcance_medio || $alcance_bajo || $caida)) return ['R', 'status-alert'];
        return ['OK', 'status-ok'];
    }

    // Histórico rojo: aquí sí hay patrón sostenido.
    if ($p3m_roja) {
        if ($pd_roja && $alcance_bajo && ($caida_fuerte || $inst_actual == 0)) return ['A', 'status-risk'];
        if ($pd_roja && $alcance_bajo) return ['E', 'status-risk'];
        if ($pd_amarilla || $alcance_medio || $alcance_bajo || $caida) return ['R', 'status-alert'];
        return ['OK', 'status-ok'];
    }

    return ['OK', 'status-ok'];
}

// ── HISTORIAL AJAX ────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'historial') {
    $talento = mysqli_real_escape_string($conexion, $_GET['talento_gs'] ?? '');
    $res = mysqli_query($conexion, "SELECT * FROM reai WHERE numero_talento_gs = '$talento' ORDER BY fecha DESC, created_at DESC");
    $registros = [];
    while ($res && $row = mysqli_fetch_assoc($res)) $registros[] = $row;
    header('Content-Type: application/json');
    echo json_encode($registros);
    exit();
}

// ── GUARDAR REAI ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puede_capturar && isset($_POST['action']) && $_POST['action'] === 'guardar') {
    $talento_vendedor = mysqli_real_escape_string($conexion, $_POST['numero_talento_gs'] ?? '');
    $nombre_vendedor  = mysqli_real_escape_string($conexion, $_POST['nombre_colaborador'] ?? '');
    $asunto           = $_POST['asunto'] ?? '';
    $fecha            = $_POST['fecha'] ?? '';
    $descripcion      = mysqli_real_escape_string($conexion, $_POST['descripcion'] ?? '');
    $evidencia_nombre = '';
    $asuntos_validos  = ['Retroalimentación','ECNUs','Acta Administrativa','Incidencia'];

    if (!in_array($asunto, $asuntos_validos, true)) {
        echo json_encode(['status'=>'error','msg'=>'Asunto no válido']); exit();
    }
    if (!empty($_FILES['evidencia']['name'])) {
        $ext     = pathinfo($_FILES['evidencia']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg','jpeg','png','pdf','doc','docx'];
        if (in_array(strtolower($ext), $allowed, true)) {
            $upload_dir = '../uploads/reai/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $nombre_archivo = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['evidencia']['name']);
            if (move_uploaded_file($_FILES['evidencia']['tmp_name'], $upload_dir . $nombre_archivo)) {
                $evidencia_nombre = $nombre_archivo;
            }
        } else {
            echo json_encode(['status'=>'error','msg'=>'Formato no permitido']); exit();
        }
    }
    $asunto_esc = mysqli_real_escape_string($conexion, $asunto);
    $sql = "INSERT INTO reai (numero_talento_gs, nombre_colaborador, asunto, fecha, descripcion, evidencia, capturado_por, talento_gs_coach, id_posicion_coach)
            VALUES ('$talento_vendedor','$nombre_vendedor','$asunto_esc','$fecha','$descripcion','$evidencia_nombre','$talento_gs_coach','$talento_gs_coach','$id_posicion')";
    echo mysqli_query($conexion, $sql)
        ? json_encode(['status'=>'ok','msg'=>'Registro guardado correctamente'])
        : json_encode(['status'=>'error','msg'=>'Error: '.mysqli_error($conexion)]);
    exit();
}

// ── PERÍODO SEMANAL / MENSUAL ────────────────────────────────────────────────
$periodo = $_GET['periodo'] ?? 'semanal';
if (!in_array($periodo, ['semanal','mensual'], true)) $periodo = 'semanal';

$ultima_fecha = date('Y-m-d', strtotime('-1 day'));
$res_uf = mysqli_query($conexion, "SELECT MAX(fecha) AS ultima_fecha FROM instalaciones WHERE fecha IS NOT NULL AND fecha <= CURDATE()");
if ($res_uf && $row_uf = mysqli_fetch_assoc($res_uf)) {
    if (!empty($row_uf['ultima_fecha'])) $ultima_fecha = $row_uf['ultima_fecha'];
}

$periodo_base = [];
$periodo_actual = [];
if ($periodo === 'mensual') {
    $anio_act = (int)date('Y', strtotime($ultima_fecha));
    $mes_act  = (int)date('n', strtotime($ultima_fecha));
    $dia_fin  = (int)date('j', strtotime($ultima_fecha));

    $inicio_actual = sprintf('%04d-%02d-01', $anio_act, $mes_act);
    $fin_actual    = sprintf('%04d-%02d-%02d', $anio_act, $mes_act, $dia_fin);

    $prev = strtotime($inicio_actual . ' -1 month');
    $anio_base = (int)date('Y', $prev);
    $mes_base  = (int)date('n', $prev);
    $ultimo_dia_base = (int)date('t', $prev);
    $dia_fin_base = min($dia_fin, $ultimo_dia_base);
    $inicio_base = sprintf('%04d-%02d-01', $anio_base, $mes_base);
    $fin_base    = sprintf('%04d-%02d-%02d', $anio_base, $mes_base, $dia_fin_base);

    $periodo_base   = ['inicio'=>$inicio_base, 'fin'=>$fin_base];
    $periodo_actual = ['inicio'=>$inicio_actual, 'fin'=>$fin_actual];
} else {
    $anio_act = (int)date('o', strtotime($ultima_fecha));
    $sem_act  = (int)date('W', strtotime($ultima_fecha));
    $sem_inicio = fecha_iso_inicio($anio_act, $sem_act);
    $dow_ultima = (int)date('N', strtotime($ultima_fecha));
    $fin_actual_dt = clone $sem_inicio;
    $fin_actual_dt->modify('+'.($dow_ultima - 1).' days');

    $base_inicio = clone $sem_inicio;
    $base_inicio->modify('-1 week');
    $base_fin = clone $base_inicio;
    $base_fin->modify('+'.($dow_ultima - 1).' days');

    $periodo_actual = ['inicio'=>$sem_inicio->format('Y-m-d'), 'fin'=>$fin_actual_dt->format('Y-m-d'), 'semana'=>$sem_act, 'anio'=>$anio_act];
    $periodo_base   = ['inicio'=>$base_inicio->format('Y-m-d'), 'fin'=>$base_fin->format('Y-m-d'), 'semana'=>(int)$base_inicio->format('W'), 'anio'=>(int)$base_inicio->format('o')];
}

$dias_habiles_base   = contar_dias_habiles($conexion, $periodo_base['inicio'], $periodo_base['fin']);
$dias_habiles_actual = contar_dias_habiles($conexion, $periodo_actual['inicio'], $periodo_actual['fin']);

// PROD. 3M: últimos 3 meses completos anteriores al mes actual del corte operativo.
// Ejemplo: si el corte está en junio, considera marzo 01 a mayo 31 completos.
$fecha_3m_inicio = date('Y-m-01', strtotime($ultima_fecha . ' -3 months'));
$fecha_3m_fin    = date('Y-m-t', strtotime($ultima_fecha . ' -1 month'));
$dias_habiles_3m = contar_dias_habiles($conexion, $fecha_3m_inicio, $fecha_3m_fin);

[$label_base, $label_actual] = periodo_label($periodo, $periodo_base, $periodo_actual);

// Semana de referencia para buscar meta semanal capturada en ejecución operativa.
$anio_meta_actual   = (int)date('o', strtotime($ultima_fecha));
$semana_meta_actual = (int)date('W', strtotime($ultima_fecha));
$sem_meta_inicio_dt = fecha_iso_inicio($anio_meta_actual, $semana_meta_actual);
$sem_meta_fin_dt    = clone $sem_meta_inicio_dt;
$sem_meta_fin_dt->modify('+6 days');
$dias_habiles_semana_meta = contar_dias_habiles($conexion, $sem_meta_inicio_dt->format('Y-m-d'), $sem_meta_fin_dt->format('Y-m-d'));

// Días hábiles del mes completo para convertir meta mensual estándar a diaria.
$mes_actual_inicio_meta = date('Y-m-01', strtotime($periodo_actual['inicio']));
$mes_actual_fin_meta    = date('Y-m-t', strtotime($periodo_actual['inicio']));
$dias_habiles_mes_actual_total = contar_dias_habiles($conexion, $mes_actual_inicio_meta, $mes_actual_fin_meta);

// Tabla de metas estándar por distrito, canal y metal.
// Para REAI se toma por ahora el metal ORO del distrito correspondiente.
$metal_reai_default = 'ORO';
$metas_estandar = [];
if (table_exists($conexion, 'reai_metas_estandar')) {
    $res_me = mysqli_query($conexion, "SELECT distrito, canal_venta, metal, meta_mensual FROM reai_metas_estandar WHERE activo = 1");
    while ($res_me && $row_me = mysqli_fetch_assoc($res_me)) {
        $dk = normaliza_key($row_me['distrito'] ?? '');
        $ck = normaliza_key($row_me['canal_venta'] ?? '');
        $mk = normaliza_key($row_me['metal'] ?? $metal_reai_default);
        if ($ck === '' || $ck === 'TODOS' || $ck === 'ALL' || $ck === '*') $ck = '*';
        if ($mk === '') $mk = normaliza_key($metal_reai_default);
        if ($dk !== '') {
            $metas_estandar[$dk . '|' . $ck . '|' . $mk] = (float)($row_me['meta_mensual'] ?? 0);
        }
    }
}

// Última semana HC para plantilla / jerarquía
$semana_hc = null; $anio_hc = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_hc = (int)$row_sem['semana'];
    $anio_hc   = (int)$row_sem['anio'];
}

// ── OBTENER COLABORADORES SEGÚN JERARQUÍA ────────────────────────────────────
// REAI jerárquico:
// ADMIN / DIRECCIÓN REGIONAL ve 4 bloques en la misma matriz: Directores Distritales, Líderes, Coaches y Vendedores.
// Director Distrital ve: Líderes, Coaches y Vendedores de su distrito/línea.
// Líder ve: Coaches y Vendedores de su línea.
// Coach ve: Vendedores de su línea.
$vendedores = [];
$hc_rows = [];
$children_by_lr = [];
$by_id_posicion = [];
$by_talento = [];

function es_puesto_vendedor_reai($posicion, $puestos_comerciales) {
    return in_array(trim((string)$posicion), $puestos_comerciales, true);
}
function es_director_distrital_reai($row) {
    $p = normaliza_key($row['posicion'] ?? '');
    return strpos($p, 'DIRECTOR DISTRITAL') !== false;
}
function inferir_nivel_reai($row, $puestos_comerciales) {
    if (es_puesto_vendedor_reai($row['posicion'] ?? '', $puestos_comerciales)) return 'VENDEDOR';
    if (es_director_distrital_reai($row)) return 'DIRECTOR DISTRITAL';
    $p = normaliza_key($row['posicion'] ?? '');
    if (strpos($p, 'COACH') !== false) return 'COACH';
    if (strpos($p, 'LIDER') !== false || strpos($p, 'GERENTE') !== false) return 'LÍDER';
    return 'COLABORADOR';
}
function obtener_vendedores_descendientes_reai($id_posicion, &$children_by_lr, $puestos_comerciales, &$memo = []) {
    $key = (string)$id_posicion;
    if (isset($memo[$key])) return $memo[$key];
    $out = [];
    foreach ($children_by_lr[$key] ?? [] as $child) {
        if (es_puesto_vendedor_reai($child['posicion'] ?? '', $puestos_comerciales)) {
            if (!empty($child['numero_talento_gs'])) $out[] = (string)$child['numero_talento_gs'];
        } else {
            $out = array_merge($out, obtener_vendedores_descendientes_reai($child['id_posicion'] ?? '', $children_by_lr, $puestos_comerciales, $memo));
        }
    }
    $out = array_values(array_unique(array_filter($out)));
    $memo[$key] = $out;
    return $out;
}
function crear_fila_reai($row, $nivel, $child_talentos = []) {
    $antig = 0;
    if (!empty($row['fecha_alta']) && $row['fecha_alta'] !== '0000-00-00') {
        try {
            $fa = new DateTime($row['fecha_alta']);
            $hoy = new DateTime();
            $antig = max(0, ($hoy->format('Y') - $fa->format('Y')) * 12 + ($hoy->format('n') - $fa->format('n')));
        } catch (Exception $e) { $antig = 0; }
    }
    return [
        'nombre_colaborador' => $row['nombre_colaborador'] ?? '',
        'numero_talento_gs'  => (string)($row['numero_talento_gs'] ?? ''),
        'id_posicion'        => (string)($row['id_posicion'] ?? ''),
        'posicion_lr'        => (string)($row['posicion_lr'] ?? ''),
        'fecha_alta'         => $row['fecha_alta'] ?? null,
        'distrito'           => $row['distrito'] ?? '',
        'canal_venta'        => $row['posicion'] ?? '',
        'posicion'           => $row['posicion'] ?? '',
        'antiguedad'         => $antig,
        'nivel_reai'         => $nivel,
        'child_talentos'     => array_values(array_unique(array_filter($child_talentos))),
    ];
}
function ordenar_por_nombre_reai(&$arr) {
    usort($arr, function($a, $b) { return strcasecmp($a['nombre_colaborador'] ?? '', $b['nombre_colaborador'] ?? ''); });
}

if ($semana_hc && $anio_hc) {
    $sql_hc = "SELECT nombre_colaborador, numero_talento_gs, id_posicion, posicion_lr, posicion, distrito, fecha_alta
               FROM hc
               WHERE semana = ? AND anio = ?
                 AND numero_talento_gs NOT LIKE '%VACANTE%'
                 AND nombre_colaborador NOT LIKE '%VACANTE%'";
    $stmt_hc = mysqli_prepare($conexion, $sql_hc);
    mysqli_stmt_bind_param($stmt_hc, "ii", $semana_hc, $anio_hc);
    mysqli_stmt_execute($stmt_hc);
    $res_hc = mysqli_stmt_get_result($stmt_hc);
    while ($r = mysqli_fetch_assoc($res_hc)) {
        $r['id_posicion'] = (string)($r['id_posicion'] ?? '');
        $r['posicion_lr'] = (string)($r['posicion_lr'] ?? '');
        $r['numero_talento_gs'] = (string)($r['numero_talento_gs'] ?? '');
        $hc_rows[] = $r;
        if ($r['id_posicion'] !== '') $by_id_posicion[$r['id_posicion']] = $r;
        if ($r['numero_talento_gs'] !== '') $by_talento[$r['numero_talento_gs']] = $r;
        $children_by_lr[$r['posicion_lr']][] = $r;
    }
    mysqli_stmt_close($stmt_hc);

    $memo_desc = [];
    $agregar_fila = function($row, $nivel) use (&$vendedores, &$children_by_lr, $puestos_comerciales, &$memo_desc) {
        if (empty($row['numero_talento_gs'])) return;
        if ($nivel === 'VENDEDOR') {
            $childs = [(string)$row['numero_talento_gs']];
        } else {
            $childs = obtener_vendedores_descendientes_reai($row['id_posicion'] ?? '', $children_by_lr, $puestos_comerciales, $memo_desc);
        }
        // Se muestra el puesto aunque temporalmente no tenga vendedores descendientes; sus métricas quedan en cero.
        $vendedores[] = crear_fila_reai($row, $nivel, $childs);
    };

    $directores = array_values(array_filter($hc_rows, function($r) { return es_director_distrital_reai($r); }));
    ordenar_por_nombre_reai($directores);

    if ($rol === 'admin' || $rol === 'director_regional') {
        // 1) Línea directa: Directores Distritales.
        foreach ($directores as $dd) $agregar_fila($dd, 'DIRECTOR DISTRITAL');

        // 2) Línea indirecta: Líderes, Coaches y Vendedores.
        $lideres = [];
        foreach ($directores as $dd) {
            foreach ($children_by_lr[(string)$dd['id_posicion']] ?? [] as $l) {
                if (!es_puesto_vendedor_reai($l['posicion'] ?? '', $puestos_comerciales)) $lideres[] = $l;
            }
        }
        ordenar_por_nombre_reai($lideres);
        foreach ($lideres as $l) $agregar_fila($l, 'LÍDER');

        $coaches = [];
        foreach ($lideres as $l) {
            foreach ($children_by_lr[(string)$l['id_posicion']] ?? [] as $c) {
                if (!es_puesto_vendedor_reai($c['posicion'] ?? '', $puestos_comerciales)) $coaches[] = $c;
            }
        }
        ordenar_por_nombre_reai($coaches);
        foreach ($coaches as $c) $agregar_fila($c, 'COACH');

        $vend_rows = [];
        foreach ($coaches as $c) {
            foreach ($children_by_lr[(string)$c['id_posicion']] ?? [] as $v) {
                if (es_puesto_vendedor_reai($v['posicion'] ?? '', $puestos_comerciales)) $vend_rows[] = $v;
            }
        }
        ordenar_por_nombre_reai($vend_rows);
        foreach ($vend_rows as $v) $agregar_fila($v, 'VENDEDOR');
    } elseif ($rol === 'director_distrital') {
        $lideres = [];
        foreach ($children_by_lr[(string)$id_posicion] ?? [] as $l) {
            if (!es_puesto_vendedor_reai($l['posicion'] ?? '', $puestos_comerciales)) $lideres[] = $l;
        }
        ordenar_por_nombre_reai($lideres);
        foreach ($lideres as $l) $agregar_fila($l, 'LÍDER');

        $coaches = [];
        foreach ($lideres as $l) {
            foreach ($children_by_lr[(string)$l['id_posicion']] ?? [] as $c) {
                if (!es_puesto_vendedor_reai($c['posicion'] ?? '', $puestos_comerciales)) $coaches[] = $c;
            }
        }
        ordenar_por_nombre_reai($coaches);
        foreach ($coaches as $c) $agregar_fila($c, 'COACH');

        $vend_rows = [];
        foreach ($coaches as $c) {
            foreach ($children_by_lr[(string)$c['id_posicion']] ?? [] as $v) {
                if (es_puesto_vendedor_reai($v['posicion'] ?? '', $puestos_comerciales)) $vend_rows[] = $v;
            }
        }
        ordenar_por_nombre_reai($vend_rows);
        foreach ($vend_rows as $v) $agregar_fila($v, 'VENDEDOR');
    } elseif ($rol === 'lider') {
        $coaches = [];
        foreach ($children_by_lr[(string)$id_posicion] ?? [] as $c) {
            if (!es_puesto_vendedor_reai($c['posicion'] ?? '', $puestos_comerciales)) $coaches[] = $c;
        }
        ordenar_por_nombre_reai($coaches);
        foreach ($coaches as $c) $agregar_fila($c, 'COACH');

        $vend_rows = [];
        foreach ($coaches as $c) {
            foreach ($children_by_lr[(string)$c['id_posicion']] ?? [] as $v) {
                if (es_puesto_vendedor_reai($v['posicion'] ?? '', $puestos_comerciales)) $vend_rows[] = $v;
            }
        }
        ordenar_por_nombre_reai($vend_rows);
        foreach ($vend_rows as $v) $agregar_fila($v, 'VENDEDOR');
    } elseif ($rol === 'coach') {
        $vend_rows = [];
        foreach ($children_by_lr[(string)$id_posicion] ?? [] as $v) {
            if (es_puesto_vendedor_reai($v['posicion'] ?? '', $puestos_comerciales)) $vend_rows[] = $v;
        }
        ordenar_por_nombre_reai($vend_rows);
        foreach ($vend_rows as $v) $agregar_fila($v, 'VENDEDOR');
    } else {
        if (isset($by_talento[(string)$talento_gs_coach])) $agregar_fila($by_talento[(string)$talento_gs_coach], inferir_nivel_reai($by_talento[(string)$talento_gs_coach], $puestos_comerciales));
    }
}

// ── MÉTRICAS POR NIVEL / COLABORADOR ───────────────────────────────────────
$stats = [];
if (!empty($vendedores)) {
    $talentos_reai = array_values(array_unique(array_filter(array_map(function($v) { return (string)($v['numero_talento_gs'] ?? ''); }, $vendedores))));
    $talentos_metricas = [];
    foreach ($vendedores as $row) {
        foreach (($row['child_talentos'] ?? []) as $tv) $talentos_metricas[] = (string)$tv;
    }
    $talentos_metricas = array_values(array_unique(array_filter($talentos_metricas)));

    $fi_base = $periodo_base['inicio'];
    $ff_base = $periodo_base['fin'];
    $fi_act  = $periodo_actual['inicio'];
    $ff_act  = $periodo_actual['fin'];

    $metricas_vendedor = [];
    if (!empty($talentos_metricas)) {
        $phm = implode(',', array_fill(0, count($talentos_metricas), '?'));
        $tiposm = str_repeat('s', count($talentos_metricas));

        $stmt_ib = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) AS total FROM instalaciones WHERE fecha BETWEEN ? AND ? AND folio_empleado IN ($phm) GROUP BY folio_empleado");
        mysqli_stmt_bind_param($stmt_ib, 'ss'.$tiposm, $fi_base, $ff_base, ...array_values($talentos_metricas));
        mysqli_stmt_execute($stmt_ib);
        $res_ib = mysqli_stmt_get_result($stmt_ib);
        while ($r = mysqli_fetch_assoc($res_ib)) $metricas_vendedor[$r['folio_empleado']]['inst_base'] = (int)$r['total'];
        mysqli_stmt_close($stmt_ib);

        $stmt_ia = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) AS total FROM instalaciones WHERE fecha BETWEEN ? AND ? AND folio_empleado IN ($phm) GROUP BY folio_empleado");
        mysqli_stmt_bind_param($stmt_ia, 'ss'.$tiposm, $fi_act, $ff_act, ...array_values($talentos_metricas));
        mysqli_stmt_execute($stmt_ia);
        $res_ia = mysqli_stmt_get_result($stmt_ia);
        while ($r = mysqli_fetch_assoc($res_ia)) $metricas_vendedor[$r['folio_empleado']]['inst_actual'] = (int)$r['total'];
        mysqli_stmt_close($stmt_ia);

        $stmt_i3m = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) AS total FROM instalaciones WHERE fecha BETWEEN ? AND ? AND folio_empleado IN ($phm) GROUP BY folio_empleado");
        mysqli_stmt_bind_param($stmt_i3m, 'ss'.$tiposm, $fecha_3m_inicio, $fecha_3m_fin, ...array_values($talentos_metricas));
        mysqli_stmt_execute($stmt_i3m);
        $res_i3m = mysqli_stmt_get_result($stmt_i3m);
        while ($r = mysqli_fetch_assoc($res_i3m)) $metricas_vendedor[$r['folio_empleado']]['inst_3m'] = (int)$r['total'];
        mysqli_stmt_close($stmt_i3m);
    }

    // Conteo REAI por el talento mostrado en la fila: Director/Líder/Coach/Vendedor.
    if (!empty($talentos_reai)) {
        $phr = implode(',', array_fill(0, count($talentos_reai), '?'));
        $tiposr = str_repeat('s', count($talentos_reai));
        $stmt_rc = mysqli_prepare($conexion, "SELECT numero_talento_gs, asunto, COUNT(*) AS total, MAX(fecha) AS ultima_fecha FROM reai WHERE numero_talento_gs IN ($phr) GROUP BY numero_talento_gs, asunto");
        mysqli_stmt_bind_param($stmt_rc, $tiposr, ...array_values($talentos_reai));
        mysqli_stmt_execute($stmt_rc);
        $res_rc = mysqli_stmt_get_result($stmt_rc);
        while ($r = mysqli_fetch_assoc($res_rc)) {
            $t = (string)$r['numero_talento_gs'];
            $stats[$t]['reai'][$r['asunto']] = (int)$r['total'];
            $stats[$t]['reai_total'] = ($stats[$t]['reai_total'] ?? 0) + (int)$r['total'];
            if (empty($stats[$t]['ultima_reai']) || $r['ultima_fecha'] > $stats[$t]['ultima_reai']) $stats[$t]['ultima_reai'] = $r['ultima_fecha'];
        }
        mysqli_stmt_close($stmt_rc);
    }

    // Metas EO por vendedor: para niveles superiores se suman las metas de sus vendedores descendientes.
    $metas_eo_vendedor = [];
    if (!empty($talentos_metricas) && table_exists($conexion, 'ejecucion_operativa_metas')) {
        $nombres_por_talento = [];
        foreach ($hc_rows as $hr) {
            if (in_array((string)($hr['numero_talento_gs'] ?? ''), $talentos_metricas, true)) {
                $nombres_por_talento[(string)$hr['numero_talento_gs']] = $hr['nombre_colaborador'] ?? '';
            }
        }
        $nombres_vend = array_values(array_unique(array_filter(array_values($nombres_por_talento))));
        $ph_t = implode(',', array_fill(0, count($talentos_metricas), '?'));
        $tipos_t = str_repeat('s', count($talentos_metricas));
        $ph_n = !empty($nombres_vend) ? implode(',', array_fill(0, count($nombres_vend), '?')) : "''";
        $tipos_n = str_repeat('s', count($nombres_vend));

        $sql_meta = "SELECT id_subordinado, nombre_subordinado, meta_asignada
                     FROM ejecucion_operativa_metas
                     WHERE anio = ?
                       AND semana = ?
                       AND nivel_subordinado = 'VENDEDOR'
                       AND (id_subordinado IN ($ph_t)" . (!empty($nombres_vend) ? " OR nombre_subordinado IN ($ph_n)" : "") . ")";
        $stmt_meta = mysqli_prepare($conexion, $sql_meta);
        if ($stmt_meta) {
            if (!empty($nombres_vend)) {
                mysqli_stmt_bind_param($stmt_meta, 'ii'.$tipos_t.$tipos_n, $anio_meta_actual, $semana_meta_actual, ...array_values($talentos_metricas), ...array_values($nombres_vend));
            } else {
                mysqli_stmt_bind_param($stmt_meta, 'ii'.$tipos_t, $anio_meta_actual, $semana_meta_actual, ...array_values($talentos_metricas));
            }
            mysqli_stmt_execute($stmt_meta);
            $res_meta = mysqli_stmt_get_result($stmt_meta);
            while ($r = mysqli_fetch_assoc($res_meta)) {
                $meta_val = (int)($r['meta_asignada'] ?? 0);
                $id_sub = (string)($r['id_subordinado'] ?? '');
                if ($id_sub !== '' && in_array($id_sub, $talentos_metricas, true)) {
                    $metas_eo_vendedor[$id_sub] = max($metas_eo_vendedor[$id_sub] ?? 0, $meta_val);
                    continue;
                }
                $nom_sub = normaliza_key($r['nombre_subordinado'] ?? '');
                foreach ($nombres_por_talento as $tg => $nom) {
                    if (normaliza_key($nom) === $nom_sub) $metas_eo_vendedor[$tg] = max($metas_eo_vendedor[$tg] ?? 0, $meta_val);
                }
            }
            mysqli_stmt_close($stmt_meta);
        }
    }

    // Consolidado por fila visible.
    foreach ($vendedores as $row) {
        $tgs_row = (string)$row['numero_talento_gs'];
        $childs = $row['child_talentos'] ?? [];
        $stats[$tgs_row]['inst_base'] = 0;
        $stats[$tgs_row]['inst_actual'] = 0;
        $stats[$tgs_row]['inst_3m'] = 0;
        $stats[$tgs_row]['meta_semanal_eo'] = 0;
        $stats[$tgs_row]['meta_prorrateada'] = 0;

        foreach ($childs as $tv) {
            $stats[$tgs_row]['inst_base']   += (int)($metricas_vendedor[$tv]['inst_base'] ?? 0);
            $stats[$tgs_row]['inst_actual'] += (int)($metricas_vendedor[$tv]['inst_actual'] ?? 0);
            $stats[$tgs_row]['inst_3m']     += (int)($metricas_vendedor[$tv]['inst_3m'] ?? 0);
            $stats[$tgs_row]['meta_semanal_eo'] += (int)($metas_eo_vendedor[$tv] ?? 0);
        }

        if ($stats[$tgs_row]['meta_semanal_eo'] > 0) {
            $meta_diaria_row = $stats[$tgs_row]['meta_semanal_eo'] / max(1, $dias_habiles_semana_meta);
            $stats[$tgs_row]['meta_prorrateada'] = (int)round($meta_diaria_row * $dias_habiles_actual, 0);
        } else {
            // Si no existe meta EO, se suman metas estándar de cada vendedor descendiente.
            $meta_mensual_sum = 0.0;
            foreach ($childs as $tv) {
                $hr = $by_talento[$tv] ?? [];
                $meta_mensual_sum += meta_mensual_estandar($hr['distrito'] ?? ($row['distrito'] ?? ''), $hr['posicion'] ?? '', $metal_reai_default, $metas_estandar);
            }
            $meta_diaria_row = $meta_mensual_sum / max(1, $dias_habiles_mes_actual_total);
            $stats[$tgs_row]['meta_prorrateada'] = (int)round($meta_diaria_row * $dias_habiles_actual, 0);
        }
    }
}

$total_hc = count($vendedores);
$total_con_reai = 0;
$total_sin_reai = 0;
$total_riesgo = 0;
foreach ($vendedores as $vend) {
    $tgs = $vend['numero_talento_gs'];
    $nivel_tmp = $vend['nivel_reai'] ?? 'VENDEDOR';
    $hc_activo_tmp = max(1, count($vend['child_talentos'] ?? []));
    $st = $stats[$tgs] ?? [];
    $inst_base = $st['inst_base'] ?? 0;
    $inst_actual = $st['inst_actual'] ?? 0;
    $reai_total = $st['reai_total'] ?? 0;
    if ($reai_total > 0) $total_con_reai++; else $total_sin_reai++;

    // REAI v2.1: productividad alineada a Ranking.
    // Vendedor: INS / días hábiles. Niveles jerárquicos: INS / HC activo / días hábiles.
    $prod_actual_tmp = $dias_habiles_actual > 0
        ? round($inst_actual / (($nivel_tmp === 'VENDEDOR') ? 1 : $hc_activo_tmp) / $dias_habiles_actual, 2)
        : 0;

    $inst_3m_tmp = (int)($st['inst_3m'] ?? 0);
    $prod_3m_tmp = $dias_habiles_3m > 0 ? round($inst_3m_tmp / $dias_habiles_3m, 2) : 0;
    $dif_tmp = $inst_actual - $inst_base;
    $pct_tmp = $inst_base > 0 ? round(($dif_tmp / $inst_base) * 100, 0) : ($inst_actual > 0 ? 100 : 0);
    $pct_alcance_tmp = 0;
    $meta_prorrateada_tmp = (int)($st['meta_prorrateada'] ?? 0);
    if ($meta_prorrateada_tmp > 0) $pct_alcance_tmp = round(($inst_actual / $meta_prorrateada_tmp) * 100, 0);

    [$accion_tmp, $accion_cls_tmp] = accion_sugerida_reai($reai_total, $prod_3m_tmp, $prod_actual_tmp, $pct_alcance_tmp, $dif_tmp, $pct_tmp, $inst_actual);
    if ($accion_tmp !== 'OK' && $accion_tmp !== 'SEG') $total_riesgo++;
}

$niveles_filtro_reai = ['TODOS'];
if ($rol === 'admin' || $rol === 'director_regional') {
    $niveles_filtro_reai = ['TODOS','DIRECTOR DISTRITAL','LÍDER','COACH','VENDEDOR'];
} elseif ($rol === 'director_distrital') {
    $niveles_filtro_reai = ['TODOS','LÍDER','COACH','VENDEDOR'];
} elseif ($rol === 'lider') {
    $niveles_filtro_reai = ['TODOS','COACH','VENDEDOR'];
} elseif ($rol === 'coach') {
    $niveles_filtro_reai = ['TODOS','VENDEDOR'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REAI v2.1 — TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=161">
    <style>
        body.page-reai .modal-overlay.active{display:flex !important;}
        body.page-reai .modal-box{max-width:560px;max-height:90vh;overflow-y:auto;}
        body.page-reai .modal-header{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:20px;}
        body.page-reai .modal-close{background:none;border:none;font-size:1.4rem;color:var(--text2);cursor:pointer;line-height:1;}
        body.page-reai .form-group{margin-bottom:16px;text-align:left;}
        body.page-reai .form-group label{display:block;font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
        body.page-reai .form-group select,
        body.page-reai .form-group input,
        body.page-reai .form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(122,43,255,.14);border-radius:12px;background:rgba(245,247,255,.85);color:var(--text);font-size:.9rem;outline:none;}
        body.page-reai .form-group textarea{resize:vertical;min-height:90px;}
        body.page-reai .btn-primary{width:100%;padding:12px;border:none;border-radius:14px;background:var(--grad-main);color:white;font-size:.92rem;font-weight:800;cursor:pointer;}
        body.page-reai .btn-primary:disabled{opacity:.6;cursor:not-allowed;}
        body.page-reai .historial-item{border:1px solid rgba(122,43,255,.12);border-radius:14px;padding:14px;margin-bottom:10px;background:rgba(255,255,255,.62);text-align:left;}
        body.page-reai .historial-header{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;}
        body.page-reai .historial-asunto{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.74rem;font-weight:800;}
        body.page-reai .asunto-r{background:#DBEAFE;color:#1D4ED8;}
        body.page-reai .asunto-e{background:#FEF3C7;color:#92400E;}
        body.page-reai .asunto-a{background:#FEE2E2;color:#991B1B;}
        body.page-reai .asunto-i{background:#F3E8FF;color:#6B21A8;}
        body.page-reai .historial-fecha,body.page-reai .historial-desc,body.page-reai .historial-evidencia a{font-size:.8rem;}
        body.page-reai .historial-fecha{color:var(--text2);}
        body.page-reai .historial-evidencia a{color:#7A2BFF;text-decoration:none;font-weight:700;}
        body.page-reai .divider{border:none;border-top:1px solid rgba(122,43,255,.12);margin:20px 0;}
        body.page-reai .section-label{font-size:.76rem;color:var(--text2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;font-weight:800;text-align:left;}
        body.page-reai .toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;font-size:.85rem;font-weight:700;z-index:9999;display:none;color:white;box-shadow:var(--shadow);}
        body.page-reai .toast.show{display:block;}
        body.page-reai .toast.success{background:#065F46;}
        body.page-reai .toast.error{background:#991B1B;}
        body.page-reai .empty-state{text-align:center;padding:48px;color:var(--text2);}
        body.page-reai .kpi-mini-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 16px;}
        body.page-reai .kpi-mini{background:rgba(255,255,255,.72);border:1px solid rgba(122,43,255,.10);border-radius:18px;padding:14px 16px;box-shadow:0 12px 30px rgba(19,32,64,.05);}
        body.page-reai .kpi-mini-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);font-weight:800;}
        body.page-reai .kpi-mini-val{font-size:1.45rem;font-weight:900;margin-top:4px;color:var(--text);}
        body.page-reai .toolbar-v2{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px;}
        body.page-reai .period-tabs{display:flex;gap:8px;background:rgba(255,255,255,.65);border-radius:16px;padding:6px;border:1px solid rgba(122,43,255,.10);}
        body.page-reai .period-tabs a{padding:8px 13px;border-radius:12px;text-decoration:none;font-size:.78rem;font-weight:900;color:var(--text2);}
        body.page-reai .period-tabs a.active{background:var(--grad-main);color:white;}
        body.page-reai .status-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:.72rem;font-weight:900;white-space:nowrap;}
        body.page-reai .status-ok{background:#DCFCE7;color:#166534;}
        body.page-reai .status-seg{background:#FEF3C7;color:#92400E;}
        body.page-reai .status-alert{background:#FFEDD5;color:#9A3412;}
        body.page-reai .status-risk{background:#FEE2E2;color:#991B1B;}
        body.page-reai .prod-pill{display:inline-flex;min-width:46px;justify-content:center;padding:5px 8px;border-radius:999px;font-weight:900;font-size:.74rem;}
        body.page-reai .prod-good{background:#DCFCE7;color:#166534;}
        body.page-reai .prod-mid{background:#FEF3C7;color:#92400E;}
        body.page-reai .prod-bad{background:#FEE2E2;color:#991B1B;}
        body.page-reai .pct-up{color:#059669;font-weight:900;}
        body.page-reai .pct-down{color:#DC2626;font-weight:900;}
        body.page-reai .pct-flat{color:var(--text2);font-weight:900;}

        /* Ajuste v3: tabla más compacta y headers ordenables */
        body.page-reai .table-card{border-radius:16px;overflow:hidden;}
        body.page-reai .table-card table{table-layout:fixed;width:100%;}
        body.page-reai .table-card th{padding:9px 10px;font-size:.68rem;line-height:1.05;white-space:nowrap;}
        body.page-reai .table-card td{padding:8px 10px;font-size:.76rem;line-height:1.12;vertical-align:middle;}
        body.page-reai .table-card tbody tr{height:46px;}
        body.page-reai .table-card th:nth-child(1), body.page-reai .table-card td:nth-child(1){width:20%;}
        body.page-reai .table-card th:nth-child(2), body.page-reai .table-card td:nth-child(2){width:5%;}
        body.page-reai .table-card th:nth-child(3), body.page-reai .table-card td:nth-child(3){width:5%;}
        body.page-reai .table-card th:nth-child(4), body.page-reai .table-card td:nth-child(4){width:9%;}
        body.page-reai .table-card th:nth-child(5), body.page-reai .table-card td:nth-child(5){width:6%;}
        body.page-reai .table-card th:nth-child(6), body.page-reai .table-card td:nth-child(6){width:6%;}
        body.page-reai .table-card th:nth-child(7), body.page-reai .table-card td:nth-child(7){width:8%;}
        body.page-reai .table-card th:nth-child(8), body.page-reai .table-card td:nth-child(8){width:7%;}
        body.page-reai .table-card th:nth-child(9), body.page-reai .table-card td:nth-child(9){width:7%;}
        body.page-reai .table-card th:nth-child(10), body.page-reai .table-card td:nth-child(10){width:8%;}
        body.page-reai .table-card th:nth-child(11), body.page-reai .table-card td:nth-child(11){width:6%;}
        body.page-reai .table-card th:nth-child(12), body.page-reai .table-card td:nth-child(12){width:13%;}
        body.page-reai .table-card .sub-text{font-size:.62rem;line-height:1;margin-top:1px;}
        body.page-reai .reai-badge{min-width:24px;height:24px;padding:0 6px;margin:0 1px;font-size:.68rem;border-radius:8px;}
        body.page-reai .prod-pill{min-width:42px;padding:4px 7px;font-size:.7rem;}
        body.page-reai .meta-pill{display:inline-flex;min-width:42px;justify-content:center;padding:4px 7px;border-radius:999px;font-weight:900;font-size:.7rem;background:#EEF2FF;color:#3730A3;}
        body.page-reai .alcance-good{color:#059669;font-weight:900;}
        body.page-reai .alcance-mid{color:#D97706;font-weight:900;}
        body.page-reai .alcance-bad{color:#DC2626;font-weight:900;}
        body.page-reai .status-pill{padding:5px 8px;font-size:.68rem;}

        body.page-reai .nivel-filter-tabs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:rgba(255,255,255,.65);border-radius:16px;padding:6px;border:1px solid rgba(122,43,255,.10);}
        body.page-reai .nivel-filter-tabs button{border:none;background:transparent;padding:8px 13px;border-radius:12px;font-size:.78rem;font-weight:900;color:var(--text2);cursor:pointer;}
        body.page-reai .nivel-filter-tabs button.active{background:var(--grad-main);color:white;}
        body.page-reai .hc-pill{display:inline-flex;min-width:34px;justify-content:center;padding:4px 7px;border-radius:999px;font-weight:900;font-size:.7rem;background:#F1F5F9;color:#334155;}
        body.page-reai .group-row td{background:linear-gradient(90deg, rgba(122,43,255,.12), rgba(0,164,255,.08));color:#1F2A44;font-weight:900;text-transform:uppercase;letter-spacing:.5px;font-size:.72rem;padding:10px 12px;border-top:1px solid rgba(122,43,255,.14);border-bottom:1px solid rgba(122,43,255,.10);}
        body.page-reai .nivel-chip{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;background:#EEF2FF;color:#3730A3;font-size:.58rem;font-weight:900;margin-left:6px;vertical-align:middle;}
        body.page-reai th.sortable{cursor:pointer;user-select:none;position:relative;}
        body.page-reai th.sortable::after{content:'↕';font-size:.62rem;margin-left:6px;color:var(--text2);opacity:.55;}
        body.page-reai th.sortable.sort-asc::after{content:'↑';opacity:1;color:#7A2BFF;}
        body.page-reai th.sortable.sort-desc::after{content:'↓';opacity:1;color:#7A2BFF;}
        @media(max-width:1100px){body.page-reai .kpi-mini-row{grid-template-columns:repeat(2,minmax(0,1fr));}.toolbar-v2{align-items:flex-start;flex-direction:column;}}
    </style>
</head>
<body class="page-reai">
<?php
$current_page = 'reai';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <h2>Seguimiento REAI</h2>
        <p>
            <?= $periodo === 'mensual' ? 'Vista mensual' : 'Vista semanal' ?> ·
            <?= date('d/m/Y', strtotime($periodo_base['inicio'])) ?> - <?= date('d/m/Y', strtotime($periodo_base['fin'])) ?> vs
            <?= date('d/m/Y', strtotime($periodo_actual['inicio'])) ?> - <?= date('d/m/Y', strtotime($periodo_actual['fin'])) ?> ·
            <?php if ($puede_capturar): ?><span style="color:#059669;font-weight:700;">✓ Captura habilitada</span><?php else: ?><span style="color:var(--text2);">Solo visualización</span><?php endif; ?>
        </p>
    </div>

    <div class="kpi-mini-row">
        <div class="kpi-mini"><div class="kpi-mini-label">HC visible</div><div class="kpi-mini-val"><?= fmt_num($total_hc) ?></div></div>
        <div class="kpi-mini"><div class="kpi-mini-label">Con REAI</div><div class="kpi-mini-val" style="color:#7A2BFF;"><?= fmt_num($total_con_reai) ?></div></div>
        <div class="kpi-mini"><div class="kpi-mini-label">Sin REAI</div><div class="kpi-mini-val" style="color:#64748B;"><?= fmt_num($total_sin_reai) ?></div></div>
        <div class="kpi-mini"><div class="kpi-mini-label">Requiere atención</div><div class="kpi-mini-val" style="color:#DC2626;"><?= fmt_num($total_riesgo) ?></div></div>
    </div>

    <?php if (empty($vendedores)): ?>
        <div class="table-card"><div class="empty-state">No se encontraron colaboradores.</div></div>
    <?php else: ?>

    <div class="toolbar-v2">
        <div class="search-bar">
            <input type="text" class="search-input" id="buscador" placeholder="Buscar colaborador, nivel o distrito..." oninput="filtrarTabla()">
        </div>
        <div class="nivel-filter-tabs" id="nivelTabs" aria-label="Filtro por nivel jerárquico">
            <?php foreach ($niveles_filtro_reai as $nf): ?>
                <button type="button" class="<?= $nf === 'TODOS' ? 'active' : '' ?>" data-nivel-filter="<?= h($nf) ?>" onclick="setFiltroNivel('<?= h($nf) ?>', this)"><?= h($nf === 'TODOS' ? 'Todos' : $nf) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="period-tabs">
            <a class="<?= $periodo === 'semanal' ? 'active' : '' ?>" href="?periodo=semanal">Semanal</a>
            <a class="<?= $periodo === 'mensual' ? 'active' : '' ?>" href="?periodo=mensual">Mensual</a>
        </div>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th class="left sortable" data-sort="text">Nombre / Nivel</th>
                    <th class="sortable" data-sort="num">Antig.</th>
                    <th class="sortable" data-sort="num">HC</th>
                    <th class="sortable" data-sort="num">Meta Prorrateada</th>
                    <th class="sortable" data-sort="num"><?= h($label_base) ?></th>
                    <th class="sortable" data-sort="num"><?= h($label_actual) ?></th>
                    <th class="sortable" data-sort="num">Dif %</th>
                    <th class="sortable" data-sort="num">Prod Día</th>
                    <th class="sortable" data-sort="num">% Alcance</th>
                    <th class="sortable" data-sort="num">Prod. 3M</th>
                    <th class="sortable" data-sort="text">Acción</th>
                    <th class="sep">REAI</th>
                </tr>
            </thead>
            <tbody id="tablaBody">
            <?php $grupo_actual_reai = null; ?>
            <?php foreach ($vendedores as $vend):
                $nivel_reai = $vend['nivel_reai'] ?? 'VENDEDOR';
                if ($grupo_actual_reai !== $nivel_reai):
                    $grupo_actual_reai = $nivel_reai;
                    $grupo_label = ($nivel_reai === 'DIRECTOR DISTRITAL') ? 'Línea Directa · Directores Distritales' : (($nivel_reai === 'LÍDER') ? 'Línea Indirecta · Líderes de Venta' : (($nivel_reai === 'COACH') ? 'Línea Indirecta · Coaches de Venta' : 'Línea Indirecta · Vendedores'));
            ?>
            <tr class="group-row" data-group-row="1"><td colspan="12"><?= h($grupo_label) ?></td></tr>
            <?php endif; ?>
            <?php
                $tgs       = $vend['numero_talento_gs'];
                $nombre    = $vend['nombre_colaborador'];
                $antig     = (int)($vend['antiguedad'] ?? 0);
                $st        = $stats[$tgs] ?? [];
                $inst_base = (int)($st['inst_base'] ?? 0);
                $inst_act  = (int)($st['inst_actual'] ?? 0);
                $dif       = $inst_act - $inst_base;
                $pct       = $inst_base > 0 ? round(($dif / $inst_base) * 100, 0) : ($inst_act > 0 ? 100 : 0);
                $hc_activo = max(1, count($vend['child_talentos'] ?? []));
                // REAI v2.1: productividad alineada a Ranking.
                // Vendedor = INS / días hábiles; Coach/Líder/Director = INS / HC activo / días hábiles.
                $prod      = $dias_habiles_actual > 0 ? round($inst_act / (($nivel_reai === 'VENDEDOR') ? 1 : $hc_activo) / $dias_habiles_actual, 2) : 0;
                $prod_cls  = $prod >= .70 ? 'prod-good' : ($prod >= .40 ? 'prod-mid' : 'prod-bad');
                $inst_3m   = (int)($st['inst_3m'] ?? 0);
                $prod_3m   = $dias_habiles_3m > 0 ? round($inst_3m / $dias_habiles_3m, 2) : 0;
                $prod_3m_cls = $prod_3m >= .70 ? 'prod-good' : ($prod_3m >= .40 ? 'prod-mid' : 'prod-bad');

                // Meta prorrateada consolidada según nivel visible.
                $meta_prorrateada = (int)($st['meta_prorrateada'] ?? 0);
                $pct_alcance = $meta_prorrateada > 0 ? round(($inst_act / $meta_prorrateada) * 100, 0) : 0;
                $alcance_cls = $pct_alcance >= 100 ? 'alcance-good' : ($pct_alcance >= 80 ? 'alcance-mid' : 'alcance-bad');

                $pct_cls   = $pct > 0 ? 'pct-up' : ($pct < 0 ? 'pct-down' : 'pct-flat');
                $reai      = $st['reai'] ?? [];
                $cnt_r     = $reai['Retroalimentación'] ?? 0;
                $cnt_e     = $reai['ECNUs'] ?? 0;
                $cnt_a     = $reai['Acta Administrativa'] ?? 0;
                $cnt_i     = $reai['Incidencia'] ?? 0;
                $reai_total = (int)($st['reai_total'] ?? 0);

                // Acción sugerida: prioridad PROD. 3M > PROD DÍA > % ALCANCE > DIF %.
                // Se muestran etiquetas cortas para no desplazar la columna REAI.
                [$estatus_txt, $estatus_cls] = accion_sugerida_reai($reai_total, $prod_3m, $prod, $pct_alcance, $dif, $pct, $inst_act);
            ?>
            <tr data-nombre="<?= strtolower(h($nombre . ' ' . $nivel_reai . ' ' . ($vend['distrito'] ?? ''))) ?>" data-nivel="<?= h($nivel_reai) ?>">
                <td class="left" data-sort-value="<?= h($nombre) ?>">
                    <div style="font-weight:600;">
                        <a href="detalle_vendedor.php?tgs=<?= urlencode($tgs) ?>&periodo=<?= urlencode($periodo) ?>" style="color:var(--blue);text-decoration:none;font-weight:700;" title="Ver detalle del vendedor">
                            <?= h($nombre) ?>
                        </a>
                        <span class="nivel-chip"><?= h($nivel_reai) ?></span>
                    </div>
                    <div class="sub-text"><?= h($tgs) ?> · <?= h($vend['distrito'] ?? '') ?></div>
                </td>
                <td data-sort-value="<?= $antig ?>"><span style="font-weight:800;"><?= $antig ?></span> <span class="sub-text">m</span></td>
                <td data-sort-value="<?= $hc_activo ?>"><span class="hc-pill" title="HC activo a cargo"><?= fmt_num($hc_activo) ?></span></td>
                <td data-sort-value="<?= $meta_prorrateada ?>"><span class="meta-pill"><?= fmt_meta($meta_prorrateada) ?></span></td>
                <td data-sort-value="<?= $inst_base ?>"><span style="font-weight:900;"><?= fmt_num($inst_base) ?></span></td>
                <td data-sort-value="<?= $inst_act ?>"><span style="font-weight:900;"><?= fmt_num($inst_act) ?></span></td>
                <td data-sort-value="<?= $pct ?>"><span class="<?= $pct_cls ?>"><?= $dif >= 0 ? '+' : '' ?><?= fmt_num($dif) ?> / <?= $pct >= 0 ? '+' : '' ?><?= fmt_num($pct) ?>%</span></td>
                <td data-sort-value="<?= $prod ?>"><span class="prod-pill <?= $prod_cls ?>"><?= fmt_prod($prod) ?></span></td>
                <td data-sort-value="<?= $pct_alcance ?>"><span class="<?= $alcance_cls ?>"><?= fmt_num($pct_alcance) ?>%</span></td>
                <td data-sort-value="<?= $prod_3m ?>"><span class="prod-pill <?= $prod_3m_cls ?>" title="<?= date('d/m/Y', strtotime($fecha_3m_inicio)) ?> - <?= date('d/m/Y', strtotime($fecha_3m_fin)) ?> · Inst: <?= fmt_num($inst_3m) ?> · DH: <?= fmt_num($dias_habiles_3m) ?>"><?= fmt_prod($prod_3m) ?></span></td>
                <td data-sort-value="<?= h($estatus_txt) ?>"><span class="status-pill <?= $estatus_cls ?>"><?= h($estatus_txt) ?></span></td>
                <td class="sep">
                    <?php
                    $asuntos_map = [
                        'R' => ['Retroalimentación',   $cnt_r],
                        'E' => ['ECNUs',               $cnt_e],
                        'A' => ['Acta Administrativa', $cnt_a],
                        'I' => ['Incidencia',           $cnt_i],
                    ];
                    foreach ($asuntos_map as $letra => [$asunto_val, $cnt]):
                        $tgs_js    = addslashes($tgs);
                        $nombre_js = addslashes($nombre);
                        $asunto_js = addslashes($asunto_val);
                    ?>
                        <?php if ($puede_capturar): ?>
                            <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'can-add' ?>"
                                onclick="abrirModal('<?= $tgs_js ?>','<?= $nombre_js ?>','<?= $asunto_js ?>')"
                                title="<?= $cnt > 0 ? h($asunto_val.' ('.$cnt.')') : h('Agregar '.$asunto_val) ?>">
                                <?= $cnt > 0 ? $letra.$cnt : $letra ?>
                            </button>
                        <?php else: ?>
                            <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'no-data' ?>"
                                <?= $cnt > 0 ? "onclick=\"abrirModal('$tgs_js','$nombre_js','$asunto_js')\"" : 'disabled' ?>>
                                <?= $cnt > 0 ? $letra.$cnt : $letra ?>
                            </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="modalOverlay" onclick="cerrarModal(event)">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle"></div>
            <button class="modal-close" onclick="cerrarModalBtn()">×</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
let currentTalento = '', currentNombre = '', currentAsunto = '';
let filtroNivel = 'TODOS';
const puedeCapturar = <?= $puede_capturar ? 'true' : 'false' ?>;
const asuntoColors = {
    'Retroalimentación':'asunto-r','ECNUs':'asunto-e',
    'Acta Administrativa':'asunto-a','Incidencia':'asunto-i'
};
const endpointActual = window.location.pathname.split('/').pop() || '';

function setFiltroNivel(nivel, btn) {
    filtroNivel = nivel || 'TODOS';
    document.querySelectorAll('#nivelTabs button').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filtrarTabla();
}

function filtrarTabla() {
    const q = document.getElementById('buscador').value.toLowerCase();
    const rows = Array.from(document.querySelectorAll('#tablaBody tr'));
    rows.forEach(tr => {
        if (tr.dataset.groupRow === '1') return;
        const n = tr.dataset.nombre || '';
        const nivel = tr.dataset.nivel || '';
        const matchTexto = q === '' || n.includes(q);
        const matchNivel = filtroNivel === 'TODOS' || nivel === filtroNivel;
        tr.classList.toggle('hidden', !(matchTexto && matchNivel));
    });
    // Oculta encabezados de grupo sin filas visibles debajo.
    rows.forEach((tr, idx) => {
        if (tr.dataset.groupRow !== '1') return;
        let visible = false;
        for (let i = idx + 1; i < rows.length; i++) {
            if (rows[i].dataset.groupRow === '1') break;
            if (!rows[i].classList.contains('hidden')) { visible = true; break; }
        }
        tr.classList.toggle('hidden', !visible);
    });
}

function inicializarOrdenamiento() {
    document.querySelectorAll('th.sortable').forEach((th, index) => {
        th.addEventListener('click', () => ordenarTabla(index, th.dataset.sort || 'text', th));
    });
}
function ordenarTabla(colIndex, tipo, th) {
    const tbody = document.getElementById('tablaBody');
    if (!tbody) return;
    const asc = th.dataset.order !== 'asc';
    document.querySelectorAll('th.sortable').forEach(h => { h.classList.remove('sort-asc','sort-desc'); h.dataset.order = ''; });
    th.dataset.order = asc ? 'asc' : 'desc';
    th.classList.add(asc ? 'sort-asc' : 'sort-desc');

    const rows = Array.from(tbody.querySelectorAll('tr'));
    const groups = [];
    let current = null;
    rows.forEach(row => {
        if (row.dataset.groupRow === '1') {
            current = {header: row, rows: []};
            groups.push(current);
        } else if (current) {
            current.rows.push(row);
        }
    });
    const cmp = (a, b) => {
        const ca = a.children[colIndex];
        const cb = b.children[colIndex];
        let va = ca?.dataset.sortValue ?? ca?.innerText ?? '';
        let vb = cb?.dataset.sortValue ?? cb?.innerText ?? '';
        if (tipo === 'num') {
            va = parseFloat(String(va).replace(/,/g,''));
            vb = parseFloat(String(vb).replace(/,/g,''));
            va = Number.isFinite(va) ? va : 0;
            vb = Number.isFinite(vb) ? vb : 0;
            return asc ? va - vb : vb - va;
        }
        va = String(va).toLowerCase();
        vb = String(vb).toLowerCase();
        return asc ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
    };
    groups.forEach(g => {
        tbody.appendChild(g.header);
        g.rows.sort(cmp).forEach(row => tbody.appendChild(row));
    });
    filtrarTabla();
}
document.addEventListener('DOMContentLoaded', function() {
    inicializarOrdenamiento();
    const colActual = 5; // columna actual: JUN / SEM actual; índice 0-based considerando nueva columna HC
    const thActual = document.querySelectorAll('th.sortable')[colActual];
    if (thActual) {
        thActual.dataset.order = 'asc'; // fuerza primer clic programático a descendente
        ordenarTabla(colActual, thActual.dataset.sort || 'num', thActual);
    }
});
function abrirModal(talento, nombre, asunto) {
    currentTalento = talento; currentNombre = nombre; currentAsunto = asunto;
    document.getElementById('modalTitle').textContent = nombre + ' — ' + asunto;
    document.getElementById('modalOverlay').classList.add('active');
    document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text2);">Cargando...</div>';

    fetch(endpointActual + '?action=historial&talento_gs=' + encodeURIComponent(talento))
        .then(r => r.json())
        .then(data => {
            const filtrados = data.filter(r => r.asunto === asunto);
            let html = '';
            if (filtrados.length > 0) {
                html += `<div class="section-label">Historial (${filtrados.length})</div>`;
                filtrados.forEach(r => {
                    html += `<div class="historial-item">
                        <div class="historial-header">
                            <span class="historial-asunto ${asuntoColors[r.asunto]||''}">${r.asunto}</span>
                            <span class="historial-fecha">${r.fecha}</span>
                        </div>
                        <div class="historial-desc">${r.descripcion||'—'}</div>
                        ${r.evidencia?`<div class="historial-evidencia"><br><a href="../uploads/reai/${r.evidencia}" target="_blank">📎 Ver evidencia</a></div>`:''}
                    </div>`;
                });
            } else {
                html += '<div style="text-align:center;padding:16px 0;color:var(--text2);font-size:0.85rem;">Sin registros previos</div>';
            }
            if (puedeCapturar) {
                const hoy = new Date().toISOString().split('T')[0];
                html += `<hr class="divider">
                <div class="section-label">Nuevo registro</div>
                <div class="form-group"><label>Asunto</label>
                    <select id="f_asunto">
                        <option value="Retroalimentación" ${asunto==='Retroalimentación'?'selected':''}>Retroalimentación</option>
                        <option value="ECNUs" ${asunto==='ECNUs'?'selected':''}>ECNUs</option>
                        <option value="Acta Administrativa" ${asunto==='Acta Administrativa'?'selected':''}>Acta Administrativa</option>
                        <option value="Incidencia" ${asunto==='Incidencia'?'selected':''}>Incidencia</option>
                    </select>
                </div>
                <div class="form-group"><label>Fecha</label><input type="date" id="f_fecha" value="${hoy}"></div>
                <div class="form-group"><label>Descripción</label><textarea id="f_descripcion" placeholder="Escribe los detalles..."></textarea></div>
                <div class="form-group"><label>Evidencia (jpg, png, pdf, doc)</label><input type="file" id="f_evidencia" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"></div>
                <button class="btn-primary" id="btnGuardar" onclick="guardarReai()">Guardar registro</button>`;
            }
            document.getElementById('modalBody').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--red);">Error al cargar los datos.</div>';
            console.error('Error cargando historial:', err);
        });
}
function guardarReai() {
    const btn = document.getElementById('btnGuardar');
    btn.disabled = true; btn.textContent = 'Guardando...';
    const fd = new FormData();
    fd.append('action','guardar');
    fd.append('numero_talento_gs', currentTalento);
    fd.append('nombre_colaborador', currentNombre);
    fd.append('asunto', document.getElementById('f_asunto').value);
    fd.append('fecha', document.getElementById('f_fecha').value);
    fd.append('descripcion', document.getElementById('f_descripcion').value);
    const ev = document.getElementById('f_evidencia');
    if (ev && ev.files[0]) fd.append('evidencia', ev.files[0]);

    fetch(endpointActual, { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                mostrarToast(data.msg, 'success');
                cerrarModalBtn();
                setTimeout(() => location.reload(), 800);
            } else {
                mostrarToast(data.msg, 'error');
                btn.disabled = false; btn.textContent = 'Guardar registro';
            }
        })
        .catch(() => { mostrarToast('Error de conexión al servidor','error'); btn.disabled = false; btn.textContent = 'Guardar registro'; });
}
function cerrarModal(e) { if (e.target.id === 'modalOverlay') cerrarModalBtn(); }
function cerrarModalBtn() {
    document.getElementById('modalOverlay').classList.remove('active');
    document.getElementById('modalBody').innerHTML = '';
}
function mostrarToast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + tipo;
    setTimeout(() => t.className = 'toast', 3000);
}
</script>
</body>
</html>
