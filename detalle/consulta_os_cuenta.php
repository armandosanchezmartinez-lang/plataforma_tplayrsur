<?php
declare(strict_types=1);
/** Talia Pulso · Consulta pública OS / Cuenta · v1.0.29
 *  Archivo independiente: no carga sesión, Pulso ni WordPress.
 */
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

const TP_LOOKUP_MAX_RESULTS = 10; // Por identificador.
const TP_LOOKUP_MAX_IDENTIFIERS = 20;
const TP_LOOKUP_MAX_INPUT = 2048;
const TP_LOOKUP_WINDOW = 300;
const TP_LOOKUP_PER_IP = 20;
const TP_LOOKUP_GLOBAL = 2000;

function tp_lookup_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Digits stay as strings: leading zeros are part of the account identifier. */
function tp_lookup_identifier($raw): ?array {
    if (!is_string($raw) || strlen($raw) > 64) return null;
    $value = trim($raw);
    if (!preg_match('/\A(OS-)?([0-9]{6,40})\z/i', $value, $matches)) return null;
    return ['digits' => $matches[2], 'osOnly' => !empty($matches[1])];
}

/** Split a pasted list; preserve the exact string representation of each identifier. */
function tp_lookup_identifiers($raw): array {
    if (!is_string($raw) || strlen($raw) > TP_LOOKUP_MAX_INPUT) {
        throw new InvalidArgumentException('La lista es demasiado extensa o no es válida.');
    }
    $tokens = preg_split('/[\s,.]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) throw new InvalidArgumentException('Ingresa al menos una OS o cuenta completa.');
    $unique = [];
    foreach ($tokens as $token) {
        $identifier = tp_lookup_identifier($token);
        if ($identifier === null) {
            throw new InvalidArgumentException('Hay un identificador inválido. Usa de 6 a 40 dígitos, con prefijo OS- opcional, separados por espacios, comas, puntos o saltos de línea.');
        }
        $key = ($identifier['osOnly'] ? 'OS-' : 'N:') . $identifier['digits'];
        if (!isset($unique[$key])) {
            $identifier['label'] = ($identifier['osOnly'] ? 'OS-' : '') . $identifier['digits'];
            $unique[$key] = $identifier;
            if (count($unique) > TP_LOOKUP_MAX_IDENTIFIERS) {
                throw new InvalidArgumentException('Puedes buscar hasta ' . TP_LOOKUP_MAX_IDENTIFIERS . ' identificadores distintos por consulta.');
            }
        }
    }
    return array_values($unique);
}

/** Each requested identifier uses one unit of the existing public query budget.
 *  The same database record may match several identifiers; return it only once.
 *  Keep input order, with the most recent matches first for each identifier.
 */
function tp_lookup_batch(array $identifiers, callable $search): array {
    $rows = []; $seen = []; $missing = []; $truncated = [];
    foreach ($identifiers as $identifier) {
        [$matches, $hasMore] = $search($identifier);
        if (!$matches) $missing[] = $identifier['label'];
        if ($hasMore) $truncated[] = $identifier['label'];
        foreach ($matches as $row) {
            // The ninth SQL field is the internal primary key; never display it.
            $key = 'id:' . (string)$row[8];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $rows[] = array_slice($row, 0, 8);
        }
    }
    return [$rows, $missing, $truncated];
}

/** Rate limit shared by workers on this server; no session/cookies required.
 *  Fail closed if the private temporary storage cannot be written.
 */
function tp_lookup_rate_limit(string $ip, int $cost = 1): bool {
    if ($cost < 1 || $cost > TP_LOOKUP_MAX_IDENTIFIERS) throw new InvalidArgumentException('Costo de consulta inválido.');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Dirección remota no disponible.');
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'talia-public-lookup-' . substr(hash('sha256', __DIR__), 0, 24);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('No se puede crear el control de consultas.');
    if (is_link($dir)) throw new RuntimeException('Ruta de control no válida.');
    $path = $dir . DIRECTORY_SEPARATOR . 'limits.json';
    if (is_link($path)) throw new RuntimeException('Archivo de control no válido.');
    $file = @fopen($path, 'c+b');
    if ($file === false) throw new RuntimeException('No se puede abrir el control de consultas.');
    try {
        if (!flock($file, LOCK_EX)) throw new RuntimeException('No se puede bloquear el control de consultas.');
        rewind($file);
        $raw = stream_get_contents($file, 1048577);
        if ($raw === false || strlen($raw) > 1048576) throw new RuntimeException('Control de consultas no válido.');
        $state = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('Control de consultas no válido.');
        $now = time();
        $windowStart = $now - ($now % TP_LOOKUP_WINDOW);
        if (($state['window'] ?? null) !== $windowStart) $state = ['window'=>$windowStart, 'total'=>0, 'ips'=>[]];
        $key = hash('sha256', $ip);
        $count = (int)($state['ips'][$key] ?? 0);
        if ($count + $cost > TP_LOOKUP_PER_IP || (int)$state['total'] + $cost > TP_LOOKUP_GLOBAL) return false;
        $state['ips'][$key] = $count + $cost;
        $state['total'] += $cost;
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) throw new RuntimeException('No se puede guardar el control de consultas.');
        return true;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

/** Resolve the same WP prefix configured in conexionTalia.php. */
function tp_lookup_connection(): array {
    $path = __DIR__ . '/../conexionTalia.php';
    if (!is_file($path)) throw new RuntimeException('Falta conexionTalia.php.');
    $conexion = null;
    $taliaWpPrefix = 'wp_';
    $level = ob_get_level();
    ob_start();
    try {
        require $path;
        $output = (string)ob_get_contents();
    } finally {
        while (ob_get_level() > $level) ob_end_clean();
    }
    if (trim($output) !== '' || !($conexion instanceof mysqli)) throw new RuntimeException('Conexión Talia inválida.');
    $conexion->set_charset('utf8mb4');
    if (!preg_match('/\A[A-Za-z0-9_]*\z/', (string)$taliaWpPrefix)) throw new RuntimeException('Prefijo WordPress inválido.');
    return [$conexion, (string)$taliaWpPrefix];
}

function tp_lookup_sales_table(mysqli $db, string $prefix): string {
    $wp = $prefix . 'talia_pulso_records_ventas';
    $result = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    $tables = [];
    while ($row = $result->fetch_assoc()) $tables[(string)$row['TABLE_NAME']] = true;
    $result->free();
    // Never mix WordPress history with older Standalone copies.
    if (isset($tables[$wp])) $table = $wp;
    elseif (isset($tables[$prefix . 'talia_pulso_records'])) throw new RuntimeException('No existe la tabla de ventas de WordPress.');
    elseif (isset($tables['pulso_ventas'])) $table = 'pulso_ventas';
    else throw new RuntimeException('No existe la tabla de ventas de Talia.');
    $columns = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    $available = [];
    while ($column = $columns->fetch_assoc()) $available[strtolower((string)$column['Field'])] = true;
    $columns->free();
    foreach (['os','id_cuenta_brm','canal_venta','nombre_empleado','jefe_plaza','jefe_ventas','distrital','ciudad','fecha_cierre','id'] as $required) {
        if (!isset($available[$required])) throw new RuntimeException('Falta columna ' . $required . ' en tabla de ventas.');
    }
    return $table;
}

function tp_lookup_search(mysqli $db, string $table, array $identifier): array {
    $digits = $identifier['digits'];
    $prefixed = 'OS-' . $digits;
    // Exact equality on stored identifiers; do not cast to numbers or strip zeros.
    $where = $identifier['osOnly'] ? '`os` IN (?, ?)' : '(`os` IN (?, ?) OR `id_cuenta_brm` = ?)';
    $sql = 'SELECT `os`, `id_cuenta_brm`, `canal_venta`, `nombre_empleado`, `distrital`, `jefe_ventas`, `jefe_plaza`, `ciudad`, `id`'
        . ' FROM `' . $table . '` WHERE ' . $where
        . ' ORDER BY `fecha_cierre` DESC, `id` DESC LIMIT ' . (TP_LOOKUP_MAX_RESULTS + 1);
    $stmt = $db->prepare($sql);
    try {
        if ($identifier['osOnly']) $stmt->bind_param('ss', $digits, $prefixed);
        else $stmt->bind_param('sss', $digits, $prefixed, $digits);
        $stmt->execute();
        $stmt->bind_result($os, $account, $channel, $seller, $districtManager, $salesManager, $plazaManager, $city, $id);
        $rows = [];
        while ($stmt->fetch()) {
            $rows[] = [$os, $account, $channel, $seller, $districtManager, $salesManager, $plazaManager, $city, $id];
        }
        $truncated = count($rows) > TP_LOOKUP_MAX_RESULTS;
        return [array_slice($rows, 0, TP_LOOKUP_MAX_RESULTS), $truncated];
    } finally { $stmt->close(); }
}

/** Preserve the explicitly requested positional field/label mapping. */
function tp_lookup_display_value($value): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : '—';
}
function tp_lookup_whatsapp(array $row, array $labels): string {
    $os = trim((string)($row[0] ?? ''));
    // Stored OS can be numeric or already prefixed; never duplicate OS.
    $number = preg_replace('/^(?:OS[\s-]*)+/i', '', $os) ?? $os;
    $heading = $number !== '' ? 'Datos de la OS-' . $number : 'Datos de la cuenta ' . tp_lookup_display_value($row[1] ?? '');
    $lines = ['*' . $heading . '*'];
    foreach ($labels as $i => $label) {
        if ($i === 0) continue;
        $lines[] = $label . ': ' . tp_lookup_display_value($row[$i] ?? '');
    }
    return implode("\n", $lines);
}

/** Compose a single WhatsApp message from all displayed, deduplicated matches. */
function tp_lookup_whatsapp_all(array $rows, array $labels): string {
    $messages = [];
    foreach ($rows as $row) $messages[] = tp_lookup_whatsapp($row, $labels);
    return implode("\n\n", $messages);
}

$labels = ['Orden de servicio', 'Cuenta', 'Canal de Venta', 'Vendedor', 'Coach', 'Lider', 'Director', 'Distrito'];
$rows = []; $error = ''; $searched = false; $truncated = []; $missing = []; $input = ''; $identifiers = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $raw = $_POST['consulta'] ?? null;
    $input = is_string($raw) && strlen($raw) <= TP_LOOKUP_MAX_INPUT ? $raw : '';
    try {
        $validationError = '';
        try { $identifiers = tp_lookup_identifiers($raw); }
        catch (InvalidArgumentException $e) { $validationError = $e->getMessage(); }
        // Count malformed attempts, and charge each distinct identifier in valid batches.
        // Never trust user-supplied proxy headers.
        $cost = $validationError !== '' ? 1 : count($identifiers);
        if (!tp_lookup_rate_limit((string)($_SERVER['REMOTE_ADDR'] ?? ''), $cost)) {
            http_response_code(429);
            header('Retry-After: ' . (TP_LOOKUP_WINDOW - time() % TP_LOOKUP_WINDOW));
            $error = 'Se alcanzó el límite temporal de consultas (20 identificadores cada cinco minutos). Intenta nuevamente en unos minutos.';
        } elseif ($validationError !== '') {
            http_response_code(400);
            $error = $validationError;
        } else {
            [$db, $prefix] = tp_lookup_connection();
            try {
                $table = tp_lookup_sales_table($db, $prefix);
                [$rows, $missing, $truncated] = tp_lookup_batch($identifiers, static fn(array $identifier): array => tp_lookup_search($db, $table, $identifier));
                $searched = true;
            } finally { $db->close(); }
        }
    } catch (Throwable $e) {
        error_log('Talia Consulta OS/Cuenta: ' . get_class($e) . ' · ' . $e->getMessage());
        http_response_code(503);
        $error = 'La consulta no está disponible en este momento. Intenta nuevamente más tarde.';
    }
} elseif ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    $error = 'Método de consulta no permitido.';
}
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Consulta OS o Cuenta · Talia Pulso</title>
    <link rel="stylesheet" href="../assets/css/consulta_os_cuenta.css?v=1.0.29">
    <script src="../assets/js/consulta_os_cuenta.js?v=1.0.27" defer></script>
