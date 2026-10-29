<?php
declare(strict_types=1);
/**
 * Talia Pulso Standalone v1.0.16 — PHP autónomo, sin src/, views/ ni config.php.
 * Conexiones independientes: /plataforma/conexionTalia.php (WordPress),
 * /plataforma/conexion.php (HC, catálogo y metas semanales).
 * Se conservan los nombres de tablas WP originales cuando estén presentes.
 */
const PULSO_TIMEZONE = 'America/Merida';
const PULSO_DB_CONNECTION_FILE = __DIR__ . '/../conexionTalia.php';
const PULSO_REFERENCE_CONNECTION_FILE = __DIR__ . '/../conexion.php';
const PULSO_LOGIN_URL = '../login.php';
const PULSO_HOME_URL = '../index.php';
const PULSO_EDITOR_ROLES = ['admin', 'administrador'];
const PULSO_REQUIRE_LOGIN = true;

// ============================= Talia Pulso / core =============================
date_default_timezone_set(PULSO_TIMEZONE);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (PULSO_REQUIRE_LOGIN && empty($_SESSION['usuario'])) {
    header('Location: ' . PULSO_LOGIN_URL, true, 302); exit;
}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function role(): string { return strtolower(trim((string)($_SESSION['rol'] ?? 'vendedor'))); }
function can_edit(): bool { return in_array(role(), PULSO_EDITOR_ROLES, true); }
function need_editor(): void { if (!can_edit()) { http_response_code(403); throw new RuntimeException('No tienes permisos para editar datos o metas.'); } }
function csrf(): string { if (!isset($_SESSION['pulso_csrf'])) { $_SESSION['pulso_csrf'] = bin2hex(random_bytes(32)); } return $_SESSION['pulso_csrf']; }
function verify_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); throw new RuntimeException('Sesión de formulario vencida. Actualiza la página.'); } }
function url(array $params=[]): string { return 'pulso.php' . ($params ? '?' . http_build_query($params) : ''); }
function flash(string $message, string $type='success'): void { $_SESSION['pulso_flash'] = compact('message', 'type'); }
function go(string $view='corte'): never { header('Location: '.url(['view'=>$view])); exit; }
function keynorm(?string $value): string {
    // No depender de LC_CTYPE: en servidores C/POSIX, iconv() puede
    // transformar "Número" en "N?mero", invalidando cabeceras de Salesforce.
    $v = trim((string)$value);
    $v = strtr($v, [
        'Á'=>'A','À'=>'A','Â'=>'A','Ä'=>'A','á'=>'a','à'=>'a','â'=>'a','ä'=>'a',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Ö'=>'O','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'Ñ'=>'N','ñ'=>'n','Ç'=>'C','ç'=>'c'
    ]);
    $v = preg_replace('/[\x{0300}-\x{036f}]/u', '', $v) ?? $v;
    $ascii = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);
    if ($ascii !== false) $v = $ascii;
    $v = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $v));
    return trim(preg_replace('/_+/', '_', $v),'_');
}
function district_key(?string $v): string {
    $k=keynorm($v); if(str_contains($k,'cancun')) return 'cancun'; if(str_contains($k,'merida')) return 'merida';
    if(str_contains($k,'tuxtla'))return 'tuxtla'; if(str_contains($k,'villa'))return 'villahermosa';
    if(str_contains($k,'coatza')||str_contains($k,'mina'))return 'coatza_mina';return $k;
}
function district_label(?string $k): string { return ['cancun'=>'CANCÚN','coatza_mina'=>'COATZA / MINA','merida'=>'MÉRIDA','tuxtla'=>'TUXTLA','villahermosa'=>'VILLAHERMOSA'][district_key($k)]??strtoupper((string)$k); }
function valid_date(?string $d): string { $d=(string)$d; $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d); return $dt && $dt->format('Y-m-d')===$d ? $d : date('Y-m-d'); }
function date_shift(string $d,int $n): string { return (new DateTimeImmutable($d))->modify(($n>=0?'+':'').$n.' days')->format('Y-m-d'); }
function week_bounds(string $date): array { $dt=new DateTimeImmutable($date);return [$dt->modify('monday this week')->format('Y-m-d'),$dt->modify('sunday this week')->format('Y-m-d')]; }
function fmtdate(?string $d): string { return $d ? date('d/m/Y',strtotime($d)): '—'; }
function fmt_time(?string $v): string {return $v ? substr($v,0,5):'—';}
function num($v): string {return number_format((float)$v,0,'.',',');}
function pct(float $part, float $total): string {return $total>0 ? number_format($part/$total*100,1).'%':'—';}
function column_names(string $table, ?DB $connection = null): array {return array_map(static fn($r)=>$r['Field'],($connection ?? db())->all('SHOW COLUMNS FROM `'.$table.'`'));}

final class DB {
    private mysqli $c;
    private array $mapping = [];
    private string $schema = '';
    /** Cada instancia DB ejecuta consultas solamente con las credenciales que recibió. */
    function __construct(mysqli $c, string $wpPrefix='wp_') {
        $this->c=$c;
        $c->set_charset('utf8mb4');
        $this->schema=(string)($c->query('SELECT DATABASE() AS db')->fetch_assoc()['db']??'');
        if ($this->schema==='') throw new RuntimeException('La conexión no seleccionó una base de datos.');
        if($wpPrefix!=='' && !preg_match('/^[A-Za-z0-9_]+$/',$wpPrefix))throw new RuntimeException('Prefijo de WordPress inválido.');
        if ($wpPrefix==='') return; // Conexión de referencias: tablas del sistema sin prefijo WP.
        $logical=['pulso_records'=>'talia_pulso_records',
            'pulso_ventas'=>'talia_pulso_records_ventas',
            'pulso_imports'=>'talia_pulso_imports',
            'pulso_targets'=>'talia_pulso_targets',
            'pulso_seguimiento_diario'=>'talia_pulso_seguimiento_diario'];
        $existing=[];
        $result=$c->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        while ($r=$result->fetch_assoc())$existing[(string)$r['TABLE_NAME']]=true;
        $result->free();
        // Nunca mezclar el historial de WordPress con copias antiguas del standalone.
        $wpFound=isset($existing[$wpPrefix.'talia_pulso_records']);
        foreach($logical as $alias=>$suffix){
            if($wpFound)$this->mapping[$alias]='`'.$wpPrefix.$suffix.'`';
        }
    }
    private function actual(string $table): string {return $this->mapping[$table]??'`'.$table.'`';}
    private function translate(string $sql): string {
        foreach($this->mapping as $name=>$actual){
            $sql=preg_replace('/(?<![\w])`?'.preg_quote($name,'/').'`?(?![\w])/', $actual, $sql);
        }
        return $sql;
    }
    function dataSource(): string {return isset($this->mapping['pulso_records'])?'WordPress histórico':'Standalone';}

    function query(string $sql, array $values=[]): mysqli_result|bool {
        $sql=$this->translate($sql);
        if (!$values) { $result=$this->c->query($sql); if($result===false)throw new RuntimeException('Error de base de datos: '.$this->c->error); return $result; }
        $stmt=$this->c->prepare($sql); if(!$stmt)throw new RuntimeException('Error preparando consulta: '.$this->c->error);
        $types=''; foreach($values as $v) $types.=is_int($v)?'i':(is_float($v)?'d':'s');
        $stmt->bind_param($types,...$values);$stmt->execute();
        $r=$stmt->get_result();$stmt->close();return $r ?: true;
    }
    function all(string $sql,array $values=[]): array {$r=$this->query($sql,$values);return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC):[];}
    function row(string $sql,array $values=[]): ?array {return $this->all($sql,$values)[0]??null;}
    function val(string $sql,array $values=[]): mixed {$r=$this->row($sql,$values);return $r?reset($r):null;}
    function has(string $table): bool {
        $identifier=$this->actual($table);
        if(preg_match('/^`([^`]+)`\.`([^`]+)`$/',$identifier,$parts)){
            $schema=$parts[1];$name=$parts[2];
        } else {
            $schema=$this->schema;$name=trim($identifier,'`');
        }
        return (bool)$this->val('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1',[$schema,$name]);
    }
    function insert(string $table,array $data): int {if(!$data)return 0;$cols=array_keys($data);$keys=implode(',',array_map(static fn($c)=>'`'.$c.'`',$cols));$p=implode(',',array_fill(0,count($cols),'?'));$this->query("INSERT INTO `$table` ($keys) VALUES ($p)",array_values($data));return $this->c->insert_id;}
    function update(string $table,array $data,string $where,array $whereParams=[]): void { $sets=implode(',',array_map(static fn($c)=>"`$c`=?",array_keys($data)));$this->query("UPDATE `$table` SET $sets WHERE $where",array_merge(array_values($data),$whereParams)); }
    function begin(): void {$this->c->begin_transaction();}
    function commit(): void {$this->c->commit();}
    function rollback(): void {$this->c->rollback();}
}
/** Carga cada archivo dentro de un ámbito local: ambos definen $conexion y $host.
 * Las credenciales permanecen exclusivamente en los archivos externos.
 */
function pulso_open_connection(string $file, string $label): array {
    if(!is_file($file))throw new RuntimeException('No existe '.$label.' en /plataforma.');
    $conexion=null; $taliaWpPrefix='wp_';
    // conexion.php puede emitir espacios después del cierre PHP; proteger los redirects.
    ob_start();
    try {
        require $file;
        $salida=(string)ob_get_clean();
    } catch(Throwable $ex) {
        ob_end_clean();
        throw $ex;
    }
    if(trim($salida)!=='')throw new RuntimeException($label.' no debe imprimir contenido.');
    if(!($conexion instanceof mysqli))throw new RuntimeException($label.' debe crear $conexion (mysqli).');
    // La conexión en sí misma certifica la base mediante SELECT DATABASE().
    return [$conexion,(string)$taliaWpPrefix];
}
/** Datos históricos y escrituras de Pulso (credenciales de WordPress). */
function db(): DB { static $db=null;if($db!==null)return $db;
    [$conexion,$prefix]=pulso_open_connection(PULSO_DB_CONNECTION_FILE,'conexionTalia.php');
    return $db=new DB($conexion,$prefix);
}
/** Solo referencias del sistema: HC, catálogo, identidad y metas semanales. */
function ref_db(): DB { static $db=null;if($db!==null)return $db;
    [$conexion]=pulso_open_connection(PULSO_REFERENCE_CONNECTION_FILE,'conexion.php');
    return $db=new DB($conexion,'');
}
function ready(): bool {
    foreach(['pulso_records','pulso_ventas','pulso_imports','pulso_targets','pulso_seguimiento_diario'] as $table) {
        if(!db()->has($table))return false;
    }
    foreach(['hc','catalogo_paquetes'] as $table) {
        if(!ref_db()->has($table))throw new RuntimeException('No se encontró '.$table.' a través de conexion.php (base del sistema). Verifica que esa conexión seleccione la base correcta.');
    }
    return true;
}
function table_count(string $table): int {return (int)db()->val("SELECT COUNT(*) FROM `$table`");}

// ============================= Talia Pulso / hierarchy =============================
/**
 * Jerarquía comercial standalone (folio -> HC -> posición), sin WordPress.
 * Origen: reglas de hc_sales_hierarchy_map() / hc_install_hierarchy_for_sale_folio()
 * de Talia Pulso 1.0.180. Nunca se atribuye por nombres de Salesforce.
 */


function hc_name_tokens(?string $name): string {
    $parts = array_values(array_filter(explode('_', keynorm($name))));
    sort($parts, SORT_STRING);
    return implode('|', $parts);
}
function hc_position_role(?string $position): string {
    $p = keynorm($position);
    if ($p === 'director_distrital') return 'director';
    if (str_starts_with($p, 'lider_ventas')) return 'lider';
    if (str_starts_with($p, 'coach')) return 'coach';
    return '';
}
function hc_scope_id(string $role, string $district, string $position): string {
    return $role . '|' . district_key($district) . '|' . $position;
}
/** Map of unique, certified seller folios for one HC photograph. */
function hc_build_hierarchy(array $rows, array $hicRows = [], ?array $period = null): array {
    $byPosition = []; $leaderPositions = []; $directors = [];
    foreach ($rows as $r) {
        $d = district_key((string)($r['distrito'] ?? ''));
        $id = trim((string)($r['id_posicion'] ?? ''));
        if ($d === '' || $id === '') continue;
        $byPosition[$d][$id][] = $r;
        if (str_contains(keynorm((string)($r['puesto_lr'] ?? '')), 'lider')) {
            $parent = trim((string)($r['posicion_lr'] ?? ''));
            if ($parent !== '') $leaderPositions[$d][$parent] = true;
        }
        if (hc_position_role((string)($r['posicion'] ?? '')) === 'director') $directors[$d][] = $r;
    }
    // A leader position must resolve to one seat in that district.
    $leaders=[];$byName=[];
    foreach ($leaderPositions as $d => $ids) foreach (array_keys($ids) as $id) {
        $candidate = $byPosition[$d][$id] ?? [];
        if (count($candidate) !== 1) continue;
        $leaders[$d][$id] = $candidate[0];
        $nameKey = hc_name_tokens((string)($candidate[0]['nombre_colaborador'] ?? ''));
        if ($nameKey !== '') $byName[$d][$nameKey][$id] = $candidate[0];
    }
    $map = []; $conflicts = [];
    foreach ($rows as $seller) {
        $folio = trim((string)($seller['numero_talento_gs'] ?? ''));
        if ($folio === '' || $folio === '-' || str_contains(strtoupper($folio), 'VACANTE')) continue;
        $d = district_key((string)($seller['distrito'] ?? ''));
        $coachId = trim((string)($seller['posicion_lr'] ?? ''));
        $coaches = $byPosition[$d][$coachId] ?? [];
        if ($d === '' || $coachId === '' || count($coaches) !== 1) continue;
        $coach = $coaches[0];
        if (hc_position_role((string)($coach['posicion'] ?? '')) !== 'coach'
            && !str_contains(keynorm((string)($coach['puesto_lr'] ?? '')), 'lider')) continue;
        $leaderPos = trim((string)($coach['posicion_lr'] ?? ''));
        $leader = null;
        if ($leaderPos !== '' && $leaderPos !== trim((string)($coach['id_posicion'] ?? ''))) {
            $leader = $leaders[$d][$leaderPos] ?? null;
        } else {
            // Controlled fallback for self-reporting coaches, as in Motor HC.
            $reportName = hc_name_tokens((string)($coach['nombre_linea_reporte'] ?? ''));
            $possible = $reportName !== '' ? array_values($byName[$d][$reportName] ?? []) : [];
            if (count($possible) === 1) {
                $leader = $possible[0];
                $leaderPos = trim((string)$leader['id_posicion']);
            }
        }
        if ($leader === null) continue;
        $directorPos = trim((string)($leader['posicion_lr'] ?? ''));
        $directorRows = $byPosition[$d][$directorPos] ?? [];
        $director = count($directorRows) === 1 && hc_position_role((string)$directorRows[0]['posicion']) === 'director'
            ? $directorRows[0] : null;
        if ($director === null && count($directors[$d] ?? []) === 1) $director = $directors[$d][0];
        $leaderName = trim((string)($leader['nombre_colaborador'] ?? ''));
        $coachName = trim((string)($coach['nombre_colaborador'] ?? ''));
        if ($leaderName === '' || $coachName === '') continue;
        $info = [
            'folio' => $folio,
            'seller_name' => trim((string)($seller['nombre_colaborador'] ?? '')),
            'seller_position' => trim((string)($seller['id_posicion'] ?? '')),
            'coach_name' => $coachName,
            'coach_id' => trim((string)($coach['id_posicion'] ?? '')),
            'leader_name' => $leaderName,
            'leader_position' => trim((string)($leader['id_posicion'] ?? $leaderPos)),
            'director_name' => trim((string)($director['nombre_colaborador'] ?? '')),
            'director_id' => trim((string)($director['id_posicion'] ?? '')),
            'district' => $d,
            'hc_year' => (int)($period['anio'] ?? 0),
            'hc_week' => (int)($period['semana'] ?? 0),
        ];
        // Never arbitrarily choose conflicting employee assignments.
        $key = implode('|', [$info['district'], $info['seller_position'], $info['coach_id'], $info['leader_position'], $info['director_id']]);
        if (isset($map[$folio]) && $map[$folio]['_chain'] !== $key) $conflicts[$folio] = true;
        $info['_chain'] = $key;
        $map[$folio] = $info;
    }
    foreach ($conflicts as $folio => $_) unset($map[$folio]);
    // History of identity: only connect a component if exactly one certified member
    // is present in the photographed hierarchy (no ambiguous attribution).
    foreach (hc_hic_components($hicRows) as $component) {
        $present = array_values(array_filter($component, static fn($f) => isset($map[$f])));
        if (count($present) !== 1) continue;
        foreach ($component as $alias) if (!isset($map[$alias]) && !isset($conflicts[$alias])) $map[$alias] = $map[$present[0]];
    }
    return $map;
}
function hc_hic_components(array $rows): array {
    $adj = [];
    foreach ($rows as $r) {
        $a = trim((string)($r['numero_talento_anterior'] ?? ''));
        $b = trim((string)($r['numero_talento_nuevo'] ?? ''));
        if ($a === '' || $b === '' || $a === $b || $a === '-' || $b === '-') continue;
        $adj[$a][$b] = true; $adj[$b][$a] = true;
    }
    $components = []; $visited = [];
    foreach (array_keys($adj) as $start) {
        if (isset($visited[$start])) continue;
        $stack = [$start]; $component = [];
        while ($stack) {
            $f = array_pop($stack);
            if (isset($visited[$f])) continue;
            $visited[$f] = true; $component[] = $f;
            foreach (array_keys($adj[$f] ?? []) as $n) if (!isset($visited[$n])) $stack[] = $n;
        }
        $components[] = $component;
    }
    return $components;
}
function hc_hic_rows(): array {
    static $rows = null;
    if ($rows === null) {
        $rows = ref_db()->has('historial_identidad_colaborador')
            ? ref_db()->all('SELECT numero_talento_anterior,numero_talento_nuevo FROM historial_identidad_colaborador') : [];
    }
    return $rows;
}
/** HC snapshot not later than its ISO week. No future photograph. */
function hc_snapshot(string $date): array {
    static $cache = [];
    $iso = new DateTimeImmutable($date);
    $key = $iso->format('oW');
    if (isset($cache[$key])) return $cache[$key];
    if (!ref_db()->has('hc')) return $cache[$key] = ['period' => null, 'rows' => []];
    $year = (int)$iso->format('o'); $week = (int)$iso->format('W');
    $p = ref_db()->row('SELECT anio,semana FROM hc WHERE anio < ? OR (anio=? AND semana<=?) GROUP BY anio,semana ORDER BY anio DESC,semana DESC LIMIT 1', [$year,$year,$week]);
    if (!$p) return $cache[$key] = ['period' => null, 'rows' => []];
    return $cache[$key] = ['period'=>$p,'rows'=>ref_db()->all('SELECT * FROM hc WHERE anio=? AND semana=?', [(int)$p['anio'],(int)$p['semana']])];
}
function hc_map_for_date(string $date): array {
    static $cache = [];
    $snapshot = hc_snapshot($date);
    $p = $snapshot['period'];
    if ($p === null) return [];
    $key = (int)$p['anio'].'-'.(int)$p['semana'];
    if (!isset($cache[$key])) $cache[$key] = hc_build_hierarchy($snapshot['rows'],hc_hic_rows(),$p);
    return $cache[$key];
}
function hc_for_sale_folio(?string $folio, string $date): ?array {
    $f = trim((string)$folio);
    if ($f === '') return null;
    return hc_map_for_date($date)[$f] ?? null;
}
/** Installation lineage: sale-date photo first, then earlier photos of this same folio/HIC component. */
function hc_for_install_sale(?string $folio, string $saleDate): ?array {
    static $cache = [];
    $folio = trim((string)$folio);
    if ($folio === '' || !preg_match('/^\d{4}-\d\d-\d\d$/', $saleDate)) return null;
    $cacheKey = $folio.'|'.$saleDate;
    if (array_key_exists($cacheKey,$cache)) return $cache[$cacheKey];
    $current = hc_for_sale_folio($folio,$saleDate);
    if ($current !== null) return $cache[$cacheKey] = $current;
    if (!ref_db()->has('hc')) return $cache[$cacheKey] = null;
    $aliases = [$folio];
    foreach (hc_hic_components(hc_hic_rows()) as $component) if (in_array($folio,$component,true)) { $aliases = $component; break; }
    $d = new DateTimeImmutable($saleDate);
    $place = implode(',',array_fill(0,count($aliases),'?'));
    $params = array_merge($aliases,[(int)$d->format('o'),(int)$d->format('o'),(int)$d->format('W')]);
    $periods = ref_db()->all("SELECT anio,semana FROM hc WHERE numero_talento_gs IN ($place) AND (anio < ? OR (anio=? AND semana<=?)) GROUP BY anio,semana ORDER BY anio DESC,semana DESC LIMIT 104",$params);
    foreach ($periods as $period) {
        $reference = (new DateTimeImmutable())->setISODate((int)$period['anio'],(int)$period['semana'],1)->format('Y-m-d');
        $map = hc_map_for_date($reference);
        if (isset($map[$folio])) return $cache[$cacheKey] = $map[$folio];
        $candidates = [];
        foreach ($aliases as $alias) if (isset($map[$alias])) $candidates[$map[$alias]['_chain']] = $map[$alias];
        if (count($candidates) === 1) return $cache[$cacheKey] = reset($candidates);
    }
    return $cache[$cacheKey] = null;
}
/** Stable selector values: role|district|HC position id. */
function hc_options(array $snapshotRows, string $district = '', ?array $allowedPositions = null): array {
    $result = [];
    foreach ($snapshotRows as $r) {
        $role = hc_position_role((string)($r['posicion'] ?? ''));
        $d = district_key((string)($r['distrito'] ?? ''));
        $pos = trim((string)($r['id_posicion'] ?? ''));
        $name = trim((string)($r['nombre_colaborador'] ?? ''));
        if ($role === '' || $d === '' || $pos === '' || $name === '') continue;
        if ($district !== '' && $d !== $district) continue;
        if ($allowedPositions !== null && !isset($allowedPositions[$d.'|'.$pos])) continue;
        $id = hc_scope_id($role,$d,$pos);
        $label = ['director'=>'Director','lider'=>'Líder','coach'=>'Coach'][$role];
        $result[$id] = ['id'=>$id,'name'=>$name,'district'=>$d,'role'=>$role,'label'=>$label.' · '.$name];
    }
    uasort($result, static fn($a,$b) => [array_search($a['role'],['director','lider','coach'],true),$a['district'],keynorm($a['name'])]
        <=> [array_search($b['role'],['director','lider','coach'],true),$b['district'],keynorm($b['name'])]);
    return $result;
}
function hc_match_scope(?array $hierarchy, string $scope): bool {
    if ($scope === '') return true;
    if ($hierarchy === null) return false;
    $parts = explode('|',$scope,3);
    if (count($parts) !== 3) return false;
    [$role,$district,$pos] = $parts;
    if ($district !== $hierarchy['district']) return false;
    return match($role) {
        'director' => $pos !== '' && $hierarchy['director_id'] === $pos,
        'lider' => $pos !== '' && $hierarchy['leader_position'] === $pos,
        'coach' => $pos !== '' && $hierarchy['coach_id'] === $pos,
        default => false,
    };
}
function hc_permission(array $hc, string $folio, ?array $hierarchy): bool {
    if ($hc['allowed'] === null) return true;
    if (isset($hc['allowed'][$folio])) return true;
    // An installation may belong to a seller who already left the current roster.
    // A currently authorized director/leader/coach can still see events that the
    // historical sale HC attributes to their authorized position in that district.
    if ($hierarchy !== null) {
        $district=(string)$hierarchy['district'];
        foreach (['seller_position','coach_id','leader_position','director_id'] as $field) {
            $position=(string)($hierarchy[$field]??'');
            if ($position!==''&&isset($hc['allowed_positions'][$district.'|'.$position])) return true;
        }
    }
    return false;
}
/** Resolve the authenticated employee without matching Salesforce display names.
 * Session folio is authoritative; position fallback is allowed only when unique.
 */
