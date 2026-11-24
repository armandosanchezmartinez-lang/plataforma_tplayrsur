<?php
declare(strict_types=1);
/** Talia Pulso · Consulta pública OS / Cuenta · v1.0.33
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
            // La misma OS puede aparecer en varias búsquedas y en ambas bases.
            // Se muestra una vez; si hay duplicado, se prefiere el origen Talia.
            $source = (string)($row[11] ?? 'talia');
            $os = trim((string)($row[0] ?? ''));
            $normalizedOs = preg_replace('/\AOS[\s-]*/i', '', $os) ?? $os;
            $key = $normalizedOs !== '' ? 'os:' . strtoupper($normalizedOs)
                : 'id:' . $source . ':' . (string)$row[8];
            $public = array_slice($row, 0, 8);
            // Contexto interno para atribución HC; nunca se imprime directamente.
            $public['_sale_id'] = (string)$row[8];
            $public['_source'] = $source;
            $public['_folio'] = (string)($row[9] ?? '');
            $public['_fecha_cierre'] = (string)($row[10] ?? '');
            if (isset($seen[$key])) {
                $existingIndex = $seen[$key];
                if ($source === 'talia' && ($rows[$existingIndex]['_source'] ?? '') !== 'talia') {
                    $rows[$existingIndex] = $public;
                }
                continue;
            }
            $seen[$key] = count($rows);
            $rows[] = $public;
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
    foreach (['os','id_cuenta_brm','canal_venta','nombre_empleado','jefe_plaza','jefe_ventas','distrital','ciudad','fecha_cierre','folio_empleado','id'] as $required) {
        if (!isset($available[$required])) throw new RuntimeException('Falta columna ' . $required . ' en tabla de ventas.');
    }
    return $table;
}

function tp_lookup_search(mysqli $db, string $table, array $identifier, string $source = 'talia'): array {
    $digits = $identifier['digits'];
    $prefixed = 'OS-' . $digits;
    // Exact equality on stored identifiers; do not cast to numbers or strip zeros.
    $where = $identifier['osOnly'] ? '`os` IN (?, ?)' : '(`os` IN (?, ?) OR `id_cuenta_brm` = ?)';
    $sql = 'SELECT `os`, `id_cuenta_brm`, `canal_venta`, `nombre_empleado`, `distrital`, `jefe_ventas`, `jefe_plaza`, `ciudad`, `id`, `folio_empleado`, `fecha_cierre`'
        . ' FROM `' . $table . '` WHERE ' . $where
        . ' ORDER BY `fecha_cierre` DESC, `id` DESC LIMIT ' . (TP_LOOKUP_MAX_RESULTS + 1);
    $stmt = $db->prepare($sql);
    try {
        if ($identifier['osOnly']) $stmt->bind_param('ss', $digits, $prefixed);
        else $stmt->bind_param('sss', $digits, $prefixed, $digits);
        $stmt->execute();
        $stmt->bind_result($os, $account, $channel, $seller, $districtManager, $salesManager, $plazaManager, $city, $id, $folio, $closeDate);
        $rows = [];
        while ($stmt->fetch()) {
            $rows[] = [$os, $account, $channel, $seller, $districtManager, $salesManager, $plazaManager, $city, $id, $folio, $closeDate, $source];
        }
        $truncated = count($rows) > TP_LOOKUP_MAX_RESULTS;
        return [array_slice($rows, 0, TP_LOOKUP_MAX_RESULTS), $truncated];
    } finally { $stmt->close(); }
}

/** Validar estructura de ventas de la base del sistema antes de buscar en ella. */
function tp_lookup_system_sales_table(mysqli $db): string {
    $table = 'ventas';
    $check = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' LIMIT 1");
    $exists = (bool)$check->fetch_row();
    $check->free();
    if (!$exists) throw new RuntimeException('No existe la tabla ventas en la base del sistema.');
    $columns = $db->query('SHOW COLUMNS FROM `ventas`');
    $available = [];
    while ($column = $columns->fetch_assoc()) $available[strtolower((string)$column['Field'])] = true;
    $columns->free();
    foreach (['os','id_cuenta_brm','canal_venta','nombre_empleado','jefe_plaza','jefe_ventas','distrital','ciudad','fecha_cierre','folio_empleado','id'] as $required) {
        if (!isset($available[$required])) throw new RuntimeException('Falta columna ' . $required . ' en ventas del sistema.');
    }
    return $table;
}