</head>
<body>
<main class="lookup-shell">
    <header class="lookup-hero">
        <p class="lookup-kicker">TALIA PULSO · CONSULTA PÚBLICA</p>
        <h1>Consulta OS o Cuenta</h1>
        <p>Consulta la información comercial de una o varias órdenes de servicio o cuentas.</p>
    </header>
    <section class="lookup-card" aria-labelledby="lookup-heading">
        <h2 id="lookup-heading">Buscar registros</h2>
        <form method="post" autocomplete="off" class="lookup-form">
            <div class="lookup-field">
                <label for="consulta">Órdenes de servicio o cuentas</label>
                <textarea id="consulta" name="consulta" rows="4" maxlength="<?=TP_LOOKUP_MAX_INPUT?>" required autocomplete="off" spellcheck="false" autocapitalize="off" placeholder="Ej. OS-12345678, 0123456789&#10;OS-87654321. 0987654321" aria-describedby="lookup-hint"><?=tp_lookup_h($input)?></textarea>
                <p id="lookup-hint" class="lookup-hint">Ingresa hasta <?=TP_LOOKUP_MAX_IDENTIFIERS?> OS o cuentas completas, separadas por espacios, comas, puntos o saltos de línea. Puedes mezclar ambos tipos. No se admiten búsquedas parciales.</p>
            </div>
            <button type="submit">Buscar</button>
        </form>
        <?php if ($error !== ''): ?>
            <div class="lookup-message lookup-error" role="alert"><?=tp_lookup_h($error)?></div>
        <?php endif; ?>
    </section>
    <?php if ($searched): ?>
    <section class="lookup-card lookup-results" aria-labelledby="results-heading">
        <div class="lookup-result-heading"><h2 id="results-heading">Resultados</h2><span class="lookup-count"><?=count($rows)?> <?=count($rows) === 1 ? 'coincidencia' : 'coincidencias'?></span></div>
        <p class="lookup-hint"><?=count($identifiers)?> <?=count($identifiers) === 1 ? 'identificador consultado' : 'identificadores consultados'?>. Las coincidencias repetidas se muestran una sola vez.</p>
        <?php if ($missing): ?><p class="lookup-message">Sin coincidencias: <?=tp_lookup_h(implode(', ', $missing))?></p><?php endif; ?>
        <?php if ($truncated): ?><p class="lookup-hint">Se muestran las <?=TP_LOOKUP_MAX_RESULTS?> coincidencias más recientes por identificador para: <?=tp_lookup_h(implode(', ', $truncated))?>. Usa una OS completa para localizar una orden específica.</p><?php endif; ?>
        <?php if ($rows): ?>
            <div class="lookup-copy-all-panel" aria-labelledby="lookup-copy-all-heading">
                <div class="lookup-copy-all-description">
                    <h3 id="lookup-copy-all-heading">Texto WhatsApp de todas las coincidencias</h3>
                    <p>Copia los datos de las <?=count($rows)?> coincidencias en un solo mensaje, separados por una línea en blanco.</p>
                </div>
                <textarea id="lookup-whatsapp-todos" readonly rows="7" aria-label="Texto WhatsApp conjunto de todas las coincidencias"><?=tp_lookup_h(tp_lookup_whatsapp_all($rows, $labels))?></textarea>
                <div class="lookup-copy-all-actions">
                    <button type="button" class="lookup-copy lookup-copy-all-button" data-copy-target="lookup-whatsapp-todos">Copiar todas las coincidencias</button>
                    <span class="lookup-copy-status" role="status" aria-live="polite"></span>
                </div>
            </div>
            <div class="lookup-table-wrap">
            <table class="lookup-table">
                <thead><tr><?php foreach ($labels as $label): ?><th scope="col"><?=tp_lookup_h($label)?></th><?php endforeach; ?><th scope="col">Texto WhatsApp</th></tr></thead>
                <tbody><?php foreach ($rows as $index => $row): ?>
                    <tr><?php foreach ($labels as $i => $label): ?><td data-label="<?=tp_lookup_h($label)?>"><?=tp_lookup_h(tp_lookup_display_value($row[$i] ?? ''))?></td><?php endforeach; ?>
                    <td class="lookup-whatsapp" data-label="Texto WhatsApp">
                        <textarea id="lookup-whatsapp-<?=$index?>" rows="9" readonly aria-label="Texto WhatsApp de la coincidencia <?=$index+1?>"><?=tp_lookup_h(tp_lookup_whatsapp($row, $labels))?></textarea>
                        <button type="button" class="lookup-copy" data-copy-target="lookup-whatsapp-<?=$index?>">Copiar</button>
                        <span class="lookup-copy-status" role="status" aria-live="polite"></span>
                    </td></tr>
                <?php endforeach; ?></tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <footer class="lookup-footer">Consulta de información comercial · Talia Pulso</footer>
</main>
</body>
</html>