function hc_logged_identity(array $rows, array $session): ?array {
    $folio=trim((string)($session['numero_talento_gs']??''));
    $position=trim((string)($session['id_posicion']??''));
    $district=district_key((string)($session['distrito']??$session['district']??''));
    $candidates=[];
    if($folio!=='') foreach($rows as $r){
        if(trim((string)($r['numero_talento_gs']??''))===$folio)$candidates[]=$r;
    }
    if($candidates && $position!==''){
        $atPosition=array_values(array_filter($candidates,static fn($r)=>trim((string)($r['id_posicion']??''))===$position));
        if($atPosition)$candidates=$atPosition;
    }
    if($candidates && $district!==''){
        $inDistrict=array_values(array_filter($candidates,static fn($r)=>district_key((string)($r['distrito']??''))===$district));
        if($inDistrict)$candidates=$inDistrict;
    }
    if(!$candidates && $position!=='')foreach($rows as $r){
        if(trim((string)($r['id_posicion']??''))===$position
          &&($district===''||district_key((string)($r['distrito']??''))===$district))$candidates[]=$r;
    }
    // Never pick an arbitrary employee when HC has a conflicting identity.
    $distinct=[];
    foreach($candidates as $r){
        $id=district_key((string)($r['distrito']??'')).'|'.trim((string)($r['id_posicion']??'')).'|'.trim((string)($r['numero_talento_gs']??''));
        $distinct[$id]=$r;
    }
    if(count($distinct)!==1)return null;
    $r=reset($distinct);
    $d=district_key((string)($r['distrito']??''));$pos=trim((string)($r['id_posicion']??''));
    $name=trim((string)($r['nombre_colaborador']??''));
    if($d===''||$pos===''||$name===''||keynorm($name)==='vacante')return null;
    $role=hc_position_role((string)($r['posicion']??''));
    if($role==='')$role='vendedor';
    return ['district'=>$d,'position'=>$pos,'name'=>$name,'role'=>$role,
        'scope'=>hc_scope_id($role,$d,$pos),'folio'=>trim((string)($r['numero_talento_gs']??''))];
}
function hc_user_option(array $hc): ?array {
    $me=$hc['identity']??null;
    if(!$me)return null;
    $label=['director'=>'Director','lider'=>'Líder','coach'=>'Coach','vendedor'=>'Vendedor'][$me['role']]??'Empleado';
    return ['id'=>$me['scope'],'name'=>$me['name'],'district'=>$me['district'],
        'role'=>$me['role'],'label'=>$label.' · '.$me['name']];
}
function hc_user_district(array $hc): string {
    if($hc['allowed']===null)return '';
    if(isset($hc['identity']['district']))return (string)$hc['identity']['district'];
    $districts=[];
    foreach(array_keys($hc['allowed_positions']??[]) as $key){
        $d=explode('|',$key,2)[0];if($d!=='')$districts[$d]=true;
    }
    return count($districts)===1?(string)array_key_first($districts):'';
}
/**
 * El Director Distrital consulta el MISMO universo operativo que Ciudad.
 * La posición seleccionada sigue disponible para el desglose jerárquico,
 * pero no vuelve a filtrar las ventas/instalaciones del distrito por folio.
 * La autorización del usuario se mantiene en hc_permission().
 */
function hc_effective_scope(string $scope,array $hc): string {
    if($scope!=='' && explode('|',$scope,2)[0]==='director')return '';
    $self=$hc['identity']['scope']??'';
    return $scope!=='' && $scope===$self ? '' : $scope;
}
/** Director seleccionado y autorizado en la fotografía HC actual. */
function pulso_director_district(array $f,array $hc): string {
    $parts=explode('|',(string)($f['leader']??''),3);
    if(count($parts)!==3||$parts[0]!=='director'||$parts[1]===''||$parts[2]==='')return '';
    if(!hc_selected_valid($f,$hc))return '';
    return (string)$parts[1];
}
/** Context with authorization constrained to the logged-in employee's HC subtree. */
function ctx_hc(string $date): array {
    $photo = hc_snapshot($date);
    $rows = $photo['rows'];
    $admin = in_array(role(), ['admin','administrador','director_regional'],true);
    if (!$rows) return ['rows'=>[], 'allowed'=>$admin?null:[], 'allowed_positions'=>$admin?null:[], 'identity'=>null, 'label'=>'Sin HC', 'period'=>$photo['period']];
    $byParent=[]; $byFolio=[]; $byPosition=[];
    foreach ($rows as $r) {
        $d = district_key((string)($r['distrito'] ?? ''));
        $pos = trim((string)($r['id_posicion'] ?? ''));
        $parent = trim((string)($r['posicion_lr'] ?? ''));
        if ($d==='' || $pos==='') continue;
        $byPosition[$d.'|'.$pos] = $r;
        if ($parent !== '' && $parent !== $pos) $byParent[$d.'|'.$parent][] = $pos;
        $folio = trim((string)($r['numero_talento_gs'] ?? ''));
        if ($folio!=='') $byFolio[$folio][] = $d.'|'.$pos;
    }
    if ($admin) return ['rows'=>$rows,'allowed'=>null,'allowed_positions'=>null,'identity'=>null,'label'=>'Regional','period'=>$photo['period']];
    $identity=hc_logged_identity($rows,$_SESSION);
    $userFolio = trim((string)($_SESSION['numero_talento_gs'] ?? ''));
    $myIds = $byFolio[$userFolio] ?? [];
    $sessionPosition = trim((string)($_SESSION['id_posicion'] ?? ''));
    if (!$myIds && $sessionPosition!=='') foreach (array_keys($byPosition) as $key) if (str_ends_with($key,'|'.$sessionPosition)) $myIds[]=$key;
    $allowedPos=[]; $stack=$myIds;
    while ($stack && count($allowedPos)<15000) {
        $p = array_pop($stack);
        if (isset($allowedPos[$p])) continue;
        $allowedPos[$p] = true;
        foreach (($byParent[$p] ?? []) as $child) {
            $district = explode('|',$p,2)[0];
            $stack[] = $district.'|'.$child;
        }
    }
    $allowed=[];
    foreach ($allowedPos as $key => $_) {
        $folio = trim((string)($byPosition[$key]['numero_talento_gs']??''));
        if ($folio !== '' && !str_contains(strtoupper($folio),'VACANTE')) $allowed[$folio]=true;
    }
    foreach (hc_hic_components(hc_hic_rows()) as $component) {
        $matched=array_filter($component,static fn($folio)=>isset($allowed[$folio]));
        if (count($matched)===1) foreach($component as $alias)$allowed[$alias]=true;
    }
    return ['rows'=>$rows,'allowed'=>$allowed,'allowed_positions'=>$allowedPos,'identity'=>$identity,'label'=>$identity['name']??$userFolio,'period'=>$photo['period']];
}

// ============================= Talia Pulso / analytics =============================
function territory(array $r): string {
    foreach(['district','distrito','city','ciudad'] as $field){
        $v=trim((string)($r[$field]??''));
        if($v!=='')return district_key($v);
    }
    return '';
}
function filters(): array {
    return [
        'date'=>valid_date((string)($_GET['date']??date('Y-m-d'))),
        'district'=>district_key((string)($_GET['district']??'')),
        'channel'=>keynorm((string)($_GET['channel']??'')),
        'leader'=>trim((string)($_GET['leader']??'')),
    ];
}
function account_key(?string $s): string {
    $s=preg_replace('/\D/','',(string)$s);
    return ltrim($s,'0')?:($s?'0':'');
}
function sale_channel(array $s): string {return keynorm((string)($s['canal_venta']??''));}
function install_channel(array $s): string {return keynorm((string)($s['sales_channel']??''));}
function district_match(array $r,string $filter): bool {return $filter===''||territory($r)===$filter;}
/** Authorize a selected HC position from the displayed photograph, not by a name. */
function hc_selected_valid(array $f,array $hc): bool {
    if ($f['leader']==='') return true;
    if(($hc['identity']['scope']??'')===$f['leader'])return true;
    $opts=hc_options($hc['rows'],$f['district'],$hc['allowed_positions']);
    return isset($opts[$f['leader']]);
}
function sales_for_date(array $f, array $hc): array {
    if(!hc_selected_valid($f,$hc))return [];
    $rows=db()->all('SELECT * FROM pulso_ventas WHERE fecha_cierre=? ORDER BY TS_CreacionHora,id',[$f['date']]);
    $out=[];
    foreach($rows as $r){
        if(!district_match($r,$f['district'])||($f['channel']&&sale_channel($r)!==$f['channel']))continue;
        $hierarchy=hc_for_sale_folio((string)($r['folio_empleado']??''),$f['date']);
        if(!hc_permission($hc,(string)($r['folio_empleado']??''),$hierarchy))continue;
        if(!hc_match_scope($hierarchy,hc_effective_scope($f['leader'],$hc)))continue;
        $r['_hierarchy']=$hierarchy;
        $out[]=$r;
    }
    return $out;
}
/** Load candidate Salesforce sales by account. Only sales *before* FieldCloud completion can own an installation. */
function installation_sale_owners(array $installs,string $throughDate): array {
    $keys=[];
    foreach($installs as $r){
        $raw=trim((string)($r['account']??''));
        $clean=account_key($raw);
        foreach(array_unique([$raw,$clean,'0'.$clean]) as $alt)if($alt!=='')$keys[$alt]=true;
    }
    $owners=[];
    foreach(array_chunk(array_keys($keys),180) as $accounts){
        $place=implode(',',array_fill(0,count($accounts),'?'));
        $sales=db()->all("SELECT * FROM pulso_ventas WHERE id_cuenta_brm IN ($place) AND TS_CreacionHora <= ? ORDER BY TS_CreacionHora DESC,id DESC", array_merge($accounts,[$throughDate.' 23:59:59']));
        foreach($sales as $s){
            $k=account_key((string)$s['id_cuenta_brm']);
            if($k!=='')$owners[$k][]=$s;
        }
    }
    foreach($owners as &$candidateRows)usort($candidateRows,static fn($a,$b)=>[$b['TS_CreacionHora'],$b['id']]<=>[$a['TS_CreacionHora'],$a['id']]);
    unset($candidateRows);
    return $owners;
}
function installs_for_date(array $f,array $hc): array {
    if(!hc_selected_valid($f,$hc))return [];
    $rows=db()->all("SELECT * FROM pulso_records WHERE event_date=? AND source='fieldcloud' AND LOWER(TRIM(type_label)) IN ('instalación','instalacion') AND LOWER(TRIM(status_label))='terminada' AND is_completed=1 ORDER BY finished_at,id",[$f['date']]);
    $candidates=[];
    foreach($rows as $r)if(district_match($r,$f['district'])&&(!$f['channel']||install_channel($r)===$f['channel']))$candidates[]=$r;
    if(!$candidates)return [];
    // Also associate ownership in regional view, so the leaders/coaches detail is consistent.
    $owners=installation_sale_owners($candidates,$f['date']);
    $out=[];
    foreach($candidates as $r){
        $k=account_key((string)($r['account']??''));
        $owner=null;
        foreach($owners[$k]??[] as $s){
            if((string)($s['TS_CreacionHora']??'')<(string)($r['finished_at']??'')){$owner=$s;break;}
        }
        $hierarchy=null;
        if($owner){
            $saleDate=substr((string)($owner['fecha_cierre']??''),0,10);
            if(!preg_match('/^\d{4}-\d\d-\d\d$/',$saleDate))$saleDate=substr((string)$owner['TS_CreacionHora'],0,10);
            $hierarchy=hc_for_install_sale((string)($owner['folio_empleado']??''),$saleDate);
        }
        if($hc['allowed']!==null && (!$owner||!hc_permission($hc,(string)$owner['folio_empleado'],$hierarchy)))continue;
        if(hc_effective_scope($f['leader'],$hc)!=='' && !hc_match_scope($hierarchy,hc_effective_scope($f['leader'],$hc)))continue;
        if($owner)$r['_owner']=$owner;
        $r['_hierarchy']=$hierarchy;
        $out[]=$r;
    }
    return $out;
}
function hourly(array $rows,string $field): array {
    $h=array_fill(0,24,0);foreach($rows as $r){$s=(string)($r[$field]??'');if(preg_match('/\d{4}-\d\d-\d\d (\d\d):/',$s,$m))$h[(int)$m[1]]++;}return $h;
}
function hours_extrema(array $rows,string $field): array {if(!$rows)return ['—','—'];$times=array_filter(array_column($rows,$field));sort($times);return [$times?substr($times[0],11,5):'—',$times?substr(end($times),11,5):'—'];}
/** Exact plan match after uppercase, trimming and whitespace normalization.
 * Do not strip punctuation, infer bundle from the plan text or guess unknown values.
 */
function catalog_plan_key(?string $value): string {
    $v = preg_replace('/\s+/u', ' ', trim((string)$value)) ?? trim((string)$value);
    if (function_exists('mb_strtoupper')) return mb_strtoupper($v, 'UTF-8');
    return strtoupper(strtr($v, ['á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ü'=>'Ü','ñ'=>'Ñ']));
}
function positive_price(mixed $value): ?float {
    return is_numeric($value) && (float)$value>0 ? (float)$value : null;
}
/** Catalog attributes are resolved independently: conflicting duplicates stay unclassified. */
function catalog_index(array $rows): array {
    $out=[];
    foreach($rows as $r){
        $key=catalog_plan_key((string)($r['nombre_plan']??''));
        if($key===''||$key==='-')continue;
        $flag=keynorm((string)($r['bundle_app']??''));
        $bundle=in_array($flag,['si','no'],true)?$flag:'';
        $price=positive_price($r['precio_lista']??null);
        if(!isset($out[$key])){
            $out[$key]=['bundle_app'=>$bundle,'precio_lista'=>$price,'bundle_ambiguous'=>false,'price_ambiguous'=>false];
            continue;
        }
        $prev=&$out[$key];
        if(!$prev['bundle_ambiguous'] && $bundle!==$prev['bundle_app']){
            $prev['bundle_app']='';$prev['bundle_ambiguous']=true;
        }
        if(!$prev['price_ambiguous']){
            if($prev['precio_lista']===null && $price!==null) $prev['precio_lista']=$price;
            elseif($prev['precio_lista']!==null && $price!==null && abs($prev['precio_lista']-$price)>.005){
                $prev['precio_lista']=null;$prev['price_ambiguous']=true;
            }
        }
        unset($prev);
    }
    return $out;
}
/** Catálogo servido exclusivamente por conexion.php (base del sistema). */
function catalog(): array {
    if(!ref_db()->has('catalogo_paquetes'))return [];
    $columns=[];
    foreach(column_names('catalogo_paquetes',ref_db()) as $c)$columns[strtolower($c)]=$c;
    if(!isset($columns['nombre_plan']))return [];
    $price=isset($columns['precio_lista'])?'`'.$columns['precio_lista'].'` AS precio_lista':'NULL AS precio_lista';
    $bundle=isset($columns['bundle_app'])?'`'.$columns['bundle_app'].'` AS bundle_app':'NULL AS bundle_app';
    return catalog_index(ref_db()->all("SELECT `{$columns['nombre_plan']}` AS nombre_plan, $price, $bundle FROM catalogo_paquetes"));
}
/** SI = bundle, NO = traditional, any other value = unclassified. */
function bundle_distribution(array $rows,array $cat,string $field): array {
    $count=['total'=>0,'bundle'=>0,'traditional'=>0,'unclassified'=>0];
    foreach($rows as $r){
        $count['total']++;
        $p=$cat[catalog_plan_key((string)($r[$field]??''))]??null;
        $bundle=$p['bundle_app']??'';
        $kind=$bundle==='si'?'bundle':($bundle==='no'?'traditional':'unclassified');
        $count[$kind]++;
    }
    return $count;
}
/** Corporate ARPU: SUM(precio_lista / 1.16) / unique accounts with valid price.
 * Installation's positive price, when provided, takes precedence over the current
 * catalog price. Contradictory prices/plans on one account are not chosen arbitrarily.
 */