/** The public page uses its own system connection without modifying conexionTalia.php. */
function tp_lookup_system_connection(): mysqli {
    $path = __DIR__ . '/../conexion.php';
    if (!is_file($path)) throw new RuntimeException('Falta conexion.php del sistema.');
    $conexion = null;
    $level = ob_get_level();
    ob_start();
    try {
        require $path;
        $output = (string)ob_get_contents();
    } finally {
        while (ob_get_level() > $level) ob_end_clean();
    }
    if (trim($output) !== '' || !($conexion instanceof mysqli)) {
        throw new RuntimeException('Conexión del sistema inválida.');
    }
    $conexion->set_charset('utf8mb4');
    return $conexion;
}

/** All names are resolved from one certified HC photograph, never Salesforce labels. */
function tp_lookup_hc_position(array $photoRows, string $district, string $position): ?array {
    if ($position === '') return null;
    $found = [];
    foreach ($photoRows as $row) {
        if (RankingAtribucionServiceV06::normalizarDistrito((string)($row['distrito'] ?? '')) !== $district) continue;
        if (trim((string)($row['id_posicion'] ?? '')) !== $position) continue;
        $found[] = $row;
    }
    return count($found) === 1 ? $found[0] : null;
}
function tp_lookup_hc_name(?array $row): string {
    $name = trim((string)($row['nombre_colaborador'] ?? ''));
    return $name !== '' && $name !== '-' ? $name : 'No identificado en HC';
}

/** El motor HC proporciona la posición y la fotografía, pero su SELECT no
 * incluye numero_base_comisiones. Consultarlo en la MISMA fotografía certificada
 * (año/semana + distrito + posición) evita usar valores de otra semana.
 */
function tp_lookup_photo_commission_bases(mysqli $system, string $photoKey): array {
    if (!preg_match('/\A([0-9]{4})-([0-9]{1,2})\z/', $photoKey, $matches)) return [];
    $year = (int)$matches[1];
    $week = (int)$matches[2];
    $stmt = $system->prepare('SELECT `distrito`, `id_posicion`, `numero_base_comisiones` FROM `hc` WHERE `anio`=? AND `semana`=?');
    try {
        $stmt->bind_param('ii', $year, $week);
        $stmt->execute();
        $stmt->bind_result($districtRaw, $positionRaw, $baseRaw);
        $bases = [];
        $ambiguous = [];
        while ($stmt->fetch()) {
            $district = RankingAtribucionServiceV06::normalizarDistrito((string)$districtRaw);
            $position = trim((string)$positionRaw);
            if ($district === null || $district === '' || $position === '') continue;
            $seat = $district . '|' . $position;
            if (array_key_exists($seat, $bases)) {
                $ambiguous[$seat] = true;
                continue;
            }
            $bases[$seat] = trim((string)$baseRaw);
        }
        foreach ($ambiguous as $seat => $_) unset($bases[$seat]);
        return $bases;
    } finally { $stmt->close(); }
}

/** Sólo usar el Líder si el Coach está marcado como VACANTE en HC. */
function tp_lookup_coach_vacant(?array $coach): bool {
    if ($coach === null) return false;
    $name = strtoupper(trim((string)($coach['nombre_colaborador'] ?? '')));
    $status = strtoupper(trim((string)($coach['status'] ?? '')));
    return str_contains($name, 'VACANT') || $status === 'VACANTE';
}

/** Project the exact role names onto the requested eight presentation columns. */
function tp_lookup_hc_project(array $row, array $resolution, array $photoRows, array $commissionBases): array {
    $district = (string)($resolution['lider_id'] ?? '');
    $district = explode('|', $district, 2)[0] ?? '';
    $coachId = trim((string)($resolution['coach_id'] ?? ''));
    $leaderId = trim((string)($resolution['lider_posicion'] ?? ''));
    $coach = tp_lookup_hc_position($photoRows, $district, $coachId);
    $leader = tp_lookup_hc_position($photoRows, $district, $leaderId);
    $directorId = trim((string)($leader['posicion_lr'] ?? ''));
    $director = tp_lookup_hc_position($photoRows, $district, $directorId);

    $sellerName = trim((string)($resolution['vendedor_nombre'] ?? ''));
    // Motores HC anteriores a v0.6.8 no exponen vendedor_nombre: solo en ese
    // caso resolver por el folio certificado dentro de la misma fotografía.
    if ($sellerName === '') {
        $sellerFolios = array_filter(array_map('strval', [
            $resolution['vendedor_folio_original'] ?? '',
            $resolution['vendedor_id'] ?? '',
        ]), static fn($folio) => $folio !== '');
        $sellerNames = [];
        foreach ($photoRows as $candidate) {
            if (RankingAtribucionServiceV06::normalizarDistrito((string)($candidate['distrito'] ?? '')) !== $district) continue;
            if (!in_array(trim((string)($candidate['numero_talento_gs'] ?? '')), $sellerFolios, true)) continue;
            $name = trim((string)($candidate['nombre_colaborador'] ?? ''));
            if ($name !== '' && $name !== '-' && strcasecmp($name,'VACANTE')!==0) $sellerNames[$name] = true;
        }
        if (count($sellerNames) === 1) $sellerName = (string)array_key_first($sellerNames);
    }
    $row[3] = $sellerName !== '' ? $sellerName : 'No identificado en HC';
    // Preserve required column order: Vendedor, Coach, Líder, Director.
    $row[4] = tp_lookup_hc_name($coach);
    $row[5] = tp_lookup_hc_name($leader);
    $row[6] = tp_lookup_hc_name($director);
    // El Coach es el contacto normal; si está VACANTE, usar el Líder de HC.
    // Nunca completar por los nombres antiguos de Salesforce.
    $contact = tp_lookup_coach_vacant($coach) ? $leader : $coach;
    // Una segunda vacante (Líder sin ocupante) no autoriza asignar una línea.
    if (tp_lookup_coach_vacant($contact)) $contact = null;
    $contactPosition = trim((string)($contact['id_posicion'] ?? ''));
    $row['_contact_base'] = $contactPosition !== ''
        ? trim((string)($commissionBases[$district . '|' . $contactPosition] ?? '')) : '';
    return $row;
}

/** Discard stale names if attribution cannot be certified by the HC engine. */
function tp_lookup_hc_unresolved(array $row): array {
    foreach ([3,4,5,6] as $index) $row[$index] = 'No identificado en HC';
    $row['_contact_base'] = '';
    return $row;
}

/** Use the same HC engine from /plataforma/includes/motor_hc.php as the platform.
 * Its resolverFilas accepts synthetic sale events: only folio and closing date
 * are authoritative, and the artificial sale key prevents merging distinct sales
 * belonging to the same customer account.
 */
function tp_lookup_resolve_sales_hc(array $rows, mysqli $system): array {
    if (!$rows) return [];
    $path = __DIR__ . '/../includes/motor_hc.php';
    if (!is_file($path)) throw new RuntimeException('No existe includes/motor_hc.php.');
    require_once $path;
    if (!class_exists('RankingAtribucionServiceV06')) {
        throw new RuntimeException('motor_hc.php no expone RankingAtribucionServiceV06.');
    }
    $motor = new RankingAtribucionServiceV06($system);
    $hicTable = (bool)$system->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='historial_identidad_colaborador' LIMIT 1"
    )->fetch_row();
    $hic = $hicTable
        ? RankingAtribucionServiceV06::resolverEquivalenciasHic($motor->cargarHic())
        : ['equivalencias'=>[], 'canonicos'=>[], 'estados'=>[]];

    $byWeek = [];
    foreach ($rows as $index => $row) {
        $date = substr((string)($row['_fecha_cierre'] ?? ''), 0, 10);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $rows[$index] = tp_lookup_hc_unresolved($row);
            continue;
        }
        $byWeek[$dt->format('o-W')][$date][] = $index;
    }
    $photoCommissionCache = [];
    foreach ($byWeek as $dates) {
        $date = array_key_first($dates);
        // Group dates from same ISO week: one photograph, one resolver call.
        $indices = array_merge(...array_values($dates));
        $hc = $motor->cargarHc($date, $date);
        $events = [];
        foreach ($indices as $index) {
            $row = $rows[$index];
            $events[] = [
                'cuenta'=>'VENTA_ID_'.(string)($row['_source'] ?? 'talia').'_' . (string)$row['_sale_id'],
                'fecha'=>substr((string)$row['_fecha_cierre'],0,10),
                'folio_empleado'=>(string)$row['_folio'],
                'lider'=>'', 'coach'=>'', 'origen_prospecto'=>'', 'subcanal'=>'',
            ];
        }
        $resolved = $motor->resolverFilas($events, $hc, $hic['equivalencias'], $hic['canonicos'], $hic['estados']);
        $indexed = [];
        foreach ($resolved as $result) $indexed[(string)($result['cuenta'] ?? '')] = $result;
        foreach ($indices as $index) {
            $row = $rows[$index];
            $result = $indexed['VENTA_ID_'.(string)($row['_source'] ?? 'talia').'_' . (string)$row['_sale_id']] ?? null;
            if ($result === null || ($result['fuente'] ?? '') !== 'HC_FOTO'
                || empty($result['lider_id']) || empty($result['coach_id'])) {
                $rows[$index] = tp_lookup_hc_unresolved($row);
                continue;
            }
            $photoKey = (string)($result['hc_foto'] ?? '');
            $photo = $hc['datos'][$photoKey] ?? [];
            if (!array_key_exists($photoKey, $photoCommissionCache)) {
                $photoCommissionCache[$photoKey] = tp_lookup_photo_commission_bases($system, $photoKey);
            }
            $rows[$index] = tp_lookup_hc_project($row, $result, $photo, $photoCommissionCache[$photoKey]);
        }
    }
    return tp_lookup_add_contact_numbers($rows, $system);
}