function arpu_for(array $inst,array $cat): array {
    $accounts=[];
    foreach($inst as $idx=>$r){
        $city=territory($r);
        $account=account_key((string)($r['account']??''));
        $id=$account!==''?'account:'.$account:'missing:'.($r['id']??$idx);
        foreach(array_unique(['all',$city]) as $group)$accounts[$group][$id][]=$r;
    }
    $out=[];
    foreach($accounts as $group=>$byAccount){
        $g=['count'=>count($byAccount),'valid'=>0,'sum'=>0.0,'arpu'=>null,'details'=>[],'excluded'=>[]];
        foreach($byAccount as $id=>$records){
            $representative=$records[0];$h=$representative['_hierarchy']??[];$owner=$representative['_owner']??[];
            $plans=[];$installationPrices=[];
            foreach($records as $r){
                $plan=catalog_plan_key((string)($r['package_label']??''));
                if($plan!=='')$plans[$plan]=true;
                $price=positive_price($r['precio_lista']??null);
                if($price!==null)$installationPrices[number_format($price,2,'.','')]=$price;
            }
            $plan=count($plans)===1?(string)array_key_first($plans):'';
            $price=null;$source='';$invalidField='';$invalidReason='';
            if(str_starts_with((string)$id,'missing:')){
                $invalidField='account';$invalidReason='Número de cuenta ausente o inválido';
            }elseif(count($plans)>1){
                $invalidField='package_label';$invalidReason='La cuenta tiene paquetes diferentes: '.implode(' / ',array_keys($plans));
            }elseif(count($installationPrices)>1){
                $invalidField='precio_lista';$invalidReason='La cuenta tiene precios diferentes: '.implode(' / ',array_keys($installationPrices));
            }elseif(count($installationPrices)===1){
                $price=(float)reset($installationPrices);$source='FieldCloud';
            }elseif($plan===''){
                $invalidField='package_label';$invalidReason='Paquete de instalación vacío';
            }elseif(!isset($cat[$plan])){
                $invalidField='package_label';$invalidReason='Paquete no encontrado en catalogo_paquetes';
            }elseif(!empty($cat[$plan]['price_ambiguous'])){
                $invalidField='precio_lista';$invalidReason='El catálogo presenta precios contradictorios';
            }else{
                $price=positive_price($cat[$plan]['precio_lista']??null);
                $source='Catálogo actual';
                if($price===null){$invalidField='precio_lista';$invalidReason='Precio de lista ausente, cero o no válido';}
            }
            $detail=[
                'account'=>(string)($representative['account']??''),
                'ot'=>implode(' / ',array_values(array_unique(array_filter(array_map(static fn($r)=>trim((string)($r['ot']??'')),$records))))),
                'date'=>substr((string)($representative['finished_at']??$representative['event_date']??''),0,10),
                'plan'=>count($plans)>1?implode(' / ',array_keys($plans)):(string)($representative['package_label']??''),
                'observed_prices'=>count($installationPrices)>1?implode(' / ',array_keys($installationPrices)):'',
                'source'=>$source,'price'=>$price,'net'=>$price!==null?round($price/1.16,2):null,
                'district'=>territory($representative),
                'leader'=>(string)($h['leader_name']??$owner['jefe_ventas']??''),
                'coach'=>(string)($h['coach_name']??$owner['jefe_plaza']??''),
                'seller'=>(string)($h['seller_name']??$owner['nombre_empleado']??''),
                'channel'=>(string)($representative['sales_channel']??''),
                'invalid_field'=>$invalidField,'invalid_reason'=>$invalidReason,
            ];
            if($invalidField!==''){$g['excluded'][]=$detail;continue;}
            $g['valid']++;$g['sum']+=$price/1.16;$g['details'][]=$detail;
        }
        $g['arpu']=$g['valid']?round($g['sum']/$g['valid'],2):null;
        $out[$group]=$g;
    }
    return $out;
}
/** Sales target derived from installations goal, never the stored sales_target. */
function sales_target_from_installations(int $target): int {return $target>0?intdiv($target*11+9,10):0;}
function targets_for(string $date): array {
    [$start,$end]=week_bounds($date);
    $out=[];foreach(db()->all("SELECT * FROM pulso_targets WHERE target_date BETWEEN ? AND ? ORDER BY CASE WHEN target_source='manual' THEN 0 ELSE 1 END, updated_at DESC",[$start,$end]) as $r){$key=district_key((string)$r['city_norm']);if($key===''||isset($out[$key]))continue;if((string)$r['sales_channel_norm']!=='')continue;$out[$key]=$r;}
    return $out;
}
function sync_weekly_targets(string $date): void {
    $wp=db(); $ref=ref_db();
    $table=$ref->has('metas_instalacion_semanal')?'metas_instalacion_semanal':($ref->has('metas_instalacion_semana')?'metas_instalacion_semana':'');
    if(!$table)return;
    $cols=array_map('keynorm',column_names($table,$ref));$names=column_names($table,$ref);$pick=static function(array $options)use($cols,$names){foreach($options as $opt){$i=array_search(keynorm($opt),$cols,true);if($i!==false)return '`'.$names[$i].'`';}return '';};
    $district=$pick(['distrito','nombre_distrito']);$year=$pick(['anio','año','year']);$week=$pick(['semana','week']);$meta=$pick(['meta','meta_instalacion','meta_instalaciones','meta_semanal','objetivo']);if(!$district||!$year||!$week||!$meta)return;
    $dt=new DateTimeImmutable($date);$yr=(int)$dt->format('o');$wk=(int)$dt->format('W');[$start,$end]=week_bounds($date);
    foreach($ref->all("SELECT $district AS nombre, SUM($meta) AS total FROM `$table` WHERE $year=? AND $week=? GROUP BY $district",[$yr,$wk]) as $r){$city=district_key((string)$r['nombre']);$value=(float)$r['total'];if($city===''||$value<=0)continue;
       $exists=$wp->val("SELECT id FROM pulso_targets WHERE target_date BETWEEN ? AND ? AND city_norm=? AND sales_channel_norm='' LIMIT 1",[$start,$end,$city]);
       if($exists)continue;
       $sourceRef="xpedient:$table:$yr:$wk:$city";
       if($wp->has('pulso_ignored_targets')&&$wp->val('SELECT source_ref FROM pulso_ignored_targets WHERE source_ref=?',[$sourceRef]))continue;
       $now=date('Y-m-d H:i:s');$wp->insert('pulso_targets',['target_date'=>$start,'city_norm'=>$city,'district_norm'=>$city,'sales_channel_norm'=>'','sales_target'=>0,'installs_target'=>(int)ceil($value/7),'target_source'=>'xpedient','source_ref'=>$sourceRef,'created_at'=>$now,'updated_at'=>$now]);
    }
}
/** Seguimiento operativo: última CARGA FIELDCLOUD, no carga Seguimiento Diario.
 * Reproduce el universo de WP v1.0.180 (estado vigente por OT, ventana de 14 días).
 * La fecha del snapshot es la de importación; no es necesariamente la hora de corte
 * ni corresponde a la última cita/agenda que tenga la orden.
 */