/** Buscar línea por numero_base_comisiones del Coach o del Líder (si VACANTE).
 * Los botones de copia existentes conservan `numero`.
 * El clic del footer usa `whats_id` cuando exista y sólo cae a `numero` si no hay whats_id.
 * Si existen varios valores distintos para el mismo identificador, no elegir arbitrariamente.
 */
function tp_lookup_add_contact_numbers(array $rows, mysqli $system): array {
    $talents = [];
    foreach ($rows as $row) {
        $folio = trim((string)($row['_contact_base'] ?? ''));
        if ($folio !== '' && $folio !== '-' && stripos($folio, 'VACANTE') === false) $talents[$folio] = true;
    }
    if (!$talents) return $rows;
    $exists = (bool)$system->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lineas_merida_2026' LIMIT 1"
    )->fetch_row();
    if (!$exists) throw new RuntimeException('No existe lineas_merida_2026 en la base del sistema.');

    // `whats_id` is optional during deployment. If absent, footer copy falls back to numero.
    $columns = $system->query('SHOW COLUMNS FROM `lineas_merida_2026`');
    $available = [];
    while ($column = $columns->fetch_assoc()) $available[strtolower((string)$column['Field'])] = true;
    $columns->free();
    foreach (['numero_talento_gs','numero'] as $required) {
        if (!isset($available[$required])) throw new RuntimeException('Falta columna ' . $required . ' en lineas_merida_2026.');
    }
    $hasWhatsId = isset($available['whats_id']);

    $folios = array_keys($talents);
    $binds = implode(',',array_fill(0,count($folios),'?'));
    $selectWhatsId = $hasWhatsId ? ', `whats_id`' : ', NULL AS `whats_id`';
    $stmt = $system->prepare('SELECT `numero_talento_gs`, `numero`' . $selectWhatsId . ' FROM `lineas_merida_2026` WHERE `numero_talento_gs` IN ('.$binds.')');
    try {
        $types = str_repeat('s',count($folios));
        $stmt->bind_param($types,...$folios);
        $stmt->execute();
        $stmt->bind_result($talento, $numero, $whatsId);
        $numbers = [];
        $whatsIds = [];
        while ($stmt->fetch()) {
            $key = trim((string)$talento);
            if ($key === '') continue;

            $numberValue = trim((string)$numero);
            $numberValue = preg_replace('/[\x00-\x1F\x7F]+/u','', $numberValue) ?? '';
            if ($numberValue !== '') $numbers[$key][$numberValue] = true;

            // Keep Unicode format characters intact; they may be meaningful in the WhatsApp identifier.
            $whatsValue = trim((string)$whatsId);
            $whatsValue = preg_replace('/[\x00-\x1F\x7F]+/u','', $whatsValue) ?? '';
            if ($whatsValue !== '') $whatsIds[$key][$whatsValue] = true;
        }
    } finally { $stmt->close(); }

    foreach ($rows as &$row) {
        $folio = trim((string)($row['_contact_base'] ?? ''));
        $numberChoices = array_keys($numbers[$folio] ?? []);
        $whatsChoices = array_keys($whatsIds[$folio] ?? []);
        $legacyNumber = count($numberChoices) === 1 ? $numberChoices[0] : '';
        $usesWhatsId = count($whatsChoices) === 1;
        $footerNumber = $usesWhatsId
            ? $whatsChoices[0]
            : (count($whatsChoices) === 0 ? $legacyNumber : '');

        $row['_contact_number'] = $legacyNumber;
        $row['_contact_footer_number'] = $footerNumber;
        $row['_contact_footer_uses_whats_id'] = $usesWhatsId;
    }
    unset($row);
    return $rows;
}

/** Preserve the explicitly requested positional field/label mapping. */
function tp_lookup_display_value($value): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : '—';
}
function tp_lookup_whatsapp(array $row, array $labels, string $contactField = '_contact_number'): string {
    $os = trim((string)($row[0] ?? ''));
    // Stored OS can be numeric or already prefixed; never duplicate OS.
    $number = preg_replace('/^(?:OS[\s-]*)+/i', '', $os) ?? $os;
    $heading = $number !== '' ? 'Datos de la OS-' . $number : 'Datos de la cuenta ' . tp_lookup_display_value($row[1] ?? '');
    $contactNumber = trim((string)($row[$contactField] ?? ''));
    $lines = ['*' . $heading . '*'];
    foreach ($labels as $i => $label) {
        if ($i === 0 || $label === 'Director' || $label === 'Distrito') continue;
        $value = tp_lookup_display_value($row[$i] ?? '');
        if ($label === 'Coach' && $contactNumber !== '') {
            $mention = '@' . ltrim($contactNumber, '@');
            if ($contactField === '_contact_footer_number' && !empty($row['_contact_footer_uses_whats_id'])) {
                // Preserve whats_id exactly when it already contains WhatsApp syntax
                // (including Unicode format characters). Only add @ to a bare value.
                $mention = str_contains($contactNumber, '@') ? $contactNumber : '@' . $contactNumber;
            }
            $value .= ' - ' . $mention;
        }
        $lines[] = $label . ': ' . $value;
    }
    return implode("\n", $lines);
}

/** Compose a single WhatsApp message from all displayed, deduplicated matches. */
function tp_lookup_whatsapp_all(array $rows, array $labels, string $contactField = '_contact_number'): string {
    $messages = [];
    foreach ($rows as $row) $messages[] = tp_lookup_whatsapp($row, $labels, $contactField);
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
                $system = tp_lookup_system_connection();
                try {
                    $systemSalesTable = null;
                    $search = static function(array $identifier) use ($db, $table, $system, &$systemSalesTable): array {
                        $primary = tp_lookup_search($db, $table, $identifier, 'talia');
                        if ($primary[0]) return $primary;
                        $systemSalesTable ??= tp_lookup_system_sales_table($system);
                        return tp_lookup_search($system, $systemSalesTable, $identifier, 'sistema');
                    };
                    [$rows, $missing, $truncated] = tp_lookup_batch($identifiers, $search);
                    if ($rows) $rows = tp_lookup_resolve_sales_hc($rows, $system);
                } finally { $system->close(); }
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
    <link rel="stylesheet" href="../assets/css/consulta_os_cuenta.css?v=1.0.33">
    <script src="../assets/js/consulta_os_cuenta.js?v=1.0.33" defer></script>
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
    <footer class="lookup-footer">
        <?php if ($rows): ?>
            <textarea id="lookup-whatsapp-footer" class="lookup-footer-copy-source" readonly tabindex="-1" aria-hidden="true"><?=tp_lookup_h(tp_lookup_whatsapp_all($rows, $labels, '_contact_footer_number'))?></textarea>
            <button type="button" class="lookup-footer-copy" data-copy-target="lookup-whatsapp-footer" data-copy-preserve-label="1" aria-describedby="lookup-footer-copy-status">Consulta de información comercial · Talia Pulso</button>
            <span id="lookup-footer-copy-status" class="lookup-copy-status lookup-footer-copy-status" role="status" aria-live="polite"></span>
        <?php else: ?>
            <span>Consulta de información comercial · Talia Pulso</span>
        <?php endif; ?>
    </footer>
</main>
</body>
</html>