function backlog(string $date,array $filter,array $inst,array $hc): array {
    $import=db()->row("SELECT id,created_at,file_name FROM pulso_imports
        WHERE source='fieldcloud' AND (inserted_rows+updated_rows)>0
          AND (min_event_date IS NULL OR min_event_date<=?)
          AND (max_event_date IS NULL OR max_event_date>=?)
        ORDER BY id DESC LIMIT 1",[$date,$date]);
    if(!$import)return ['rows'=>[],'byCity'=>[],'snapshot'=>null,'source'=>'FieldCloud'];
    $snapshot=(string)($import['created_at']??'');
    $dateStart=date_shift($date,-14).' 00:00:00';$dateEnd=$date.' 23:59:59';
    $rows=db()->all("SELECT * FROM pulso_records WHERE source='fieldcloud' AND import_id=?
        AND LOWER(TRIM(type_label)) IN ('instalación','instalacion') ORDER BY source_row,id",[(int)$import['id']]);
    // Para preservar la foto vigente por orden se elige la aparición con la última
    // actividad operativa, luego su posición en el archivo y finalmente id.
    $byOrder=[];
    foreach($rows as $r){
        if(keynorm((string)($r['type_label']??''))!=='instalacion')continue;
        $operationalAt='';
        foreach(['ffm_created_at','last_schedule_at','confirmation_at','finished_at'] as $field){
            $candidate=(string)($r[$field]??'');
            if($candidate!=='' && $candidate>$operationalAt)$operationalAt=$candidate;
        }
        if($operationalAt<$dateStart || $operationalAt>$dateEnd)continue;
        $key=trim((string)($r['ot']??''));
        if($key==='')$key=trim((string)($r['os']??''));
        if($key==='')$key=account_key((string)($r['account']??''));
        if($key==='')continue;
        $key=keynorm($key);
        $r['_last_activity']=$operationalAt;
        $prev=$byOrder[$key]??null;
        if(!$prev||[$operationalAt,(int)($r['source_row']??0),(int)($r['id']??0)] >
            [(string)($prev['_last_activity']??''),(int)($prev['source_row']??0),(int)($prev['id']??0)]){
            $byOrder[$key]=$r;
        }
    }
    if(!hc_selected_valid($filter,$hc))return ['rows'=>[],'byCity'=>[],'snapshot'=>$snapshot,'source'=>'FieldCloud'];
    $scope=hc_effective_scope($filter['leader'],$hc);
    $needsOwner=$hc['allowed']!==null||$scope!=='';
    $candidates=array_values($byOrder);
    $salesByAccount=$needsOwner?installation_sale_owners($candidates,$date):[];
    $by=[];$items=[];$instAccounts=[];
    foreach($inst as $r)if(keynorm((string)($r['confirmation']??''))==='confirmada'){
        $instAccounts[account_key((string)($r['account']??''))]=true;
    }
    foreach($candidates as $r){
        $status=keynorm((string)($r['status_label']??''));
        if(!in_array($status,['asignada','pendiente','detenida'],true))continue;
        if(!empty($r['is_completed']))continue;
        if($status==='pendiente' && keynorm((string)($r['confirmation']??''))!=='confirmada')continue;
        if(!district_match($r,$filter['district']))continue;
        if($filter['channel'] && install_channel($r)!==$filter['channel'])continue;
        $key=account_key((string)($r['account']??''));
        if($needsOwner){
            $authorized=false;
            foreach($salesByAccount[$key]??[] as $sale){
                $saleDate=substr((string)($sale['fecha_cierre']??''),0,10);
                if(!preg_match('/^\\d{4}-\\d\\d-\\d\\d$/',$saleDate))$saleDate=substr((string)($sale['TS_CreacionHora']??''),0,10);
                $hierarchy=hc_for_install_sale((string)($sale['folio_empleado']??''),$saleDate);
                if(hc_permission($hc,(string)($sale['folio_empleado']??''),$hierarchy)&&hc_match_scope($hierarchy,$scope)){
                    $authorized=true; $r['_owner']=$sale;$r['_hierarchy']=$hierarchy;break;
                }
            }
            if(!$authorized)continue;
        }
        // Utilizar el mismo estado canónico en el conteo y en sus detalles.
        // status_label conserva su valor original de FieldCloud para auditoría.
        $col=['asignada'=>'assigned','pendiente'=>'pending','detenida'=>'detained'][$status];
        $r['_realizada']=isset($instAccounts[$key]);$r['_status']=$col;
        $city=territory($r);
        if(!isset($by[$city]))$by[$city]=['assigned'=>0,'pending'=>0,'detained'=>0,'done'=>0,'total'=>0];
        $by[$city][$col]++;$by[$city]['total']++;
        if($r['_realizada'])$by[$city]['done']++;
        $items[]=$r;
    }
    return ['rows'=>$items,'byCity'=>$by,'snapshot'=>$snapshot,'source'=>'FieldCloud','import_id'=>(int)$import['id']];
}
function arpu_variance(?float $today,?float $prior): array {
    if($today===null||$prior===null)return ['amount'=>null,'percent'=>null];
    $diff=round($today-$prior,2);
    return ['amount'=>$diff,'percent'=>$prior>0?round($diff/$prior*100,1):null];
}
/** Same primary FieldCloud ND universe as the WordPress director detail.
 * Cancelada: finished_at on the selected date; Reagendada: last_schedule_at.
 * Reagendada is excluded if the account has another primary Terminada order.
 * Counts are by OT/FieldCloud row, not unique account.
 */
function nd_for_date(array $f,array $hc): array {
    if(!hc_selected_valid($f,$hc))return [];
    $sql="SELECT *, CASE WHEN LOWER(TRIM(status_label))='cancelada' THEN finished_at ELSE last_schedule_at END AS nd_effective_at
          FROM pulso_records WHERE source='fieldcloud' AND is_primary=1
          AND LOWER(TRIM(state_label)) IN ('cancelada','reagendada')
          AND account IS NOT NULL AND TRIM(account)<>''
          AND ((LOWER(TRIM(status_label))='cancelada' AND finished_at>=? AND finished_at<?)
            OR (LOWER(TRIM(status_label))<>'cancelada' AND last_schedule_at>=? AND last_schedule_at<?))
          ORDER BY nd_effective_at,id";
    $next=date_shift($f['date'],1);
    $rows=db()->all($sql,[$f['date'].' 00:00:00',$next.' 00:00:00',$f['date'].' 00:00:00',$next.' 00:00:00']);
    $candidates=[];$accounts=[];
    foreach($rows as $r){
        if(!district_match($r,$f['district']) || ($f['channel']!=='' && install_channel($r)!==$f['channel']))continue;
        $key=account_key((string)$r['account']);if($key==='')continue;
        $candidates[]=$r;$accounts[$key]=true;
    }
    if(!$candidates)return [];
    // Indexed in batches; only the accounts in this operative cut are inspected.
    $term=[];
    foreach(array_chunk(array_keys($accounts),160) as $chunk){
        $alts=[];
        foreach($chunk as $a)foreach([$a,'0'.$a] as $v)$alts[$v]=true;
        $place=implode(',',array_fill(0,count($alts),'?'));
        foreach(db()->all("SELECT id,account FROM pulso_records WHERE source='fieldcloud' AND is_primary=1 AND LOWER(TRIM(status_label))='terminada' AND account IN ($place)",array_keys($alts)) as $row){
            $term[account_key((string)$row['account'])][(int)$row['id']]=true;
        }
    }
    $eligible=[];
    foreach($candidates as $r){
        $key=account_key((string)$r['account']);
        if(keynorm((string)$r['state_label'])==='reagendada' && !empty($term[$key])){
            // The current row cannot be its own different completed order.
            $different=array_diff(array_keys($term[$key]),[(int)$r['id']]);
            if($different)continue;
        }
        $eligible[]=$r;
    }
    if(!$eligible)return [];
    // WordPress ND owner resolution uses the most recent Salesforce owner for the account,
    // without a date restriction; the district and HC permissions still apply.
    $owners=installation_sale_owners($eligible,'9999-12-31');
    $scope=hc_effective_scope($f['leader'],$hc);
    $out=[];
    foreach($eligible as $r){
        $account=account_key((string)$r['account']);$owner=null;$hierarchy=null;
        foreach($owners[$account]??[] as $sale){
            if(territory($sale)!==territory($r))continue;
            $date=substr((string)($sale['fecha_cierre']??''),0,10);
            if(!preg_match('/^\d{4}-\d\d-\d\d$/',$date))$date=substr((string)$sale['TS_CreacionHora'],0,10);
            $h=hc_for_install_sale((string)($sale['folio_empleado']??''),$date);
            if(!hc_permission($hc,(string)($sale['folio_empleado']??''),$h))continue;
            if($scope!=='' && !hc_match_scope($h,$scope))continue;
            $owner=$sale;$hierarchy=$h;break;
        }
        // WordPress's ND detail only publishes records with an attributable sale.
        if($owner===null || $hierarchy===null)continue;
        $r['_owner']=$owner;$r['_hierarchy']=$hierarchy;$r['_nd_at']=$r['nd_effective_at'];
        $out[]=$r;
    }
    return $out;
}
/** Hora de corte: el bloque 09:00–09:59 cierra a las 10:00.
 * Igual que WordPress, FieldCloud gobierna el corte; usa la hora del nombre
 * del reporte (1000-horas) si existe, o redondea la hora de carga al entero
 * más próximo. Nunca se acepta un corte futuro para la fecha de hoy.
 */
function pulso_cutoff_from_filename(?string $file): ?int {
    $stem=pathinfo((string)$file,PATHINFO_FILENAME);
    if(preg_match('/(?:^|[^0-9])([01]?[0-9]|2[0-3])[:._-]?([0-5][0-9])[-_\\s]*(?:horas?|hrs?)(?:$|[^a-z])/i',$stem,$m)){
        return (int)$m[1]+((int)$m[2]>=30?1:0);
    }
    return null;
}
function pulso_daily_cutoff(string $date): array {
    $isToday=$date===date('Y-m-d');
    $max=$isToday?(int)date('G'):24;
    $rows=db()->all("SELECT id,file_name,created_at,source,min_event_date,max_event_date
        FROM pulso_imports WHERE source='fieldcloud' AND (min_event_date IS NULL OR min_event_date<=?)
          AND (max_event_date IS NULL OR max_event_date>=?)
        ORDER BY id DESC LIMIT 1",[$date,$date]);
    if(!$rows){
        $rows=db()->all("SELECT id,file_name,created_at,source,min_event_date,max_event_date
            FROM pulso_imports WHERE (min_event_date IS NULL OR min_event_date<=?)
              AND (max_event_date IS NULL OR max_event_date>=?)
            ORDER BY id DESC LIMIT 1",[$date,$date]);
    }
    if(!$rows)return ['hour'=>$max,'source'=>$isToday?'reloj_local':'dia_completo','import'=>null];
    $row=$rows[0];
    $declared=pulso_cutoff_from_filename((string)($row['file_name']??''));
    $source='nombre_archivo';
    if($declared===null){
        $stamp=(string)($row['created_at']??'');
        if(substr($stamp,0,10)!==$date)return ['hour'=>$max,'source'=>'dia_completo','import'=>$row];
        $declared=(int)substr($stamp,11,2)+((int)substr($stamp,14,2)>=30?1:0);
        $source='hora_importacion';
    }
    return ['hour'=>min($max,max(0,$declared)),'source'=>$source,'import'=>$row];
}
/**
 * Universo de ventas: la fecha operativa ES fecha_cierre, no TS_CreacionHora.
 * Si la oportunidad fue creada en otro día (o no tiene marca horaria),
 * se contabiliza en su fecha de cierre, pero no participa en Ritmo del día.
 * Cuando la creación sí ocurre el día del cierre, se aplica el corte horario
 * exclusivo para no contar eventos futuros del mismo día.
 */
function pulso_sale_counts_at_cutoff(array $row,string $date,int $closingHour): bool {
    if(substr((string)($row['fecha_cierre']??''),0,10)!==$date)return false;
    $stamp=(string)($row['TS_CreacionHora']??'');
    if(!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d/', $stamp))return true;
    if(substr($stamp,0,10)!==$date)return true;
    return $closingHour>=24 || $stamp<$date.' '.sprintf('%02d:00:00',$closingHour);
}
/** Las ventas solo tienen posición horaria si creación y cierre son del mismo día. */
function pulso_sale_charts_at_cutoff(array $row,string $date,int $closingHour): bool {
    $stamp=(string)($row['TS_CreacionHora']??'');
    return substr((string)($row['fecha_cierre']??''),0,10)===$date
        && pulso_before_cutoff($row,'TS_CreacionHora',$date,$closingHour);
}
/** El día consultado se filtra con límite exclusivo; también el comparativo (-7 días). */
function pulso_before_cutoff(array $row,string $field,string $date,int $closingHour): bool {
    $timestamp=(string)($row[$field]??'');
    if(!preg_match('/^\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d/', $timestamp))return false;
    if(substr($timestamp,0,10)!==$date)return false;
    return $closingHour>=24 || $timestamp < $date.' '.sprintf('%02d:00:00',$closingHour);
}
/** Agrupa por el bloque horario indicado, sin límite de fecha histórico. */
function pulso_historical_hour_record(array $f,array $hc,int $closingHour): array {
    $hour=$closingHour-1;
    if($hour<0)return ['sales'=>0,'installs'=>0,'hour'=>0,'source'=>'Sin hora completa'];
    $dateMax=$f['date'];$saleCounts=[];$instCounts=[];
    $scope=hc_effective_scope($f['leader'],$hc);
    $needsOwner=$scope!==''||$hc['allowed']!==null;
    if(!$needsOwner){
        $sales=db()->all("SELECT fecha_cierre AS dia, distrito, canal_venta, COUNT(*) AS n FROM pulso_ventas
          WHERE TS_CreacionHora IS NOT NULL AND HOUR(TS_CreacionHora)=? AND DATE(TS_CreacionHora)=fecha_cierre AND fecha_cierre<=?
          GROUP BY fecha_cierre,distrito,canal_venta",[$hour,$dateMax]);
        foreach($sales as $r){
            if(!district_match($r,$f['district'])||($f['channel']&&sale_channel($r)!==$f['channel']))continue;
            $day=(string)$r['dia'];$saleCounts[$day]=($saleCounts[$day]??0)+(int)$r['n'];
        }
        $installs=db()->all("SELECT event_date AS dia,district,city,sales_channel,COUNT(*) AS n FROM pulso_records
          WHERE source='fieldcloud' AND LOWER(TRIM(type_label)) IN ('instalación','instalacion')
            AND LOWER(TRIM(status_label))='terminada' AND is_completed=1
            AND HOUR(finished_at)=? AND DATE(finished_at)=event_date AND event_date<=?
          GROUP BY event_date,district,city,sales_channel",[$hour,$dateMax]);
        foreach($installs as $r){
            if(!district_match($r,$f['district'])||($f['channel']&&install_channel($r)!==$f['channel']))continue;
            $day=(string)$r['dia'];$instCounts[$day]=($instCounts[$day]??0)+(int)$r['n'];
        }
    } else {
        // Contextos personales: resolver HC antes de contar, sin devolver un récord regional.
        $sales=db()->all("SELECT * FROM pulso_ventas WHERE HOUR(TS_CreacionHora)=? AND DATE(TS_CreacionHora)=fecha_cierre AND fecha_cierre<=?",[$hour,$dateMax]);
        foreach($sales as $r){
            if(!district_match($r,$f['district'])||($f['channel']&&sale_channel($r)!==$f['channel']))continue;
            $day=(string)$r['fecha_cierre'];$hier=hc_for_sale_folio((string)($r['folio_empleado']??''),$day);
            if(!hc_permission($hc,(string)($r['folio_empleado']??''),$hier)||!hc_match_scope($hier,$scope))continue;
            $saleCounts[$day]=($saleCounts[$day]??0)+1;
        }
        $installs=db()->all("SELECT * FROM pulso_records WHERE source='fieldcloud'
          AND LOWER(TRIM(type_label)) IN ('instalación','instalacion') AND LOWER(TRIM(status_label))='terminada'
          AND is_completed=1 AND HOUR(finished_at)=? AND DATE(finished_at)=event_date AND event_date<=?",[$hour,$dateMax]);
        $candidates=array_values(array_filter($installs,static fn($r)=>district_match($r,$f['district'])&&(!$f['channel']||install_channel($r)===$f['channel'])));
        // Agrupar por fecha para que la venta titular nunca sea posterior a la instalación.
        $perDay=[];foreach($candidates as $r)$perDay[(string)$r['event_date']][]=$r;
        foreach($perDay as $day=>$items){
            $owners=installation_sale_owners($items,$day);
            foreach($items as $r){
                $key=account_key((string)($r['account']??''));
                foreach($owners[$key]??[] as $sale){
                    if((string)($sale['TS_CreacionHora']??'')>=(string)($r['finished_at']??''))continue;
                    $saleDate=substr((string)($sale['fecha_cierre']??''),0,10);
                    $hier=hc_for_install_sale((string)($sale['folio_empleado']??''),$saleDate);
                    if(hc_permission($hc,(string)($sale['folio_empleado']??''),$hier)&&hc_match_scope($hier,$scope)){
                        $instCounts[$day]=($instCounts[$day]??0)+1;break;
                    }
                }
            }
        }
    }
    return ['sales'=>max([0,...array_values($saleCounts)]),'installs'=>max([0,...array_values($instCounts)]),
            'hour'=>$hour,'source'=>'Historial importado de Pulso'];
}
function daily_report(array $f): array {
    $hc=ctx_hc($f['date']);
    $ownDistrict=hc_user_district($hc);
    if($ownDistrict!=='')$f['district']=$ownDistrict;
    // El Director filtra por distrito, igual que el selector Ciudad. No se
    // cambia el alcance de la sesión ni se concede acceso a otros distritos.
    $directorDistrict=pulso_director_district($f,$hc);
    if($directorDistrict!=='')$f['district']=$directorDistrict;
    $cutoffInfo=pulso_daily_cutoff($f['date']);$cutoff=(int)$cutoffInfo['hour'];
    $sales=array_values(array_filter(sales_for_date($f,$hc),static fn($r)=>pulso_sale_counts_at_cutoff($r,$f['date'],$cutoff)));
    $inst=array_values(array_filter(installs_for_date($f,$hc),static fn($r)=>pulso_before_cutoff($r,'finished_at',$f['date'],$cutoff)));
    $nd=nd_for_date($f,$hc);
    $prev=$f;$prev['date']=date_shift($f['date'],-7);
    // Para Director el comparable semanal es el distrito, aun si cambió
    // la posición/ocupante HC entre las dos fotografías semanales.
    if($directorDistrict!=='')$prev['leader']='';
    $prevHc=ctx_hc($prev['date']);
    $lastSales=array_values(array_filter(sales_for_date($prev,$prevHc),static fn($r)=>pulso_sale_counts_at_cutoff($r,$prev['date'],$cutoff)));
    $lastInst=array_values(array_filter(installs_for_date($prev,$prevHc),static fn($r)=>pulso_before_cutoff($r,'finished_at',$prev['date'],$cutoff)));
    $allInst=db()->all("SELECT * FROM pulso_records WHERE event_date=? AND source='fieldcloud' AND is_completed=1 AND LOWER(TRIM(type_label)) IN ('instalación','instalacion')",[$f['date']]);
    $catalogData=catalog();$arpu=arpu_for($inst,$catalogData);
    // ISO previous week (Monday through Sunday), same authorization and selected filters.
    [$monday]=week_bounds($f['date']);
    $previousWeekStart=date_shift($monday,-7);$previousWeekEnd=date_shift($monday,-1);
    $prevWeekInst=[];
    if($hc['allowed']===null&&hc_effective_scope($f['leader'],$hc)===''){
        // For unrestricted regional reports ARPU only needs the installation facts,
        // so avoid seven owner/HC joins for the previous week.
        $previousFacts=db()->all("SELECT * FROM pulso_records WHERE event_date BETWEEN ? AND ? AND source='fieldcloud' AND LOWER(TRIM(type_label)) IN ('instalación','instalacion') AND LOWER(TRIM(status_label))='terminada' AND is_completed=1",[$previousWeekStart,$previousWeekEnd]);
        foreach($previousFacts as $rec){
            if(district_match($rec,$f['district'])&&(!$f['channel']||install_channel($rec)===$f['channel']))$prevWeekInst[]=$rec;
        }
    }else{
        for($day=$previousWeekStart;$day<=$previousWeekEnd;$day=date_shift($day,1)){
            $weekFilter=$f;$weekFilter['date']=$day;
            // Keep the current authorized scope and district for the historic week.
            foreach(installs_for_date($weekFilter,$hc) as $rec)$prevWeekInst[]=$rec;
        }
    }
    $arpuPreviousWeek=arpu_for($prevWeekInst,$catalogData);
    sync_weekly_targets($f['date']);$targets=targets_for($f['date']);$backlog=backlog($f['date'],$f,$allInst,$hc);
    // Nodos de la gráfica por HORA DE CIERRE: 09:00–09:59 se dibuja a las 10:00.
    $saleHours=array_fill(0,25,0);$instHours=array_fill(0,25,0);
    $prevSaleHours=array_fill(0,25,0);$prevInstHours=array_fill(0,25,0);
    foreach([[$sales,'TS_CreacionHora',0,$f['date']],[$inst,'finished_at',1,$f['date']],[$lastSales,'TS_CreacionHora',2,$prev['date']],[$lastInst,'finished_at',3,$prev['date']]] as [$rows,$field,$series,$day]){
        foreach($rows as $r){
            // Una venta creada otro día sí se cuenta, pero nunca se grafica.
            if(($series===0||$series===2)&&!pulso_sale_charts_at_cutoff($r,$day,$cutoff))continue;
            if(preg_match('/^\d{4}-\d\d-\d\d (\d\d):/',(string)($r[$field]??''),$match)){
                $closing=(int)$match[1]+1;
                if($closing<=$cutoff){
                    if($series===0)$saleHours[$closing]++;
                    elseif($series===1)$instHours[$closing]++;
                    elseif($series===2)$prevSaleHours[$closing]++;
                    else $prevInstHours[$closing]++;
                }
            }
        }
    }
    $record=pulso_historical_hour_record($f,$hc,$cutoff);
    // Una serie histórica no se extiende más allá del corte del día seleccionado.
    for($hour=$cutoff+1;$hour<=24;$hour++){
        $saleHours[$hour]=$instHours[$hour]=$prevSaleHours[$hour]=$prevInstHours[$hour]=null;
    }
    $cities=$f['district']!==''?[$f['district']]:['cancun','coatza_mina','merida','tuxtla','villahermosa'];$districts=[];
    foreach($cities as $c){
        $s=array_values(array_filter($sales,static fn($r)=>territory($r)===$c));
        $in=array_values(array_filter($inst,static fn($r)=>territory($r)===$c));
        [$firstInstallation]=hours_extrema($in,'finished_at');
        $currentARPU=$arpu[$c]['arpu']??null;
        $previousARPU=$arpuPreviousWeek[$c]['arpu']??null;
        $districts[$c]=[
            'sales'=>count($s),'installs'=>count($in),'target'=>$targets[$c]??null,
            'arpu'=>$currentARPU,'previousArpu'=>$previousARPU,'arpuDelta'=>arpu_variance($currentARPU,$previousARPU),
            'firstInstallation'=>$firstInstallation,
            'backlog'=>$backlog['byCity'][$c]??null,
            'bundleSales'=>bundle_distribution($s,$catalogData,'nombre_plan'),
            'bundleInstalls'=>bundle_distribution($in,$catalogData,'package_label')
        ];
    }
    return compact('f','hc','sales','inst','lastSales','lastInst','arpu','arpuPreviousWeek','previousWeekStart','previousWeekEnd','targets','backlog','saleHours','instHours','prevSaleHours','prevInstHours','districts','cutoff','cutoffInfo','record','nd','prevWeekInst');
}

// ============================= Talia Pulso / details =============================
/** Standalone v1.0.9: auditable drilldowns, including numeric HC position IDs. */


function tp_detail_dataset(string $title, array $columns, array $rows): array {
    return ['title'=>$title,'columns'=>$columns,'rows'=>$rows];
}
function tp_detail_sales(array $rows): array {
    $out=[];
    foreach($rows as $r){$h=$r['_hierarchy']??[];
        $out[]=[substr((string)($r['TS_CreacionHora']??''),0,16),(string)($r['id_cuenta_brm']??''),(string)($r['numero_oportunidad']??''),district_label(territory($r)),(string)($r['canal_venta']??''),
        (string)($h['leader_name']??$r['jefe_ventas']??''),(string)($h['coach_name']??$r['jefe_plaza']??''),(string)($h['seller_name']??$r['nombre_empleado']??'')];
    }
    return tp_detail_dataset('Ventas contabilizadas',['Fecha / hora','Cuenta','Oportunidad','Distrito','Canal','Líder','Coach','Vendedor'],$out);
}
function tp_detail_installs(array $rows): array {
    $out=[];
    foreach($rows as $r){$h=$r['_hierarchy']??[];$s=$r['_owner']??[];
        $out[]=[substr((string)($r['finished_at']??''),0,16),(string)($r['account']??''),(string)($r['os']??''),(string)($r['ot']??''),district_label(territory($r)),
        (string)($h['leader_name']??$s['jefe_ventas']??''),(string)($h['coach_name']??$s['jefe_plaza']??''),(string)($h['seller_name']??$s['nombre_empleado']??''),(string)($r['package_label']??'')];
    }
    return tp_detail_dataset('Instalaciones contabilizadas',['Fecha / hora','Cuenta','OS','OT','Distrito','Líder','Coach','Vendedor','Paquete'],$out);
}
function tp_detail_nd(array $rows): array {
    $out=[];
    foreach($rows as $r){$h=$r['_hierarchy']??[];$s=$r['_owner']??[];
        $out[]=[substr((string)($r['_nd_at']??''),0,16),(string)($r['account']??''),(string)($r['os']??''),(string)($r['ot']??''),district_label(territory($r)),
        (string)($h['leader_name']??$s['jefe_ventas']??''),(string)($h['coach_name']??$s['jefe_plaza']??''),(string)($h['seller_name']??$s['nombre_empleado']??''),
        (string)($r['status_label']??''),(string)($r['state_label']??''),(string)($r['reason_label']??'')];
    }
    return tp_detail_dataset('ND · Canceladas y reagendadas',['Fecha ND','Cuenta','OS','OT','Distrito','Líder','Coach','Vendedor','Estatus','Estado','Motivo'],$out);
}
function tp_detail_followup(array $rows): array {
    $out=[];
    foreach($rows as $r){
        $out[]=[(string)($r['account']??''),(string)($r['os']??''),(string)($r['ot']??''),district_label(territory($r)),
            (string)($r['status_label']??''),(string)($r['state_label']??''),(string)($r['confirmation']??''),substr((string)($r['last_schedule_at']??''),0,16),
            !empty($r['_realizada'])?'Sí':'No'];
    }
    return tp_detail_dataset('Seguimiento diario · registros contabilizados',['Cuenta','OS','OT','Distrito','Estatus','Estado','Confirmación','Última agenda','Realizada'],$out);
}
/** Audits all accounts: included and excluded. Each invalid cell is highlighted by the UI. */
function tp_detail_arpu(array $valid,array $excluded=[]): array {
    $out=[];$errors=[];
    foreach(array_merge($valid,$excluded) as $i=>$r){
        $invalid=(string)($r['invalid_field']??'');$used=$invalid==='';
        $out[]=[
            $used?'Incluida':'Excluida',(string)($r['date']??''),(string)($r['account']??''),(string)($r['ot']??''),
            (string)($r['plan']??''),(string)($r['source']??''),
            !empty($r['observed_prices'])?(string)$r['observed_prices']:(isset($r['price'])?'$'.number_format((float)$r['price'],2):'—'),
            isset($r['net'])?'$'.number_format((float)$r['net'],2):'—',
            district_label((string)($r['district']??'')),
            (string)($r['leader']??''),(string)($r['coach']??''),(string)($r['seller']??''),
            (string)($r['channel']??''),(string)($r['invalid_reason']??''),
        ];
        if(!$used){
            $col=['account'=>2,'package_label'=>4,'precio_lista'=>6][$invalid]??13;
            $errors[(string)$i]=[$col,$invalid,(string)($r['invalid_reason']??'')];
        }
    }
    $result=tp_detail_dataset('ARPU · auditoría de cuentas',[
        'Cómputo','Fecha','Cuenta','OT','Paquete FieldCloud','Fuente','Precio lista','Precio sin IVA',
        'Distrito','Líder','Coach','Vendedor','Canal','Motivo de exclusión'
    ],$out);
    $result['invalidCells']=$errors;
    $result['summary']=count($valid).' incluidas · '.count($excluded).' excluidas · '.count($out).' cuentas evaluadas';
    return $result;
}
/** The same position-based hierarchy is used for the counts and their detail rows. */
function tp_scope_contains(?array $hierarchy,string $role,string $district,string $position): bool {
    if(!$hierarchy || !$position || ($hierarchy['district']??'')!==$district)return false;
    $field=['lider'=>'leader_position','coach'=>'coach_id','experto'=>'seller_position'][$role]??'';
    return $field!=='' && (string)($hierarchy[$field]??'')===$position;
}
function tp_hierarchy_table(array $hc,string $selected,array $sales,array $inst,array $nd): array {
    $parts=explode('|',$selected,3);
    if(count($parts)!==3 || !in_array($parts[0],['director','lider','coach'],true))return [];
    [$role,$district,$position]=$parts;
    if(!$district||!$position)return [];
    $members=[];$map=[];
    foreach($hc['rows']??[] as $r){
        if(district_key((string)($r['distrito']??''))!==$district)continue;
        $id=trim((string)($r['id_posicion']??''));
        if($id!=='')$map[$id]=$r;
    }
    // PHP converts canonical numeric array keys to int; HC positions are logical strings.
    $add=function(string $r,string|int $id,string $name,int $depth=0)use(&$members,$district): void {
        $id=(string)$id;
        if($id===''||$name==='')return;
        $key=$r.'|'.$id;
        $members[$key]=['role'=>$r,'position'=>$id,'name'=>$name,'depth'=>$depth,'district'=>$district];
    };
    $personName=static fn(array $row):string=>trim((string)($row['nombre_colaborador']??''));
    if($role==='director'){
        foreach($map as $id=>$row){
            if(hc_position_role((string)($row['posicion']??''))!=='lider' || (string)($row['posicion_lr']??'')!==$position)continue;
            $add('lider',$id,$personName($row));
            foreach($map as $cid=>$coach){
                if(hc_position_role((string)($coach['posicion']??''))==='coach' && (string)($coach['posicion_lr']??'')===(string)$id)$add('coach',$cid,$personName($coach),1);
            }
        }
    }elseif($role==='lider'){
        foreach($map as $id=>$row)if(hc_position_role((string)($row['posicion']??''))==='coach' && (string)($row['posicion_lr']??'')===$position)$add('coach',$id,$personName($row));
    }else{
        foreach($map as $id=>$row){
            $p=keynorm((string)($row['posicion']??''));
            $seller=(str_contains($p,'vendedor')||str_contains($p,'experto')) && hc_position_role((string)($row['posicion']??''))==='';
            if($seller && (string)($row['posicion_lr']??'')===$position)$add('experto',$id,$personName($row));
        }
    }
    // Include observed positions missing from the displayed photograph (historical sales).
    foreach(array_merge($sales,$inst,$nd) as $r){$h=$r['_hierarchy']??null;if(!$h || ($h['district']??'')!==$district)continue;
        if($role==='director' && (string)($h['director_id']??'')===$position){
            $add('lider',(string)($h['leader_position']??''),(string)($h['leader_name']??''));
            $add('coach',(string)($h['coach_id']??''),(string)($h['coach_name']??''),1);
        }elseif($role==='lider' && (string)($h['leader_position']??'')===$position){
            $add('coach',(string)($h['coach_id']??''),(string)($h['coach_name']??''));
        }elseif($role==='coach' && (string)($h['coach_id']??'')===$position){
            $add('experto',(string)($h['seller_position']??''),(string)($h['seller_name']??''));
        }
    }
    $result=[];
    foreach($members as $key=>$member){
        $r=$member['role'];$p=$member['position'];
        $match=static fn($row):bool=>tp_scope_contains($row['_hierarchy']??null,$r,$district,$p);
        $member['salesRows']=array_values(array_filter($sales,$match));
        $member['instRows']=array_values(array_filter($inst,$match));
        $member['ndRows']=array_values(array_filter($nd,$match));
        $result[]=$member;
    }
    usort($result, static fn($a,$b)=>[$a['depth'],keynorm($a['name'])]<=>[$b['depth'],keynorm($b['name'])]);
    // Make nested leader/coach rows contiguous, not sorted across all leaders.
    if($role==='director'){
        $leaders=array_values(array_filter($result,static fn($r)=>$r['role']==='lider'));
        $coaches=array_values(array_filter($result,static fn($r)=>$r['role']==='coach'));
        $ordered=[];
        foreach($leaders as $leader){$ordered[]=$leader;
            foreach($coaches as $i=>$coach){
                $parent=$map[$coach['position']]['posicion_lr']??null;
                if((string)$parent===(string)$leader['position']){$ordered[]=$coach;unset($coaches[$i]);}
            }
        }
        foreach($coaches as $coach)$ordered[]=$coach;
        $result=$ordered;
    }
    return $result;
}

// ============================= Talia Pulso / reports =============================
/** v1.0.10: Two standalone views, sharing the exact authorized ND population. */


function tp_regional_report(string $date): array {
    $date=valid_date($date);
    $hc=ctx_hc($date);
    // WordPress regional report intentionally ignores UI filters, but never login permissions.
    $scope=['date'=>$date,'district'=>hc_user_district($hc),'channel'=>'','leader'=>''];
    return [
        'date'=>$date,'scope'=>$scope,'hc'=>$hc,
        'sales'=>sales_for_date($scope,$hc),
        'inst'=>installs_for_date($scope,$hc),
        'nd'=>nd_for_date($scope,$hc),
    ];
}
function tp_report_name(array $r,string $name): string {
    $h=$r['_hierarchy']??[];
    $fallback=$r['_owner']??$r;
    $field=['leader'=>'leader_name','coach'=>'coach_name','seller'=>'seller_name'][$name];
    $saleField=['leader'=>'jefe_ventas','coach'=>'jefe_plaza','seller'=>'nombre_empleado'][$name];
    $raw=trim((string)($h[$field]??$fallback[$saleField]??''));
    return $raw!==''?$raw:('Sin '.(['leader'=>'líder','coach'=>'coach','seller'=>'vendedor'][$name]));
}
/** Regional roster: only the HC positions displayed in the selector.
 * Records that cannot be attributed to a displayed HC Leader AND Coach
 * are excluded from this roster's totals, detail and ND executive report.
 * No rows or data are deleted from the source database or daily KPI queries.
 */
function tp_grouped_roster(array $data): array {
    $hc=$data['hc'];
    $scopeDistrict=(string)($data['scope']['district']??'');
    $options=hc_options($hc['rows']??[],$scopeDistrict,$hc['allowed_positions']??null);
    $leaders=[];$coaches=[];$rowIndex=[];
    foreach(($hc['rows']??[]) as $r){
        $district=district_key((string)($r['distrito']??''));
        $id=trim((string)($r['id_posicion']??''));
        $role=hc_position_role((string)($r['posicion']??''));
        if($district===''||$id===''||!in_array($role,['lider','coach'],true))continue;
        if(!isset($options[hc_scope_id($role,$district,$id)]))continue;
        $rowIndex[$district][$id]=$r;
    }
    foreach($options as $opt){
        $district=$opt['district'];$role=$opt['role'];
        if(!in_array($role,['lider','coach'],true))continue;
        $id=explode('|',(string)$opt['id'],3)[2]??'';
        if($role==='lider')$leaders[$district][$id]=['name'=>$opt['name'],'id'=>$id];
        else $coaches[$district][$id]=['name'=>$opt['name'],'id'=>$id,'hc'=>$rowIndex[$district][$id]??[]];
    }
    $districts=[];
    foreach(($scopeDistrict!==''?[$scopeDistrict]:['cancun','coatza_mina','merida','tuxtla','villahermosa']) as $d){
        $districts[$d]=['key'=>$d,'sales'=>[],'inst'=>[],'nd'=>[],
                        'leaders'=>[],'unlinked_coaches'=>0];
    }
    $parentMap=[];
    foreach($coaches as $district=>$group)foreach($group as $coachId=>$coach){
        $row=$coach['hc'];
        $parent=trim((string)($row['posicion_lr']??''));
        if($parent!==''&&isset($leaders[$district][$parent])){
            $parentMap[$district][$coachId]=$parent;
            continue;
        }
        // Same strict, unique fallback as hc_build_hierarchy() for self-reporting coaches.
        $reportName=hc_name_tokens((string)($row['nombre_linea_reporte']??''));
        if($reportName==='')continue;
        $matches=[];
        foreach(($leaders[$district]??[]) as $leaderId=>$leader){
            if(hc_name_tokens($leader['name'])===$reportName)$matches[]=(string)$leaderId;
        }
        if(count($matches)===1)$parentMap[$district][$coachId]=$matches[0];
    }
    foreach($leaders as $district=>$group)foreach($group as $leaderId=>$leader){
        if(!isset($districts[$district]))continue;
        $districts[$district]['leaders']['position:'.$leaderId]=[
            'name'=>$leader['name'],'sales'=>[],'inst'=>[],'nd'=>[],'coaches'=>[],
        ];
    }
    foreach($coaches as $district=>$group)foreach($group as $coachId=>$coach){
        if(!isset($districts[$district]))continue;
        $leaderId=$parentMap[$district][$coachId]??null;
        if($leaderId===null || !isset($districts[$district]['leaders']['position:'.$leaderId])){
            $districts[$district]['unlinked_coaches']++;
            continue;
        }
        $districts[$district]['leaders']['position:'.$leaderId]['coaches']['position:'.$coachId]=[
            'name'=>$coach['name'],'sales'=>[],'inst'=>[],'nd'=>[],
        ];
    }
    foreach(['sales','inst','nd'] as $kind)foreach($data[$kind]??[] as $record){
        $district=territory($record);
        if(!isset($districts[$district]))continue;
        $hierarchy=$record['_hierarchy']??null;
        $leaderId=trim((string)($hierarchy['leader_position']??''));
        $coachId=trim((string)($hierarchy['coach_id']??''));
        $leaderKey='position:'.$leaderId;
        $coachKey='position:'.$coachId;
        $linked=$leaderId!=='' && $coachId!==''
            && isset($districts[$district]['leaders'][$leaderKey]['coaches'][$coachKey]);
        if(!$linked)continue; // No se contabiliza si no corresponde a una fila visible.
        $districts[$district][$kind][]=$record;
        $districts[$district]['leaders'][$leaderKey][$kind][]=$record;
        $districts[$district]['leaders'][$leaderKey]['coaches'][$coachKey][$kind][]=$record;
    }
    foreach($districts as &$district){
        uasort($district['leaders'],static fn($a,$b)=>strcasecmp($a['name'],$b['name']));
        foreach($district['leaders'] as &$leader){
            uasort($leader['coaches'],static fn($a,$b)=>strcasecmp($a['name'],$b['name']));
        }
        unset($leader);
        foreach(['sales','inst','nd'] as $kind){
            $assigned=0;
            foreach($district['leaders'] as $leader)$assigned+=count($leader[$kind]);
            if($assigned!==count($district[$kind])){
                throw new LogicException('Roster HC sin conciliación: '.$district['key'].' / '.$kind);
            }
        }
    }
    unset($district);
    return $districts;
}
function tp_sorted_nd(array $rows): array {
    usort($rows,static function($a,$b): int {
        $orderA=[district_label(territory($a)),tp_report_name($a,'leader'),tp_report_name($a,'coach'),(string)($a['state_label']??'')];
        $orderB=[district_label(territory($b)),tp_report_name($b,'leader'),tp_report_name($b,'coach'),(string)($b['state_label']??'')];
        foreach($orderA as $index=>$value){
            $cmp=strcasecmp((string)$value,(string)$orderB[$index]);
            if($cmp!==0)return $cmp;
        }
        return strcmp((string)($a['_nd_at']??''),(string)($b['_nd_at']??''));
    });
    return $rows;
}
function tp_nd_summary(array $rows): array {
    $stats=['count'=>count($rows),'canceladas'=>0,'reagendadas'=>0,'reasons'=>[],'districts'=>[],'leaders'=>[]];
    foreach($rows as $r){
        $state=keynorm((string)($r['state_label']??''));
        if($state==='cancelada')$stats['canceladas']++;elseif($state==='reagendada')$stats['reagendadas']++;
        $reason=trim((string)($r['reason_label']??''))?:'Sin motivo';
        $reasonKey=keynorm($reason)?:'sin_motivo';
        if(!isset($stats['reasons'][$reasonKey]))$stats['reasons'][$reasonKey]=['label'=>$reason,'count'=>0];
        $stats['reasons'][$reasonKey]['count']++;
        $district=district_label(territory($r)?:'Sin distrito');
        $leader=tp_report_name($r,'leader');
        $stats['districts'][$district]=($stats['districts'][$district]??0)+1;
        $stats['leaders'][$leader]=($stats['leaders'][$leader]??0)+1;
    }
    uasort($stats['reasons'],static fn($a,$b)=>$b['count']<=>$a['count'] ?: strcmp($a['label'],$b['label']));
    arsort($stats['districts'],SORT_NUMERIC);arsort($stats['leaders'],SORT_NUMERIC);
    return $stats;
}
function tp_print_modal(array $sets): void { ?>
<div class="tp-record-modal" id="tpRecordModal" hidden aria-hidden="true">
  <div class="tp-record-dialog" role="dialog" aria-modal="true" aria-labelledby="tp-record-title" tabindex="-1">
    <header class="tp-record-head"><div><small>DETALLE DE REGISTROS · TALIA PULSO</small><h2 id="tp-record-title">Detalle</h2><p id="tp-record-count"></p></div>
    <button type="button" class="tp-detail-close" id="tpRecordClose" aria-label="Cerrar detalle">Cerrar ×</button></header>
    <div class="tp-record-body"><div class="tp-table-wrap"><table class="tp-table" id="tpRecordTable"><thead></thead><tbody></tbody></table></div></div>
  </div>
</div>
<script id="tpDetailsData" type="application/json"><?=json_encode($sets,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<?php }
/** Data sets created from exactly the arrays used to calculate each displayed metric. */
function tp_visible_roster_rows(array $districts): array {
    $rows=['sales'=>[],'inst'=>[],'nd'=>[]];
    foreach($districts as $district)foreach(['sales','inst','nd'] as $kind){
        array_push($rows[$kind],...($district[$kind]??[]));
    }
    return $rows;
}
function tp_roster_sets(array $regional,array $districts): array {
    $visible=tp_visible_roster_rows($districts);
    $out=['roster:all:sales'=>tp_detail_sales($visible['sales']),'roster:all:inst'=>tp_detail_installs($visible['inst']),
          'roster:all:nd'=>tp_detail_nd(tp_sorted_nd($visible['nd']))];
    foreach($districts as $district=>$row){
        foreach(['sales'=>'sales','inst'=>'inst','nd'=>'nd'] as $key=>$kind){
            $build=['sales'=>'tp_detail_sales','inst'=>'tp_detail_installs','nd'=>'tp_detail_nd'][$kind];
            $out['roster:'.$district.':'.$key]=$build($row[$kind]);
        }
        foreach($row['leaders'] as $lkey=>$leader){
            $slug=md5($district.'|'.$lkey);
            foreach(['sales','inst','nd'] as $kind){
                $build=['sales'=>'tp_detail_sales','inst'=>'tp_detail_installs','nd'=>'tp_detail_nd'][$kind];
                $set=$build($leader[$kind]);$set['title']=ucfirst($kind).' · '.$leader['name'];
                $out['roster:l:'.$slug.':'.$kind]=$set;
            }
            foreach($leader['coaches'] as $ckey=>$coach){
                $slugC=md5($district.'|'.$lkey.'|'.$ckey);
                foreach(['sales','inst','nd'] as $kind){
                    $build=['sales'=>'tp_detail_sales','inst'=>'tp_detail_installs','nd'=>'tp_detail_nd'][$kind];
                    $set=$build($coach[$kind]);$set['title']=ucfirst($kind).' · '.$coach['name'];
                    $out['roster:c:'.$slugC.':'.$kind]=$set;
                }
            }
        }
    }
    return $out;
}

// ============================= Talia Pulso / importer =============================
/** XLSX/CSV/TSV sin librerías Composer: reconoce cabeceras reales de v1.0.180. */
function dt(?string $s): ?string {
    $s=trim((string)$s); if($s==='')return null;
    if (is_numeric($s) && (float)$s>20000 && (float)$s<80000) return gmdate('Y-m-d H:i:s',(int)round(((float)$s-25569)*86400));
    $s=preg_replace('/\s+/',' ',str_replace('T',' ',$s));
    foreach(['!d/m/Y H:i:s','!d/m/Y H:i','!d/m/Y h:i:s A','!d/m/Y h:i A','!d/m/Y','!Y-m-d H:i:s','!Y-m-d H:i','!Y-m-d','!m/d/Y H:i:s','!m/d/Y H:i','!m/d/Y h:i:s A','!m/d/Y h:i A','!m/d/Y'] as $f) {
        $t=DateTimeImmutable::createFromFormat($f,$s);$e=DateTimeImmutable::getLastErrors();
        if($t && (!$e || (!$e['error_count']&&!$e['warning_count'])))return $t->format('Y-m-d H:i:s');
    }
    return null;
}
function norm_row(array $header,array $row): array { $a=[]; foreach($header as $i=>$key){if($key!=='')$a[$key]=trim((string)($row[$i]??''));}return $a; }
function fval(array $row, string|array $aliases): string {foreach((array)$aliases as $key){$key=keynorm($key);if(isset($row[$key])&&$row[$key]!=='')return $row[$key];}return '';}
function ndate(?string $s): ?string {return $s ? substr($s,0,10):null;}
function decimal(string $s): ?float {
    // Salesforce exporta monedas como "$449.00" y, en ocasiones, agrupadas.
    $s = preg_replace('/[^0-9.,+\-]/u', '', trim($s));
    if ($s === null || $s === '' || $s === '-' || $s === '+') return null;
    $dot = strrpos($s,'.'); $comma = strrpos($s,',');
    if ($dot !== false && $comma !== false) {
        // El último separador es el decimal; el otro es de millares.
        $s = $dot > $comma ? str_replace(',', '', $s) : str_replace('.', '', str_replace(',', '.', $s));
    } elseif ($comma !== false) {
        // 449,00 o 1,299 (agrupación inglesa).
        $s = preg_match('/,\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}
/** Identifica la fila de títulos independientemente de la configuración regional. */
function detect_header_row(array $rows, string $mode): ?int {
    foreach ($rows as $i=>$row) {
        $keys = array_map(static fn($v)=>keynorm((string)$v), $row);
        $joined = implode(' ', $keys);
        if ($mode === 'salesforce_sales' && str_contains($joined,'numero_de_oportunidad') && str_contains($joined,'ts_creacionhora')) return $i;
        if ($mode === 'fieldcloud' && str_contains($joined,'cuenta') && str_contains($joined,'fecha_termino') && str_contains($joined,'tipo')) return $i;
        if ($mode === 'seguimiento_diario' && str_contains($joined,'cuenta') && str_contains($joined,'fecha_ultima_agenda') && str_contains($joined,'club_wifi') && str_contains($joined,'turno_ultima_agenda')) return $i;
    }
    return null;
}
function open_rows(array $upload,string $mode): array {
    if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??'')))throw new RuntimeException('Error al recibir el archivo.');
    if ((int)$upload['size']>25*1024*1024)throw new RuntimeException('Archivo mayor a 25 MB.');
    $ext=strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));if(!in_array($ext,['csv','tsv','txt','xlsx'],true))throw new RuntimeException('Formato inválido: sólo CSV/TSV/XLSX.');
    $rows=[];$file=$upload['tmp_name'];
    if($ext==='xlsx'){
        if(!class_exists('ZipArchive'))throw new RuntimeException('Activa las extensiones PHP zip y SimpleXML para XLSX.');
        $z=new ZipArchive();if($z->open($file)!==true)throw new RuntimeException('XLSX inválido.');
        foreach(range(0,$z->numFiles-1) as $idx){$stat=$z->statIndex($idx);if(($stat['size']??0)>65*1024*1024){$z->close();throw new RuntimeException('XLSX descomprimido demasiado grande.');}}
        $strings=[];$raw=$z->getFromName('xl/sharedStrings.xml');
        if($raw){$xml=simplexml_load_string($raw,'SimpleXMLElement',LIBXML_NONET);if($xml)foreach($xml->si as $si){$text='';foreach($si->xpath('.//t')?:[] as $node)$text.=(string)$node;$strings[]=$text;}}
        $raw=$z->getFromName('xl/worksheets/sheet1.xml');if(!$raw)for($i=2;$i<=10;$i++){ $raw=$z->getFromName('xl/worksheets/sheet'.$i.'.xml');if($raw)break; }
        $z->close();if(!$raw)throw new RuntimeException('No existe hoja XLSX con datos.');
        $xml=simplexml_load_string($raw,'SimpleXMLElement',LIBXML_NONET);if(!$xml)throw new RuntimeException('XML XLSX incorrecto.');
        foreach($xml->sheetData->row as $r){$line=[];foreach($r->c as $c){$ref=strtoupper((string)$c['r']);preg_match('/^[A-Z]+/',$ref,$m);$n=0;foreach(str_split($m[0]??'A') as $letter)$n=$n*26+ord($letter)-64;$n--;if($n>500)continue;$type=(string)$c['t'];$line[$n]=$type==='s'?($strings[(int)$c->v]??''):($type==='inlineStr'?(string)$c->is->t:(string)$c->v);}if($line){$max=max(array_keys($line));$rows[]=array_replace(array_fill(0,$max+1,''),$line);}if(count($rows)>50000)throw new RuntimeException('Límite de 50,000 filas excedido.');}
    } else {
        $fh=fopen($file,'rb');if(!$fh)throw new RuntimeException('No se pudo leer el CSV.');
        $sample=(string)fgets($fh);rewind($fh);$delimiter=$ext==='tsv'?"\t":(substr_count($sample,';')>substr_count($sample,',')?';':',');
        while(($line=fgetcsv($fh,0,$delimiter,'"','\\'))!==false){$rows[]=$line;if(count($rows)>50000)throw new RuntimeException('Límite de 50,000 filas excedido.');}fclose($fh);
    }
    $headerIdx=detect_header_row($rows, $mode);
    if($headerIdx===null)throw new RuntimeException('No se reconocieron los encabezados para '.$mode.'. Revisa que la primera hoja de datos contenga las columnas obligatorias.');
    return [array_map(static fn($x)=>keynorm(trim((string)$x,"\xEF\xBB\xBF\r\n \t")),$rows[$headerIdx]),array_slice($rows,$headerIdx+1)];
}
function values_from_aliases(array $row,array $mapping): array { $out=[];foreach($mapping as $column=>$aliases)$out[$column]=fval($row,$aliases);return $out;}
function fieldcloud_row(array $r,int $importId,int $rownum,string $now): ?array {
    $ot=fval($r,'OT');if(!$ot)return null;
    $type=fval($r,['Tipo','Tipo trabajo','Tipo de trabajo']);$status=fval($r,['Estatus','Estatus orden','Estatus de la orden']);$state=fval($r,'Estado');
    $finished=dt(fval($r,['Fecha termino','fecha_termino']));$district=fval($r,'Distrito');$city=fval($r,'Ciudad');$channel=fval($r,['Canal venta','Canal de venta','Canal']);
    $mapping=[
      'account'=>'Cuenta','ticket'=>'Ticket','os'=>'OS','customer_name'=>'Nombre cliente','subtype_label'=>'Subtipo','business_unit'=>'Unidad negocio',
      'cluster_label'=>'Cluster','package_label'=>'Paquete','dispatcher_user'=>'Usuario FFM despacho/AA','dispatcher_name'=>'Nombre despacho/AA',
      'provider'=>'Empresa(proveedor)','operation_user_type'=>'Tipo operacion/usuario','crew_type'=>'Tipo cuadrilla',
      'installer_user'=>'Usuario instalador','technician_name'=>'Nombre tecnico','supervisor_user'=>'Usuario supervisor',
      'supervisor_name'=>'Nombre supervisor','reason_label'=>'Motivo','confirmation'=>'Confirmacion'];
    $data=values_from_aliases($r,$mapping);
    return array_merge($data,[
      'import_id'=>$importId,'source'=>'fieldcloud','source_row'=>$rownum,'row_hash'=>hash('sha256','fieldcloud|'.$ot),
      'ot'=>$ot,'type_label'=>$type,'status_label'=>$status,'state_label'=>$state,'city'=>$city,'city_norm'=>district_key($city),
      'district'=>$district,'district_norm'=>district_key($district),'sales_channel'=>$channel,'sales_channel_norm'=>keynorm($channel),
      'latitude'=>decimal(fval($r,'Latitud')),'longitude'=>decimal(fval($r,'Longitud')),
      'ffm_created_at'=>dt(fval($r,'Fecha creacion FFM')),'last_schedule_at'=>dt(fval($r,'Fecha ultima agenda')),
      'finished_at'=>$finished,'event_date'=>ndate($finished),'confirmation_at'=>dt(fval($r,'Fecha Confirmacion')),
      'is_primary'=>keynorm($type)==='instalacion'?1:0,
      'is_completed'=>keynorm($status)==='terminada'||in_array(keynorm($state),['completa','trabajo_finalizado'],true)?1:0,
      'imported_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
}
function salesforce_row(array $r,string $file,string $now): ?array {
    $op=fval($r,['Cotización: Oportunidad: Número de oportunidad','Número de oportunidad','numero_oportunidad']);
    $create=dt(fval($r,['Cotización: Oportunidad: TS_CreacionHora','TS_CreacionHora','ts_creacion_hora']));
    $close=dt(fval($r,['Cotización: Oportunidad: Fecha de cierre','Fecha de cierre','fecha_cierre']));
    if(!$op||!$create||(!$close&&!$create))return null;
    $mapping=[
       'id_cuenta_brm'=>['CuentaFactura: Id de Cuenta BRM','Id de Cuenta BRM','id_cuenta_brm'],
       'canal_venta'=>['Canal de Venta','Canal','canal_venta'],'subcanal'=>['Cotización: Oportunidad: Subcanal','Subcanal'],
       'jefe_plaza'=>['Jefe de Plaza','Cotización: Oportunidad: Empleado: Jefe de Plaza: Nombre de Empleado'],
       'jefe_ventas'=>['Cotización: Oportunidad: Empleado: Jefe de Ventas: Nombre de Empleado','Jefe de Ventas'],
       'distrital'=>['Cotización: Oportunidad: Empleado: Distrital: Nombre de Empleado','Distrital'],
       'nombre_empleado'=>['Cotización: Oportunidad: Empleado: Nombre de Empleado','Nombre de Empleado'],
       'estatus_mesa_control'=>['Cotización: Oportunidad: Estatus Mesa de Control','Estatus Mesa de Control','Estatus'],
       'nombre_plan'=>['Nombre del plan','Nombre Plan','Plan','Paquete','Cotización: Nombre del plan','Cotización: Producto: Nombre del plan','Cotización: Oportunidad: Nombre del plan','Producto: Nombre del plan'],
       'cluster'=>'Cluster','distrito'=>['Sitio: Sitio: Distrito.','Sitio: Distrito','Distrito'],
       'forma_pago'=>['Sitio: Cotización: Oportunidad: Forma de pago','Forma de pago'],
       'telefono_movil'=>'Teléfono móvil','plazo'=>'Cotización: Plazo',
       'folio_empleado'=>['Cotización: Oportunidad: Empleado: Folio de empleado','Folio empleado','Folio de empleado','Número de talento','numero_talento_gs'],
       'id_ot_gim'=>'Id OT GIM','cp'=>'CP','calle'=>['Sitio: Sitio: Calle','Calle'],
       'numero_exterior'=>['Sitio: Sitio: Número exterior editable','Sitio: Sitio: Número exterior','Número exterior'],
       'numero_interior'=>['Sitio: Sitio: Número interior','Número interior'],'colonia'=>['Sitio: Sitio: Colonia','Colonia'],
       'municipio'=>['Sitio: Sitio: Delegación / Municipio','Sitio: Sitio: Municipio','Municipio'],
       'ciudad'=>['Sitio: Sitio: Ciudad','Ciudad'],'estado'=>['Sitio: Sitio: Estado','Estado'],
       'codigo_postal'=>['Sitio: Sitio: Código postal','Código postal'],'region'=>['Sitio: Sitio: Region','Region']];
    $data=values_from_aliases($r,$mapping);
    foreach(['precio_pronto_pago'=>['Precio pronto pago','Precio Pronto Pago'],'precio_lista'=>['Precio lista','Precio Lista'],
         'total_cargo_unico'=>['Total (Cargo único)','Total cargo único']] as $field=>$alias)$data[$field]=decimal(fval($r,$alias));
    return array_merge($data,[
      'fecha_cierre'=>ndate($close)??ndate($create),'TS_CreacionHora'=>$create,
      'fecha_activacion'=>ndate(dt(fval($r,['CuentaFactura: Fecha activación','Fecha de activación','Fecha activación']))),
      'numero_oportunidad'=>$op,'archivo_origen'=>basename($file),'importado_en'=>$now]);
}
function seguimiento_row(array $r,int $importId,int $n,string $now): ?array {
    $account=fval($r,'Cuenta');$os=fval($r,'OS');$ot=fval($r,'OT');if(!$account&&!$os&&!$ot)return null;
    $m=[
      'account'=>'Cuenta','ticket'=>'Ticket','os'=>'OS','ot'=>'OT','customer_name'=>'Nombre cliente',
      'type_label'=>'Tipo','subtype_label'=>'Subtipo','business_unit'=>'Unidad negocio','city'=>'Ciudad',
      'district'=>'Distrito','cluster_label'=>'Cluster','package_label'=>'Paquete','club_wifi'=>'Club wifi',
      'dispatcher_user'=>'Usuario FFM despacho/AA','dispatcher_name'=>'Nombre despacho/AA','provider'=>'Empresa(proveedor)',
      'operation_user_type'=>'Tipo operacion/usuario','crew_type'=>'Tipo cuadrilla','installer_user'=>'Usuario instalador',
      'technician_name'=>'Nombre tecnico','auxiliary_user'=>'Usuario auxiliar','auxiliary_name'=>'Nombre auxiliar',
      'supervisor_user'=>'Usuario supervisor','supervisor_name'=>'Nombre supervisor','last_schedule_shift'=>'Turno ultima agenda',
      'status_label'=>'Estatus','state_label'=>'Estado','reason_label'=>'Motivo','sales_channel'=>'Canal venta','confirmation'=>'Confirmacion'];
    $d=values_from_aliases($r,$m);
    $d['row_hash']=hash('sha256',implode('|',[$os,$ot,$account,fval($r,'Fecha ultima agenda'),fval($r,'Fecha termino'),$d['status_label'],$d['state_label'],$d['reason_label'],$d['type_label'],$d['subtype_label']]));
    return array_merge($d,['import_id'=>$importId,'source_row'=>$n,'latitude'=>decimal(fval($r,'Latitud')),'longitude'=>decimal(fval($r,'Longitud')),
      'ffm_created_at'=>dt(fval($r,'Fecha creacion FFM')),'last_schedule_at'=>dt(fval($r,'Fecha ultima agenda')),
      'finished_at'=>dt(fval($r,'Fecha termino')),'confirmation_at'=>dt(fval($r,'Fecha Confirmacion')),
      'imported_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
}
function import_files(array $files): array {
    $names=['salesforce_sales_file'=>'salesforce_sales','fieldcloud_file'=>'fieldcloud','seguimiento_diario_file'=>'seguimiento_diario'];$parsed=[];
    foreach($names as $key=>$mode)if(($files[$key]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$parsed[$mode]=['file'=>$files[$key], 'data'=>open_rows($files[$key],$mode)];
    if(!$parsed)throw new RuntimeException('Selecciona al menos un archivo.');
    $db=db();$results=[];$db->begin();
    try {
      foreach($parsed as $mode=>$input){[$headers,$rows]=$input['data'];$file=basename($input['file']['name']);$now=date('Y-m-d H:i:s');
        $stats=['source'=>$mode,'filename'=>$file,'total'=>count($rows),'inserted'=>0,'updated'=>0,'duplicates'=>0,'skipped'=>0,'errors'=>0,'min'=>null,'max'=>null];
        $iid=$db->insert('pulso_imports',['source'=>$mode,'file_name'=>$file,'sheet_name'=>'Hoja 1','total_rows'=>count($rows),'created_by'=>0,'created_at'=>$now]);
        foreach($rows as $i=>$arr){if(!array_filter($arr,static fn($x)=>trim((string)$x)!==''))continue;$r=norm_row($headers,$arr);
           $d=match($mode){'salesforce_sales'=>salesforce_row($r,$file,$now),'fieldcloud'=>fieldcloud_row($r,$iid,$i+1,$now),default=>seguimiento_row($r,$iid,$i+1,$now)};
           if(!$d){$stats['skipped']++;continue;}
           $t=match($mode){'salesforce_sales'=>'pulso_ventas','fieldcloud'=>'pulso_records',default=>'pulso_seguimiento_diario'};
           $idkey=$mode==='salesforce_sales'?'numero_oportunidad':($mode==='fieldcloud'?'ot':'row_hash');
           $old=$mode==='fieldcloud'?$db->row("SELECT * FROM `$t` WHERE `source`='fieldcloud' AND (`ot`=? OR `row_hash`=?) LIMIT 1",[$d['ot'],$d['row_hash']]):$db->row("SELECT * FROM `$t` WHERE `$idkey`=? LIMIT 1",[$d[$idkey]]);
           if($old){
             $ignore=['import_id','source_row','archivo_origen','importado_en','imported_at','updated_at','created_at'];$different=false;
             foreach($d as $k=>$v)if(!in_array($k,$ignore,true) && (string)($old[$k]??'')!==(string)($v??'')){$different=true;break;}
             if(!$different){$stats['duplicates']++;
               // Cada importación de Seguimiento debe volver a fechar su snapshot completo,
               // incluso cuando los valores operativos no cambiaron.
               if($mode==='seguimiento_diario'){$db->update($t,['import_id'=>$iid,'source_row'=>$i+1,'imported_at'=>$now,'updated_at'=>$now],'id=?',[(int)$old['id']]);$stats['updated']++;}
             }
             else{unset($d['created_at']);$db->update($t,$d,'id=?',[(int)$old['id']]);$stats['updated']++;}
           }else{$db->insert($t,$d);$stats['inserted']++;}
           $event=$mode==='salesforce_sales'?$d['fecha_cierre']:($mode==='fieldcloud'?$d['event_date']:ndate($d['last_schedule_at']??$d['finished_at']??$d['ffm_created_at']));
           if(!$event){$stats['skipped']++;} else {if(!$stats['min']||$event<$stats['min'])$stats['min']=$event;if(!$stats['max']||$event>$stats['max'])$stats['max']=$event;}
        }
        $db->update('pulso_imports',['inserted_rows'=>$stats['inserted'],'updated_rows'=>$stats['updated'],'duplicate_rows'=>$stats['duplicates'],
            'skipped_rows'=>$stats['skipped'],'error_rows'=>$stats['errors'],'min_event_date'=>$stats['min'],'max_event_date'=>$stats['max']],'id=?',[$iid]);
        $results[]=$stats;
      }
      $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    return $results;
}

// ============================== Page controller ==============================



$view=in_array((string)($_GET['view']??'corte'),['corte','importar','metas','conversion','nd'],true)?(string)($_GET['view']??'corte'):'corte';
// Operative views are available to authorized sessions; data editing is admin-only.
if(in_array($view,['importar','metas'],true) && !in_array(role(),['admin','administrador'],true)){
    http_response_code(403);
    exit('403 · Importación y Metas y Objetivos sólo están disponibles para administradores.');
}
$error='';$installed=false;
try {$installed=ready();}catch(Throwable $ex){$error=$ex->getMessage();}
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();need_editor();if(!in_array(role(),['admin','administrador'],true))throw new RuntimeException('Acceso exclusivo de administradores.');if(!$installed)throw new RuntimeException('Primero ejecuta las cinco tablas de WordPress (o tablas Standalone equivalentes).');
    $action=(string)($_POST['action']??'');
    if($action==='import'){
        $stats=import_files($_FILES);$msg=[];foreach($stats as $r)$msg[]=$r['source'].': '.num($r['inserted']).' insertados, '.num($r['updated']).' actualizados, '.num($r['duplicates']).' sin cambios y '.num($r['skipped']).' omitidos';
        flash(implode(' · ',$msg));go('importar');
    }
    if($action==='save_target'){
        $date=valid_date((string)($_POST['target_date']??''));$city=district_key((string)($_POST['city']??''));$channel=keynorm((string)($_POST['channel']??''));
        if(!$city)throw new RuntimeException('Especifica una ciudad.');
        [$start,$end]=week_bounds($date);$id=max(0,(int)($_POST['target_id']??0));$now=date('Y-m-d H:i:s');
        $originRow=$id?db()->row('SELECT source_ref,target_source FROM pulso_targets WHERE id=?',[$id]):null;
        $data=['target_date'=>$start,'city_norm'=>$city,'district_norm'=>$city,'sales_channel_norm'=>$channel,
        'sales_target'=>max(0,(int)($_POST['sales_target']??0)),'installs_target'=>max(0,(int)($_POST['installs_target']??0)),
        'target_source'=>'manual','source_ref'=>$originRow['source_ref']??'','updated_at'=>$now];
        db()->begin();try{
          $overrides=db()->all('SELECT source_ref FROM pulso_targets WHERE target_date BETWEEN ? AND ? AND city_norm=? AND sales_channel_norm=? AND id<>?',[$start,$end,$city,$channel,$id]);
          foreach($overrides as $override)if($override['source_ref'] && db()->has('pulso_ignored_targets'))db()->query('INSERT IGNORE INTO pulso_ignored_targets (source_ref,deleted_at) VALUES (?,NOW())',[$override['source_ref']]);
          db()->query('DELETE FROM pulso_targets WHERE target_date BETWEEN ? AND ? AND city_norm=? AND sales_channel_norm=? AND id<>?',[$start,$end,$city,$channel,$id]);
          if($id){if(!db()->val('SELECT id FROM pulso_targets WHERE id=?',[$id]))throw new RuntimeException('La meta ya no existe.');db()->update('pulso_targets',$data,'id=?',[$id]);}
          else{ $data['created_at']=$now;db()->insert('pulso_targets',$data); }
          db()->commit();
        }catch(Throwable $ex){db()->rollback();throw $ex;}
        flash('Meta guardada para la semana ISO de '.fmtdate($start).'.');go('metas');
    }
    if($action==='delete_target'){
        $id=(int)($_POST['target_id']??0);$row=db()->row('SELECT target_source,source_ref FROM pulso_targets WHERE id=?',[$id]);
        if(!$row)throw new RuntimeException('La meta ya no existe.');
        if($row['source_ref'] && db()->has('pulso_ignored_targets'))db()->query('INSERT IGNORE INTO pulso_ignored_targets (source_ref,deleted_at) VALUES (?,NOW())',[$row['source_ref']]);
        db()->query('DELETE FROM pulso_targets WHERE id=?',[$id]);flash('Meta eliminada.');go('metas');
    }
    throw new RuntimeException('Acción desconocida.');
 }
}catch(Throwable $ex){$error=$ex->getMessage();}
$f=filters();$report=null;$regional=null;$roster=[];$rosterSets=[];$ndStats=[];$imports=[];$targets=[];$edit=null;$options=[];
if($installed && !$error){try{
 if($view==='corte'){
    $report=daily_report($f);$f=$report['f'];
    $options=hc_options($report['hc']['rows'],$f['district'],$report['hc']['allowed_positions']);
    $me=hc_user_option($report['hc']);if($me!==null)$options[$me['id']]=$me;
    $selectedScope=$f['leader']!==''?$f['leader']:($me['id']??'');
    $ownDistrict=hc_user_district($report['hc']);
 }
 if(in_array($view,['conversion','nd'],true)){
    $regional=tp_regional_report($f['date']);
    $roster=tp_grouped_roster($regional);
    // La tabla regional y el reporte ejecutivo ND comparten exactamente las filas HC visibles.
    $regional=array_replace($regional,tp_visible_roster_rows($roster));
    if($view==='conversion'){
        $rosterSets=tp_roster_sets($regional,$roster);
    }else{
        $ndStats=tp_nd_summary($regional['nd']);
    }
 }
 if($view==='importar')$imports=db()->all('SELECT * FROM pulso_imports ORDER BY id DESC LIMIT 30');
 if($view==='metas'){
    sync_weekly_targets(valid_date((string)($_GET['target_date']??date('Y-m-d'))));
    $targets=db()->all('SELECT * FROM pulso_targets ORDER BY target_date DESC,city_norm LIMIT 120');
    $id=(int)($_GET['edit']??0);if($id)$edit=db()->row('SELECT * FROM pulso_targets WHERE id=?',[$id]);
 }
}catch(Throwable $ex){$error=$ex->getMessage();}}
$latestImport='';if($installed){try{$latestImport=(string)db()->val('SELECT MAX(created_at) FROM pulso_imports');}catch(Throwable $ex){$error=$ex->getMessage();}}
$title=['corte'=>'Corte Diario','importar'=>'Importar datos','metas'=>'Metas y Objetivos','conversion'=>'Ventas por Líder y Coach','nd'=>'Cuentas con ND'][$view];
$subtitle=['corte'=>'Ritmo del día, comparativo vs semana pasada y conversión por canal.',
'importar'=>'Carga unificada de Ventas Salesforce, Instalaciones FieldCloud y Reporte Seguimiento Diario.',
'metas'=>'Metas diarias editables por ciudad; las metas semanales se importan y dividen entre 7.',
'conversion'=>'Distribución regional por distrito, líder y coach.','nd'=>'Motivos y concentración de no instalaciones del día.'][$view];
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · Talia Pulso</title><link rel="stylesheet" href="../assets/css/pulso.css?v=1.0.21-record-tipografia"></head>
<body class="tp-view-<?=h($view)?>"><div class="talia-pulso-shell is-front"><main class="talia-pulso-main">
<?php if(in_array($view,['corte','importar','metas'],true)):?><section class="tp-hero"><div><span class="tp-kicker">CORTE DIARIO · TALIA PULSO · PHP INDEPENDIENTE</span><h1><?=h($title)?></h1><p><?=h($subtitle)?></p></div>
<div class="tp-hero-actions"><?php if($view==='corte'): ?><span class="tp-chip-meta">Fecha: <b><?=h($f['date'])?></b></span><a class="tp-pill" href="<?=h(url(['view'=>'corte','date'=>date('Y-m-d')]))?>">Hoy</a><?php elseif($view==='metas'):?><a class="tp-btn tp-btn-gradient" href="#editar">Editar metas</a><?php endif;?></div></section><?php endif; ?>
<?php if(isset($_SESSION['pulso_flash'])){$msg=$_SESSION['pulso_flash'];unset($_SESSION['pulso_flash']);?><div class="talia-pulso-notice <?=h($msg['type'])?>"><?=h($msg['message'])?></div><?php } ?>
<?php if($error): ?><div class="talia-pulso-notice error"><b>Configuración / consulta:</b> <?=h($error)?></div><?php endif;?>
<?php if(!$installed):?><section class="tp-card"><h2>Configuración inicial</h2><p>No se encontraron todas las tablas de Pulso. Verifica que <code>conexionTalia.php</code> apunte a la base histórica correcta y que <code>taliaWpPrefix</code> coincida con el prefijo de WordPress. No ejecutes el DDL Standalone sobre la base de WordPress.</p></section><?php endif;?>
<?php if($view==='corte'&&$report):
  $sales=$report['sales'];$inst=$report['inst'];$preS=$report['lastSales'];$preI=$report['lastInst'];$d=$report['districts'];$arpu=$report['arpu']['all']['arpu']??null;
  $previousArpu=$report['arpuPreviousWeek']['all']['arpu']??null;
  $nd=$report['nd']??[];
  $selectedHierarchy=$f['leader']!==''?$f['leader']:($me['id']??'');
  $showRegionalLinks=$f['district']==='' && $f['channel']==='' && $f['leader']==='' && $selectedHierarchy==='';
  $hierarchyRows=tp_hierarchy_table($report['hc'],$selectedHierarchy,$sales,$inst,$nd);
  $showHierarchy=count(explode('|',$selectedHierarchy))===3 && in_array(explode('|',$selectedHierarchy)[0],['director','lider','coach'],true);
  $topHierarchyRows=array_values(array_filter($hierarchyRows, static fn($row)=>((int)($row['depth'] ?? 0))===0));
  $hierarchyUniqueRows=static function(array $rows, string $type): array {
      $seen=[]; $unique=[];
      foreach($rows as $row){
          $key='';
          if($type==='sales'){
              $key=(string)($row['numero_oportunidad'] ?? $row['id'] ?? '');
              if($key==='')$key='sales|'.md5(json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
          }elseif($type==='inst'){
              $key=(string)($row['ot'] ?? $row['account'] ?? $row['id'] ?? '');
              if($key==='')$key='inst|'.md5(json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
          }else{
              $key=(string)($row['ot'] ?? $row['account'] ?? $row['id'] ?? '');
              if($key==='')$key='nd|'.md5(json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
          }
          if(isset($seen[$key]))continue;
          $seen[$key]=true;
          $unique[]=$row;
      }
      return $unique;
  };
  $hcTotalSales=[]; $hcTotalInst=[]; $hcTotalNd=[];
  foreach($topHierarchyRows as $member){
      $hcTotalSales=array_merge($hcTotalSales, $member['salesRows'] ?? []);
      $hcTotalInst=array_merge($hcTotalInst, $member['instRows'] ?? []);
      $hcTotalNd=array_merge($hcTotalNd, $member['ndRows'] ?? []);
  }
  $hcTotalSales=$hierarchyUniqueRows($hcTotalSales, 'sales');
  $hcTotalInst=$hierarchyUniqueRows($hcTotalInst, 'inst');
  $hcTotalNd=$hierarchyUniqueRows($hcTotalNd, 'nd');
  $detailSets=[
      'arpu:today'=>tp_detail_arpu($report['arpu']['all']['details']??[],$report['arpu']['all']['excluded']??[]),
      'arpu:previous'=>tp_detail_arpu($report['arpuPreviousWeek']['all']['details']??[],$report['arpuPreviousWeek']['all']['excluded']??[]),
  ];
  $followupLabels=['assigned'=>'Asignadas','pending'=>'Pendientes','detained'=>'Detenidas'];
  foreach($d as $city=>$district){
      $set=tp_detail_arpu($report['arpu'][$city]['details']??[],$report['arpu'][$city]['excluded']??[]);$set['title']='ARPU del día · '.district_label($city);
      $detailSets['arpu:'.$city.':today']=$set;
      $set=tp_detail_arpu($report['arpuPreviousWeek'][$city]['details']??[],$report['arpuPreviousWeek'][$city]['excluded']??[]);$set['title']='ARPU semana anterior · '.district_label($city);
      $detailSets['arpu:'.$city.':previous']=$set;
      foreach(['assigned','pending','detained'] as $status){
          $items=array_values(array_filter($report['backlog']['rows'],static fn($r)=>territory($r)===$city && ($r['_status']??'')===$status));
          $set=tp_detail_followup($items);$set['title']='Seguimiento · '.district_label($city).' · '.($followupLabels[$status]??$status);
          $detailSets['follow:'.$city.':'.$status]=$set;
      }
  }
  foreach(['assigned','pending','detained'] as $status){
      $items=array_values(array_filter($report['backlog']['rows'],static fn($r)=>($r['_status']??'')===$status));
      $set=tp_detail_followup($items);$set['title']='Seguimiento · Total · '.($followupLabels[$status]??$status);
      $detailSets['follow:all:'.$status]=$set;
  }
  foreach($hierarchyRows as $index=>$member){
      $leaderLabel=ucfirst($member['role']).' · '.$member['name'];
      $set=tp_detail_sales($member['salesRows']);$set['title']='Ventas · '.$leaderLabel;$detailSets['hc:'.$index.':sales']=$set;
      $set=tp_detail_installs($member['instRows']);$set['title']='Instalaciones · '.$leaderLabel;$detailSets['hc:'.$index.':inst']=$set;
      $set=tp_detail_nd($member['ndRows']);$set['title']='ND · '.$leaderLabel;$detailSets['hc:'.$index.':nd']=$set;
  }
  $detailSets['hc:all:sales']=tp_detail_sales($showHierarchy ? $hcTotalSales : $sales);
  $detailSets['hc:all:inst']=tp_detail_installs($showHierarchy ? $hcTotalInst : $inst);
  $detailSets['hc:all:nd']=tp_detail_nd($showHierarchy ? $hcTotalNd : $nd);
?>
<section class="tp-card tp-daily-filterbar"><form class="tp-filterform" method="get"><input type="hidden" name="view" value="corte">
<label>DÍA BASE<input type="date" name="date" value="<?=h($f['date'])?>"></label>
<label>CIUDAD<select name="district" id="tpDistrictSelect"><?php if($ownDistrict===''):?><option value="">Todos</option><?php endif;?><?php foreach($ownDistrict!==''?[$ownDistrict]:['cancun','coatza_mina','merida','tuxtla','villahermosa'] as $key):?><option value="<?=h($key)?>" <?= $f['district']===$key?'selected':'' ?>><?=h(district_label($key))?></option><?php endforeach;?></select></label>
<label>CANAL<select name="channel"><option value="">Todos</option><?php foreach(['cambaceo'=>'Cambaceo','punto_de_venta'=>'Punto de Venta','call_center'=>'Call Center','ecommerce'=>'eCommerce','winback'=>'Winback'] as $k=>$v):?><option value="<?=h($k)?>" <?=$f['channel']===$k?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label>
<label>DIRECTOR / LÍDER / COACH<select name="leader" id="tpHierarchySelect"><option value=""><?= $me!==null?'Mi equipo / todos los autorizados':'Todos' ?></option><?php foreach($options as $o):$outside=$f['district']!==''&&$o['district']!==$f['district'];?><option data-district="<?=h($o['district'])?>" data-role="<?=h($o['role'])?>" value="<?=h($o['id'])?>" <?=$selectedScope===$o['id']?'selected':''?> <?=$outside?'hidden disabled':''?>><?=h($o['label'])?></option><?php endforeach;?></select></label>
<button type="submit" class="tp-btn tp-btn-gradient">Aplicar</button></form>
<div class="tp-date-nav"><?php foreach(range(-3,4) as $delta){$date=date_shift($f['date'],$delta);$label=['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][(int)date('w',strtotime($date))];?><a class="tp-day <?=$delta===0?'active':''?>" href="<?=h(url(array_merge(['view'=>'corte','date'=>$date],array_diff_key($f,['date'=>true]))))?>"><?=h($label)?> <?=h(date('d',strtotime($date)))?></a><?php } ?><a class="tp-day" href="<?=h(url(['view'=>'corte','date'=>date('Y-m-d')]))?>">Hoy</a></div></section>
<div id="tp-daily-report-screenshot" data-tp-screenshot="daily-kpis-rhythm"><section class="tp-kpi-grid tp-kpi-grid-arpu">
<article class="tp-kpi violet"><span class="tp-kpi-icon" aria-hidden="true">📈</span><span>VENTAS</span><strong><?=num(count($sales))?></strong><small>Semana pasada: <?=num(count($preS))?> · <?=pct(count($sales),max(1,count($preS)))?> completado</small><button class="tp-detail-open" data-detail="ventas">Detalle</button></article>
<article class="tp-kpi cyan"><span class="tp-kpi-icon" aria-hidden="true">🔧</span><span>INSTALACIONES</span><strong><?=num(count($inst))?></strong><small>Semana pasada: <?=num(count($preI))?> · <?=pct(count($inst),max(1,count($preI)))?> completado</small><button class="tp-detail-open" data-detail="instalaciones">Detalle</button></article>
<article class="tp-kpi"><?php if($showRegionalLinks):?><a data-regional-link class="tp-kpi-icon-link" href="<?=h(url(['view'=>'nd','date'=>$f['date']]))?>" aria-label="Abrir Cuentas con ND" title="Ver reporte ejecutivo de Cuentas con ND">🔄</a><a data-regional-link class="tp-conversion-title" href="<?=h(url(['view'=>'conversion','date'=>$f['date']]))?>" title="Abrir distribución regional de ventas, instalaciones y ND">CONVERSIÓN ↗</a><?php endif;?><span data-regional-placeholder class="tp-kpi-icon" aria-hidden="true" <?=$showRegionalLinks?'hidden':''?>>🔄</span><span data-regional-placeholder class="tp-conversion-label" <?=$showRegionalLinks?'hidden':''?>>CONVERSIÓN</span><strong><?=count($sales)?pct(count($inst),count($sales)):'—'?></strong><small>Instalaciones / ventas</small></article>
<article class="tp-kpi tp-kpi-record"><span class="tp-kpi-icon" aria-hidden="true">🏆</span><span>RÉCORD HISTÓRICO POR HORA</span>
<div class="tp-record-values" aria-label="Récord de ventas e instalaciones del bloque horario">
  <div class="tp-record-unit"><span class="tp-record-label">VENTAS</span><strong><?=num($report['record']['sales']??0)?></strong></div>
  <div class="tp-record-unit"><span class="tp-record-label">INSTALACIONES</span><strong><?=num($report['record']['installs']??0)?></strong></div>
</div>
<small>Récord del bloque <?=h(sprintf('%02d:00',$report['cutoff']))?> en el historial importado</small></article>
<button type="button" class="tp-kpi tp-kpi-action" data-record-set="arpu:today" aria-haspopup="dialog" title="Ver cuentas utilizadas y excluidas del ARPU"><span class="tp-kpi-icon" aria-hidden="true">💲</span><span>ARPU</span><strong><?= $arpu!==null?'$'.number_format($arpu,2):'—' ?></strong><small><?=isset($report['arpu']['all'])?$report['arpu']['all']['valid'].' precios válidos de '.$report['arpu']['all']['count']:'Sin instalaciones válidas'?></small><small class="tp-click-hint">Ver cuentas incluidas y excluidas ↗</small></button>
</section>
<section class="tp-daily-layout"><div class="tp-leftstack"><article class="tp-card tp-rhythm"><div class="tp-card-head"><div><h2>Ritmo del día</h2><p>Última actualización: <strong><?=h($latestImport?:'Sin importaciones')?></strong></p><p>Ventas por Fecha de cierre; en la gráfica solo aparecen las creadas el mismo día (TS_CreacionHora). Instalaciones por Fecha término. Hora de corte <?=sprintf('%02d:00',$report['cutoff'])?>; se incluye únicamente el bloque anterior completo.</p></div><span class="tp-pill pink">Ritmo por hora</span></div>
<?php $chartedSales=array_values(array_filter($sales,static fn($r)=>pulso_sale_charts_at_cutoff($r,$f['date'],$report['cutoff'])));[$svfirst,$svlast]=hours_extrema($chartedSales,'TS_CreacionHora');[$infirst,$inlast]=hours_extrema($inst,'finished_at'); ?>
<div class="tp-moment-grid"><div><span>Primera venta</span><strong><?=h($svfirst)?></strong></div><div><span>Última venta</span><strong><?=h($svlast)?></strong></div><div><span>Primera instalación</span><strong><?=h($infirst)?></strong></div><div><span>Última instalación</span><strong><?=h($inlast)?></strong></div></div>
<div class="tp-legend"><b class="sale"></b> Ventas por hora <b class="install"></b> Instalaciones por hora <b class="previous-sale"></b> Ventas semana pasada <b class="previous-install"></b> Instalaciones semana pasada</div>
<div class="tp-chart-container" id="rhythmChart" role="img" aria-label="Comparativo de ventas e instalaciones por hora"></div>
<script id="rhythmData" type="application/json"><?=json_encode(['sales'=>$report['saleHours'],'installs'=>$report['instHours'],'previousSales'=>$report['prevSaleHours'],'previousInstalls'=>$report['prevInstHours'],'cutoff'=>$report['cutoff']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<p class="tp-chart-note">Cada punto representa un bloque horario completo. Las ventas se contabilizan por Fecha de cierre; solo se grafican cuando la Fecha de cierre y la fecha de TS_CreacionHora coinciden. Las ventas creadas otro día permanecen en el total, pero no se incluyen en la gráfica. Se compara el mismo día de la semana anterior.</p></article>
<article class="tp-card"><div class="tp-card-head"><h2>Seguimiento de instalaciones por distrito</h2><p>Última importación FieldCloud: <?=h($report['backlog']['snapshot']?:'No disponible')?></p></div><div class="tp-table-wrap"><table class="tp-table compact tp-followup-table"><thead><tr><th>DISTRITO</th><th>ASIGNADAS</th><th>%</th><th>PENDIENTES</th><th>%</th><th>DETENIDAS</th><th>%</th><th>TOTAL</th></tr></thead><tbody>
<?php $bt=['assigned'=>0,'pending'=>0,'detained'=>0,'total'=>0];foreach($d as $key=>$row):$b=$row['backlog']??['assigned'=>0,'pending'=>0,'detained'=>0,'total'=>0];foreach($bt as $field=>$_)$bt[$field]+=$b[$field]??0; ?>
<tr><td><b><?=h(district_label($key))?></b></td>
<td><button type="button" class="tp-metric-link" data-record-set="follow:<?=h($key)?>:assigned" aria-label="Detalle de asignadas en <?=h(district_label($key))?>"><?=num($b['assigned'])?></button></td>
<td><?=pct($b['assigned'],$b['total'])?></td>
<td><button type="button" class="tp-metric-link" data-record-set="follow:<?=h($key)?>:pending" aria-label="Detalle de pendientes en <?=h(district_label($key))?>"><?=num($b['pending'])?></button></td>
<td><?=pct($b['pending'],$b['total'])?></td>
<td><button type="button" class="tp-metric-link" data-record-set="follow:<?=h($key)?>:detained" aria-label="Detalle de detenidas en <?=h(district_label($key))?>"><?=num($b['detained'])?></button></td>
<td><?=pct($b['detained'],$b['total'])?></td><td><?=num($b['total'])?></td></tr><?php endforeach;?>
<tr class="tp-total"><td>TOTAL</td>
<td><button type="button" class="tp-metric-link" data-record-set="follow:all:assigned"><?=num($bt['assigned'])?></button></td><td><?=pct($bt['assigned'],$bt['total'])?></td>
<td><button type="button" class="tp-metric-link" data-record-set="follow:all:pending"><?=num($bt['pending'])?></button></td><td><?=pct($bt['pending'],$bt['total'])?></td>
<td><button type="button" class="tp-metric-link" data-record-set="follow:all:detained"><?=num($bt['detained'])?></button></td><td><?=pct($bt['detained'],$bt['total'])?></td><td><?=num($bt['total'])?></td></tr></tbody></table></div></article></div>
<aside class="tp-rightstack"><section class="tp-card"><h2>Avance vs meta por distrito</h2><p class="tp-method-note">Meta ventas = ⌈meta instalaciones × 1.10⌉. Bundle y tradicional se clasifican exclusivamente con <code>catalogo_paquetes.bundle_app</code>.</p><div class="tp-districts"><?php foreach($d as $key=>$row):
$t=$row['target'];$in=$t?(int)$t['installs_target']:0;$vs=sales_target_from_installations($in);
$bv=$row['bundleSales'];$bi=$row['bundleInstalls'];
?><div class="tp-district-item">
<div class="tp-district-heading"><b><?=h(district_label($key))?></b><span class="tp-first-install <?= $row['firstInstallation']==='—'?'tp-first-empty':'' ?>" title="Hora de la primera instalación terminada del distrito en el corte seleccionado"><?php if($row['firstInstallation']!=='—'):?><i aria-hidden="true"></i><?=h($row['firstInstallation'])?><?php else:?>Sin instalación<?php endif;?></span></div>
<div class="tp-bundle-comparison" aria-label="Participación comercial por catálogo">
  <div class="tp-bundle-row">
    <span class="tp-bundle-label">VENTAS</span>
    <span class="tp-bundle-tags">
      <span title="<?=num($bv['bundle'])?> ventas Bundle">Bundle <?=pct($bv['bundle'],$bv['total'])?></span>
      <span title="<?=num($bv['traditional'])?> ventas Tradicional">Tradicional <?=pct($bv['traditional'],$bv['total'])?></span>
    </span>
  </div>
  <div class="tp-bundle-row">
    <span class="tp-bundle-label">INST.</span>
    <span class="tp-bundle-tags">
      <span title="<?=num($bi['bundle'])?> instalaciones Bundle">Bundle <?=pct($bi['bundle'],$bi['total'])?></span>
      <span title="<?=num($bi['traditional'])?> instalaciones Tradicional">Tradicional <?=pct($bi['traditional'],$bi['total'])?></span>
    </span>
  </div>
</div>
<div class="tp-progress-head"><small><?=num($row['sales'])?> / <?=$vs?:'—'?> VENTAS</small><span><?= $vs?number_format($row['sales']/$vs*100,1).'%':'—' ?></span></div>
<div class="tp-track"><i style="width:<?=min(100,$vs?$row['sales']/$vs*100:0)?>%"></i></div>
<div class="tp-progress-head"><small><?=num($row['installs'])?> / <?=$in?:'—'?> INSTALACIONES</small><span><?=$in?number_format($row['installs']/$in*100,1).'%':'—'?></span></div>
<div class="tp-track install"><i style="width:<?=min(100,$in?$row['installs']/$in*100:0)?>%"></i></div>
</div><?php endforeach;?></div></section>
<section class="tp-card tp-arpu-panel"><h2>ARPU promedio</h2>
<p>Instalaciones terminadas del día. Comparación con la semana ISO anterior (<?=h(fmtdate($report['previousWeekStart']))?>–<?=h(fmtdate($report['previousWeekEnd']))?>).</p>
<div class="tp-table-wrap"><table class="tp-table tp-arpu-table"><thead><tr><th>DISTRITO</th><th>DÍA</th><th>SEMANA ANT.</th><th>Δ</th></tr></thead><tbody>
<?php foreach($d as $key=>$row):
  $prior=$row['previousArpu'];$delta=$row['arpuDelta'];$amount=$delta['amount'];$percent=$delta['percent'];
  $deltaClass=$amount===null?'unavailable':($amount>0?'positive':($amount<0?'negative':'neutral'));
?><tr><td><?=h(district_label($key))?></td>
<td class="numeric"><button type="button" class="tp-metric-link" data-record-set="arpu:<?=h($key)?>:today" aria-label="Ver detalle ARPU del día en <?=h(district_label($key))?>"><?=$row['arpu']!==null?'$'.number_format($row['arpu'],2):'—'?></button></td>
<td class="numeric"><button type="button" class="tp-metric-link" data-record-set="arpu:<?=h($key)?>:previous" aria-label="Ver detalle ARPU de semana anterior en <?=h(district_label($key))?>"><?=$prior!==null?'$'.number_format($prior,2):'—'?></button></td>
<td><span class="tp-arpu-delta <?=h($deltaClass)?>"><?php if($amount!==null):?>
<strong><?=($amount>=0?'+':'−').'$'.number_format(abs($amount),2)?></strong>
<small><?=$percent===null?'—':($percent>=0?'+':'−').number_format(abs($percent),1).'% '?></small>
<?php else:?>—<?php endif;?></span></td></tr><?php endforeach;?></tbody></table></div>
<small>ARPU = Σ(precio_lista ÷ 1.16) ÷ cuentas con precio válido &gt; 0. La comparación es contra el promedio de la semana anterior, no contra el mismo día.</small>
</section>
<?php if($showHierarchy): ?>
<section class="tp-card tp-hc-distribution" id="tp-hierarchy-distribution">
 <div class="tp-card-head"><div><h2>Ventas, Instalaciones y ND por <?=h(['director'=>'Líder y Coach','lider'=>'Coach','coach'=>'Experto'][explode('|',$selectedHierarchy)[0]]??'Equipo')?></h2>
 <p><?=h($options[$selectedHierarchy]['label']??$me['label']??'Estructura comercial')?> · ND: Cancelada o Reagendada (sin otra Terminada).</p></div></div>
 <div class="tp-table-wrap"><table class="tp-table tp-hc-table"><thead><tr><th>NIVEL</th><th>VENTAS</th><th>INST</th><th>ND</th></tr></thead><tbody>
 <?php foreach($hierarchyRows as $i=>$member): ?>
 <tr class="<?=h($member['role']==='lider'?'tp-hc-leader':'tp-hc-child')?>"><td class="tp-hc-name" style="--tp-indent:<?=min(2,(int)$member['depth'])?>"><?=h(['lider'=>'Líder','coach'=>'Coach','experto'=>'Experto'][$member['role']]??$member['role'])?> · <?=h($member['name'])?></td>
 <td><button type="button" class="tp-metric-link" data-record-set="hc:<?=$i?>:sales"><?=num(count($member['salesRows']))?></button></td>
 <td><button type="button" class="tp-metric-link" data-record-set="hc:<?=$i?>:inst"><?=num(count($member['instRows']))?></button></td>
 <td><button type="button" class="tp-metric-link" data-record-set="hc:<?=$i?>:nd"><?=num(count($member['ndRows']))?></button></td></tr>
 <?php endforeach; ?>
 <tr class="tp-total"><td>TOTAL DEL FILTRO</td><td><button type="button" class="tp-metric-link" data-record-set="hc:all:sales"><?=num(count($showHierarchy ? $hcTotalSales : $sales))?></button></td>
 <td><button type="button" class="tp-metric-link" data-record-set="hc:all:inst"><?=num(count($showHierarchy ? $hcTotalInst : $inst))?></button></td>
 <td><button type="button" class="tp-metric-link" data-record-set="hc:all:nd"><?=num(count($showHierarchy ? $hcTotalNd : $nd))?></button></td></tr>
 </tbody></table></div>
</section>
<?php endif; ?>
</aside></section></div>
<section class="tp-card tp-details" id="detalle-ventas" hidden><div class="tp-detail-header"><h2>Ventas contabilizadas</h2><button type="button" class="tp-detail-close" data-close-detail="ventas" aria-label="Cerrar detalle de ventas">Cerrar ×</button></div><div class="tp-table-wrap"><table class="tp-table"><thead><tr><th>Hora</th><th>Cuenta</th><th>Distrito</th><th>Canal</th><th>Líder</th><th>Coach</th><th>Vendedor</th></tr></thead><tbody><?php foreach($sales as $r):?><tr><td title="<?=h('Fecha de cierre: '.($r['fecha_cierre']??'').' · Fecha de creación: '.($r['TS_CreacionHora']??''))?>"><?=pulso_sale_charts_at_cutoff($r,$f['date'],$report['cutoff'])?h(substr((string)$r['TS_CreacionHora'],11,5)):'—'?></td><td><?=h($r['id_cuenta_brm'])?></td><td><?=h(district_label($r['distrito']))?></td><td><?=h($r['canal_venta'])?></td><td><?=h($r['_hierarchy']['leader_name']??$r['jefe_ventas'])?></td><td><?=h($r['_hierarchy']['coach_name']??$r['jefe_plaza'])?></td><td><?=h($r['_hierarchy']['seller_name']??$r['nombre_empleado'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="tp-card tp-details" id="detalle-instalaciones" hidden><div class="tp-detail-header"><h2>Instalaciones contabilizadas</h2><button type="button" class="tp-detail-close" data-close-detail="instalaciones" aria-label="Cerrar detalle de instalaciones">Cerrar ×</button></div><div class="tp-table-wrap"><table class="tp-table"><thead><tr><th>Hora</th><th>Cuenta</th><th>OT</th><th>Distrito</th><th>Líder</th><th>Coach</th><th>Vendedor</th></tr></thead><tbody><?php foreach($inst as $r):
  $owner=$r['_owner']??[];$lineage=$r['_hierarchy']??[];
  $leaderName=$lineage['leader_name']??($owner['jefe_ventas']??'');
  $coachName=$lineage['coach_name']??($owner['jefe_plaza']??'');
  $sellerName=$lineage['seller_name']??($owner['nombre_empleado']??'');
?><tr><td><?=h(substr((string)$r['finished_at'],11,5))?></td><td><?=h($r['account'])?></td><td><?=h($r['ot'])?></td><td><?=h(district_label(territory($r)))?></td><td><?=h($leaderName?:'—')?></td><td><?=h($coachName?:'—')?></td><td><?=h($sellerName?:'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="tp-card"><h2>ARPU promedio · Resumen</h2><p>El día y la semana ISO anterior se calculan desde FieldCloud y <code>catalogo_paquetes</code> con la misma fórmula de ARPU.</p><div class="tp-mini-kpis"><div><small>ARPU del día</small><strong><?=$arpu!==null?'$'.number_format($arpu,2):'—'?></strong></div><div><small>Semana anterior</small><strong><?=$previousArpu!==null?'$'.number_format($previousArpu,2):'—'?></strong><span><?=h(fmtdate($report['previousWeekStart']))?> – <?=h(fmtdate($report['previousWeekEnd']))?></span></div><div><small>Precio cubierto</small><strong><?=isset($report['arpu']['all'])?pct($report['arpu']['all']['valid'],$report['arpu']['all']['count']):'—'?></strong></div></div></section>
<?php tp_print_modal($detailSets); ?>
<?php endif; ?>
<?php if($view==='conversion' && $regional): ?>
<?php
/** Vista integrada: $regional, $roster y $rosterSets vienen del controlador. */
$ndRows=tp_sorted_nd($regional['nd']);
$date=$regional['date'];
// Referencia de WordPress: dos distritos arriba y tres abajo en la vista regional.
// La lista es sólo presentación; las agrupaciones y datasets conservan sus claves HC.
$order=['coatza_mina','villahermosa','merida','tuxtla','cancun'];
$sortedRoster=[];
foreach($order as $district){if(isset($roster[$district]))$sortedRoster[$district]=$roster[$district];}
foreach($roster as $district=>$record){if(!isset($sortedRoster[$district]))$sortedRoster[$district]=$record;}
$roster=$sortedRoster;
$rosterCount=count($roster);
?>
<section class="tp-roster-page" data-tp-screenshot="conversion-roster">
  <header class="tp-report-head tp-roster-compact-head">
    <div><h1>Ventas por Líder y Coach</h1>
      <p><?= $rosterCount===5?'Todos los Líderes y Coaches':'Líderes y Coaches autorizados' ?> · <?=num($rosterCount)?> <?= $rosterCount===1?'distrito':'distritos' ?> · vista distribuida por distrito</p></div>
    <div class="tp-report-head-actions"><span>Fecha: <?=h(fmtdate($date))?></span>
      <a href="<?=h(url(['view'=>'corte','date'=>$date]))?>">Volver a Corte Diario ↗</a>
    </div>
  </header>
  <div class="tp-roster-grid tp-roster-count-<?=min(6,$rosterCount)?>">
  <?php foreach($roster as $district=>$item):?>
  <section class="tp-roster-district">
    <h2>DISTRITO · <?=h(district_label($district))?></h2>
    <table class="tp-roster-table"><thead><tr><th>NIVEL</th><th>VENTAS</th><th>INST</th><th>ND</th></tr></thead><tbody>
      <?php foreach($item['leaders'] as $lk=>$leader):$lHash=md5($district.'|'.$lk);?>
      <tr class="tp-roster-leader"><th scope="row">Líder · <?=h($leader['name'])?></th>
        <?php foreach(['sales','inst','nd'] as $kind):?><td><button type="button" data-record-set="roster:l:<?=$lHash?>:<?=$kind?>" class="tp-metric-link"><?=num(count($leader[$kind]))?></button></td><?php endforeach; ?></tr>
      <?php foreach($leader['coaches'] as $ck=>$coach):$cHash=md5($district.'|'.$lk.'|'.$ck);?>
      <tr><th scope="row" class="tp-roster-coach">↳ Coach · <?=h($coach['name'])?></th>
        <?php foreach(['sales','inst','nd'] as $kind):?><td><button type="button" data-record-set="roster:c:<?=$cHash?>:<?=$kind?>" class="tp-metric-link"><?=num(count($coach[$kind]))?></button></td><?php endforeach; ?></tr>
      <?php endforeach;endforeach; ?>
      <?php if(!$item['leaders']):?><tr><td colspan="4">Sin registros atribuibles al distrito en este corte.</td></tr><?php endif; ?>
      <tr class="tp-roster-total"><th scope="row">TOTAL DISTRITO</th>
        <?php foreach(['sales','inst','nd'] as $kind):?><td><button type="button" data-record-set="roster:<?=h($district)?>:<?=$kind?>" class="tp-metric-link"><?=num(count($item[$kind]))?></button></td><?php endforeach; ?></tr>
    </tbody></table>
  </section>
  <?php endforeach; ?>
  </div>
  <section class="tp-card tp-roster-nd-list"><div class="tp-detail-header">
    <div><h2>Detalle de todos los ND</h2><p>Registros contabilizados en el roster regional</p></div>
    <span class="tp-roster-nd-count"><?=num(count($ndRows))?> registros</span>
  </div>
  <div class="tp-table-wrap"><table class="tp-table compact"><thead><tr>
    <th>DISTRITO</th><th>CUENTA</th><th>OS</th><th>OT</th><th>LÍDER</th><th>COACH</th><th>VENDEDOR</th>
    <th>ESTATUS</th><th>ESTADO</th><th>MOTIVO</th><th>FECHA ND</th>
  </tr></thead><tbody>
  <?php foreach($ndRows as $r):?><tr>
    <td><?=h(district_label(territory($r)))?></td><td><?=h($r['account']??'')?></td><td><?=h($r['os']??'')?></td><td><?=h($r['ot']??'')?></td>
    <td><?=h(tp_report_name($r,'leader'))?></td><td><?=h(tp_report_name($r,'coach'))?></td><td><?=h(tp_report_name($r,'seller'))?></td>
    <td><?=h($r['status_label']??'')?></td><td><?=h($r['state_label']??'')?></td><td><?=h($r['reason_label']??'')?></td><td><?=h($r['_nd_at']??'')?></td>
  </tr><?php endforeach; ?>
  <?php if(!$ndRows):?><tr><td colspan="11">Sin ND contabilizados para este corte.</td></tr><?php endif; ?>
  </tbody></table></div>
  </section>
</section>
<?php tp_print_modal($rosterSets); ?>

<?php endif; ?>
<?php if($view==='nd' && $regional): ?>
<?php
/** Requires $regional and $ndStats. Counts are from $regional['nd'] only. */
$total=$ndStats['count'];$topReason=$ndStats['reasons']?reset($ndStats['reasons']):null;
$topDistrict=$ndStats['districts']?(string)array_key_first($ndStats['districts']):'—';
$topLeader=$ndStats['leaders']?(string)array_key_first($ndStats['leaders']):'—';
$maxReason=max(array_merge([1],array_map(static fn($v)=>(int)$v['count'],array_values($ndStats['reasons']))));
$maxDistrict=max(array_merge([1],array_values($ndStats['districts'])));
$maxLeader=max(array_merge([1],array_values($ndStats['leaders'])));
$rows=tp_sorted_nd($regional['nd']);
$date=$regional['date'];
?>
<section class="tp-nd-report" data-tp-screenshot="nd-executive-report">
<header class="tp-nd-header"><div><small>TOTALPLAY | REGIÓN SUR · TALIA PULSO</small>
<h1>Cuentas con ND</h1><p>REPORTE EJECUTIVO · CORTE <?=h(fmtdate($date))?></p></div>
<div class="tp-nd-header-right"><b><?=num($total)?> REGISTROS</b><span><?=num($ndStats['canceladas'])?> canceladas · <?=num($ndStats['reagendadas'])?> reagendadas</span>
<a href="<?=h(url(['view'=>'conversion','date'=>$date]))?>">Ver distribución por Líder y Coach ↗</a>
<a href="<?=h(url(['view'=>'corte','date'=>$date]))?>">Volver a Corte Diario</a></div>
</header>
<div class="tp-nd-body">
  <div class="tp-nd-kpis">
    <article class="tp-nd-kpi blue"><strong><?=num($total)?></strong><div>CUENTAS CON ND</div></article>
    <article class="tp-nd-kpi red"><strong><?=num($ndStats['canceladas'])?></strong><div>CANCELADAS</div></article>
    <article class="tp-nd-kpi amber"><strong><?=num($ndStats['reagendadas'])?></strong><div>PENDIENTE / REAGENDADA</div></article>
    <article class="tp-nd-kpi pink"><strong><?= $topReason && $total ? number_format(100*$topReason['count']/$total,1).'%' : '—' ?></strong><div>MOTIVO PRINCIPAL<small><?=h($topReason['label']??'Sin ND')?></small></div></article>
  </div>
  <div class="tp-nd-panels">
    <section class="tp-nd-panel"><h2>Motivos de ND</h2><p>Los 8 motivos con mayor incidencia · participación sobre <?=num($total)?> registros.</p>
      <?php $index=0;foreach($ndStats['reasons'] as $r):if($index++>=8)break;
        $n=$r['count'];$percent=$total?100*$n/$total:0; ?>
      <div class="tp-nd-bar-row"><b title="<?=h($r['label'])?>"><?=h($r['label'])?></b><div class="tp-nd-track"><i class="<?=$index===1?'hot':'blue'?>" style="width:<?=100*$n/$maxReason?>%"></i></div><span><?=num($n)?> · <?=number_format($percent,1)?>%</span></div>
      <?php endforeach; if(!$index):?><p>Sin registros ND.</p><?php endif; ?>
    </section>
    <section class="tp-nd-panel"><h2>Concentración por distrito</h2><p><?=h($topDistrict)?> representa <?= $total?number_format(100*reset($ndStats['districts'])/$total,1):0 ?>% del total.</p>
      <?php $index=0;foreach($ndStats['districts'] as $label=>$n):$index++;?>
      <div class="tp-nd-bar-row"><b title="<?=h($label)?>"><?=h($label)?></b><div class="tp-nd-track"><i class="<?=$index===1?'hot':'teal'?>" style="width:<?=100*$n/$maxDistrict?>%"></i></div><span><?=num($n)?> · <?= $total?number_format(100*$n/$total,1):0 ?>%</span></div>
      <?php endforeach; ?>
    </section>
  </div>
  <section class="tp-nd-panel tp-nd-leaders"><h2>Concentración por Líder</h2><p><?=h($topLeader)?> representa <?= $total?number_format(100*reset($ndStats['leaders'])/$total,1):0 ?>% del total.</p>
    <?php $index=0;foreach($ndStats['leaders'] as $label=>$n):$index++;?>
    <div class="tp-nd-bar-row"><b title="<?=h($label)?>"><?=h($label)?></b><div class="tp-nd-track"><i class="<?=$index===1?'hot':'teal'?>" style="width:<?=100*$n/$maxLeader?>%"></i></div><span><?=num($n)?> · <?= $total?number_format(100*$n/$total,1):0 ?>%</span></div>
    <?php endforeach; ?>
  </section>
  <div class="tp-nd-focus"><b>FOCO DE SEGUIMIENTO</b>
    <?= $topReason ? 'Priorizar “'.h($topReason['label']).'” y reforzar seguimiento en '.h($topDistrict).'.' : 'Sin incidencias ND contabilizadas en este corte.' ?>
  </div>
  <section class="tp-nd-panel tp-nd-detail"><h2>Detalle de cuentas</h2><p>Fuente: exactamente los registros ND utilizados en las concentraciones anteriores, ordenados por distrito, líder, coach y estado.</p>
    <div class="tp-table-wrap"><table class="tp-nd-table"><thead><tr><th>DISTRITO</th><th>CUENTA</th><th>OS</th><th>LÍDER</th><th>COACH</th><th>VENDEDOR</th><th>MOTIVO ND / ESTADO</th></tr></thead><tbody>
    <?php foreach($rows as $r):$state=(string)($r['state_label']??'');?><tr>
      <td><?=h(district_label(territory($r)))?></td><td><?=h($r['account']??'')?></td><td><?=h($r['os']??'')?></td>
      <td><?=h(tp_report_name($r,'leader'))?></td><td><?=h(tp_report_name($r,'coach'))?></td><td><?=h(tp_report_name($r,'seller'))?></td>
      <td class="<?=keynorm($state)==='cancelada'?'tp-nd-cancel':'tp-nd-reag'?>"><?=h(($r['reason_label']??'')?:'Sin motivo')?> · <?=h($state)?></td>
    </tr><?php endforeach; ?>
    <?php if(!$rows):?><tr><td colspan="7">Sin registros ND en este corte.</td></tr><?php endif; ?></tbody></table></div>
  </section>
</div>
<footer class="tp-nd-footer">ND = Cancelada o Reagendada según FieldCloud · <?=num($total)?> registros con atribución autorizada.</footer>
</section>

<?php endif; ?>
<?php if($view==='importar' && $installed):?>
<section class="tp-card"><h2>Importar datos operativos</h2><p>Selecciona archivos de Ventas Salesforce, Instalaciones FieldCloud y/o Reporte Seguimiento Diario. Puedes importar uno o varios archivos en una operación atómica.</p><form method="post" enctype="multipart/form-data" class="tp-import-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="import"><div class="tp-import-pair">
<label class="tp-dropzone"><strong>Ventas Salesforce</strong><small>XLSX/CSV. Día: Fecha de cierre; hora: TS_CreacionHora; identidad: Número de oportunidad; HC: folio_empleado.</small><input name="salesforce_sales_file" type="file" accept=".xlsx,.csv,.tsv,.txt" <?=!can_edit()?'disabled':''?>></label>
<label class="tp-dropzone"><strong>Instalaciones FieldCloud</strong><small>XLSX/CSV. Identidad por OT; fecha y hora desde Fecha termino. Sólo Instalación entra al universo primario.</small><input name="fieldcloud_file" type="file" accept=".xlsx,.csv,.tsv,.txt" <?=!can_edit()?'disabled':''?>></label>
<label class="tp-dropzone"><strong>Reporte Seguimiento Diario</strong><small>XLSX/CSV. Tabla separada; histórico y estados por Fecha ultima agenda y último snapshot.</small><input name="seguimiento_diario_file" type="file" accept=".xlsx,.csv,.tsv,.txt" <?=!can_edit()?'disabled':''?>></label></div>
<div class="tp-import-actions"><small>Máximo: 25 MB por archivo, 50,000 filas. <?=can_edit()?'':'Sólo administradores pueden importar.'?></small><button class="tp-btn tp-btn-gradient" type="submit" <?=!can_edit()?'disabled':''?>>Importar archivos</button></div></form></section>
<section class="tp-card"><h2>Importaciones recientes</h2><div class="tp-table-wrap"><table class="tp-table"><thead><tr><th>FECHA CARGA</th><th>FUENTE</th><th>ARCHIVO</th><th>FILAS</th><th>INSERTADOS</th><th>ACTUALIZADOS</th><th>OMITIDOS</th><th>FECHAS OPERATIVAS</th></tr></thead><tbody>
<?php foreach($imports as $r):?><tr><td><?=h($r['created_at'])?></td><td><?=h(['salesforce_sales'=>'Ventas Salesforce','fieldcloud'=>'FieldCloud','seguimiento_diario'=>'Reporte Seguimiento Diario'][$r['source']]??$r['source'])?></td><td><?=h($r['file_name'])?></td><td><?=num($r['total_rows'])?></td><td><?=num($r['inserted_rows'])?></td><td><?=num($r['updated_rows'])?></td><td><?=num($r['skipped_rows'])?></td><td><?=h(($r['min_event_date']?:'—').' → '.($r['max_event_date']?:'—'))?></td></tr><?php endforeach;?><?php if(!$imports):?><tr><td colspan="8">Aún no hay importaciones.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif; ?>
<?php if($view==='metas'&&$installed):?>
<section class="tp-card" id="editar"><h2><?=$edit?'Editar meta registrada':'Editar metas'?></h2><p>Las metas se asignan por Ciudad/Distrito. Si existe <code>metas_instalacion_semanal</code> se sincroniza el objetivo dividido entre siete (redondeo hacia arriba). Una edición manual tiene prioridad sobre importaciones automáticas.</p>
<form method="post" class="tp-target-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_target"><input type="hidden" name="target_id" value="<?=h($edit['id']??0)?>">
<label>FECHA<input type="date" name="target_date" value="<?=h($edit['target_date']??($_GET['target_date']??date('Y-m-d')))?>" required></label>
<label>CIUDAD<input name="city" list="districts" value="<?=h($edit['city_norm']??'')?>" placeholder="Ciudad FieldCloud" required><datalist id="districts"><?php foreach(['cancun','coatza_mina','merida','tuxtla','villahermosa'] as $c):?><option value="<?=h($c)?>"><?=h(district_label($c))?></option><?php endforeach;?></datalist></label>
<label>CANAL<input name="channel" placeholder="Opcional" value="<?=h($edit['sales_channel_norm']??'')?>"></label>
<label>META VENTAS<input type="number" min="0" name="sales_target" value="<?=h($edit['sales_target']??0)?>"></label>
<label>META INSTALACIONES<input type="number" min="0" name="installs_target" value="<?=h($edit['installs_target']??0)?>"></label>
<button type="submit" class="tp-btn tp-btn-gradient" <?=!can_edit()?'disabled':''?>>Guardar meta</button></form></section>
<section class="tp-card"><h2>Metas recientes</h2><p>Una meta vigente por semana ISO, ciudad y canal.</p><div class="tp-table-wrap"><table class="tp-table compact"><thead><tr><th>FECHA</th><th>CIUDAD</th><th>CANAL</th><th>VENTAS</th><th>INST. DIARIA</th><th>ORIGEN</th><th>ACCIÓN</th></tr></thead><tbody>
<?php foreach($targets as $r):?><tr><td><?=h($r['target_date'])?></td><td><?=h($r['city_norm'])?></td><td><?=h($r['sales_channel_norm']?:'Todos')?></td><td><?=num($r['sales_target'])?></td><td><?=num($r['installs_target'])?></td><td><?=h($r['target_source']==='xpedient'?'Xpedient ÷ 7':'Manual')?></td><td class="tp-actions-cell"><a href="<?=h(url(['view'=>'metas','edit'=>$r['id']]))?>#editar">Editar</a><form method="post" class="tp-deleteform" onsubmit="return confirm('¿Eliminar esta meta?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_target"><input type="hidden" name="target_id" value="<?=h($r['id'])?>"><button type="submit" <?=!can_edit()?'disabled':''?>>Eliminar</button></form></td></tr><?php endforeach;?><?php if(!$targets):?><tr><td colspan="7">Sin metas registradas.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif; ?>
<footer class="tp-footer">Talia Pulso · Aplicación PHP autónoma · Datos MySQL en tiempo de consulta · Usuario: <?=h($_SESSION['usuario']??'')?></footer>
</main></div><script src="../assets/js/pulso.js?v=1.0.18-fecha-cierre-director" defer></script></body></html>
