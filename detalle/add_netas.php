<?php
/**
 * TalIA / TotalExpedient · Add Netas y FCST Add Netas
 * Migración unificada de plugin KRN v1.8.26 a PHP nativo.
 * DESPLIEGUE: copiar ESTE archivo Y fcst_add_netas.php dentro de /plataforma/detalle/.
 * No necesita WordPress, APIs adicionales ni archivos includes propios.
 * Usa la sesión actual y ../conexion.php de TotalExpedient.
 * Vista Add Netas en solo lectura; FCST conserva edición y reportes con token CSRF.
 * No modifica tablas operativas; crea únicamente `tx_addnetas_transfers` si no existe.
 * NOTA: sobrescrituras FCST previamente guardadas en la BD de WordPress no se migran automáticamente.
 */
if (!defined('TX_AN_ENTRY_VIEW')) { define('TX_AN_ENTRY_VIEW', 'real'); }
if (!defined('TX_AN_READY')) { define('TX_AN_READY', true); }
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__).'/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');

/** No acepta permisos de GET/POST. Sigue el mismo patrón de ranking_productividad.php. */
function tx_an_user() {
    $username = trim((string)($_SESSION['usuario'] ?? ''));
    $role = strtolower(trim((string)($_SESSION['rol'] ?? '')));
    if ($username === '' || $role === '') { return null; }
    // El rol se obtiene EXCLUSIVAMENTE de la sesión autenticada de TotalExpedient.
    return array('id'=>(int)($_SESSION['usuario_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0),
       'name'=>trim((string)($_SESSION['nombre'] ?? $username)), 'role'=>$role);
}
function tx_an_can($mode='view') {
    $user = tx_an_user();
    if ($user === null) { return false; }
    // Política TotalExpedient: tres perfiles regionales y un perfil distrital.
    // La autorización al distrito se verifica adicionalmente contra HC en servidor.
    return in_array($user['role'], array('admin','gerente_regional','director_regional','director_distrital'), true);
}
function tx_an_require_access($mode='view') {
    if (!tx_an_can($mode)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Sin permisos para Add Netas / FCST. Inicia sesión con un perfil autorizado.');
    }
}
tx_an_require_access('view');

/** La plataforma usa /plataforma/conexion.php y su variable $conexion (mysqli). */
$tx_connection_file = dirname(__DIR__) . '/conexion.php';
if (!is_file($tx_connection_file)) {
    http_response_code(503);
    exit('No se encontró /plataforma/conexion.php.');
}
require_once $tx_connection_file;
$tx_mysql_link = null;
foreach (array('conexion','conn','mysqli','db') as $tx_var) {
    if (isset($$tx_var) && is_object($$tx_var) && method_exists($$tx_var,'real_escape_string') && method_exists($$tx_var,'query')) {
        $tx_mysql_link = $$tx_var; break;
    }
}
if ($tx_mysql_link === null) {
    http_response_code(503);
    exit('No se encontró la conexión mysqli de TotalExpedient en conexion.php.');
}

function tx_an_config($key, $default=null) {
    if ($key === 'override_table') { return 'tx_addnetas_transfers'; }
    // Tabla informativa opcional: solo se lee si existe en misma base.
    if ($key === 'director_forecast_table') { return 'wp_krn_xpedient_forecast_virtual'; }
    return $default;
}
function tx_an_base_path() {
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/plataforma/detalle/add_netas.php');
    $directory = str_replace('\\','/',dirname($path));
    return $directory === '/' ? '' : rtrim($directory,'/');
}
function tx_an_view_path($view) {
    return tx_an_base_path().'/'.($view === 'fcst' ? 'fcst_add_netas.php' : 'add_netas.php');
}
function tx_an_session_secret() {
    if (session_status() !== PHP_SESSION_ACTIVE) { throw new RuntimeException('Sesión no disponible.'); }
    if (empty($_SESSION['tx_an_csrf']) || !is_string($_SESSION['tx_an_csrf'])) {
        $_SESSION['tx_an_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['tx_an_csrf'];
}

/** Adaptador mysqli para API de consultas del plugin WordPress: SELECT y UPSERT local. */
class TxAnDB {
    private $link;
    public $prefix='tx_';
    public $last_error='';
    public $insert_id=0;
    public function __construct($mysqli) { $this->link=$mysqli; }
    public function hide_errors() { return true; }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function prepare($query, ...$values) {
        if (count($values)===1 && is_array($values[0])) { $values=$values[0]; }
        $i=0;
        $out=preg_replace_callback('/%(?:%|s|d|f)/', function($m) use (&$i,$values) {
            $part=$m[0]; if ($part==='%%') return '%';
            if (!array_key_exists($i,$values)) throw new InvalidArgumentException('Faltan parámetros SQL.');
            $val=$values[$i++];
            if ($val===null) return 'NULL';
            if ($part==='%d') return (string)(int)$val;
            if ($part==='%f') return (string)(float)$val;
            return "'".$this->link->real_escape_string((string)$val)."'";
        },$query);
        if ($i!==count($values)) throw new InvalidArgumentException('Sobran parámetros SQL.');
        return $out;
    }
    public function query($query) {
        try {
            $result=$this->link->query($query);
            if ($result===false) throw new RuntimeException((string)$this->link->error);
            $this->last_error='';
            $this->insert_id=(int)($this->link->insert_id ?? 0);
            return $result===true ? (int)($this->link->affected_rows ?? 1) : $result;
        } catch (Throwable $e) {
            $this->last_error=$e->getMessage(); error_log('Add Netas SQL: '.$e->getMessage()); return false;
        }
    }
    public function get_results($query,$output=OBJECT) {
        try {
            $r=$this->link->query($query);
            if ($r===false) throw new RuntimeException((string)$this->link->error);
            $rows=[];
            if (is_object($r) && method_exists($r,'fetch_assoc')) {
                while (($a=$r->fetch_assoc())!==null) { $rows[]=($output===ARRAY_A?$a:(object)$a); }
                if (method_exists($r,'free')) $r->free();
            }
            $this->last_error=''; return $rows;
        } catch (Throwable $e) {
            $this->last_error=$e->getMessage(); error_log('Add Netas SELECT: '.$e->getMessage()); return [];
        }
    }
    public function get_row($query,$output=OBJECT) { $r=$this->get_results($query,$output); return $r[0] ?? null; }
    public function get_col($query) { return array_map(function($r){return (array_values((array)$r))[0] ?? null;},$this->get_results($query,ARRAY_A)); }
    public function get_var($query) { $r=$this->get_row($query,ARRAY_A); return $r===null?null:(array_values($r)[0]??null); }
    public function replace($table,$data) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/',$table) || !is_array($data) || !$data) return false;
        $columns=array_keys($data);
        foreach ($columns as $c) if (!preg_match('/^[a-zA-Z0-9_]+$/',$c)) return false;
        $quoted=array_map(function($c){return '`'.$c.'`';},$columns);
        $values=array_map(function($v){
            if ($v===null) return 'NULL';
            if (is_int($v)||is_float($v)) return (string)$v;
            return "'".$this->link->real_escape_string((string)$v)."'";
        },array_values($data));
        $updates=[];
        foreach ($columns as $c) if (!in_array($c,['id','created_at'],true)) $updates[]='`'.$c.'`=VALUES(`'.$c.'`)';
        $sql='INSERT INTO `'.$table.'` ('.implode(',',$quoted).') VALUES ('.implode(',',$values).') ON DUPLICATE KEY UPDATE '.implode(',',$updates);
        return $this->query($sql);
    }
}
/** Emula el tipo wpdb para funciones heredadas no invocadas. */
class wpdb extends TxAnDB {}
$GLOBALS['wpdb']=new TxAnDB($tx_mysql_link);
function tx_an_db($channel='source') { return $GLOBALS['wpdb']; }

/** Normalización de distritos. Nunca se admite un distrito arbitrario del navegador. */
function tx_an_normalize_district($raw) {
    $s = strtr(trim((string)$raw), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
         'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = strtoupper($s);
    $key = preg_replace('/[^A-Z0-9]/', '', $s);
    if ($key === 'CANCUN') return 'CANCÚN';
    if ($key === 'MERIDA') return 'MÉRIDA';
    if ($key === 'TUXTLA' || $key === 'TUXTLAGUTIERREZ') return 'TUXTLA';
    if ($key === 'VILLAHERMOSA') return 'VILLAHERMOSA';
    if (in_array($key, ['COATZAMINA','COATZACOLCOSMINATITLAN','COATZACOLCOSMINATITLÁN','COATZACOALCOSMINATITLAN','COATZACOALCOSMINATITLANVERACRUZ'], true)) return 'COATZA / MINA';
    return '';
}

/**
 * Resuelve adscripción distrital desde el HC vigente de la persona/posición autenticada.
 * Un distrito ausente o ambiguo DENIEGA acceso, nunca amplía a nivel regional.
 */
function tx_an_scope_district() {
    static $evaluated=false, $resolved='';
    if ($evaluated) return $resolved;
    $evaluated=true;
    if (strtolower(trim((string)($_SESSION['rol'] ?? ''))) !== 'director_distrital') return $resolved;
    $db = tx_an_db();
    $talent = trim((string)($_SESSION['numero_talento_gs'] ?? ''));
    $position = trim((string)($_SESSION['id_posicion'] ?? ''));
    // Prioridad al empleado autenticado; respaldo por posición vigente.
    foreach ([['numero_talento_gs',$talent], ['id_posicion',$position]] as $candidate) {
        [$col,$value]=$candidate;
        if ($value === '') continue;
        $sql = $db->prepare("SELECT distrito, anio, semana FROM `hc` WHERE `{$col}`=%s AND distrito IS NOT NULL AND TRIM(distrito)<>'' ORDER BY anio DESC, semana DESC LIMIT 30", $value);
        $results = $db->get_results($sql, ARRAY_A);
        $latestPeriod=null; $found=[];
        foreach ($results as $rec) {
            $period=sprintf('%04d-%02d', (int)($rec['anio']??0), (int)($rec['semana']??0));
            if ($latestPeriod !== null && $period !== $latestPeriod) break;
            if ($latestPeriod === null) $latestPeriod=$period;
            $d=tx_an_normalize_district($rec['distrito']??'');
            if ($d !== '') $found[$d]=true;
        }
        if (count($found) === 1) return $resolved=(string)array_key_first($found);
        if (count($found) > 1) return '';
    }
    // Compatibilidad con sesiones legadas que ya contienen distrito confiable.
    foreach (['distrito','distrito_usuario','nombre_distrito'] as $key) {
        $d=tx_an_normalize_district($_SESSION[$key]??'');
        if ($d !== '') return $resolved=$d;
    }
    return '';
}
function tx_an_allowed_districts() {
    $all=['CANCÚN','COATZA / MINA','MÉRIDA','TUXTLA','VILLAHERMOSA'];
    if (strtolower(trim((string)($_SESSION['rol']??''))) !== 'director_distrital') return $all;
    $d=tx_an_scope_district();
    return $d !== '' ? [$d] : [];
}
function tx_an_scope_label() {
    return strtolower(trim((string)($_SESSION['rol']??''))) === 'director_distrital' ? tx_an_scope_district() : 'REGIÓN SUR';
}
function tx_an_total_label() {
    return strtolower(trim((string)($_SESSION['rol']??''))) === 'director_distrital' ? 'TOTAL '.tx_an_scope_district() : 'REGIÓN SUR';
}
function tx_an_require_scope() {
    if (strtolower(trim((string)($_SESSION['rol']??''))) === 'director_distrital' && tx_an_scope_district() === '') {
        http_response_code(403);
        exit('No fue posible confirmar tu distrito en HC. Solicita validación de tu posición y sesión.');
    }
}
tx_an_require_scope();

/** Tabla aislada del FCST: no modifica instalaciones, cancelaciones, ventas ni HC. */
function tx_an_ensure_fcst_table() {
    static $ok=null;
    if ($ok!==null) return $ok;
    $db=$GLOBALS['wpdb'];
    // Evita requerir permiso CREATE cuando la tabla ya está instalada.
    $existing = $db->get_var("SHOW TABLES LIKE 'tx_addnetas_transfers'");
    if ((string)$existing === 'tx_addnetas_transfers') { return $ok=true; }
    $sql='CREATE TABLE IF NOT EXISTS `tx_addnetas_transfers` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `year` SMALLINT UNSIGNED NOT NULL, `week` TINYINT UNSIGNED NOT NULL,
      `district` VARCHAR(100) NOT NULL,
      `quantity` INT NOT NULL DEFAULT 0,
      `installations` INT NULL, `voluntary` INT NULL,
      `involuntary` INT NULL, `involuntary_total` INT NULL,
      `transfer_p` INT NULL, `transfer_f` INT NULL,
      `objective` INT NULL,
      `period_start` DATE NOT NULL, `period_end` DATE NOT NULL,
      `user_id` BIGINT UNSIGNED NULL, `user_name` VARCHAR(190) NULL,
      `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`), UNIQUE KEY `period_district` (`year`,`week`,`district`),
      KEY `period_start` (`period_start`), KEY `updated_at` (`updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    // Si ya existe, IF NOT EXISTS la deja intacta. Un fallo NO autoriza otras escrituras.
    $ok=($db->query($sql)!==false);
    return $ok;
}

/** Sustituciones mínimas de funciones WP utilizadas por el HTML/JS de FCST 1.8.26.
 *  NO implementa Wordpress, sus hooks, usuarios, ni grants anteriores.
 */
if (!defined('TX_AN_READY')) { http_response_code(403); exit; }
class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($obj) { return $obj instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone('America/Merida'); }
function wp_date($format, $timestamp = null, $timezone = null) {
    $date = new DateTime('@'.($timestamp === null ? time() : (int)$timestamp));
    $date->setTimezone($timezone instanceof DateTimeZone ? $timezone : wp_timezone());
    $text = $date->format($format);
    $es = array('January'=>'enero','February'=>'febrero','March'=>'marzo','April'=>'abril','May'=>'mayo','June'=>'junio','July'=>'julio','August'=>'agosto','September'=>'septiembre','October'=>'octubre','November'=>'noviembre','December'=>'diciembre',
    'Jan'=>'ene','Feb'=>'feb','Mar'=>'mar','Apr'=>'abr','Jun'=>'jun','Jul'=>'jul','Aug'=>'ago','Sep'=>'sep','Oct'=>'oct','Nov'=>'nov','Dec'=>'dic');
    return strtr($text, $es);
}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); }
function wp_rand($min, $max) { return random_int((int)$min,(int)$max); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_key($text) { return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$text)); }
function sanitize_text_field($text) { return trim(strip_tags((string)$text)); }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_sql($value) { return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$value); }
function esc_url($value) { return esc_attr($value); }
function number_format_i18n($n, $decimals = 0) { return number_format((float)$n, (int)$decimals, '.', ','); }
function remove_accents($str) {
    return strtr((string)$str, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'));
}
function is_user_logged_in() { return tx_an_user() !== null; }
function get_current_user_id() { $u=tx_an_user(); return (int)($u['id'] ?? 0); }
function wp_get_current_user() { $u=tx_an_user(); return (object)array('ID'=>$u['id'] ?? 0,'display_name'=>$u['name'] ?? ''); }
function current_time($type) { return wp_date($type === 'mysql' ? 'Y-m-d H:i:s' : $type); }
function get_option($key, $default = false) { return $key==='blog_charset' ? 'UTF-8' : ($key==='krn_addnetas_cache_revision' ? 1 : $default); }
function update_option($key, $value, $autoload = null) { return true; }
function get_transient($key) { return false; }
function set_transient($key, $value, $seconds) { return true; }
function delete_transient($key) { return true; }
function wp_cache_get($key, $group = '', $force = false, &$found = null) { $found=false; return false; }
function wp_cache_set($key, $value, $group = '', $expire = 0) { return true; }
function remove_query_arg($keys, $url = null) {
    $url = $url ?: ($_SERVER['REQUEST_URI'] ?? tx_an_view_path('real'));
    $parts = parse_url($url); if (!$parts) { return $url; }
    parse_str($parts['query'] ?? '', $query);
    foreach ((array)$keys as $key) { unset($query[$key]); }
    return ($parts['path'] ?? '/') . ($query ? '?'.http_build_query($query) : '');
}
function add_query_arg($args, $url = '') {
    $url = $url ?: ($_SERVER['REQUEST_URI'] ?? tx_an_view_path('real'));
    $parts = parse_url($url); parse_str($parts['query'] ?? '', $query);
    if (is_array($args)) { $query = array_merge($query, $args); }
    return ($parts['path'] ?? '/') . ($query ? '?'.http_build_query($query) : '');
}
function admin_url($filename) {
    if ($filename === 'admin-ajax.php') { return tx_an_view_path('fcst'); }
    if ($filename === 'admin-post.php') { return tx_an_view_path(defined('TX_AN_ENTRY_VIEW') ? TX_AN_ENTRY_VIEW : 'real'); }
    return tx_an_base_path().'/';
}
function wp_create_nonce($action) { return hash_hmac('sha256', (string)$action, tx_an_session_secret()); }
function wp_nonce_url($url, $action) { return add_query_arg(array('_wpnonce'=>wp_create_nonce($action)), $url); }
function check_ajax_referer($action, $key = 'nonce') {
    if (!is_string($_POST[$key] ?? null) || !hash_equals(wp_create_nonce($action), $_POST[$key])) {
        wp_send_json_error(array('message'=>'Sesión vencida o token inválido.'), 403);
    }
}
function check_admin_referer($action) {
    if (!is_string($_GET['_wpnonce'] ?? null) || !hash_equals(wp_create_nonce($action), $_GET['_wpnonce'])) {
        wp_die('Token de seguridad inválido.', 'FCST Add Netas', array('response'=>403));
    }
}
function wp_send_json_error($data, $status = 400) {
    http_response_code((int)$status); header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(array('success'=>false,'data'=>$data)); exit;
}
function wp_send_json_success($data) {
    http_response_code(200); header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(array('success'=>true,'data'=>$data)); exit;
}
function wp_die($text, $title = '', $args = array()) { http_response_code((int)($args['response'] ?? 403)); die(esc_html($text)); }
function bloginfo($attribute) { echo 'UTF-8'; }
function nocache_headers() { header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache'); }
function selected($selected, $current = true, $echo = true) {
    $attr = ((string)$selected === (string)$current) ? ' selected="selected"' : '';
    if ($echo) { echo $attr; }
    return $attr;
}

if (!defined('TX_AN_READY')) { http_response_code(403); exit; }

/**
 * ADD Netas
 *
 * Plugin autónomo de ADD Netas. Lee instalaciones y cancelaciones mediante el
 * contrato de conexión de Xpedient, siempre en modo lectura, y conserva la
 * captura de traslados exclusivamente en la base local de WordPress.
 */
final class KRN_Add_Netas_Standalone {
    const VERSION = '1.8.26';
    const SCHEMA_VERSION = '1.6.0';
    const PAGE_VERSION = '1.8.7';
    const FORECAST_ALGORITHM_VERSION = '3.5.1';
    const CAP_VIEW = 'addnetas.view';
    const CAP_EDIT = 'addnetas.edit';
    const UPGRADE_OPTION = 'krn_addnetas_standalone_version';
    const CACHE_REVISION_OPTION = 'krn_addnetas_cache_revision';
    const ACCESS_SEED_OPTION = 'krn_addnetas_access_seed_version';
    const LEGACY_MIGRATION_OPTION = 'krn_addnetas_legacy_transfer_migration_v1';
    const FCST_RESET_OPTION = 'krn_addnetas_fcst_reset_v122';
    const COMPONENT_RESET_OPTION = 'krn_addnetas_component_reset_v131';
    const PEOPLE_SCOPE_CONTRACT = 'talia_personas.user_scope.v2';
    const PEOPLE_SCOPE_CONTRACT_VERSION = '2.0.0';
    const PEOPLE_SCOPE_META = '_krn_addnetas_people_scope_v2';
    const PEOPLE_SCOPE_BOOTSTRAP_OPTION = 'krn_addnetas_people_scope_contract_version';
    const CORE_DOMAIN_SCOPE_CONTRACT = 'krn_core.domain_scope.v1';
    const CORE_DOMAIN_SCOPE_CONTRACT_VERSION = '1.0.0';
    const SNAPSHOT_CONTRACT = 'talia_addnetas.snapshot.v1';
    const SNAPSHOT_CONTRACT_VERSION = '1.0.0';

    private static $runtime_cache = array();

    public static function init() {
        // Toma de control segura si todavía está instalada Xpedient 1.8.107.41.
        add_action('plugins_loaded', array(__CLASS__, 'disable_embedded_module'), PHP_INT_MAX);
        add_action('init', array(__CLASS__, 'register_shortcode'), 9);
        // Reafirma los shortcodes al final de init para evitar que una copia embebida antigua de ADD Netas los sobreescriba.
        add_action('init', array(__CLASS__, 'register_shortcode'), PHP_INT_MAX);
        add_action('init', array(__CLASS__, 'register_to_core'), 22);
        add_action('init', array(__CLASS__, 'register_navigation_v2'), 23);
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'), 5);
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 99);
        add_action('wp_ajax_krn_addnetas_save', array(__CLASS__, 'save_ajax'));
        // Alias temporal para clientes que todavía envían la acción antigua.
        add_action('wp_ajax_krn_xpedient_addnetas_save', array(__CLASS__, 'save_ajax'));
        add_action('admin_post_krn_addnetas_executive_pdf', array(__CLASS__, 'executive_pdf_report'));
        // Gobierno canónico: Core decide vistas/capabilities y Personas entrega identidad/scope.
        add_action('admin_init', array(__CLASS__, 'maybe_refresh_people_scope_contract'), 34);
        add_action('krn_core_contract_delivery', array(__CLASS__, 'receive_core_contract_delivery'), 10, 3);
        add_action('krn_core_contract_delivery_talia_personas_user_scope_v2', array(__CLASS__, 'receive_people_scope_snapshot'), 10, 2);
        add_action('talia_personas_user_scope_updated', array(__CLASS__, 'receive_people_scope_user_update'), 10, 4);
        add_filter('krn_core_contract_snapshot', array(__CLASS__, 'provide_core_contract_snapshot'), 20, 3);
        add_filter('user_has_cap', array(__CLASS__, 'filter_user_caps'), 50, 4);
    }

    public static function disable_embedded_module() {
        if (!class_exists('KRN_AddNetas_Module')) { return; }
        remove_action('init', array('KRN_AddNetas_Module', 'register_shortcode'), 9);
        remove_action('init', array('KRN_AddNetas_Module', 'register_to_core'), 22);
        remove_action('init', array('KRN_AddNetas_Module', 'register_navigation_v2'), 23);
        remove_action('admin_init', array('KRN_AddNetas_Module', 'maybe_upgrade'), 5);
        remove_action('wp_ajax_krn_addnetas_save', array('KRN_AddNetas_Module', 'save_ajax'));
        remove_action('wp_ajax_krn_xpedient_addnetas_save', array('KRN_AddNetas_Module', 'save_ajax'));
        remove_action('wp_login', array('KRN_AddNetas_Module', 'maybe_seed_login_user'), 10);
    }

    public static function activate() {
        self::install_schema();
        self::install_capabilities();
        self::ensure_page();
        // v1.8.5: no se siembran accesos por nombre. Core/Personas son la autoridad primaria.
        self::migrate_legacy_transfers_once();
        self::reset_stale_forecast_overrides_once();
        self::reset_projection_components_once();
        update_option(self::UPGRADE_OPTION, self::VERSION, false);
    }

    public static function maybe_upgrade() {
        if (wp_doing_ajax() || wp_doing_cron()) { return; }
        global $pagenow;
        if (in_array((string)$pagenow, array('admin-ajax.php','admin-post.php','async-upload.php'), true)) { return; }
        if ((string)get_option(self::UPGRADE_OPTION, '') === self::VERSION) { return; }

        self::install_schema();
        self::install_capabilities();
        self::ensure_page();
        // v1.8.5: no se siembran accesos por nombre. Core/Personas son la autoridad primaria.
        self::migrate_legacy_transfers_once();
        self::reset_stale_forecast_overrides_once();
        self::reset_projection_components_once();
        update_option(self::UPGRADE_OPTION, self::VERSION, false);
    }

    public static function register_shortcode() {
        add_shortcode('krn_addnetas', array(__CLASS__, 'shortcode'));
        add_shortcode('talia_addnetas', array(__CLASS__, 'shortcode'));
        add_shortcode('krn_addnetas_real', array(__CLASS__, 'shortcode_real'));
        add_shortcode('talia_addnetas_real', array(__CLASS__, 'shortcode_real'));
    }

    private static function governable_views_manifest() {
        return array(
            'addnetas_real' => array(
                'label'=>'Dashboard Add Netas',
                'capability'=>self::CAP_VIEW,
                'default_roles'=>array('administrator','krn_super_admin','talia_workforce_administrador','talia_sac_director'),
                'scope_required'=>false,
                'mode'=>'read_only',
            ),
            'addnetas_fcst' => array(
                'label'=>'FCST Add Netas',
                'capability'=>self::CAP_VIEW,
                'edit_capability'=>self::CAP_EDIT,
                'default_roles'=>array('administrator','krn_super_admin','talia_workforce_administrador','talia_sac_director'),
                'scope_required'=>false,
                'mode'=>'read_write_projection',
            ),
        );
    }

    public static function manifest() {
        return array(
            'id' => 'krn_addnetas',
            'slug' => 'add-netas',
            'name' => 'Add Netas',
            'aliases' => array('addnetas','add_netas','proyeccion_addnetas'),
            'version' => self::VERSION,
            'contract_version' => '1.8.7',
            'description' => 'Módulo de Adiciones Netas Región Sur con vista REAL de solo lectura y vista FCST editable; integra instalaciones, cancelaciones y referencias SAC/Cobranza.',
            'status' => 'active_plugin',
            'source_plugin' => 'krn-add-netas/krn-add-netas.php',
            'capabilities' => array(self::CAP_VIEW, self::CAP_EDIT),
            'data_contracts' => array(
                array(
                    'key'=>self::PEOPLE_SCOPE_CONTRACT,
                    'version'=>self::PEOPLE_SCOPE_CONTRACT_VERSION,
                    'owner'=>'kore',
                    'provider'=>'talia_personas',
                    'consumer'=>'krn_addnetas',
                    'purpose'=>'Identidad, jerarquía, adscripción y contexto de autorización del usuario sin acoplar ADD Netas a tablas de Personas.',
                    'delivery'=>'push_snapshot',
                    'bootstrap'=>'once_per_consumer_version',
                    'refresh_policy'=>'provider_update_event',
                    'runtime_fetch'=>false,
                    'storage'=>'user_meta:' . self::PEOPLE_SCOPE_META,
                    'fields'=>array('user_id','person_id','employee_id','district','allowed_districts','hierarchy_role','operational_role','workforce_role','wp_role','scope_mode','status','is_admin','is_regional','scope_hash','hierarchy_revision'),
                    'fallback'=>'personas_identity_snapshot_then_local_capability',
                    'privacy'=>'minimum_operational_identity_no_commercial_rows',
                ),
                array(
                    'key'=>self::CORE_DOMAIN_SCOPE_CONTRACT,
                    'version'=>self::CORE_DOMAIN_SCOPE_CONTRACT_VERSION,
                    'owner'=>'core',
                    'provider'=>'krn_core',
                    'consumer'=>'krn_addnetas',
                    'purpose'=>'Resolver el perfil SAC/Cobranza y el repositorio autorizado sin depender del rol WordPress ni de listas nominales.',
                    'delivery'=>'in_process_function',
                    'runtime_fetch'=>true,
                    'storage'=>'none',
                    'function'=>'krn_core_domain_scope_context',
                    'fields'=>array('status','domain','role','scope_model','repositories','modules'),
                    'fallback'=>'personas_workforce_hierarchy_snapshot',
                    'privacy'=>'authorization_context_only',
                ),
                array(
                    'key'=>self::SNAPSHOT_CONTRACT,
                    'version'=>self::SNAPSHOT_CONTRACT_VERSION,
                    'owner'=>'krn_addnetas',
                    'provider'=>'krn_addnetas',
                    'consumer'=>'core',
                    'purpose'=>'Exponer el snapshot agregado y versionado de ADD Netas a consumidores autorizados sin entregar conexión Legacy ni tablas internas.',
                    'delivery'=>'in_process_function',
                    'runtime_fetch'=>true,
                    'storage'=>'none',
                    'function'=>'talia_addnetas_snapshot_v1',
                    'fields'=>array('module','version','context','data','read_only_legacy'),
                    'privacy'=>'authenticated_capability_required',
                ),
            ),
            'governable_views' => self::governable_views_manifest(),
            'visibility_governance' => array(
                'enabled'=>true,
                'supports_role_overrides'=>true,
                'supports_user_overrides'=>true,
                'scope_source'=>self::PEOPLE_SCOPE_CONTRACT,
                'domain_scope_source'=>self::CORE_DOMAIN_SCOPE_CONTRACT,
                'module_assignment_meta'=>'krn_operational_modules',
                'domain_module_assignment_meta'=>'krn_core_domain_modules_v1',
                'domain_role_policy'=>array('sac_cobranza'=>array('view'=>array('director'),'edit'=>array('director'))),
                'fallback'=>'people_scope_then_legacy_capability',
                'legacy_nominal_seed'=>'deprecated_no_new_grants',
            ),
            'dependencies' => array('krn_core_connection:talia_xpedient', self::PEOPLE_SCOPE_CONTRACT, self::CORE_DOMAIN_SCOPE_CONTRACT),
            'data_connections' => array(
                array(
                    'key'=>'xpedient_legacy',
                    'label'=>'Instalaciones, cancelaciones y pronóstico SAC/Cobranza',
                    'owner'=>'xpedient',
                    'mode'=>'read_only',
                    'write_policy'=>'never_write_legacy',
                    'requires_core_awareness'=>true,
                ),
                array(
                    'key'=>'wordpress_local',
                    'label'=>'Overrides locales de proyección',
                    'owner'=>'addnetas',
                    'mode'=>'read_write',
                    'table'=>self::table_name(),
                    'requires_core_awareness'=>true,
                ),
            ),
            'navigation' => array(array(
                'key'=>'addnetas',
                'title'=>'Add Netas',
                'icon'=>'sales',
                'icon_key'=>'sales',
                'order'=>29,
                'capability'=>self::CAP_VIEW,
                'route'=>home_url('/real-add-netas/'),
                'url'=>home_url('/real-add-netas/'),
                'children'=>array(
                    array(
                        'key'=>'addnetas_real',
                        'title'=>'Dashboard Add Netas',
                        'icon'=>'dashboard',
                        'icon_key'=>'dashboard',
                        'order'=>10,
                        'capability'=>self::CAP_VIEW,
                        'page_slug'=>'real-add-netas',
                        'route'=>home_url('/real-add-netas/'),
                        'url'=>home_url('/real-add-netas/'),
                        'shortcode'=>'[krn_addnetas_real]',
                        'create_page'=>true,
                    ),
                    array(
                        'key'=>'addnetas_fcst',
                        'title'=>'FCST Add Netas',
                        'icon'=>'sales',
                        'icon_key'=>'sales',
                        'order'=>20,
                        'capability'=>self::CAP_VIEW,
                        'page_slug'=>'add-netas',
                        'route'=>home_url('/add-netas/'),
                        'url'=>home_url('/add-netas/'),
                        'shortcode'=>'[krn_addnetas]',
                        'create_page'=>true,
                    ),
                ),
            )),
            'pages' => array(
                array(
                    'key'=>'addnetas_real',
                    'title'=>'Dashboard Add Netas',
                    'slug'=>'real-add-netas',
                    'shortcode'=>'[krn_addnetas_real]',
                    'root'=>true,
                    'capability'=>self::CAP_VIEW,
                ),
                array(
                    'key'=>'addnetas_fcst',
                    'title'=>'FCST Add Netas',
                    'slug'=>'add-netas',
                    'shortcode'=>'[krn_addnetas]',
                    'root'=>false,
                    'capability'=>self::CAP_VIEW,
                ),
            ),
            'design' => array(
                'uses_core_theme'=>true,
                'supports_light_mode'=>true,
                'supports_dark_mode'=>true,
                'layout_type'=>'operational_8_col',
                'icon_system'=>'kes_icons_v1',
            ),
        );
    }

    public static function register_to_core() {
        $manifest = self::manifest();
        if (function_exists('krn_core_register_module')) {
            try { krn_core_register_module($manifest); } catch (Throwable $e) {}
        }
        foreach ((array)($manifest['data_contracts'] ?? array()) as $contract) {
            if (!is_array($contract) || empty($contract['key'])) { continue; }
            do_action('krn_core_register_data_contract', $contract);
            do_action('krn_core_register_contract', $contract);
        }
    }

    public static function register_navigation_v2() {
        if (!function_exists('krn_core_register_navigation_module')) { return; }
        krn_core_register_navigation_module(array(
            'id'=>'krn_addnetas',
            'label'=>'Add Netas',
            'icon'=>'sales',
            'icon_key'=>'sales',
            'route'=>home_url('/real-add-netas/'),
            'order'=>29,
            'enabled'=>true,
            'audience'=>'authenticated',
            'capability'=>self::CAP_VIEW,
            'version'=>self::VERSION,
            'children_provider'=>array(__CLASS__, 'navigation_v2_children'),
            'source'=>'krn_add_netas_standalone_v2',
        ));
    }

    public static function navigation_v2_children($user_id = 0, $context = 'desktop', $module = array()) {
        $manifest = self::manifest();
        $navigation = isset($manifest['navigation']) && is_array($manifest['navigation']) ? $manifest['navigation'] : array();
        $root = $navigation ? reset($navigation) : array();
        $children = is_array($root) && !empty($root['children']) && is_array($root['children']) ? $root['children'] : array();

        // Navigation V2 puede consumir los hijos desde desktop o desde el drawer móvil.
        // Entregamos label + route/url explícitos para que ambas superficies muestren
        // Dashboard Add Netas y FCST Add Netas sin depender de la normalización page_slug.
        foreach ($children as &$child) {
            $slug = sanitize_title((string)($child['page_slug'] ?? ''));
            if ($slug === '') { continue; }
            $route = home_url('/' . $slug . '/');
            $child['label'] = (string)($child['title'] ?? '');
            $child['route'] = $route;
            $child['url'] = $route;
            $child['enabled'] = true;
            $child['audience'] = 'authenticated';
            $child['version'] = self::VERSION;
        }
        unset($child);
        return $children;
    }

    public static function admin_menu() {
        if (!current_user_can('manage_options') && !current_user_can('manage_krn_core')) { return; }
        add_management_page(
            'FCST Add Netas · Diagnóstico',
            'FCST Add Netas',
            current_user_can('manage_options') ? 'manage_options' : 'manage_krn_core',
            'krn-add-netas-diagnostics',
            array(__CLASS__, 'render_diagnostics')
        );
    }

    public static function render_diagnostics() {
        if (!current_user_can('manage_options') && !current_user_can('manage_krn_core')) { wp_die('Sin permisos.'); }
        global $wpdb;
        $table = self::table_name();
        $local_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        $connection = self::legacy_connection();
        $connection_ok = !is_wp_error($connection);
        $installations = $connection_ok ? self::legacy_table_exists($connection, 'instalaciones') : false;
        $cancellations = $connection_ok ? self::legacy_table_exists($connection, 'cancelaciones') : false;
        $forecast = $connection_ok ? self::legacy_table_exists($connection, 'vw_pronostico_cancelaciones_sur_actual') : false;
        ?>
        <div class="wrap">
            <h1>FCST Add Netas · Diagnóstico</h1>
            <p>El módulo es autónomo. Legacy se consulta en modo lectura y los traslados se guardan únicamente en WordPress.</p>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><th>Versión</th><td><?php echo esc_html(self::VERSION); ?></td></tr>
                    <tr><th>Contrato Personas</th><td><code><?php echo esc_html(self::PEOPLE_SCOPE_CONTRACT); ?></code> · v<?php echo esc_html(self::PEOPLE_SCOPE_CONTRACT_VERSION); ?></td></tr>
                    <tr><th>Contrato snapshot</th><td><code><?php echo esc_html(self::SNAPSHOT_CONTRACT); ?></code> · v<?php echo esc_html(self::SNAPSHOT_CONTRACT_VERSION); ?></td></tr>
                    <tr><th>Autoridad de acceso</th><td>Core / capabilities + asignación de módulo · Personas para identidad y perfil admin · legacy solo fallback</td></tr>
                    <tr><th>Ruta</th><td><code>/add-netas/</code></td></tr>
                    <tr><th>Tabla local</th><td><code><?php echo esc_html($table); ?></code> · <?php echo esc_html($local_exists ? 'Disponible' : 'Pendiente de crear'); ?></td></tr>
                    <tr><th>Conexión Legacy</th><td><?php echo esc_html($connection_ok ? 'Disponible' : $connection->get_error_message()); ?></td></tr>
                    <tr><th>Instalaciones</th><td><?php echo esc_html($installations ? 'Disponible' : 'No detectada'); ?></td></tr>
                    <tr><th>Cancelaciones</th><td><?php echo esc_html($cancellations ? 'Disponible' : 'No detectada'); ?></td></tr>
                    <tr><th>Pronóstico SAC/Cobranza</th><td><code>vw_pronostico_cancelaciones_sur_actual</code> · <?php echo esc_html($forecast ? 'Disponible' : 'No detectada'); ?></td></tr>
                    <tr><th>Política Legacy</th><td><strong>Solo lectura</strong></td></tr>
                    <tr><th>Módulo embebido anterior</th><td><?php echo esc_html(class_exists('KRN_AddNetas_Module') ? 'Detectado y neutralizado por compatibilidad' : 'No presente'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function table_name() {
        return tx_an_config('override_table', 'tx_addnetas_transfers');
    }

    private static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT UNSIGNED NOT NULL,
            week TINYINT UNSIGNED NOT NULL,
            district VARCHAR(100) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            installations INT NULL,
            voluntary INT NULL,
            involuntary INT NULL,
            involuntary_total INT NULL DEFAULT NULL,
            transfer_p INT NULL DEFAULT NULL,
            transfer_f INT NULL DEFAULT NULL,
            objective INT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            user_name VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY period_district (year, week, district),
            KEY period_start (period_start),
            KEY updated_at (updated_at)
        ) {$charset};");
        // v1.3.1 necesita distinguir 'sin captura' (NULL) de un cero capturado por el usuario.
        // dbDelta suele aplicar el cambio, pero el ALTER explícito asegura compatibilidad en instalaciones existentes.
        $wpdb->query("ALTER TABLE {$table} MODIFY transfer_p INT NULL DEFAULT NULL, MODIFY transfer_f INT NULL DEFAULT NULL");
        // v1.8.16 separa el antiguo override de Orgánicas del nuevo total rector de Involuntarias.
        $has_involuntary_total = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'involuntary_total'");
        if (!$has_involuntary_total) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN involuntary_total INT NULL DEFAULT NULL AFTER involuntary");
        }
        update_option('krn_addnetas_schema_version', self::SCHEMA_VERSION, false);
        if (!get_option(self::CACHE_REVISION_OPTION, 0)) { update_option(self::CACHE_REVISION_OPTION, 1, false); }
    }

    private static function reset_stale_forecast_overrides_once() {
        if (get_option(self::FCST_RESET_OPTION, false)) { return; }
        global $wpdb;
        $table = self::table_name();
        $exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            // Limpieza única de sugerencias heredadas para recalcular con el algoritmo 2.2.0.
            // Traslados P/F deben iniciar siempre en cero para cada periodo, sin heredar
            // el antiguo campo quantity ni capturas precargadas por versiones anteriores.
            $wpdb->query("UPDATE {$table} SET installations=NULL, voluntary=NULL, involuntary=NULL, quantity=0, transfer_p=0, transfer_f=0");
            $old = array('CANCÚN'=>59,'COATZA / MINA'=>47,'MÉRIDA'=>96,'TUXTLA'=>71,'VILLAHERMOSA'=>54);
            $new = self::default_objectives();
            foreach ($old as $district=>$old_value) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET objective=%d WHERE district=%s AND (objective IS NULL OR objective=%d)",
                    (int)$new[$district], $district, (int)$old_value
                ));
            }
        }
        update_option(self::FCST_RESET_OPTION, 1, false);
        self::bump_cache_revision();
    }

    private static function reset_projection_components_once() {
        if (get_option(self::COMPONENT_RESET_OPTION, false)) { return; }
        global $wpdb;
        $table = self::table_name();
        $exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            // v1.3.1 cambia la semántica: involuntary almacena el override de ORGÁNICAS,
            // y P/F pasan a ser proyecciones editables. Se limpian únicamente estos
            // componentes para arrancar con la sugerencia vigente de SAC/Cobranza.
            $wpdb->query("UPDATE {$table} SET involuntary=NULL, transfer_p=NULL, transfer_f=NULL, quantity=0");
        }
        update_option(self::COMPONENT_RESET_OPTION, 1, false);
        self::bump_cache_revision();
    }


    private static function install_capabilities() {
        foreach (array('administrator','krn_super_admin','talia_workforce_administrador') as $role_key) {
            $role = get_role($role_key);
            if (!$role) { continue; }
            $role->add_cap(self::CAP_VIEW, true);
            $role->add_cap(self::CAP_EDIT, true);
        }
    }

    private static function normalize_text($value) {
        $value = strtolower(remove_accents(trim((string)$value)));
        $value = preg_replace('/[^a-z0-9@._\- ]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private static function target_identities() {
        return array(
            'emails'=>array(
                'diego.guemez@totalplay.com.mx',
            ),
            'employees'=>array('65077184','65076661','65077444'),
            'names'=>array(
                'armando sanchez martinez',
                'alma yadira chavez carrillo',
                'chavez carrillo alma yadira',
                'emmanuel cabrera molano',
                'cabrera molano emmanuel',
                'emmanuel cabrera',
                'diego armando guemez brito',
                'guemez brito diego armando',
                'alx montiel',
                'alex montiel',
                'alx',
                'pedro pablo tzuc',
            ),
        );
    }

    private static function user_identity_values($user) {
        if (!$user) { return array(); }
        $values = array(
            $user->display_name,
            trim((string)get_user_meta($user->ID, 'first_name', true) . ' ' . (string)get_user_meta($user->ID, 'last_name', true)),
            trim((string)get_user_meta($user->ID, 'nombre_colaborador', true)),
            trim((string)get_user_meta($user->ID, '_krn_legacy_name', true)),
            trim((string)get_user_meta($user->ID, 'talia_people_name', true)),
        );
        return array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize_text'), $values))));
    }

    private static function is_seed_identity($user) {
        if (!$user) { return false; }
        $targets = self::target_identities();
        $email = strtolower(trim((string)$user->user_email));
        if ($email !== '' && in_array($email, $targets['emails'], true)) { return true; }
        foreach (array('numero_talento_gs','numero_empleado','employee_number','talent_id','talia_people_employee_number','talia_people_talent') as $meta_key) {
            $employee = preg_replace('/[^0-9]/', '', (string)get_user_meta($user->ID, $meta_key, true));
            if ($employee !== '' && in_array($employee, $targets['employees'], true)) { return true; }
        }
        $login_employee = preg_replace('/[^0-9]/', '', (string)$user->user_login);
        if ($login_employee !== '' && in_array($login_employee, $targets['employees'], true)) { return true; }
        foreach (self::user_identity_values($user) as $value) {
            if (in_array($value, $targets['names'], true) || strpos($value, 'pedro pablo tzuc') === 0) { return true; }
        }
        return false;
    }

    private static function grant_user_access($user_id) {
        $user = new WP_User((int)$user_id);
        if (!$user || !$user->exists()) { return false; }
        $user->add_cap(self::CAP_VIEW, true);
        $user->add_cap(self::CAP_EDIT, true);
        update_user_meta($user->ID, '_krn_addnetas_access', 'edit');
        return true;
    }

    /**
     * Compatibilidad histórica únicamente. v1.8.5 deja de invocar este sembrado:
     * no se conceden permisos nuevos por nombre, correo o número de empleado.
     */
    private static function seed_access_users() {
        update_option(self::ACCESS_SEED_OPTION, 'deprecated:' . self::VERSION, false);
    }

    public static function maybe_seed_login_user($user_login, $user) {
        // Deprecated. La identidad nominal ya no otorga acceso.
        return false;
    }

    private static function normalize_people_scope($scope, $user_id = 0, $revision = '') {
        if (!is_array($scope)) { return array(); }
        $user_id = absint($scope['user_id'] ?? $scope['wp_user_id'] ?? $user_id);
        if (!$user_id) { return array(); }
        $out = array(
            'contract'=>self::PEOPLE_SCOPE_CONTRACT,
            'contract_version'=>sanitize_text_field((string)($scope['contract_version'] ?? self::PEOPLE_SCOPE_CONTRACT_VERSION)),
            'provider'=>'talia_personas',
            'user_id'=>$user_id,
            'person_id'=>absint($scope['person_id'] ?? 0),
            'employee_id'=>sanitize_text_field((string)($scope['employee_id'] ?? $scope['talento_gs'] ?? '')),
            'district'=>sanitize_text_field((string)($scope['district'] ?? '')),
            'allowed_districts'=>array_values(array_filter(array_map('sanitize_text_field', (array)($scope['allowed_districts'] ?? $scope['districts'] ?? array())))),
            'hierarchy_role'=>sanitize_key((string)($scope['hierarchy_role'] ?? '')),
            'operational_role'=>sanitize_key((string)($scope['operational_role'] ?? $scope['hierarchy_role'] ?? '')),
            'workforce_role'=>sanitize_key((string)($scope['workforce_role'] ?? '')),
            'wp_role'=>sanitize_key((string)($scope['wp_role'] ?? '')),
            'scope_mode'=>sanitize_key((string)($scope['scope_mode'] ?? $scope['scope_type'] ?? 'unresolved')),
            'status'=>sanitize_key((string)($scope['status'] ?? $scope['scope_status'] ?? 'unknown')),
            'is_admin'=>!empty($scope['is_admin']),
            'is_regional'=>!empty($scope['is_regional']),
            'scope_hash'=>sanitize_text_field((string)($scope['scope_hash'] ?? '')),
            'hierarchy_revision'=>sanitize_text_field((string)($scope['hierarchy_revision'] ?? $revision)),
            'provider_revision'=>sanitize_text_field((string)($scope['provider_revision'] ?? $revision)),
            'synced_at'=>current_time('mysql'),
        );
        return $out;
    }

    private static function store_people_scope_snapshot($user_id, $scope = array(), $revision = '') {
        $user_id = absint($user_id);
        $scope = self::normalize_people_scope($scope, $user_id, $revision);
        if (!$user_id || empty($scope['user_id'])) { return false; }
        update_user_meta($user_id, self::PEOPLE_SCOPE_META, $scope);
        return true;
    }

    private static function import_people_scope_payload($payload = array(), $context = array()) {
        if (!is_array($payload)) { return 0; }
        $revision = sanitize_text_field((string)($payload['revision'] ?? $payload['provider_revision'] ?? $context['revision'] ?? ''));
        $records = array();
        if (!empty($payload['users']) && is_array($payload['users'])) { $records = $payload['users']; }
        elseif (!empty($payload['user']) && is_array($payload['user'])) { $records = array($payload['user']); }
        elseif (!empty($payload['user_id']) || !empty($payload['wp_user_id'])) { $records = array($payload); }
        $stored = 0;
        foreach ($records as $record) {
            if (!is_array($record)) { continue; }
            $uid = absint($record['user_id'] ?? $record['wp_user_id'] ?? 0);
            if ($uid && self::store_people_scope_snapshot($uid, $record, $revision)) { $stored++; }
        }
        return $stored;
    }

    public static function receive_core_contract_delivery($contract_key, $payload = null, $context = array()) {
        $key = strtolower(trim((string)$contract_key));
        if (!in_array($key, array(self::PEOPLE_SCOPE_CONTRACT, 'talia_personas_user_scope_v2'), true)) { return 0; }
        return self::import_people_scope_payload(is_array($payload) ? $payload : array(), is_array($context) ? $context : array());
    }

    public static function receive_people_scope_snapshot($payload = array(), $context = array()) {
        return self::import_people_scope_payload(is_array($payload) ? $payload : array(), is_array($context) ? $context : array());
    }

    public static function receive_people_scope_user_update($user_id = 0, $scope = array(), $revision = '', $old_hash = '') {
        if (is_array($user_id) && !$scope) {
            $scope = $user_id;
            $user_id = absint($scope['user_id'] ?? $scope['wp_user_id'] ?? 0);
        }
        return self::store_people_scope_snapshot(absint($user_id), is_array($scope) ? $scope : array(), $revision);
    }

    public static function maybe_refresh_people_scope_contract() {
        if ((string)get_option(self::PEOPLE_SCOPE_BOOTSTRAP_OPTION, '') === self::VERSION) { return; }
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) { return; }
        global $pagenow;
        if (in_array((string)$pagenow, array('admin-ajax.php','admin-post.php','async-upload.php'), true)) { return; }
        $lock = 'krn_addnetas_people_scope_contract_lock';
        if (get_transient($lock)) { return; }
        set_transient($lock, 1, 5 * MINUTE_IN_SECONDS);
        try {
            self::register_to_core();
            $context = array(
                'consumer'=>'krn_addnetas',
                'consumer_version'=>self::VERSION,
                'contract'=>self::PEOPLE_SCOPE_CONTRACT,
                'contract_version'=>self::PEOPLE_SCOPE_CONTRACT_VERSION,
                'user_id'=>get_current_user_id(),
                'fields'=>array('user_id','person_id','employee_id','district','allowed_districts','hierarchy_role','operational_role','workforce_role','wp_role','scope_mode','status','is_admin','is_regional','scope_hash','hierarchy_revision'),
                'delivery'=>'current_user_bootstrap',
                'runtime_fetch'=>false,
            );
            $payload = apply_filters('krn_core_contract_snapshot', null, self::PEOPLE_SCOPE_CONTRACT, $context);
            if (!is_array($payload)) { $payload = apply_filters('talia_personas_export_user_scope_snapshot', null, $context); }
            if (is_array($payload)) { self::import_people_scope_payload($payload, $context); }
            do_action('krn_core_contract_refresh_requested', self::PEOPLE_SCOPE_CONTRACT, $context);
            update_option(self::PEOPLE_SCOPE_BOOTSTRAP_OPTION, self::VERSION, false);
        } finally {
            delete_transient($lock);
        }
    }

    private static function people_scope_for_user($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) { return array(); }
        $scope = get_user_meta($user_id, self::PEOPLE_SCOPE_META, true);
        if (is_array($scope) && !empty($scope['user_id'])) { return $scope; }
        // Personas v2 ya publica un snapshot ligero por usuario; leerlo no recorre HC ni jerarquías.
        $scope = get_user_meta($user_id, '_krn_people_scope_identity_v2', true);
        if (is_array($scope) && !empty($scope['user_id'])) {
            self::store_people_scope_snapshot($user_id, $scope, (string)($scope['hierarchy_revision'] ?? ''));
            return self::normalize_people_scope($scope, $user_id, (string)($scope['hierarchy_revision'] ?? ''));
        }
        // Fallback de compatibilidad: sólo un usuario y sólo si el helper canónico está disponible.
        if (function_exists('krn_people_user_scope_v2')) {
            $scope = krn_people_user_scope_v2($user_id);
            if (is_array($scope) && !empty($scope['user_id'])) {
                self::store_people_scope_snapshot($user_id, $scope, (string)($scope['hierarchy_revision'] ?? ''));
                return self::normalize_people_scope($scope, $user_id, (string)($scope['hierarchy_revision'] ?? ''));
            }
        }
        return array();
    }

    private static function people_scope_active($scope) {
        if (!is_array($scope) || empty($scope['user_id'])) { return true; }
        $status = sanitize_key((string)($scope['status'] ?? 'unknown'));
        return !in_array($status, array('baja','inactive','disabled','terminated','archived'), true);
    }

    /**
     * Aplana cualquier representación de módulos publicada por Core.
     * Core 0.2.26+ conserva módulos legacy y módulos por dominio en metadatos
     * distintos; ADD Netas debe reconocer ambos sin reconstruir perfiles.
     */
    private static function flatten_module_tokens($value, &$flat) {
        if (is_array($value)) {
            foreach ($value as $key=>$item) {
                if (!is_int($key)) { $flat[] = sanitize_key((string)$key); }
                self::flatten_module_tokens($item, $flat);
            }
            return;
        }
        if (is_scalar($value)) { $flat[] = sanitize_key((string)$value); }
    }

    private static function core_module_tokens_for_user($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) { return array(); }
        $sources = array(
            get_user_meta($user_id, 'krn_operational_modules', true),
            get_user_meta($user_id, 'krn_core_domain_modules_v1', true),
        );
        if (function_exists('krn_core_effective_modules')) {
            try { $sources[] = krn_core_effective_modules($user_id); } catch (Throwable $e) {}
        }
        $flat = array();
        foreach ($sources as $source) { self::flatten_module_tokens($source, $flat); }
        return array_values(array_unique(array_filter(array_map('sanitize_key', $flat))));
    }

    private static function user_has_core_module_assignment($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) { return false; }
        $aliases = array('krn_addnetas','addnetas','add_netas','add-netas','talia_addnetas','fcst_add_netas');
        $flat = self::core_module_tokens_for_user($user_id);
        $assigned = (bool)array_intersect($aliases, $flat);
        return (bool)apply_filters('talia_addnetas_core_module_assignment', $assigned, $user_id, $flat);
    }

    private static function normalize_sac_domain_role($role) {
        $role = sanitize_key((string)$role);
        $aliases = array(
            'regional'=>'director','direccion_regional'=>'director','direccin_regional'=>'director',
            'regional_direccion_regional'=>'director','director_regional'=>'director',
            'director_distrital'=>'director','director'=>'director','gerente'=>'gerente',
            'jefe'=>'jefe','lider'=>'jefe','lider_ventas'=>'jefe','gestor'=>'gestor',
        );
        return sanitize_key((string)($aliases[$role] ?? ''));
    }

    /**
     * Resuelve el perfil SAC/Cobranza sin listas nominales.
     * Prioridad: contrato Core -> perfiles Core -> espejo Personas -> snapshot
     * ligero de Personas -> metadatos legacy auditables.
     */
    private static function sac_domain_context_for_user($user_id, $people_scope = array()) {
        $user_id = absint($user_id);
        if (!$user_id) { return array(); }

        if (function_exists('krn_core_domain_scope_context')) {
            try {
                $context = krn_core_domain_scope_context($user_id, 'sac_cobranza');
                if (is_array($context) && !empty($context['ok']) && ($context['status'] ?? '') === 'active') {
                    $role = self::normalize_sac_domain_role($context['role'] ?? '');
                    if ($role) {
                        return array(
                            'source'=>'krn_core.domain_scope.v1',
                            'role'=>$role,
                            'repositories'=>array_values(array_unique(array_filter(array_map('sanitize_key', (array)($context['repositories'] ?? array()))))),
                            'scope_model'=>sanitize_key((string)($context['scope_model'] ?? 'assigned')),
                            'active'=>true,
                        );
                    }
                }
            } catch (Throwable $e) {}
        }

        if (function_exists('krn_core_user_domain_profiles')) {
            try {
                $profiles = krn_core_user_domain_profiles($user_id);
                $entry = is_array($profiles) && isset($profiles['sac_cobranza']) && is_array($profiles['sac_cobranza'])
                    ? $profiles['sac_cobranza'] : array();
                $role = self::normalize_sac_domain_role($entry['role'] ?? '');
                if ($role) {
                    return array(
                        'source'=>'krn_core_user_domain_profiles',
                        'role'=>$role,
                        'repositories'=>array_values(array_unique(array_filter(array_map('sanitize_key', (array)($entry['repositories'] ?? array('sac_cobranza')))))),
                        'scope_model'=>sanitize_key((string)($entry['scope_model'] ?? 'assigned')),
                        'active'=>!array_key_exists('active', $entry) || (bool)$entry['active'],
                    );
                }
            } catch (Throwable $e) {}
        }

        $mirrored_role = self::normalize_sac_domain_role(get_user_meta($user_id, 'talia_people_sac_role', true));
        if ($mirrored_role) {
            return array('source'=>'personas_sac_role_mirror','role'=>$mirrored_role,'repositories'=>array('sac_cobranza'),'scope_model'=>'assigned','active'=>true);
        }

        $scope = is_array($people_scope) ? $people_scope : array();
        $workforce = sanitize_key((string)($scope['workforce_role'] ?? get_user_meta($user_id, 'talia_people_workforce_role', true)));
        if (strpos($workforce, 'talia_workforce_') === 0) { $workforce = substr($workforce, strlen('talia_workforce_')); }
        $is_sac = in_array($workforce, array('sac','cobranza','sac_cobranza','sac_y_cobranza','sac-y-cobranza'), true);
        if (!$is_sac) {
            $user = get_userdata($user_id);
            $roles = $user && is_array($user->roles) ? array_map('sanitize_key', $user->roles) : array();
            $is_sac = (bool)array_intersect(array('talia_workforce_sac_cobranza','talia_workforce_sac_y_cobranza'), $roles);
        }
        if (!$is_sac) { return array(); }

        $role_candidates = array(
            $scope['operational_role'] ?? '',
            $scope['hierarchy_role'] ?? '',
            get_user_meta($user_id, 'talia_people_operational_role', true),
            get_user_meta($user_id, 'talia_people_hierarchy_role', true),
            get_user_meta($user_id, 'krn_operational_role', true),
        );
        foreach ($role_candidates as $candidate) {
            $role = self::normalize_sac_domain_role($candidate);
            if ($role) {
                return array(
                    'source'=>'personas_workforce_hierarchy_fallback',
                    'role'=>$role,
                    'repositories'=>array('sac_cobranza'),
                    'scope_model'=>$role === 'director' ? 'region' : ($role === 'gerente' ? 'district' : ($role === 'jefe' ? 'team' : 'self')),
                    'active'=>true,
                );
            }
        }
        return array();
    }

    private static function user_has_sac_domain_access($user_id, $mode = 'view', $people_scope = array()) {
        $mode = $mode === 'edit' ? 'edit' : 'view';
        $context = self::sac_domain_context_for_user($user_id, $people_scope);
        if (empty($context['active']) || empty($context['role'])) { return false; }
        $policy = array(
            'view'=>array('director'),
            'edit'=>array('director'),
        );
        $filtered_policy = apply_filters('talia_addnetas_sac_domain_access_policy', $policy, absint($user_id), $context);
        if (is_array($filtered_policy)) { $policy = $filtered_policy; }
        $allowed_roles = array_values(array_unique(array_filter(array_map('sanitize_key', (array)($policy[$mode] ?? array())))));
        $repositories = array_values(array_unique(array_filter(array_map('sanitize_key', (array)($context['repositories'] ?? array())))));
        $repository_granted = !$repositories || in_array('sac_cobranza', $repositories, true) || in_array('sac', $repositories, true) || in_array('cobranza', $repositories, true);
        return $repository_granted && in_array(sanitize_key((string)$context['role']), $allowed_roles, true);
    }

    private static function raw_user_allcaps($user_id) {
        $user = get_userdata(absint($user_id));
        return $user && is_array($user->allcaps) ? $user->allcaps : array();
    }

    private static function canonical_access_decision($user_id, $mode = 'view', $allcaps = null) {
        $user_id = absint($user_id);
        if (!$user_id) { return false; }
        $mode = $mode === 'edit' ? 'edit' : 'view';
        $allcaps = is_array($allcaps) ? $allcaps : self::raw_user_allcaps($user_id);
        if (!empty($allcaps['manage_options']) || !empty($allcaps['manage_krn_core'])) { return true; }
        if ($mode === 'view' && (!empty($allcaps[self::CAP_VIEW]) || !empty($allcaps[self::CAP_EDIT]))) { return true; }
        if ($mode === 'edit' && !empty($allcaps[self::CAP_EDIT])) { return true; }

        $scope = self::people_scope_for_user($user_id);
        if (!self::people_scope_active($scope)) { return false; }
        // Administrador canónico de Personas equivale al perfil administrador de Talia,
        // sin exigir que su área Workforce sea Comercial.
        if (!empty($scope['is_admin'])) { return true; }

        // El perfil canónico SAC/Cobranza Director habilita Dashboard y FCST.
        // La decisión se toma por contrato/perfil, nunca por nombre de persona.
        if (self::user_has_sac_domain_access($user_id, $mode, $scope)) { return true; }

        // La asignación explícita del módulo realizada por Core/Personas habilita lectura.
        // Edición sigue exigiendo capability edit, perfil SAC Director o perfil administrador.
        if ($mode === 'view' && self::user_has_core_module_assignment($user_id)) { return true; }
        return false;
    }

    public static function filter_user_caps($allcaps, $caps, $args, $user) {
        if (!is_array($allcaps)) { return $allcaps; }
        $requested = array_values(array_map(static function($cap){ return strtolower(trim((string)$cap)); }, (array)$caps));
        if (!array_intersect($requested, array(self::CAP_VIEW, self::CAP_EDIT))) { return $allcaps; }
        $user_id = is_object($user) && !empty($user->ID) ? absint($user->ID) : absint($args[1] ?? 0);
        if (!$user_id) { return $allcaps; }
        if (self::canonical_access_decision($user_id, 'view', $allcaps)) { $allcaps[self::CAP_VIEW] = true; }
        if (self::canonical_access_decision($user_id, 'edit', $allcaps)) { $allcaps[self::CAP_EDIT] = true; }
        return $allcaps;
    }

    public static function provide_core_contract_snapshot($payload, $contract_key, $context = array()) {
        $key = strtolower(trim((string)$contract_key));
        if (!in_array($key, array(self::SNAPSHOT_CONTRACT, 'talia_addnetas_snapshot_v1'), true)) { return $payload; }
        $args = is_array($context) && !empty($context['args']) && is_array($context['args']) ? $context['args'] : array();
        $snapshot = self::public_snapshot($args);
        return is_wp_error($snapshot) ? $payload : $snapshot;
    }

    public static function can_view($user_id = 0) {
        return tx_an_can('view');
    }

    public static function can_edit($user_id = 0) {
        return tx_an_can('edit');
    }

    private static function ensure_page() {
        // FCST Add Netas conserva la ruta histórica /add-netas/.
        $page = get_page_by_path('add-netas', OBJECT, 'page');
        if (!$page) {
            $legacy = get_page_by_path('proyeccion-addnetas', OBJECT, 'page');
            if ($legacy) {
                $updated = wp_update_post(array(
                    'ID'=>(int)$legacy->ID,
                    'post_title'=>'FCST Add Netas',
                    'post_name'=>'add-netas',
                    'post_content'=>'[krn_addnetas]',
                    'post_status'=>'publish',
                    'comment_status'=>'closed',
                ), true);
                if (!is_wp_error($updated)) { $page = get_post((int)$updated); }
            }
        }
        if (!$page) {
            $id = wp_insert_post(array(
                'post_title'=>'FCST Add Netas',
                'post_name'=>'add-netas',
                'post_content'=>'[krn_addnetas]',
                'post_status'=>'publish',
                'post_type'=>'page',
                'comment_status'=>'closed',
            ), true);
            if (!is_wp_error($id)) { $page = get_post((int)$id); }
        } elseif (trim((string)$page->post_content) !== '[krn_addnetas]' || (string)$page->post_title !== 'FCST Add Netas') {
            wp_update_post(array('ID'=>(int)$page->ID, 'post_content'=>'[krn_addnetas]', 'post_title'=>'FCST Add Netas'));
        }
        if ($page) { update_option('krn_addnetas_page_id', (int)$page->ID, false); }

        // Vista REAL, misma experiencia visual pero sin edición ni FCST en los cálculos.
        $real = get_page_by_path('real-add-netas', OBJECT, 'page');
        if (!$real) {
            $id = wp_insert_post(array(
                'post_title'=>'Dashboard Add Netas',
                'post_name'=>'real-add-netas',
                'post_content'=>'[krn_addnetas_real]',
                'post_status'=>'publish',
                'post_type'=>'page',
                'comment_status'=>'closed',
            ), true);
            if (!is_wp_error($id)) { $real = get_post((int)$id); }
        } elseif (trim((string)$real->post_content) !== '[krn_addnetas_real]' || (string)$real->post_title !== 'Dashboard Add Netas') {
            wp_update_post(array('ID'=>(int)$real->ID, 'post_content'=>'[krn_addnetas_real]', 'post_title'=>'Dashboard Add Netas'));
        }
        if ($real) { update_option('krn_addnetas_real_page_id', (int)$real->ID, false); }
        update_option('krn_addnetas_page_version', self::PAGE_VERSION, false);
    }

    private static function decrypt_connection_secret($stored) {
        $stored = (string)$stored;
        if ($stored === '') { return ''; }
        if (strpos($stored, 'openssl:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($stored, 8), true);
            if (!$raw || strlen($raw) <= 16) { return ''; }
            $iv = substr($raw, 0, 16);
            $cipher = substr($raw, 16);
            $key = hash('sha256', wp_salt('auth'), true);
            $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return is_string($plain) ? $plain : '';
        }
        if (strpos($stored, 'b64:') === 0) {
            $plain = base64_decode(substr($stored, 4), true);
            return is_string($plain) ? $plain : '';
        }
        return '';
    }

    private static function legacy_connection() {
        try { return tx_an_db('source'); }
        catch (Throwable $e) { return new WP_Error('connection', 'Fuente TotalExpedient no disponible. Revisa configuración y conexión.'); }
    }

    private static function legacy_table_exists($db, $table) {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
        if ($table === '') { return false; }
        $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $table));
        return !empty($found);
    }

    private static function legacy_columns($db, $table) {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
        if ($table === '' || !self::legacy_table_exists($db, $table)) { return array(); }
        $rows = $db->get_results('DESCRIBE `' . esc_sql($table) . '`');
        $cols = array();
        foreach ((array)$rows as $row) { if (!empty($row->Field)) { $cols[] = (string)$row->Field; } }
        return $cols;
    }

    private static function first_column(array $columns, array $candidates) {
        foreach ($candidates as $candidate) { if (in_array($candidate, $columns, true)) { return $candidate; } }
        return '';
    }

    private static function ident($value) {
        return '`' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$value) . '`';
    }

    private static function migrate_legacy_transfers_once() {
        if (get_option(self::LEGACY_MIGRATION_OPTION, false)) { return; }
        $db = self::legacy_connection();
        if (is_wp_error($db)) { return; }
        if (!self::legacy_table_exists($db, 'proyeccion_addnetas_traslados')) {
            update_option(self::LEGACY_MIGRATION_OPTION, array('status'=>'not_found','at'=>current_time('mysql')), false);
            return;
        }
        $rows = $db->get_results('SELECT anio, semana, distrito, cantidad, usuario_id, usuario_nombre FROM `proyeccion_addnetas_traslados` ORDER BY anio, semana, distrito', ARRAY_A);
        global $wpdb;
        $table = self::table_name();
        $count = 0;
        foreach ((array)$rows as $row) {
            $year = max(2020, (int)($row['anio'] ?? 0));
            $week = max(1, min(53, (int)($row['semana'] ?? 0)));
            $district = self::normalize_district($row['distrito'] ?? '');
            if ($district === '') { continue; }
            list($start, $end) = self::iso_week_bounds($year, $week);
            $wpdb->replace($table, array(
                'year'=>$year,
                'week'=>$week,
                'district'=>$district,
                'quantity'=>max(0, (int)($row['cantidad'] ?? 0)),
                'period_start'=>$start,
                'period_end'=>$end,
                'user_id'=>absint($row['usuario_id'] ?? 0) ?: null,
                'user_name'=>sanitize_text_field($row['usuario_nombre'] ?? ''),
                'created_at'=>current_time('mysql'),
                'updated_at'=>current_time('mysql'),
            ), array('%d','%d','%s','%d','%s','%s','%d','%s','%s','%s'));
            $count++;
        }
        update_option(self::LEGACY_MIGRATION_OPTION, array('status'=>'migrated','rows'=>$count,'at'=>current_time('mysql')), false);
        self::bump_cache_revision();
    }

    private static function normalize_district($value) {
        $value = strtoupper(remove_accents(trim((string)$value)));
        $value = preg_replace('/\s+/', ' ', $value);
        $map = array(
            'CANCUN'=>'CANCÚN',
            'COATZA MINA'=>'COATZA / MINA',
            'COATZA-MINA'=>'COATZA / MINA',
            'COATZA / MINA'=>'COATZA / MINA',
            'COATZACOALCOS-MINATITLAN'=>'COATZA / MINA',
            'MERIDA'=>'MÉRIDA',
            'TUXTLA'=>'TUXTLA',
            'TUXTLA GUTIERREZ'=>'TUXTLA',
            'VILLAHERMOSA'=>'VILLAHERMOSA',
        );
        return $map[$value] ?? '';
    }

    private static function district_case($column) {
        $c = 'UPPER(TRIM(COALESCE(' . self::ident($column) . ",'')))";
        return "CASE
            WHEN {$c} IN ('CANCUN','CANCÚN') THEN 'CANCÚN'
            WHEN {$c} IN ('COATZA MINA','COATZA-MINA','COATZA / MINA','COATZACOALCOS-MINATITLAN','COATZACOALCOS-MINATITLÁN') THEN 'COATZA / MINA'
            WHEN {$c} IN ('MERIDA','MÉRIDA') THEN 'MÉRIDA'
            WHEN {$c} IN ('TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ') THEN 'TUXTLA'
            WHEN {$c} IN ('VILLAHERMOSA') THEN 'VILLAHERMOSA'
            ELSE '' END";
    }

    private static function iso_week_bounds($year, $week) {
        $date = new DateTime('now', wp_timezone());
        $date->setISODate((int)$year, (int)$week, 1)->setTime(0,0,0);
        $start = clone $date;
        $end = clone $date;
        $end->modify('+6 days');
        return array($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    private static function context(array $args = array()) {
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $active_year = (int)$now->format('o');
        $active_week = (int)$now->format('W');
        $mode_raw = array_key_exists('add_mode', $args) ? $args['add_mode'] : ($_GET['add_mode'] ?? '');
        $mode = sanitize_key(wp_unslash((string)$mode_raw)) === 'mensual' ? 'mensual' : 'semanal';

        if ($mode === 'mensual') {
            $active_month = $now->format('Y-m');
            $month_raw = array_key_exists('add_month', $args) ? $args['add_month'] : ($_GET['add_month'] ?? $active_month);
            $month = sanitize_text_field(wp_unslash((string)$month_raw));
            if (!preg_match('/^\d{4}-\d{2}$/', $month) || $month > $active_month) { $month = $active_month; }
            $month_date = DateTime::createFromFormat('!Y-m-d', $month . '-01', $tz);
            if (!$month_date) { $month_date = new DateTime($active_month . '-01', $tz); $month = $active_month; }
            $previous = clone $month_date;
            $previous->modify('-1 month');
            return array(
                'mode'=>'mensual',
                'year'=>(int)$month_date->format('Y'),
                'month'=>$month,
                'start'=>$month_date->format('Y-m-01'),
                'end'=>$month_date->format('Y-m-t'),
                'active_month'=>$active_month,
                'previous_month'=>$previous->format('Y-m'),
                'label'=>ucfirst(wp_date('F Y', $month_date->getTimestamp(), $tz)),
            );
        }

        $year_raw = array_key_exists('add_year', $args) ? $args['add_year'] : ($_GET['add_year'] ?? $active_year);
        $week_raw = array_key_exists('add_week', $args) ? $args['add_week'] : ($_GET['add_week'] ?? $active_week);
        $year = max(2020, (int)$year_raw);
        $week = max(1, min(53, (int)$week_raw));
        if ($year > $active_year || ($year === $active_year && $week > $active_week)) { $year = $active_year; $week = $active_week; }
        list($start, $end) = self::iso_week_bounds($year, $week);
        $previous = new DateTime($start, $tz);
        $previous->modify('-7 days');
        return array(
            'mode'=>'semanal',
            'year'=>$year,
            'week'=>$week,
            'start'=>$start,
            'end'=>$end,
            'active_year'=>$active_year,
            'active_week'=>$active_week,
            'previous_year'=>(int)$previous->format('o'),
            'previous_week'=>(int)$previous->format('W'),
            'label'=>'Semana ' . $week . ' · ' . $year,
        );
    }

    private static function cutoff_date() {
        $cutoff = new DateTime('now', wp_timezone());
        $cutoff->modify('-1 day')->setTime(23, 59, 59);
        return $cutoff->format('Y-m-d');
    }

    private static function cache_key(array $ctx) {
        $revision = (int)get_option(self::CACHE_REVISION_OPTION, 1);
        $identity = array(
            'version'=>self::VERSION,
            'scope_role'=>strtolower(trim((string)($_SESSION['rol']??''))),
            'scope_district'=>tx_an_scope_district(),
            'forecast_algorithm'=>self::FORECAST_ALGORITHM_VERSION,
            'cutoff'=>self::cutoff_date(),
            'mode'=>$ctx['mode'],
            'year'=>$ctx['year'] ?? 0,
            'week'=>$ctx['week'] ?? 0,
            'month'=>$ctx['month'] ?? '',
            'start'=>$ctx['start'],
            'end'=>$ctx['end'],
            'revision'=>$revision,
        );
        return 'data_' . md5(wp_json_encode($identity));
    }

    private static function cache_get($key) {
        if (isset(self::$runtime_cache[$key])) { return self::$runtime_cache[$key]; }
        $found = false;
        $value = wp_cache_get($key, 'krn_addnetas', false, $found);
        if ($found && is_array($value)) { return self::$runtime_cache[$key] = $value; }
        $value = get_transient('krn_addnetas_' . md5($key));
        return is_array($value) ? (self::$runtime_cache[$key] = $value) : false;
    }

    private static function cache_set($key, array $value) {
        self::$runtime_cache[$key] = $value;
        wp_cache_set($key, $value, 'krn_addnetas', 300);
        set_transient('krn_addnetas_' . md5($key), $value, 300);
    }

    private static function bump_cache_revision() {
        $revision = (int)get_option(self::CACHE_REVISION_OPTION, 1) + 1;
        update_option(self::CACHE_REVISION_OPTION, $revision, false);
        self::$runtime_cache = array();
        return $revision;
    }

    private static function source_spec($db) {
        $spec = array('installations'=>array(), 'cancellations'=>array(), 'forecast'=>array(), 'warnings'=>array());
        if (self::legacy_table_exists($db, 'instalaciones')) {
            $cols = self::legacy_columns($db, 'instalaciones');
            $spec['installations'] = array(
                'table'=>'instalaciones',
                'account'=>self::first_column($cols, array('cuenta','numero_cuenta','account_number')),
                'district'=>self::first_column($cols, array('distrito','district','plaza')),
                'date'=>self::first_column($cols, array('fecha','fecha_instalacion','fecha_terminacion','fecha_completada')),
                'origin'=>self::first_column($cols, array('origen_prospecto','origen','prospect_origin')),
            );
        }
        if (self::legacy_table_exists($db, 'cancelaciones')) {
            $cols = self::legacy_columns($db, 'cancelaciones');
            $spec['cancellations'] = array(
                'table'=>'cancelaciones',
                'account'=>self::first_column($cols, array('cuenta','numero_cuenta','account_number')),
                'district'=>self::first_column($cols, array('distrito','district','plaza')),
                'date'=>self::first_column($cols, array('fecha_cancelacion','fecha','fecha_baja')),
                'type'=>self::first_column($cols, array('tipo_cancelacion','motivo_cancelacion','tipo','motivo')),
            );
        }
        if (self::legacy_table_exists($db, 'vw_pronostico_cancelaciones_sur_actual')) {
            $cols = self::legacy_columns($db, 'vw_pronostico_cancelaciones_sur_actual');
            $spec['forecast'] = array(
                'table'=>'vw_pronostico_cancelaciones_sur_actual',
                'account'=>self::first_column($cols, array('cuenta','numero_cuenta','account_number')),
                'district'=>self::first_column($cols, array('distrito','district','plaza')),
                'final_date'=>self::first_column($cols, array('fecha_final','fecha final','fecha_pronostico')),
                'final_week'=>self::first_column($cols, array('semana_final','semana final','semana_pronostico')),
                'substatus'=>self::first_column($cols, array('subestatus','substatus')),
            );
        }
        foreach (array('installations','cancellations') as $source) {
            if (empty($spec[$source]['table']) || empty($spec[$source]['account']) || empty($spec[$source]['district']) || empty($spec[$source]['date'])) {
                $spec['warnings'][] = 'La fuente ' . $source . ' no contiene todas las columnas requeridas.';
            }
        }
        if (!empty($spec['installations']['table']) && empty($spec['installations']['origin'])) {
            $spec['warnings'][] = 'Instalaciones no expone origen_prospecto; el cálculo usa todas las filas válidas como respaldo.';
        }
        if (!empty($spec['cancellations']['table']) && empty($spec['cancellations']['type'])) {
            $spec['warnings'][] = 'Cancelaciones no expone tipo_cancelacion; no es posible separar Voluntarias e Involuntarias.';
        }
        if (empty($spec['forecast']['table']) || empty($spec['forecast']['account']) || empty($spec['forecast']['district']) || empty($spec['forecast']['final_date']) || empty($spec['forecast']['substatus'])) {
            $spec['warnings'][] = 'Pronóstico SAC/Cobranza no disponible; Involuntarias usará temporalmente el histórico de tres semanas.';
        }
        return $spec;
    }

    private static function previous_same_weekdays($target_date, $count = 3) {
        $dates = array();
        $cursor = new DateTime($target_date, wp_timezone());
        for ($i = 0; $i < max(1, (int)$count); $i++) {
            $cursor->modify('-7 days');
            $dates[] = $cursor->format('Y-m-d');
        }
        return $dates;
    }

    private static function daily_installations($db, array $source, $history_start, $history_end) {
        $out = array();
        if (empty($source['table']) || empty($source['account']) || empty($source['district']) || empty($source['date'])) { return $out; }
        $district_case = self::district_case($source['district']);
        $account = self::ident($source['account']);
        $date = self::ident($source['date']);
        $origin_clause = '';
        if (!empty($source['origin'])) {
            $origin = self::ident($source['origin']);
            $origin_clause = " AND COALESCE(TRIM({$origin}), '') <> '-'";
        }
        // Fórmula homologada al Dashboard regional: COUNT(cuenta), no COUNT DISTINCT.
        $sql = $db->prepare(
            "SELECT DATE({$date}) data_date, {$district_case} district_norm, COUNT({$account}) total
             FROM `instalaciones`
             WHERE DATE({$date}) BETWEEN %s AND %s
               AND {$account} IS NOT NULL AND TRIM({$account})<>'' {$origin_clause}
             GROUP BY data_date, district_norm",
            $history_start,
            $history_end
        );
        foreach ((array)$db->get_results($sql, ARRAY_A) as $row) {
            $date_key = (string)($row['data_date'] ?? '');
            $district = self::normalize_district($row['district_norm'] ?? '');
            if ($date_key !== '' && $district !== '') { $out[$date_key][$district] = max(0, (int)($row['total'] ?? 0)); }
        }
        return $out;
    }

    private static function daily_cancellations($db, array $source, $history_start, $history_end) {
        $out = array();
        if (empty($source['table']) || empty($source['account']) || empty($source['district']) || empty($source['date'])) { return $out; }
        $district = self::ident($source['district']);
        $district_case = self::district_case($district);
        $account = self::ident($source['account']);
        $date = self::ident($source['date']);
        $type = !empty($source['type']) ? self::ident($source['type']) : '';

        /*
         * Regla oficial SAC/Cobranza:
         * - Se contabilizan los registros de cancelaciones por fecha y distrito.
         * - La clasificación usa igualdad exacta, no LIKE.
         * - No se colapsa una cuenta a una sola fila dentro de toda la ventana histórica,
         *   porque eso reduce indebidamente los totales semanales y el FCST.
         */
        $voluntary_case = $type !== ''
            ? "CASE WHEN UPPER(TRIM(COALESCE({$type},''))) = 'CANCELACION VOLUNTARIA' THEN 1 ELSE 0 END"
            : "0";
        $involuntary_case = $type !== ''
            ? "CASE WHEN UPPER(TRIM(COALESCE({$type},''))) = 'CANCELACION INVOLUNTARIA' THEN 1 ELSE 0 END"
            : "0";

        $sql = $db->prepare(
            "SELECT DATE({$date}) data_date,
                    {$district_case} district_norm,
                    COUNT({$account}) cancelaciones,
                    SUM({$voluntary_case}) voluntarias,
                    SUM({$involuntary_case}) involuntarias
             FROM `cancelaciones`
             WHERE DATE({$date}) BETWEEN %s AND %s
               AND {$account} IS NOT NULL
               AND TRIM({$account}) <> ''
             GROUP BY data_date, district_norm",
            $history_start,
            $history_end
        );

        foreach ((array)$db->get_results($sql, ARRAY_A) as $row) {
            $date_key = (string)($row['data_date'] ?? '');
            $district_norm = self::normalize_district($row['district_norm'] ?? '');
            if ($date_key === '' || $district_norm === '') { continue; }
            $total = max(0, (int)($row['cancelaciones'] ?? 0));
            $voluntary = max(0, (int)($row['voluntarias'] ?? 0));
            $involuntary = max(0, (int)($row['involuntarias'] ?? 0));
            $out[$date_key][$district_norm] = array(
                'cancelaciones'=>$total,
                'voluntarias'=>$voluntary,
                'involuntarias'=>$involuntary,
                'sin_clasificar'=>max(0, $total - $voluntary - $involuntary),
            );
        }
        return $out;
    }

    /**
     * Serie semanal para lectura ejecutiva.
     *
     * Devuelve las 10 semanas que terminan en la semana seleccionada. Las nueve
     * anteriores se construyen con datos reales de las tablas operativas. La
     * semana seleccionada también conserva su real observado, pero en la capa
     * visual se sustituye por la proyección editable vigente para que el usuario
     * compare el FCST contra su propia historia sin mezclar fuentes.
     */
    private static function weekly_history($db, array $spec, array $ctx, $weeks = 10) {
        if (($ctx['mode'] ?? '') !== 'semanal') { return array(); }
        $districts = tx_an_allowed_districts();
        $weeks = max(2, min(16, (int)$weeks));
        $tz = wp_timezone();
        $selected_start = new DateTime($ctx['start'], $tz);
        $periods = array();
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = clone $selected_start;
            if ($i > 0) { $start->modify('-' . $i . ' weeks'); }
            $end = clone $start; $end->modify('+6 days');
            $rows = array();
            foreach ($districts as $d) {
                $rows[$d] = array(
                    'installations'=>0,
                    'cancellations'=>0,
                    'voluntary'=>0,
                    'involuntary'=>0,
                    'add_netas'=>0,
                );
            }
            $periods[] = array(
                'year'=>(int)$start->format('o'),
                'week'=>(int)$start->format('W'),
                'label'=>'S' . (int)$start->format('W'),
                'start'=>$start->format('Y-m-d'),
                'end'=>$end->format('Y-m-d'),
                'selected'=>$start->format('Y-m-d') === $ctx['start'],
                'rows'=>$rows,
                'region'=>array('installations'=>0,'cancellations'=>0,'voluntary'=>0,'involuntary'=>0,'add_netas'=>0),
            );
        }
        if (!$periods) { return array(); }
        $history_start = $periods[0]['start'];
        $history_end = min($periods[count($periods)-1]['end'], self::cutoff_date());
        if ($history_end < $history_start) { return $periods; }

        $imap = self::daily_installations($db, $spec['installations'] ?? array(), $history_start, $history_end);
        $cmap = self::daily_cancellations($db, $spec['cancellations'] ?? array(), $history_start, $history_end);
        $by_date = array();
        foreach ($periods as $idx => $period) {
            $cursor = new DateTime($period['start'], $tz);
            $end = new DateTime($period['end'], $tz);
            while ($cursor <= $end) {
                $by_date[$cursor->format('Y-m-d')] = $idx;
                $cursor->modify('+1 day');
            }
        }

        foreach ($imap as $date => $district_map) {
            if (!isset($by_date[$date])) { continue; }
            $idx = $by_date[$date];
            foreach ($districts as $d) {
                $periods[$idx]['rows'][$d]['installations'] += max(0, (int)($district_map[$d] ?? 0));
            }
        }
        foreach ($cmap as $date => $district_map) {
            if (!isset($by_date[$date])) { continue; }
            $idx = $by_date[$date];
            foreach ($districts as $d) {
                $x = $district_map[$d] ?? array();
                $v = max(0, (int)($x['voluntarias'] ?? 0));
                $iv = max(0, (int)($x['involuntarias'] ?? 0));
                $periods[$idx]['rows'][$d]['voluntary'] += $v;
                $periods[$idx]['rows'][$d]['involuntary'] += $iv;
                // La pantalla de FCST trabaja con Voluntarias + Involuntarias.
                $periods[$idx]['rows'][$d]['cancellations'] += $v + $iv;
            }
        }

        foreach ($periods as $idx => $period) {
            foreach ($districts as $d) {
                $periods[$idx]['rows'][$d]['add_netas'] =
                    (int)$periods[$idx]['rows'][$d]['installations'] -
                    (int)$periods[$idx]['rows'][$d]['cancellations'];
                foreach (array('installations','cancellations','voluntary','involuntary','add_netas') as $metric) {
                    $periods[$idx]['region'][$metric] += (int)$periods[$idx]['rows'][$d][$metric];
                }
            }
        }
        return $periods;
    }

    private static function forecast_week_profile($db, array $forecast, array $cancel_source, array $ctx, $latest_c, array $real_involuntary) {
        $districts = tx_an_allowed_districts();
        $out = array('available'=>false,'rows'=>array(),'warning'=>'');
        foreach ($districts as $d) {
            $out['rows'][$d] = array(
                'organicas_reales'=>0,'organicas_fcst'=>0,'organicas'=>0,'organicas_vencidas'=>0,
                'traslado_p_reales'=>0,'traslado_p_fcst'=>0,'traslado_p'=>0,'traslado_p_vencidos'=>0,
                'traslado_p_programado'=>0,'traslado_f'=>0,
            );
        }
        if (($ctx['mode'] ?? '') !== 'semanal') { return $out; }
        foreach (array('table','account','district','final_date','substatus') as $required) {
            if (empty($forecast[$required])) { $out['warning']='Pronóstico SAC/Cobranza incompleto.'; return $out; }
        }
        foreach (array('table','account','district','date','type') as $required) {
            if (empty($cancel_source[$required])) { $out['warning']='Cancelaciones no permite conciliar cuentas reales contra Pronóstico.'; return $out; }
        }

        $ftable = preg_replace('/[^A-Za-z0-9_]/', '', (string)$forecast['table']);
        $faccount = self::ident($forecast['account']);
        $fdistrict = self::ident($forecast['district']);
        $fdate = self::ident($forecast['final_date']);
        $fsub = self::ident($forecast['substatus']);
        $fcase = self::district_case($forecast['district']);

        $ctable = preg_replace('/[^A-Za-z0-9_]/', '', (string)$cancel_source['table']);
        $caccount = self::ident($cancel_source['account']);
        $cdistrict = self::ident($cancel_source['district']);
        $cdate = self::ident($cancel_source['date']);
        $ctype = self::ident($cancel_source['type']);
        $cd = 'UPPER(TRIM(COALESCE(c.' . $cdistrict . ",'')))";
        $ccase = "CASE
            WHEN {$cd} IN ('CANCUN','CANCÚN') THEN 'CANCÚN'
            WHEN {$cd} IN ('COATZA MINA','COATZA-MINA','COATZA / MINA','COATZACOALCOS-MINATITLAN','COATZACOALCOS-MINATITLÁN') THEN 'COATZA / MINA'
            WHEN {$cd} IN ('MERIDA','MÉRIDA') THEN 'MÉRIDA'
            WHEN {$cd} IN ('TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ') THEN 'TUXTLA'
            WHEN {$cd}='VILLAHERMOSA' THEN 'VILLAHERMOSA'
            ELSE '' END";

        $real_end = $latest_c && $latest_c >= $ctx['start'] ? $latest_c : date('Y-m-d', strtotime($ctx['start'].' -1 day'));

        /*
         * Pronóstico v3.5:
         * - ORGÁNICO y TRASLADO P se toman del último snapshot SAC/Cobranza para la semana activa.
         * - Una cuenta futura se proyecta solo si aún no aparece como involuntaria real en QLIK.
         * - Una cuenta cuya FECHA FINAL ya venció y no se materializó se muestra como riesgo, pero no se infla en el FCST base.
         * - TRASLADO F NO se infiere del archivo semanal: inicia en 0 y solo toma la captura oficial de Corporativo.
         */
        $actual_subquery = $db->prepare(
            "SELECT DISTINCT TRIM(LEADING '0' FROM TRIM({$caccount})) cuenta_real
             FROM `{$ctable}`
             WHERE DATE({$cdate}) BETWEEN %s AND %s
               AND {$caccount} IS NOT NULL AND TRIM({$caccount})<>''
               AND UPPER(TRIM(COALESCE({$ctype},'')))='CANCELACION INVOLUNTARIA'",
            $ctx['start'], max($ctx['start'], $real_end)
        );

        $sql = $db->prepare(
            "SELECT {$fcase} district_norm,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE({$fsub},''))) IN ('ORGANICO','ORGÁNICO')
                                  AND DATE({$fdate}) BETWEEN %s AND %s
                                  AND a.cuenta_real IS NULL AND DATE({$fdate}) > %s THEN 1 ELSE 0 END) organicas_fcst,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE({$fsub},''))) IN ('ORGANICO','ORGÁNICO')
                                  AND DATE({$fdate}) BETWEEN %s AND %s
                                  AND a.cuenta_real IS NULL AND DATE({$fdate}) <= %s THEN 1 ELSE 0 END) organicas_vencidas,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE({$fsub},'')))='TRASLADO'
                                  AND DATE({$fdate}) BETWEEN %s AND %s THEN 1 ELSE 0 END) traslado_p_programado,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE({$fsub},'')))='TRASLADO'
                                  AND DATE({$fdate}) BETWEEN %s AND %s
                                  AND a.cuenta_real IS NULL AND DATE({$fdate}) > %s THEN 1 ELSE 0 END) traslado_p_fcst,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE({$fsub},'')))='TRASLADO'
                                  AND DATE({$fdate}) BETWEEN %s AND %s
                                  AND a.cuenta_real IS NULL AND DATE({$fdate}) <= %s THEN 1 ELSE 0 END) traslado_p_vencidos
             FROM `{$ftable}` p
             LEFT JOIN ({$actual_subquery}) a ON a.cuenta_real=TRIM(LEADING '0' FROM TRIM(p.{$faccount}))
             WHERE DATE({$fdate}) BETWEEN %s AND %s
             GROUP BY district_norm",
            $ctx['start'],$ctx['end'],$real_end,
            $ctx['start'],$ctx['end'],$real_end,
            $ctx['start'],$ctx['end'],
            $ctx['start'],$ctx['end'],$real_end,
            $ctx['start'],$ctx['end'],$real_end,
            $ctx['start'],$ctx['end']
        );
        $scheduled = array();
        foreach ((array)$db->get_results($sql, ARRAY_A) as $row) {
            $d = self::normalize_district($row['district_norm'] ?? ''); if ($d==='') continue;
            $scheduled[$d] = $row;
        }

        // Identifica qué cancelaciones reales involuntarias corresponden a TRASLADO P del snapshot vigente.
        $actual_transfer = array();
        if ($latest_c && $latest_c >= $ctx['start']) {
            $sql_actual = $db->prepare(
                "SELECT {$ccase} district_norm, COUNT(DISTINCT TRIM(c.{$caccount})) traslado_real
                 FROM `{$ctable}` c
                 INNER JOIN `{$ftable}` p ON TRIM(LEADING '0' FROM TRIM(p.{$faccount}))=TRIM(LEADING '0' FROM TRIM(c.{$caccount}))
                 WHERE DATE(c.{$cdate}) BETWEEN %s AND %s
                   AND UPPER(TRIM(COALESCE(c.{$ctype},'')))='CANCELACION INVOLUNTARIA'
                   AND UPPER(TRIM(COALESCE(p.{$fsub},'')))='TRASLADO'
                   AND DATE(p.{$fdate}) BETWEEN %s AND %s
                 GROUP BY district_norm",
                $ctx['start'],$latest_c,$ctx['start'],$ctx['end']
            );
            foreach ((array)$db->get_results($sql_actual, ARRAY_A) as $row) {
                $d=self::normalize_district($row['district_norm']??''); if($d!=='')$actual_transfer[$d]=max(0,(int)($row['traslado_real']??0));
            }
        }

        foreach ($districts as $d) {
            $real_inv = max(0,(int)($real_involuntary[$d] ?? 0));
            $tr_real = min($real_inv, max(0,(int)($actual_transfer[$d] ?? 0)));
            $org_real = max(0, $real_inv - $tr_real);
            $x = $scheduled[$d] ?? array();
            $org_fcst = max(0,(int)($x['organicas_fcst'] ?? 0));
            $tr_fcst = max(0,(int)($x['traslado_p_fcst'] ?? 0));
            $out['rows'][$d] = array(
                'organicas_reales'=>$org_real,
                'organicas_fcst'=>$org_fcst,
                'organicas'=>$org_real+$org_fcst,
                'organicas_vencidas'=>max(0,(int)($x['organicas_vencidas'] ?? 0)),
                'traslado_p_reales'=>$tr_real,
                'traslado_p_fcst'=>$tr_fcst,
                // Sugerencia editable: el total PROGRAMADO por SAC para la semana activa.
                'traslado_p'=>max(0,(int)($x['traslado_p_programado'] ?? 0)),
                'traslado_p_vencidos'=>max(0,(int)($x['traslado_p_vencidos'] ?? 0)),
                'traslado_p_programado'=>max(0,(int)($x['traslado_p_programado'] ?? 0)),
                // Traslado F solo se conoce cuando Corporativo lo comunica; no se infiere del snapshot semanal.
                'traslado_f'=>0,
            );
        }
        $out['available']=true;
        return $out;
    }

    private static function default_objectives() {
        return array('CANCÚN'=>76,'COATZA / MINA'=>53,'MÉRIDA'=>109,'TUXTLA'=>84,'VILLAHERMOSA'=>64);
    }

    private static function local_values(array $ctx) {
        global $wpdb;
        $table = self::table_name();
        $out = array();
        if ($ctx['mode'] === 'semanal') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT district, installations, voluntary, involuntary, involuntary_total, transfer_p, transfer_f, objective, quantity FROM {$table} WHERE year=%d AND week=%d",
                (int)$ctx['year'], (int)$ctx['week']
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT district, SUM(COALESCE(installations,0)) installations, SUM(COALESCE(voluntary,0)) voluntary, SUM(involuntary) involuntary, SUM(COALESCE(involuntary_total, CASE WHEN involuntary IS NOT NULL THEN involuntary + COALESCE(transfer_p,0) ELSE 0 END)) involuntary_total, SUM(COALESCE(transfer_p,0)) transfer_p, SUM(COALESCE(transfer_f,0)) transfer_f, SUM(COALESCE(objective,0)) objective FROM {$table} WHERE period_start BETWEEN %s AND %s GROUP BY district",
                $ctx['start'], $ctx['end']
            ), ARRAY_A);
        }
        foreach ((array)$rows as $row) {
            $d = self::normalize_district($row['district'] ?? '');
            if ($d === '') continue;
            $out[$d] = array(
                'installations'=>isset($row['installations']) ? (int)$row['installations'] : null,
                'voluntary'=>isset($row['voluntary']) ? (int)$row['voluntary'] : null,
                // `involuntary` conserva el valor legacy de Orgánicas para compatibilidad.
                'involuntary'=>array_key_exists('involuntary',$row) && $row['involuntary'] !== null ? max(0,(int)$row['involuntary']) : null,
                'involuntary_total'=>array_key_exists('involuntary_total',$row) && $row['involuntary_total'] !== null ? max(0,(int)$row['involuntary_total']) : null,
                'transfer_p'=>array_key_exists('transfer_p',$row) && $row['transfer_p'] !== null ? max(0,(int)$row['transfer_p']) : null,
                'transfer_f'=>array_key_exists('transfer_f',$row) && $row['transfer_f'] !== null ? max(0,(int)$row['transfer_f']) : null,
                'objective'=>isset($row['objective']) ? (int)$row['objective'] : null,
            );
        }
        return $out;
    }

    private static function director_installation_fcst($db, array $ctx) {
        $districts = tx_an_allowed_districts();
        $out = array_fill_keys($districts, null);
        if (($ctx['mode'] ?? '') !== 'semanal') { return $out; }
        $table = tx_an_config('director_forecast_table', '');
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) { return $out; }
        try {
            $conn = tx_an_db('director');
            if (!self::legacy_table_exists($conn, $table)) { return $out; }
            $cols = self::legacy_columns($conn, $table);
            foreach (array('anio','semana','distrito','forecast') as $needed) {
                if (!in_array($needed, $cols, true)) { return $out; }
            }
            $select = array('anio','semana','distrito','forecast');
            foreach (array('puesto_responsable','nivel_forecast','updated_at','created_at','id') as $optional) {
                if (in_array($optional, $cols, true)) { $select[] = $optional; }
            }
            $order = array();
            foreach (array('updated_at','created_at','id') as $orderfield) {
                if (in_array($orderfield, $cols, true)) { $order[] = '`'.$orderfield.'` DESC'; }
            }
            $sql = $conn->prepare('SELECT '.implode(',', array_map(array(__CLASS__, 'ident'), $select)).
                ' FROM `'.$table.'` WHERE anio=%d AND semana=%d'.($order ? ' ORDER BY '.implode(',', $order) : ''),
                (int)$ctx['year'], (int)$ctx['week']);
            $hasrole = in_array('puesto_responsable', $cols, true);
            $haslevel = in_array('nivel_forecast', $cols, true);
            foreach ((array)$conn->get_results($sql, ARRAY_A) as $row) {
                $d = self::normalize_district($row['distrito'] ?? '');
                if (!array_key_exists($d, $out) || $out[$d] !== null) { continue; }
                $role = strtoupper(remove_accents((string)($row['puesto_responsable'] ?? '')));
                $level = strtoupper(remove_accents((string)($row['nivel_forecast'] ?? '')));
                if ($hasrole || $haslevel) {
                    if (strpos($role,'DIRECTOR') === false && strpos($level,'DISTRIT') === false) { continue; }
                }
                $out[$d] = max(0, (int)$row['forecast']);
            }
        } catch (Throwable $e) { /* Informativo, nunca altera FCST operativo. */ }
        return $out;
    }

    private static function inactive_dates($db, $start, $end) {
        $out = array();
        if (!self::legacy_table_exists($db, 'dias_inhabiles')) return $out;
        $cols = self::legacy_columns($db, 'dias_inhabiles');
        $date_col = self::first_column($cols, array('fecha','date','dia'));
        if (!$date_col) return $out;
        $date = self::ident($date_col);
        $sql = $db->prepare("SELECT DATE({$date}) d FROM `dias_inhabiles` WHERE DATE({$date}) BETWEEN %s AND %s", $start, $end);
        foreach ((array)$db->get_col($sql) as $d) $out[(string)$d] = true;
        return $out;
    }

    private static function is_business_day($date, array $inactive) {
        $dt = new DateTime($date, wp_timezone());
        return (int)$dt->format('N') !== 7 && empty($inactive[$date]);
    }

    private static function real_range_label($start, $end) {
        if (!$end || $end < $start) return 'Sin información real';
        $tz = wp_timezone();
        $a = new DateTime($start, $tz); $b = new DateTime($end, $tz);
        $months = array(1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre');
        if ($a->format('Y-m') === $b->format('Y-m')) return $a->format('j') . ' al ' . $b->format('j') . ' de ' . $months[(int)$b->format('n')] . ' ' . $b->format('Y');
        return $a->format('j') . ' de ' . $months[(int)$a->format('n')] . ' al ' . $b->format('j') . ' de ' . $months[(int)$b->format('n')] . ' ' . $b->format('Y');
    }

    private static function data(array $ctx) {
        $cache_key = self::cache_key($ctx);
        $cached = self::cache_get($cache_key);
        if (is_array($cached)) { $cached['cache']='hit'; return $cached; }
        $districts = tx_an_allowed_districts();
        $defaults = self::default_objectives();
        $rows = array();
        foreach ($districts as $d) $rows[$d] = array(
            'instalaciones'=>0,'instalaciones_reales'=>0,'instalaciones_fcst'=>0,
            'voluntarias'=>0,'voluntarias_reales'=>0,'voluntarias_fcst'=>0,
            'involuntarias'=>0,'involuntarias_reales'=>0,'involuntarias_fcst'=>0,
            'organicas'=>0,'organicas_reales'=>0,'organicas_fcst'=>0,'organicas_vencidas'=>0,
            'cancelaciones'=>0,'cancelaciones_reales'=>0,'cancelaciones_fcst'=>0,
            'traslado_p'=>0,'traslado_p_reales'=>0,'traslado_p_fcst'=>0,'traslado_p_vencidos'=>0,'traslado_p_programado'=>0,
            'traslado_f'=>0,'objetivo'=>$defaults[$d],'director_fcst'=>null
        );
        $result = array(
            'rows'=>$rows,'warnings'=>array(),'error'=>'','cache'=>'miss','source'=>array(),'cutoff'=>self::cutoff_date(),
            'projected_days'=>0,'real_days'=>0,'forecast_source'=>'historical_fallback',
            'forecast_algorithm'=>'Instalaciones: REAL + FCST con 4 semanas equivalentes por día pendiente. Voluntarias: histórico 3 semanas. Involuntarias es editable como total operativo; Orgánicas = Involuntarias - Traslado P + Traslado F y no es editable. Traslado P/F son editables y Orgánicas se reconcilia en tiempo real.',
            'real_installations_end'=>'','real_cancellations_end'=>'','real_installations_label'=>'','real_cancellations_label'=>'',
            'history'=>array()
        );
        $db = self::legacy_connection();
        if (is_wp_error($db)) { $result['error']=$db->get_error_message(); return $result; }
        $spec = self::source_spec($db); $result['warnings']=$spec['warnings']; $result['source']=$spec;
        $tz=wp_timezone(); $period_start=new DateTime($ctx['start'],$tz); $period_end=new DateTime($ctx['end'],$tz);
        $history_start_obj=clone $period_start; $history_start_obj->modify('-28 days');
        $history_start=$history_start_obj->format('Y-m-d'); $history_end=min($ctx['end'],$result['cutoff']);
        $imap=self::daily_installations($db,$spec['installations'],$history_start,$history_end);
        $cmap=self::daily_cancellations($db,$spec['cancellations'],$history_start,$history_end);
        $inactive=self::inactive_dates($db,$history_start,$ctx['end']);
        $latest_i=''; $latest_c='';
        for($cur=clone $period_start;$cur<=$period_end;$cur->modify('+1 day')){
            $date=$cur->format('Y-m-d'); if($date>$result['cutoff']) continue;
            $has_i=!empty($imap[$date]); $has_c=!empty($cmap[$date]);
            if($has_i) $latest_i=$date; if($has_c) $latest_c=$date;
        }
        $result['real_installations_end']=$latest_i; $result['real_cancellations_end']=$latest_c;
        $result['real_installations_label']=self::real_range_label($ctx['start'],$latest_i);
        $result['real_cancellations_label']=self::real_range_label($ctx['start'],$latest_c);

        $real_inv_by_district=array();
        foreach($districts as $d){
            $real_i=0;$real_v=0;$real_inv=0;$elapsed_business=0;$pending_business=0;$elapsed_calendar=0;$pending_calendar=0;$pending_dates_i=array();$pending_dates_c=array();
            for($cur=clone $period_start;$cur<=$period_end;$cur->modify('+1 day')){
                $date=$cur->format('Y-m-d');
                if($latest_i && $date<=$latest_i){ $real_i+=max(0,(int)($imap[$date][$d]??0)); $elapsed_calendar++; if(self::is_business_day($date,$inactive))$elapsed_business++; }
                elseif(!$latest_i || $date>$latest_i){ $pending_dates_i[]=$date; $pending_calendar++; if(self::is_business_day($date,$inactive))$pending_business++; }
                if($latest_c && $date<=$latest_c){ $x=$cmap[$date][$d]??array(); $real_v+=max(0,(int)($x['voluntarias']??0)); $real_inv+=max(0,(int)($x['involuntarias']??0)); }
                elseif(!$latest_c || $date>$latest_c){ $pending_dates_c[]=$date; }
            }
            // FCST INSTALACIONES v3.4.0: promedio de las últimas 4 semanas para CADA día pendiente.
            // Ejemplo viernes: promedio de los últimos 4 viernes; sábado: promedio de los últimos 4 sábados; domingo: promedio de los últimos 4 domingos.
            // El FCST semanal es la suma de esos promedios diarios. No aplica ritmo, momentum, aceleración ni pisos agresivos.
            $hist_i_weeks=array(0,0,0,0);
            foreach($pending_dates_i as $date){
                for($w=1;$w<=4;$w++){
                    $hd=date('Y-m-d',strtotime($date.' -'.$w.' week'));
                    $hist_i_weeks[$w-1]+=max(0,(int)($imap[$hd][$d]??0));
                }
            }
            $hist_i=array_sum($hist_i_weeks)/4;
            $fcst_i=max(0,(int)round($hist_i));
            $hist_v_weeks=array(0,0,0);$hist_inv_weeks=array(0,0,0);
            foreach($pending_dates_c as $date){ for($w=1;$w<=3;$w++){ $hd=date('Y-m-d',strtotime($date.' -'.$w.' week')); $x=$cmap[$hd][$d]??array(); $hist_v_weeks[$w-1]+=max(0,(int)($x['voluntarias']??0)); $hist_inv_weeks[$w-1]+=max(0,(int)($x['involuntarias']??0)); } }
            $fcst_v=(int)round(array_sum($hist_v_weeks)/3); $fcst_inv=(int)round(array_sum($hist_inv_weeks)/3);
            $result['rows'][$d]['instalaciones_reales']=$real_i; $result['rows'][$d]['instalaciones_fcst']=$fcst_i; $result['rows'][$d]['instalaciones']=$real_i+$fcst_i;
            $result['rows'][$d]['voluntarias_reales']=$real_v; $result['rows'][$d]['voluntarias_fcst']=$fcst_v; $result['rows'][$d]['voluntarias']=$real_v+$fcst_v;
            // Fallback temporal: será sustituido por Pronóstico SAC/Cobranza si la vista está disponible.
            $result['rows'][$d]['involuntarias_reales']=$real_inv; $result['rows'][$d]['involuntarias_fcst']=$fcst_inv; $result['rows'][$d]['involuntarias']=$real_inv+$fcst_inv;
            $result['rows'][$d]['organicas_reales']=$real_inv; $result['rows'][$d]['organicas_fcst']=$fcst_inv; $result['rows'][$d]['organicas']=$real_inv+$fcst_inv;
            $real_inv_by_district[$d]=$real_inv;
        }

        $director_fcst = self::director_installation_fcst($db, $ctx);
        foreach ($districts as $d) { $result['rows'][$d]['director_fcst'] = $director_fcst[$d] ?? null; }

        $forecast=self::forecast_week_profile($db,$spec['forecast'],$spec['cancellations'],$ctx,$latest_c,$real_inv_by_district);
        if(!empty($forecast['warning']))$result['warnings'][]=$forecast['warning'];
        if(!empty($forecast['available'])){
            $result['forecast_source']='pronostico_sac_cobranza';
            foreach($districts as $d){
                $f=$forecast['rows'][$d];
                $result['rows'][$d]['organicas_reales']=$f['organicas_reales'];
                $result['rows'][$d]['organicas_fcst']=$f['organicas_fcst'];
                $result['rows'][$d]['organicas']=$f['organicas'];
                $result['rows'][$d]['organicas_vencidas']=$f['organicas_vencidas'];
                $result['rows'][$d]['traslado_p_reales']=$f['traslado_p_reales'];
                $result['rows'][$d]['traslado_p_fcst']=$f['traslado_p_fcst'];
                $result['rows'][$d]['traslado_p']=$f['traslado_p'];
                $result['rows'][$d]['traslado_p_vencidos']=$f['traslado_p_vencidos'];
                $result['rows'][$d]['traslado_p_programado']=$f['traslado_p_programado'];
                $result['rows'][$d]['traslado_f']=$f['traslado_f'];
                $result['rows'][$d]['involuntarias_reales']=$real_inv_by_district[$d];
                $result['rows'][$d]['involuntarias']=$f['organicas']+$f['traslado_p'];
                $result['rows'][$d]['involuntarias_fcst']=max(0,$result['rows'][$d]['involuntarias']-$result['rows'][$d]['involuntarias_reales']);
            }
        }

        foreach($districts as $d){
            $result['rows'][$d]['cancelaciones_reales']=$result['rows'][$d]['voluntarias_reales']+$result['rows'][$d]['involuntarias_reales'];
            $result['rows'][$d]['cancelaciones_fcst']=$result['rows'][$d]['voluntarias_fcst']+$result['rows'][$d]['involuntarias_fcst'];
            $result['rows'][$d]['cancelaciones']=$result['rows'][$d]['voluntarias']+$result['rows'][$d]['involuntarias'];
        }

        // Overrides locales de proyección (v1.8.22):
        // installations = Instalaciones; voluntary = Voluntarias; involuntary_total = TOTAL EDITABLE de INVOLUNTARIAS;
        // involuntary conserva el antiguo override de ORGÁNICAS solo para migración compatible;
        // transfer_p = Traslado P; transfer_f = Traslado F. ORGÁNICAS ya no se captura.
        $local=self::local_values($ctx);
        foreach($districts as $d){
            if(isset($local[$d])){
                $v=$local[$d];
                if($v['installations']!==null)$result['rows'][$d]['instalaciones']=max(0,$v['installations']);
                if($v['voluntary']!==null)$result['rows'][$d]['voluntarias']=max(0,$v['voluntary']);
                if($v['transfer_p']!==null)$result['rows'][$d]['traslado_p']=max(0,$v['transfer_p']);
                if($v['transfer_f']!==null)$result['rows'][$d]['traslado_f']=max(0,$v['transfer_f']);
                // Nuevo campo rector. Si todavía no existe, preserva el override legacy de Orgánicas
                // convirtiéndolo al vuelo con el P efectivo de la semana.
                if($v['involuntary_total']!==null){
                    $result['rows'][$d]['involuntarias']=max(0,$v['involuntary_total']);
                } elseif($v['involuntary']!==null) {
                    $result['rows'][$d]['involuntarias']=max(0,(int)$v['involuntary']+(int)$result['rows'][$d]['traslado_p']);
                }
                if($v['objective']!==null)$result['rows'][$d]['objetivo']=max(0,$v['objective']);
            }
            // Regla SAC/Cobranza v1.8.22:
            // INVOLUNTARIAS = ORGÁNICAS + TRASLADO P - TRASLADO F
            // => ORGÁNICAS = INVOLUNTARIAS - TRASLADO P + TRASLADO F.
            $result['rows'][$d]['organicas']=max(0,(int)$result['rows'][$d]['involuntarias']-(int)$result['rows'][$d]['traslado_p']+(int)$result['rows'][$d]['traslado_f']);
            $result['rows'][$d]['involuntarias_fcst']=max(0,$result['rows'][$d]['involuntarias']-$result['rows'][$d]['involuntarias_reales']);
            $result['rows'][$d]['cancelaciones']=$result['rows'][$d]['voluntarias']+$result['rows'][$d]['involuntarias'];
            $result['rows'][$d]['cancelaciones_fcst']=max(0,$result['rows'][$d]['cancelaciones']-$result['rows'][$d]['cancelaciones_reales']);
        }
        $result['projected_days']=0;$result['real_days']=0;
        for($cur=clone $period_start;$cur<=$period_end;$cur->modify('+1 day')){ if($cur->format('Y-m-d')<=$result['cutoff'])$result['real_days']++;else$result['projected_days']++; }
        $result['history']=self::weekly_history($db,$spec,$ctx,10);
        self::cache_set($cache_key,$result); return $result;
    }

    public static function public_snapshot(array $args = array()) {
        if (!is_user_logged_in() || !self::can_view()) {
            return new WP_Error('addnetas_forbidden', 'Sin permisos para consultar ADD Netas.');
        }
        $ctx = self::context($args);
        $data = self::data($ctx);
        return array(
            'module'=>'krn_add_netas',
            'version'=>self::VERSION,
            'context'=>$ctx,
            'data'=>$data,
            'read_only_legacy'=>true,
            'local_transfer_table'=>self::table_name(),
        );
    }

    public static function save_ajax() {
        if (!is_user_logged_in() || !self::can_edit()) wp_send_json_error(array('message'=>'Sin permisos para editar ADD Netas.'),403);
        check_ajax_referer('krn_addnetas_save','nonce');
        $year=max(2020,(int)($_POST['year']??0)); $week=max(1,min(53,(int)($_POST['week']??0)));
        $district=self::normalize_district(sanitize_text_field(wp_unslash($_POST['district']??''))); if($district==='')wp_send_json_error(array('message'=>'Distrito inválido.'),400);
        if (!in_array($district, tx_an_allowed_districts(), true)) wp_send_json_error(array('message'=>'No tienes permiso para modificar otro distrito.'),403);
        $field=sanitize_key($_POST['field']??''); $allowed=array('installations','voluntary','involuntary','transfer_p','transfer_f','objective'); if(!in_array($field,$allowed,true))wp_send_json_error(array('message'=>'Campo inválido.'),400);
        $value=max(0,(int)($_POST['value']??0)); list($start,$end)=self::iso_week_bounds($year,$week); $user=wp_get_current_user(); global $wpdb; $table=self::table_name(); $now=current_time('mysql');
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE year=%d AND week=%d AND district=%s",$year,$week,$district),ARRAY_A);
        $posted_involuntary_total = isset($_POST['involuntary_total']) ? max(0,(int)$_POST['involuntary_total']) : null;
        $data=array('year'=>$year,'week'=>$week,'district'=>$district,'quantity'=>(int)($existing['quantity']??0),'installations'=>$existing['installations']??null,'voluntary'=>$existing['voluntary']??null,'involuntary'=>$existing['involuntary']??null,'involuntary_total'=>array_key_exists('involuntary_total',(array)$existing)&&$existing['involuntary_total']!==null?(int)$existing['involuntary_total']:null,'transfer_p'=>array_key_exists('transfer_p',(array)$existing)&&$existing['transfer_p']!==null?(int)$existing['transfer_p']:null,'transfer_f'=>array_key_exists('transfer_f',(array)$existing)&&$existing['transfer_f']!==null?(int)$existing['transfer_f']:null,'objective'=>$existing['objective']??null,'period_start'=>$start,'period_end'=>$end,'user_id'=>get_current_user_id(),'user_name'=>$user->display_name,'created_at'=>$existing['created_at']??$now,'updated_at'=>$now);
        if($posted_involuntary_total!==null)$data['involuntary_total']=$posted_involuntary_total;
        $data[$field]=$value; if($field==='transfer_p')$data['quantity']=$value; if($field==='involuntary')$data['involuntary_total']=$value;
        $ok=$wpdb->replace($table,$data); if($ok===false)wp_send_json_error(array('message'=>'No se pudo guardar: '.$wpdb->last_error),500);
        self::bump_cache_revision(); wp_send_json_success(array('message'=>'Proyección guardada.','district'=>$district,'field'=>$field,'value'=>$value));
    }

    public static function executive_pdf_report() {
        if (!is_user_logged_in() || !self::can_view()) {
            wp_die('Sin permisos para consultar ADD Netas.', 'ADD Netas', array('response'=>403));
        }
        check_admin_referer('krn_addnetas_executive_pdf');
        $ctx = self::context(array(
            'add_mode'=>isset($_GET['add_mode']) ? sanitize_key(wp_unslash($_GET['add_mode'])) : 'semanal',
            'add_year'=>isset($_GET['add_year']) ? (int)$_GET['add_year'] : 0,
            'add_week'=>isset($_GET['add_week']) ? (int)$_GET['add_week'] : 0,
            'add_month'=>isset($_GET['add_month']) ? sanitize_text_field(wp_unslash($_GET['add_month'])) : '',
        ));
        $selected_day = self::dashboard_selected_day($ctx);
        $ctx = self::dashboard_context_to_day($ctx, $selected_day);
        $data = self::data($ctx);
        $view = sanitize_key((string)($_GET['add_view'] ?? 'fcst')) === 'real' ? 'real' : 'fcst';
        $is_real = $view === 'real';
        $rows = $data['rows'];
        if ($is_real) { $rows = self::real_rows($rows); }
        $districts = tx_an_allowed_districts();
        $totals = array('instalaciones'=>0,'cancelaciones'=>0,'voluntarias'=>0,'involuntarias'=>0,'organicas'=>0,'traslado_p'=>0,'traslado_f'=>0,'objetivo'=>0,'add_netas'=>0);
        foreach ($districts as $d) {
            $rows[$d]['involuntarias'] = max(0, (int)($rows[$d]['involuntarias'] ?? 0));
            $rows[$d]['cancelaciones'] = (int)$rows[$d]['voluntarias'] + (int)$rows[$d]['involuntarias'];
            $rows[$d]['add_netas'] = (int)$rows[$d]['instalaciones'] - (int)$rows[$d]['cancelaciones'];
            foreach ($totals as $k=>$v) { $totals[$k] += (int)($rows[$d][$k] ?? 0); }
        }
        $reach = $totals['objetivo'] > 0 ? round(($totals['add_netas'] / $totals['objetivo']) * 100, 1) : 0;
        $projection_rows = array();
        $projection_totals = array();
        if ($is_real && ($ctx['mode'] ?? '') === 'semanal'
            && (int)($ctx['year'] ?? 0) === (int)($ctx['active_year'] ?? -1)
            && (int)($ctx['week'] ?? 0) === (int)($ctx['active_week'] ?? -1)) {
            $projection_report = self::executive_report_summary($ctx, 'real');
            $projection_rows = $projection_report['projection']['rows'] ?? array();
            $projection_totals = $projection_report['projection']['totals'] ?? array();
        }
        $generated = wp_date('d/m/Y H:i', null, wp_timezone());
        $cutoff = !empty($data['cutoff']) ? wp_date('d/m/Y', strtotime($data['cutoff']), wp_timezone()) : 'Sin corte';
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?><!doctype html>
<html lang="es"><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $is_real?'Real Add Netas':'FCST Add Netas'; ?> · Reporte ejecutivo · <?php echo esc_html($ctx['label']); ?></title>
<style>
@page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:#172033;background:#f5f3f8}.report{max-width:1200px;margin:20px auto;padding:26px;background:#fff;border-radius:24px}.top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;border-bottom:2px solid #eee6f8;padding-bottom:18px}.eyebrow{font-size:11px;font-weight:700;letter-spacing:.16em;color:#7c3aed}.top h1{margin:7px 0 4px;font-size:30px;color:#2d174d}.top p{margin:0;color:#6b7280}.meta{text-align:right;font-size:12px;color:#6b7280}.meta strong{display:block;color:#2d174d;font-size:14px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.kpi{padding:15px;border:1px solid #e7e1ef;border-radius:16px}.kpi span{display:block;font-size:11px;color:#6b7280}.kpi strong{display:block;margin-top:5px;font-size:24px;color:#2d174d}.reach.good{background:#eaf8ef}.reach.mid{background:#fff7dc}.reach.low{background:#fff0f2}.section-title{margin:20px 0 8px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#6b5a7d}table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:9px 7px;border:1px solid #e6e1eb;text-align:right}th:first-child,td:first-child{text-align:left}thead th{background:#f5f0fb;color:#39245a}.inv-group{background:#eef4ff!important}.inv-total{background:#dfeaff!important;font-weight:800;color:#233b69}.fcstsub{display:block;margin-top:2px;color:#6d28d9;font-size:8px;font-weight:800}.net td{font-weight:700;background:#f8eefd}.objective td{background:#f7f4fb}.good{color:#147a3e}.mid{color:#9a6900}.low{color:#b4233c}.notes{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}.note{padding:11px;border-radius:12px;background:#f3f8fb;color:#5f6b7a;font-size:10px;line-height:1.45}.footer{display:flex;justify-content:space-between;margin-top:16px;padding-top:10px;border-top:1px solid #ece7f1;color:#7b8794;font-size:9px}.toolbar{max-width:1200px;margin:18px auto 0;display:flex;justify-content:flex-end;gap:8px}.toolbar button{border:0;border-radius:12px;padding:11px 16px;color:#fff;background:linear-gradient(135deg,#7224d7,#dd009d);cursor:pointer}.toolbar button.secondary{background:#5f6673}@media print{body{background:#fff}.toolbar{display:none}.report{margin:0;max-width:none;padding:0;border-radius:0;box-shadow:none}.kpi,table,.note{break-inside:avoid}}

.addnetas-anchor{position:absolute;display:block;transform:translateY(-14px)}
.addnetas-mobile-jump{display:none}
.addnetas-matrix-mobile-nav{display:none}
.addnetas-swipe-hint{display:none;text-align:center;color:#748198;font-size:11px;margin-top:-8px;padding:2px 0 0}
.addnetas-table-wrap{overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#cbb6ee #f7f5fb}
.addnetas-table-wrap thead th{position:sticky;top:0;z-index:4;background:#fff}
.addnetas-table-wrap thead th:first-child{left:0;z-index:7}
.addnetas-table-wrap tbody th{position:sticky;left:0;z-index:3;background:#fff;box-shadow:1px 0 0 #e6eaf0}
.addnetas-table-wrap tbody tr.group-parent th{background:#fbf8ff}.addnetas-table-wrap tbody tr.group-parent-secondary th{background:#f8f4ff}.addnetas-table-wrap tbody tr.row-organic th{background:#fcfbff}.addnetas-table-wrap tbody tr.row-transfer th{background:#eaf8ff}.addnetas-table-wrap tbody tr.row-transfer-f th{background:#f3fbff}.addnetas-table-wrap tbody tr.row-transfer-risk th{background:#f8fcff}.addnetas-table-wrap tbody tr.row-net th{background:#f8eefd}.addnetas-table-wrap tbody tr.row-objective th{background:#f5f2ff}
.an-chart-scroll{overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#cbb6ee #f7f5fb}
@media(max-width:760px){
  .addnetas-shell{gap:12px;min-width:0;width:100%}
  .addnetas-hero{padding:17px 15px;border-radius:18px;gap:10px}
  .addnetas-hero h1{font-size:27px;line-height:1.08;margin:6px 0}
  .addnetas-hero p{font-size:12px;line-height:1.45;margin:0}
  .addnetas-period{display:flex;gap:7px;align-items:baseline;flex-wrap:wrap}
  .addnetas-period strong{font-size:13px}.addnetas-period small{font-size:11px}
  .addnetas-nav{overflow-x:auto;flex-wrap:nowrap;white-space:nowrap;padding:6px;border-radius:14px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .addnetas-nav::-webkit-scrollbar{display:none}
  .addnetas-nav a{flex:0 0 auto;padding:9px 11px;font-size:12px}
  .addnetas-nav .addnetas-pdf{margin-left:0}
  .addnetas-mobile-jump{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;position:sticky;top:6px;z-index:30;padding:6px;border:1px solid #e8ddfb;border-radius:14px;background:rgba(255,255,255,.96);backdrop-filter:blur(10px)}
  .addnetas-mobile-jump a{text-align:center;text-decoration:none;color:#5b4778;background:#f7f3fb;padding:9px 7px;border-radius:10px;font-size:12px;font-weight:700}
  .addnetas-mobile-jump a:nth-child(1),.addnetas-mobile-jump a:nth-child(2){color:#fff;background:linear-gradient(135deg,#7224d7,#dd009d)}
  .addnetas-kpis{grid-template-columns:1fr 1fr;gap:8px}
  .addnetas-kpis article{padding:12px;border-radius:14px;min-width:0}
  .addnetas-kpis span,.addnetas-kpis small{font-size:10px;line-height:1.25}
  .addnetas-kpis strong{font-size:22px;margin:4px 0}
  .addnetas-matrix-mobile-nav{display:grid;grid-template-columns:42px minmax(0,1fr) 42px;grid-template-areas:"prev select next" "pos pos pos";gap:6px 8px;align-items:end;margin-bottom:8px;padding:9px;border:1px solid #e5daf7;border-radius:14px;background:#fff}
  .addnetas-matrix-nav-btn{width:42px;height:42px;border:1px solid #d9c6f5;border-radius:12px;background:#fff;color:#6d28d9;font-size:25px;line-height:1;touch-action:manipulation}
  .addnetas-matrix-nav-btn[data-matrix-prev]{grid-area:prev}.addnetas-matrix-nav-btn[data-matrix-next]{grid-area:next}
  .addnetas-matrix-select-wrap{grid-area:select;display:grid;gap:3px;min-width:0}.addnetas-matrix-select-wrap span{font-size:9px;font-weight:700;letter-spacing:.08em;color:#7c5fa3;text-transform:uppercase}.addnetas-matrix-select-wrap select{width:100%;min-width:0;height:42px;border:1px solid #d9c6f5;border-radius:11px;background:#fff;color:#34224f;padding:0 10px;font-size:13px;font-weight:700}
  .addnetas-matrix-mobile-nav>[data-matrix-position]{grid-area:pos;text-align:center;color:#7b879a;font-size:10px}
  .addnetas-table-wrap{border-radius:14px;max-width:100%;overflow-x:hidden!important;overflow-y:visible!important;scroll-snap-type:none}
  .addnetas-table-wrap table{width:100%;min-width:0!important;table-layout:fixed;font-size:11px}
  .addnetas-table-wrap th,.addnetas-table-wrap td{padding:10px 8px;min-width:0!important;max-width:none!important;scroll-snap-align:none;overflow-wrap:anywhere}
  .addnetas-table-wrap th:first-child,.addnetas-table-wrap td:first-child{width:46%;min-width:0!important;max-width:none!important}
  .addnetas-table-wrap tr>*:not(:first-child){width:54%}
  .addnetas-table-wrap thead th,.addnetas-table-wrap tbody th{position:static!important;left:auto!important;top:auto!important;box-shadow:none!important}
  .addnetas-table-wrap .addnetas-mobile-col-hidden{display:none!important}
  .addnetas-table-wrap tbody th{font-size:11px;line-height:1.25}
  .group-child th{padding-left:18px!important}.group-grandchild th{padding-left:28px!important}.row-transfer-risk th{padding-left:34px!important}
  .addnetas-input{width:66px;height:36px;font-size:14px}
  .fcst-detail{font-size:11px;color:#556274;font-weight:600;white-space:normal;line-height:1.35}.fcst-director{font-size:11px;color:#0d78c2;font-weight:800;line-height:1.35}.fcst-hint,.fcst-risk{font-size:10px;color:#556274;font-weight:600;line-height:1.35;white-space:normal}.fcst-risk{color:#9a6400}
  .addnetas-swipe-hint{display:block}
  .addnetas-visuals{padding:12px 10px;border-radius:16px;gap:12px}
  .addnetas-visuals-head h2{font-size:19px}.addnetas-visuals-head p{font-size:11px;line-height:1.4}
  .addnetas-chart-mobile-nav{display:grid;grid-template-columns:42px minmax(0,1fr) 42px;grid-template-areas:"prev select next" "pos pos pos";gap:6px 8px;align-items:end;position:sticky;top:58px;z-index:22;padding:9px;border:1px solid #e5daf7;border-radius:14px;background:rgba(255,255,255,.97);backdrop-filter:blur(9px)}
  .addnetas-chart-nav-btn{width:42px;height:42px;border:1px solid #d9c6f5;border-radius:12px;background:#fff;color:#6d28d9;font-size:25px;line-height:1;box-shadow:0 1px 2px rgba(16,24,40,.04);touch-action:manipulation}
  .addnetas-chart-nav-btn[data-chart-prev]{grid-area:prev}.addnetas-chart-nav-btn[data-chart-next]{grid-area:next}
  .addnetas-chart-select-wrap{grid-area:select;display:grid;gap:3px;min-width:0}.addnetas-chart-select-wrap span{font-size:9px;font-weight:700;letter-spacing:.08em;color:#7c5fa3;text-transform:uppercase}.addnetas-chart-select-wrap select{width:100%;min-width:0;height:42px;border:1px solid #d9c6f5;border-radius:11px;background:#fff;color:#34224f;padding:0 10px;font-size:13px;font-weight:700}
  .addnetas-chart-mobile-nav>[data-chart-position]{grid-area:pos;text-align:center;color:#7b879a;font-size:10px}
  .addnetas-chart-stack{display:block}
  .addnetas-chart-card-wide{display:none;padding:12px 10px;border-radius:14px;box-shadow:none;min-width:0;overflow:hidden}
  .addnetas-chart-card-wide.is-mobile-active{display:block}
  .addnetas-chart-title{gap:7px}.addnetas-chart-title h3{font-size:15px}.addnetas-chart-title small{font-size:9px}
  .addnetas-chart{min-height:260px;margin-top:9px;min-width:0}
  .an-legend{gap:8px 12px;font-size:10px;margin-bottom:7px}
  .an-chart-scroll{display:block;width:100%;max-width:100%;overflow:hidden!important;touch-action:manipulation}
  .an-svg{display:block;width:100%!important;min-width:0!important;max-width:100%!important;height:auto}
  .an-mobile-scroll-controls{display:grid;grid-template-columns:40px minmax(0,1fr) 40px;gap:7px;align-items:center;margin:4px 0 8px;width:100%}
  .an-mobile-scroll-controls span{text-align:center;color:#748198;font-size:10px;line-height:1.25;white-space:normal}
  .an-mobile-scroll-controls button{width:40px;height:36px;border:1px solid #d9c6f5;border-radius:10px;background:#f8f5fd;color:#6d28d9;font-size:20px;line-height:1;touch-action:manipulation}
  .an-mobile-scroll-controls button:disabled{opacity:.3;color:#9ca3af}
  .an-axis-label,.an-week-label{font-size:9px}.an-region-value{font-size:9px}.an-week-fcst{font-size:8px}
  .save-projection-btn{min-height:44px;font-size:12px}.save-projection-cell small{font-size:8px}
  .addnetas-note,.addnetas-alert{font-size:11px;line-height:1.4;padding:10px 12px;border-radius:12px}
  .an-tooltip{max-width:min(250px,calc(100vw - 28px));font-size:11px;padding:9px 10px}
}
@media(max-width:420px){
  .addnetas-kpis{grid-template-columns:1fr 1fr}
  .addnetas-hero h1{font-size:24px}
  .addnetas-table-wrap table{min-width:0!important}
  .addnetas-table-wrap th,.addnetas-table-wrap td{min-width:0!important;padding:9px 7px}
  .addnetas-table-wrap th:first-child,.addnetas-table-wrap td:first-child{min-width:0!important;max-width:none!important}
  .an-svg{width:100%!important;min-width:0!important;max-width:100%!important}
  .addnetas-mobile-jump a{font-size:11px;padding:8px 5px}
}

</style></head><body>
<div class="toolbar"><button class="secondary" type="button" onclick="window.close()">Cerrar</button><button type="button" onclick="window.print()">Guardar / imprimir PDF</button></div>
<main class="report"><div class="top"><div><div class="eyebrow">TALIA · <?php echo esc_html(tx_an_scope_label()); ?></div><h1><?php echo $is_real?'Real Add Netas':'FCST Add Netas'; ?> · Reporte ejecutivo</h1><p><?php echo esc_html($ctx['label']); ?> · <?php echo esc_html($ctx['start'].' — '.$ctx['end']); ?></p></div><div class="meta"><strong>Corte de información: <?php echo esc_html($cutoff); ?></strong>Generado: <?php echo esc_html($generated); ?><br><?php echo esc_html($is_real?($data['real_days'].' días con corte real'):($data['projected_days'].' días proyectados')); ?></div></div>
<section class="kpis"><div class="kpi"><span><?php echo $is_real?'Instalaciones reales':'Instalaciones esperadas'; ?></span><strong><?php echo number_format_i18n($totals['instalaciones']); ?></strong></div><div class="kpi"><span><?php echo $is_real?'Cancelaciones reales':'Cancelaciones esperadas'; ?></span><strong><?php echo number_format_i18n($totals['cancelaciones']); ?></strong></div><div class="kpi"><span><?php echo $is_real?'ADD Netas real':'ADD Netas esperado'; ?></span><strong><?php echo number_format_i18n($totals['add_netas']); ?></strong></div><div class="kpi reach <?php echo $reach>=100?'good':($reach>=80?'mid':'low'); ?>"><span>Alcance <?php echo tx_an_scope_label()==="REGIÓN SUR"?"regional":"distrital"; ?></span><strong><?php echo esc_html(number_format_i18n($reach,1).'%'); ?></strong></div></section>
<h2 class="section-title">Resultado por distrito</h2><table><thead><tr><th>Distrito</th><th>Instalaciones</th><th>Voluntarias</th><th class="inv-group inv-total">Involuntarias</th><th class="inv-group">Orgánicas</th><th class="inv-group">Traslado P</th><th class="inv-group">Traslado F</th><th>Cancelaciones</th><th>ADD Netas</th><th>Objetivo</th><th>Alcance</th></tr></thead><tbody>
<?php foreach($districts as $d): $pct=$rows[$d]['objetivo']>0?round(($rows[$d]['add_netas']/$rows[$d]['objetivo'])*100,1):0; ?><tr><td><strong><?php echo esc_html($d); ?></strong></td><td><?php echo number_format_i18n($rows[$d]['instalaciones']); ?></td><td><?php echo number_format_i18n($rows[$d]['voluntarias']); ?></td><td class="inv-group inv-total"><strong><?php echo number_format_i18n($rows[$d]['involuntarias']); ?></strong></td><td class="inv-group"><strong><?php echo number_format_i18n($rows[$d]['organicas']); ?></strong></td><td class="inv-group"><strong><?php echo number_format_i18n($rows[$d]['traslado_p']); ?></strong></td><td class="inv-group"><?php echo number_format_i18n($rows[$d]['traslado_f']); ?></td><td><?php echo number_format_i18n($rows[$d]['cancelaciones']); ?></td><td><strong><?php echo number_format_i18n($rows[$d]['add_netas']); ?></strong></td><td><?php echo number_format_i18n($rows[$d]['objetivo']); ?></td><td class="<?php echo $pct>=100?'good':($pct>=80?'mid':'low'); ?>"><strong><?php echo number_format_i18n($pct,1); ?>%</strong></td></tr><?php endforeach; ?>
<tr class="net"><td><?php echo esc_html(tx_an_total_label()); ?></td><td><?php echo number_format_i18n($totals['instalaciones']); ?></td><td><?php echo number_format_i18n($totals['voluntarias']); ?></td><td class="inv-group inv-total"><strong><?php echo number_format_i18n($totals['involuntarias']); ?></strong></td><td class="inv-group"><strong><?php echo number_format_i18n($totals['organicas']); ?></strong></td><td class="inv-group"><strong><?php echo number_format_i18n($totals['traslado_p']); ?></strong></td><td class="inv-group"><?php echo number_format_i18n($totals['traslado_f']); ?></td><td><?php echo number_format_i18n($totals['cancelaciones']); ?></td><td><?php echo number_format_i18n($totals['add_netas']); ?></td><td><?php echo number_format_i18n($totals['objetivo']); ?></td><td class="<?php echo $reach>=100?'good':($reach>=80?'mid':'low'); ?>"><?php echo number_format_i18n($reach,1); ?>%</td></tr></tbody></table>

<div class="footer"><span>ADD Netas <?php echo esc_html(self::VERSION); ?> · Forecast <?php echo esc_html(self::FORECAST_ALGORITHM_VERSION); ?></span><span>Reporte para uso ejecutivo · Fuente de lectura: Xpedient / captura local ADD Netas</span></div></main>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print();},250);});</script></body></html><?php
        exit;
    }

    private static function period_urls(array $ctx) {
        $base = remove_query_arg(array('add_mode','add_year','add_week','add_month','add_day'));
        $urls = array(
            'weekly'=>add_query_arg(array('add_mode'=>'semanal'), $base),
            'monthly'=>add_query_arg(array('add_mode'=>'mensual'), $base),
        );
        if ($ctx['mode'] === 'semanal') {
            $urls['previous'] = add_query_arg(array('add_mode'=>'semanal','add_year'=>$ctx['previous_year'],'add_week'=>$ctx['previous_week']), $base);
            $urls['active'] = add_query_arg(array('add_mode'=>'semanal','add_year'=>$ctx['active_year'],'add_week'=>$ctx['active_week']), $base);

            // Navegación secuencial por semana. El siguiente periodo sólo se habilita
            // cuando ya existe al menos un día real disponible según el corte.
            $tz = wp_timezone();
            $next = new DateTime((string)$ctx['start'], $tz);
            $next->modify('+7 days');
            $cutoff = new DateTime(self::cutoff_date(), $tz);
            $urls['has_next'] = $next <= $cutoff;
            $urls['next'] = $urls['has_next']
                ? add_query_arg(array('add_mode'=>'semanal','add_year'=>(int)$next->format('o'),'add_week'=>(int)$next->format('W')), $base)
                : '';
        } else {
            $urls['previous'] = add_query_arg(array('add_mode'=>'mensual','add_month'=>$ctx['previous_month']), $base);
            $urls['active'] = add_query_arg(array('add_mode'=>'mensual','add_month'=>$ctx['active_month']), $base);
        }
        return $urls;
    }

    private static function dashboard_context() {
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $args = array();
        $requested_mode = sanitize_key(wp_unslash((string)($_GET['add_mode'] ?? 'semanal')));
        if ($requested_mode !== 'mensual' && (int)$now->format('N') === 1) {
            $active_year = (int)$now->format('o');
            $active_week = (int)$now->format('W');
            $requested_year = isset($_GET['add_year']) ? (int)$_GET['add_year'] : $active_year;
            $requested_week = isset($_GET['add_week']) ? (int)$_GET['add_week'] : $active_week;
            if ($requested_year === $active_year && $requested_week === $active_week) {
                $prev = clone $now; $prev->modify('-7 days');
                $args = array('add_mode'=>'semanal','add_year'=>(int)$prev->format('o'),'add_week'=>(int)$prev->format('W'));
            }
        }
        return self::context($args);
    }

    private static function dashboard_previous_context(array $ctx) {
        if (($ctx['mode'] ?? '') === 'mensual') {
            return self::context(array('add_mode'=>'mensual','add_month'=>$ctx['previous_month']));
        }
        return self::context(array('add_mode'=>'semanal','add_year'=>$ctx['previous_year'],'add_week'=>$ctx['previous_week']));
    }

    private static function dashboard_sales_source($db) {
        if (!self::legacy_table_exists($db, 'ventas')) { return array(); }
        $cols = self::legacy_columns($db, 'ventas');
        return array(
            'table'=>'ventas',
            'district'=>self::first_column($cols, array('distrito','district','plaza')),
            'date'=>self::first_column($cols, array('fecha_cierre','fecha','fecha_venta','created_at')),
        );
    }

    private static function dashboard_daily_sales($db, array $source, $start, $end) {
        $out = array();
        if (empty($source['table']) || empty($source['district']) || empty($source['date'])) { return $out; }
        $date = self::ident($source['date']);
        $district_case = self::district_case($source['district']);
        $sql = $db->prepare(
            "SELECT DATE({$date}) data_date, {$district_case} district_norm, COUNT(*) ventas
             FROM `ventas`
             WHERE DATE({$date}) BETWEEN %s AND %s
             GROUP BY data_date, district_norm",
            $start, $end
        );
        foreach ((array)$db->get_results($sql, ARRAY_A) as $row) {
            $day = (string)($row['data_date'] ?? '');
            $district = self::normalize_district($row['district_norm'] ?? '');
            if ($day === '' || $district === '') { continue; }
            $out[$day][$district] = max(0, (int)($row['ventas'] ?? 0));
        }
        return $out;
    }

    private static function dashboard_objectives(array $ctx) {
        $districts = tx_an_allowed_districts();
        $defaults = self::default_objectives();
        $local = self::local_values($ctx);
        $out = array();
        if (($ctx['mode'] ?? '') === 'semanal') {
            foreach ($districts as $d) {
                $out[$d] = isset($local[$d]['objective']) && $local[$d]['objective'] !== null
                    ? max(0,(int)$local[$d]['objective'])
                    : (int)$defaults[$d];
            }
            return $out;
        }
        $mondays = 0;
        $cursor = new DateTime($ctx['start'], wp_timezone());
        $end = new DateTime($ctx['end'], wp_timezone());
        while ($cursor <= $end) { if ((int)$cursor->format('N') === 1) { $mondays++; } $cursor->modify('+1 day'); }
        $mondays = max(1, $mondays);
        foreach ($districts as $d) {
            $stored = isset($local[$d]['objective']) && $local[$d]['objective'] !== null ? max(0,(int)$local[$d]['objective']) : 0;
            $out[$d] = $stored > 0 ? $stored : ((int)$defaults[$d] * $mondays);
        }
        return $out;
    }

    private static function dashboard_period_metrics(array $ctx) {
        $districts = tx_an_allowed_districts();
        $rows = array();
        foreach ($districts as $d) $rows[$d] = array('sales'=>0,'installations'=>0,'cancellations'=>0,'voluntary'=>0,'involuntary'=>0,'gain'=>0,'objective'=>0);
        $result = array('rows'=>$rows,'totals'=>array('sales'=>0,'installations'=>0,'cancellations'=>0,'voluntary'=>0,'involuntary'=>0,'gain'=>0,'objective'=>0),'warnings'=>array(),'cutoff'=>self::cutoff_date());
        $db = self::legacy_connection();
        if (is_wp_error($db)) { $result['warnings'][]=$db->get_error_message(); return $result; }
        $spec = self::source_spec($db);
        $sales_source = self::dashboard_sales_source($db);
        $end = min($ctx['end'], self::cutoff_date());
        if ($end < $ctx['start']) { return $result; }
        $imap = self::daily_installations($db, $spec['installations'] ?? array(), $ctx['start'], $end);
        $cmap = self::daily_cancellations($db, $spec['cancellations'] ?? array(), $ctx['start'], $end);
        $smap = self::dashboard_daily_sales($db, $sales_source, $ctx['start'], $end);
        foreach ($imap as $day=>$district_map) foreach ($districts as $d) $rows[$d]['installations'] += max(0,(int)($district_map[$d] ?? 0));
        foreach ($cmap as $day=>$district_map) foreach ($districts as $d) {
            $x = $district_map[$d] ?? array();
            $rows[$d]['voluntary'] += max(0,(int)($x['voluntarias'] ?? 0));
            $rows[$d]['involuntary'] += max(0,(int)($x['involuntarias'] ?? 0));
        }
        foreach ($smap as $day=>$district_map) foreach ($districts as $d) $rows[$d]['sales'] += max(0,(int)($district_map[$d] ?? 0));
        $objectives = self::dashboard_objectives($ctx);
        foreach ($districts as $d) {
            $rows[$d]['cancellations'] = $rows[$d]['voluntary'] + $rows[$d]['involuntary'];
            $rows[$d]['gain'] = self::dashboard_gain($rows[$d]['installations'], $rows[$d]['cancellations']);
            $rows[$d]['objective'] = max(0,(int)($objectives[$d] ?? 0));
            foreach ($result['totals'] as $k=>$v) $result['totals'][$k] += (int)($rows[$d][$k] ?? 0);
        }
        $result['rows'] = $rows;
        if (empty($sales_source['table']) || empty($sales_source['district']) || empty($sales_source['date'])) {
            $result['warnings'][] = 'La tabla ventas no está disponible con fecha_cierre y distrito; el KPI Ventas se mostrará en cero hasta completar ese contrato.';
        }
        return $result;
    }

    private static function dashboard_history(array $ctx, $count = 10) {
        $districts = tx_an_allowed_districts();
        $db = self::legacy_connection();
        if (is_wp_error($db)) { return array(); }
        $spec = self::source_spec($db); $sales_source = self::dashboard_sales_source($db);
        $periods = array(); $tz = wp_timezone();
        $count = ($ctx['mode'] ?? '') === 'mensual' ? max(3,min(12,(int)$count)) : max(4,min(16,(int)$count));
        if (($ctx['mode'] ?? '') === 'mensual') {
            $anchor = new DateTime($ctx['start'], $tz);
            for ($i=$count-1;$i>=0;$i--) {
                $start = clone $anchor; if($i)$start->modify('-'.$i.' months');
                $end = clone $start; $end->modify('last day of this month');
                $periods[] = array('label'=>ucfirst(wp_date('M Y',$start->getTimestamp(),$tz)),'start'=>$start->format('Y-m-01'),'end'=>$end->format('Y-m-d'),'selected'=>$start->format('Y-m')===($ctx['month']??''));
            }
        } else {
            $anchor = new DateTime($ctx['start'], $tz);
            for ($i=$count-1;$i>=0;$i--) {
                $start = clone $anchor; if($i)$start->modify('-'.$i.' weeks');
                $end = clone $start; $end->modify('+6 days');
                $periods[] = array('label'=>'S'.(int)$start->format('W'),'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'selected'=>$start->format('Y-m-d')===$ctx['start']);
            }
        }
        if(!$periods)return array();
        $start_all=$periods[0]['start']; $end_all=min(end($periods)['end'], (string)($ctx['end'] ?? end($periods)['end']), self::cutoff_date());
        if($end_all<$start_all)return array();
        $imap=self::daily_installations($db,$spec['installations']??array(),$start_all,$end_all);
        $cmap=self::daily_cancellations($db,$spec['cancellations']??array(),$start_all,$end_all);
        $smap=self::dashboard_daily_sales($db,$sales_source,$start_all,$end_all);
        foreach($periods as $idx=>$period){
            $periods[$idx]['rows']=array();
            foreach($districts as $d)$periods[$idx]['rows'][$d]=array('sales'=>0,'installations'=>0,'cancellations'=>0,'gain'=>0);
            $periods[$idx]['region']=array('sales'=>0,'installations'=>0,'cancellations'=>0,'gain'=>0);
        }
        $assign=function($day) use($periods){ foreach($periods as $idx=>$p){ if($day>=$p['start']&&$day<=$p['end'])return $idx; } return null; };
        foreach($imap as $day=>$map){$idx=$assign($day);if($idx===null)continue;foreach($districts as $d)$periods[$idx]['rows'][$d]['installations']+=max(0,(int)($map[$d]??0));}
        foreach($cmap as $day=>$map){$idx=$assign($day);if($idx===null)continue;foreach($districts as $d){$x=$map[$d]??array();$periods[$idx]['rows'][$d]['cancellations']+=max(0,(int)($x['voluntarias']??0))+max(0,(int)($x['involuntarias']??0));}}
        foreach($smap as $day=>$map){$idx=$assign($day);if($idx===null)continue;foreach($districts as $d)$periods[$idx]['rows'][$d]['sales']+=max(0,(int)($map[$d]??0));}
        foreach($periods as $idx=>$p){foreach($districts as $d){$periods[$idx]['rows'][$d]['gain']=self::dashboard_gain($periods[$idx]['rows'][$d]['installations'],$periods[$idx]['rows'][$d]['cancellations']);foreach(array('sales','installations','cancellations','gain') as $m)$periods[$idx]['region'][$m]+=(int)$periods[$idx]['rows'][$d][$m];}}
        return $periods;
    }


    /**
     * Histórico semanal del año (S01 → semana del periodo seleccionado).
     * Se usa exclusivamente en los minigráficos del Dashboard Add Netas.
     * Consolida todas las fuentes en una sola ventana para evitar consultas por semana.
     */
    private static function dashboard_weekly_history(array $ctx) {
        $districts = tx_an_allowed_districts();
        $db = self::legacy_connection();
        if (is_wp_error($db)) { return array(); }

        $tz = wp_timezone();
        $cutoff = self::cutoff_date();
        $selected_end = min((string)$ctx['end'], $cutoff);
        if ($selected_end === '' || $selected_end < '2020-01-01') { return array(); }

        $selected_dt = new DateTime($selected_end, $tz);
        $year = ($ctx['mode'] ?? '') === 'semanal' ? (int)$ctx['year'] : (int)$ctx['year'];
        $week_limit = ($ctx['mode'] ?? '') === 'semanal'
            ? max(1, min(53, (int)$ctx['week']))
            : max(1, min(53, (int)$selected_dt->format('W')));

        // Si el cierre mensual cae en una semana ISO del año contiguo, cerramos en la última semana ISO del año solicitado.
        if (($ctx['mode'] ?? '') === 'mensual' && (int)$selected_dt->format('o') !== $year) {
            $last = new DateTime($year . '-12-28', $tz); // siempre pertenece a la última semana ISO del año.
            $week_limit = (int)$last->format('W');
        }

        list($start_all,) = self::iso_week_bounds($year, 1);
        list(, $end_week) = self::iso_week_bounds($year, $week_limit);
        $end_all = min($end_week, $selected_end, $cutoff);
        if ($end_all < $start_all) { return array(); }

        $spec = self::source_spec($db);
        $sales_source = self::dashboard_sales_source($db);
        $imap = self::daily_installations($db, $spec['installations'] ?? array(), $start_all, $end_all);
        $cmap = self::daily_cancellations($db, $spec['cancellations'] ?? array(), $start_all, $end_all);
        $smap = self::dashboard_daily_sales($db, $sales_source, $start_all, $end_all);

        // Objetivos semanales en una sola consulta. Si una semana no tiene captura local, aplica el objetivo canónico.
        $objective_rows = array();
        global $wpdb;
        $table = self::table_name();
        $stored = $wpdb->get_results($wpdb->prepare(
            "SELECT week, district, objective FROM {$table} WHERE year=%d AND week BETWEEN 1 AND %d",
            $year, $week_limit
        ), ARRAY_A);
        foreach ((array)$stored as $row) {
            $w = max(1, min(53, (int)($row['week'] ?? 0)));
            $d = self::normalize_district($row['district'] ?? '');
            if ($d === '') { continue; }
            $objective_rows[$w][$d] = array_key_exists('objective', $row) && $row['objective'] !== null
                ? max(0, (int)$row['objective'])
                : null;
        }
        $defaults = self::default_objectives();

        $periods = array();
        for ($w = 1; $w <= $week_limit; $w++) {
            list($start, $end) = self::iso_week_bounds($year, $w);
            $periods[$w] = array(
                'label' => 'S' . str_pad((string)$w, 2, '0', STR_PAD_LEFT),
                'start' => $start,
                'end' => $end,
                'selected' => (($ctx['mode'] ?? '') === 'semanal' && $w === (int)$ctx['week']),
                'rows' => array(),
                'region' => array('sales'=>0,'installations'=>0,'cancellations'=>0,'gain'=>0,'objective'=>0,'reach'=>0),
            );
            foreach ($districts as $d) {
                $objective = array_key_exists($d, $objective_rows[$w] ?? array()) && $objective_rows[$w][$d] !== null
                    ? (int)$objective_rows[$w][$d]
                    : (int)$defaults[$d];
                $periods[$w]['rows'][$d] = array(
                    'sales'=>0,'installations'=>0,'cancellations'=>0,'gain'=>0,'objective'=>$objective,'reach'=>0,
                );
                $periods[$w]['region']['objective'] += $objective;
            }
        }

        $week_for_day = function($day) use ($year, $week_limit, $tz) {
            $dt = new DateTime((string)$day, $tz);
            if ((int)$dt->format('o') !== $year) { return 0; }
            $week = (int)$dt->format('W');
            return ($week >= 1 && $week <= $week_limit) ? $week : 0;
        };

        foreach ($imap as $day => $map) {
            $w = $week_for_day($day); if (!$w) { continue; }
            foreach ($districts as $d) { $periods[$w]['rows'][$d]['installations'] += max(0, (int)($map[$d] ?? 0)); }
        }
        foreach ($cmap as $day => $map) {
            $w = $week_for_day($day); if (!$w) { continue; }
            foreach ($districts as $d) {
                $x = $map[$d] ?? array();
                $periods[$w]['rows'][$d]['cancellations'] += max(0, (int)($x['voluntarias'] ?? 0)) + max(0, (int)($x['involuntarias'] ?? 0));
            }
        }
        foreach ($smap as $day => $map) {
            $w = $week_for_day($day); if (!$w) { continue; }
            foreach ($districts as $d) { $periods[$w]['rows'][$d]['sales'] += max(0, (int)($map[$d] ?? 0)); }
        }

        foreach ($periods as $w => $period) {
            foreach ($districts as $d) {
                $r =& $periods[$w]['rows'][$d];
                $r['gain'] = self::dashboard_gain($r['installations'], $r['cancellations']);
                $r['reach'] = $r['objective'] > 0 ? round(($r['gain'] / $r['objective']) * 100, 1) : 0;
                foreach (array('sales','installations','cancellations','gain') as $metric) {
                    $periods[$w]['region'][$metric] += (int)$r[$metric];
                }
            }
            $periods[$w]['region']['reach'] = $periods[$w]['region']['objective'] > 0
                ? round(($periods[$w]['region']['gain'] / $periods[$w]['region']['objective']) * 100, 1)
                : 0;
        }
        return array_values($periods);
    }

    private static function dashboard_gain($installations, $cancellations) {
        return (int)$installations - (int)$cancellations;
    }

    /**
     * Día de corte opcional dentro de una semana del Dashboard Add Netas.
     * El filtro es acumulado desde el lunes de la semana hasta el día elegido.
     */
    private static function dashboard_selected_day(array $ctx) {
        if (($ctx['mode'] ?? '') !== 'semanal') { return ''; }
        $raw = isset($_GET['add_day']) ? sanitize_text_field(wp_unslash((string)$_GET['add_day'])) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) { return ''; }
        $tz = wp_timezone();
        $dt = DateTime::createFromFormat('!Y-m-d', $raw, $tz);
        if (!$dt || $dt->format('Y-m-d') !== $raw) { return ''; }
        $max = min((string)$ctx['end'], self::cutoff_date());
        if ($raw < (string)$ctx['start'] || $raw > $max) { return ''; }
        return $raw;
    }

    private static function dashboard_context_to_day(array $ctx, $day) {
        if (!$day) { return $ctx; }
        $ctx['end'] = (string)$day;
        $ctx['selected_day'] = (string)$day;
        return $ctx;
    }

    private static function dashboard_previous_context_to_same_day(array $ctx, array $previous_ctx, $day) {
        if (!$day || ($ctx['mode'] ?? '') !== 'semanal') { return $previous_ctx; }
        $tz = wp_timezone();
        $start = new DateTime((string)$ctx['start'], $tz);
        $selected = new DateTime((string)$day, $tz);
        $offset = max(0, min(6, (int)$start->diff($selected)->days));
        $previous_end = new DateTime((string)$previous_ctx['start'], $tz);
        if ($offset) { $previous_end->modify('+' . $offset . ' days'); }
        $previous_ctx['end'] = min((string)$previous_ctx['end'], $previous_end->format('Y-m-d'));
        $previous_ctx['selected_day'] = $previous_ctx['end'];
        return $previous_ctx;
    }

    private static function dashboard_delta($current, $previous) {
        $current=(float)$current; $previous=(float)$previous; $diff=$current-$previous;
        return array('diff'=>$diff,'pct'=>abs($previous)>0.0001?($diff/abs($previous))*100:null);
    }

    private static function executive_report_summary(array $ctx, $view = 'real') {
        $data = self::data($ctx);
        $view = $view === 'real' ? 'real' : 'fcst';
        $is_real = $view === 'real';
        $rows = $data['rows'];
        if ($is_real) { $rows = self::real_rows($rows); }
        $districts = tx_an_allowed_districts();
        $prepared = array();
        $totals = array('instalaciones'=>0,'cancelaciones'=>0,'voluntarias'=>0,'involuntarias'=>0,'organicas'=>0,'traslado_p'=>0,'traslado_f'=>0,'objetivo'=>0,'add_netas'=>0);
        foreach ($districts as $d) {
            $row = isset($rows[$d]) && is_array($rows[$d]) ? $rows[$d] : array();
            $row['instalaciones'] = (int)($row['instalaciones'] ?? 0);
            $row['voluntarias'] = (int)($row['voluntarias'] ?? 0);
            $row['organicas'] = (int)($row['organicas'] ?? 0);
            $row['traslado_p'] = (int)($row['traslado_p'] ?? 0);
            $row['traslado_f'] = (int)($row['traslado_f'] ?? 0);
            $row['objetivo'] = (int)($row['objetivo'] ?? 0);
            $row['involuntarias'] = max(0, (int)($row['involuntarias'] ?? 0));
            $row['cancelaciones'] = (int)$row['voluntarias'] + (int)$row['involuntarias'];
            $row['add_netas'] = (int)$row['instalaciones'] - (int)$row['cancelaciones'];
            $prepared[$d] = $row;
            foreach (array_keys($totals) as $k) { $totals[$k] += (int)($row[$k] ?? 0); }
        }
        $reach = $totals['objetivo'] > 0 ? round(($totals['add_netas'] / $totals['objetivo']) * 100, 1) : 0;

        // En Dashboard REAL, la semana activa conserva la lectura materializada como dato principal,
        // pero muestra también el cierre proyectado para facilitar la conciliación REAL vs FCST.
        $projection = null;
        $is_active_week = $is_real
            && ($ctx['mode'] ?? '') === 'semanal'
            && (int)($ctx['year'] ?? 0) === (int)($ctx['active_year'] ?? -1)
            && (int)($ctx['week'] ?? 0) === (int)($ctx['active_week'] ?? -1);
        if ($is_active_week) {
            $fcst_ctx = self::context(array(
                'add_mode'=>'semanal',
                'add_year'=>(int)$ctx['year'],
                'add_week'=>(int)$ctx['week'],
            ));
            $fcst_data = self::data($fcst_ctx);
            $fcst_rows = array();
            $fcst_totals = array('instalaciones'=>0,'cancelaciones'=>0,'voluntarias'=>0,'involuntarias'=>0,'organicas'=>0,'traslado_p'=>0,'traslado_f'=>0,'objetivo'=>0,'add_netas'=>0);
            foreach ($districts as $d) {
                $fr = isset($fcst_data['rows'][$d]) && is_array($fcst_data['rows'][$d]) ? $fcst_data['rows'][$d] : array();
                $fr['instalaciones'] = max(0,(int)($fr['instalaciones'] ?? 0));
                $fr['voluntarias'] = max(0,(int)($fr['voluntarias'] ?? 0));
                $fr['involuntarias'] = max(0,(int)($fr['involuntarias'] ?? 0));
                $fr['organicas'] = max(0,(int)($fr['organicas'] ?? 0));
                $fr['traslado_p'] = max(0,(int)($fr['traslado_p'] ?? 0));
                $fr['traslado_f'] = max(0,(int)($fr['traslado_f'] ?? 0));
                $fr['objetivo'] = max(0,(int)($fr['objetivo'] ?? 0));
                $fr['cancelaciones'] = $fr['voluntarias'] + $fr['involuntarias'];
                $fr['add_netas'] = $fr['instalaciones'] - $fr['cancelaciones'];
                $fcst_rows[$d] = $fr;
                foreach (array_keys($fcst_totals) as $k) { $fcst_totals[$k] += (int)($fr[$k] ?? 0); }
            }
            $projection = array('rows'=>$fcst_rows,'totals'=>$fcst_totals);

            /*
             * Semana activa / Dashboard REAL:
             * - Traslado P principal = REAL materializado al corte (cruce Qlik vs Pronóstico SAC).
             * - Traslado P FCST = programado SAC de la semana y se conserva sólo como referencia.
             * - Traslado F = captura oficial Corporativo vigente para descomponer la involuntaria.
             *
             * INV real permanece rector y Orgánicas se concilia con el Traslado P REAL:
             *   Orgánicas = Involuntarias - Traslado P REAL + Traslado F
             *
             * Ejemplo S35 Región SUR: 626 = 551 + 145 - 70; FCST Traslado P = 147.
             */
            foreach ($districts as $d) {
                if (!isset($prepared[$d], $fcst_rows[$d])) { continue; }
                $prepared[$d]['traslado_p_materializado'] = max(0, (int)($prepared[$d]['traslado_p'] ?? 0));
                $prepared[$d]['traslado_p_fcst'] = max(0, (int)($fcst_rows[$d]['traslado_p'] ?? 0));
                // El valor principal de traslado_p NO se sustituye: conserva el REAL materializado.
                $prepared[$d]['traslado_f'] = max(0, (int)($fcst_rows[$d]['traslado_f'] ?? 0));
                $prepared[$d]['organicas'] = max(0,
                    (int)$prepared[$d]['involuntarias']
                    - (int)$prepared[$d]['traslado_p']
                    + (int)$prepared[$d]['traslado_f']
                );
            }

            // Recalcula únicamente la composición/totales del reporte.
            // Cancelaciones y ADD Netas siguen siendo los valores REAL materializados.
            $totals = array('instalaciones'=>0,'cancelaciones'=>0,'voluntarias'=>0,'involuntarias'=>0,'organicas'=>0,'traslado_p'=>0,'traslado_f'=>0,'objetivo'=>0,'add_netas'=>0);
            foreach ($districts as $d) {
                foreach (array_keys($totals) as $k) {
                    $totals[$k] += (int)($prepared[$d][$k] ?? 0);
                }
            }
            $reach = $totals['objetivo'] > 0 ? round(($totals['add_netas'] / $totals['objetivo']) * 100, 1) : 0;
        }

        return array(
            'ctx' => $ctx,
            'data' => $data,
            'view' => $view,
            'is_real' => $is_real,
            'districts' => $districts,
            'rows' => $prepared,
            'totals' => $totals,
            'projection' => $projection,
            'reach' => $reach,
            'generated' => wp_date('d/m/Y H:i', null, wp_timezone()),
            'cutoff' => !empty($data['cutoff']) ? wp_date('d/m/Y', strtotime($data['cutoff']), wp_timezone()) : 'Sin corte',
        );
    }

    private static function render_executive_report_embed(array $report) {
        if (empty($report['districts']) || empty($report['rows']) || empty($report['totals'])) { return ''; }
        $ctx = $report['ctx'];
        $districts = $report['districts'];
        $rows = $report['rows'];
        $totals = $report['totals'];
        $projection = isset($report['projection']) && is_array($report['projection']) ? $report['projection'] : null;
        $projection_rows = $projection['rows'] ?? array();
        $projection_totals = $projection['totals'] ?? array();
        $reach = (float)$report['reach'];
        $is_real = !empty($report['is_real']);
        ob_start(); ?>
  <article class="andb-card andb-exec-card">
    <div class="andb-exec-top">
      <div>
        <span class="andb-card-head-label">REPORTE EJECUTIVO INTEGRADO</span>
        <h2><?php echo $is_real ? 'Real Add Netas' : 'FCST Add Netas'; ?> · <?php echo esc_html($ctx['label']); ?></h2>
        <p><?php echo esc_html($ctx['start'] . ' — ' . $ctx['end']); ?></p>
      </div>
      <div class="andb-exec-meta">
        <strong>Corte de información: <?php echo esc_html($report['cutoff']); ?></strong>
        <small>Generado: <?php echo esc_html($report['generated']); ?></small>
        <small><?php echo esc_html($is_real ? (($report['data']['real_days'] ?? 0) . ' días con corte real') : (($report['data']['projected_days'] ?? 0) . ' días proyectados')); ?></small>
      </div>
    </div>
    <div class="andb-exec-kpis">
      <div class="andb-exec-kpi"><span><?php echo $is_real ? 'Instalaciones reales' : 'Instalaciones esperadas'; ?></span><strong><?php echo number_format_i18n($totals['instalaciones']); ?></strong></div>
      <div class="andb-exec-kpi"><span><?php echo $is_real ? 'Cancelaciones reales' : 'Cancelaciones esperadas'; ?></span><strong><?php echo number_format_i18n($totals['cancelaciones']); ?></strong></div>
      <div class="andb-exec-kpi"><span><?php echo $is_real ? 'ADD Netas real' : 'ADD Netas esperado'; ?></span><strong><?php echo number_format_i18n($totals['add_netas']); ?></strong></div>
      <div class="andb-exec-kpi reach <?php echo $reach>=100?'good':($reach>=80?'mid':'low'); ?>"><span>Alcance <?php echo tx_an_scope_label()==="REGIÓN SUR"?"regional":"distrital"; ?></span><strong><?php echo esc_html(number_format_i18n($reach,1).'%'); ?></strong></div>
    </div>
    <div class="andb-exec-table-head">Resultado por distrito</div>
    <div class="andb-table-wrap andb-exec-table-wrap"><table><thead><tr><th>Distrito</th><th>Instalaciones</th><th>Voluntarias</th><th class="andb-inv-group andb-inv-total">Involuntarias</th><th class="andb-inv-group">Orgánicas</th><th class="andb-inv-group">Traslado P</th><th class="andb-inv-group">Traslado F</th><th>Cancelaciones</th><th>ADD Netas</th><th>Objetivo</th><th>Alcance</th></tr></thead><tbody>
    <?php foreach($districts as $d): $pct = (int)($rows[$d]['objetivo'] ?? 0) > 0 ? round(((int)$rows[$d]['add_netas'] / (int)$rows[$d]['objetivo']) * 100, 1) : 0; ?><tr><th><?php echo esc_html($d); ?></th><td><?php echo number_format_i18n($rows[$d]['instalaciones']); ?></td><td><?php echo number_format_i18n($rows[$d]['voluntarias']); ?></td><td class="andb-inv-group andb-inv-total"><strong><?php echo number_format_i18n($rows[$d]['involuntarias']); ?></strong></td><td class="andb-inv-group"><strong><?php echo number_format_i18n($rows[$d]['organicas']); ?></strong></td><td class="andb-inv-group"><strong><?php echo number_format_i18n($rows[$d]['traslado_p']); ?></strong></td><td class="andb-inv-group"><?php echo number_format_i18n($rows[$d]['traslado_f']); ?></td><td><?php echo number_format_i18n($rows[$d]['cancelaciones']); ?></td><td><strong><?php echo number_format_i18n($rows[$d]['add_netas']); ?></strong></td><td><?php echo number_format_i18n($rows[$d]['objetivo']); ?></td><td><strong class="<?php echo $pct>=100?'good':($pct>=80?'mid':'bad'); ?>"><?php echo number_format_i18n($pct,1); ?>%</strong></td></tr><?php endforeach; ?>
    <tr class="andb-exec-total"><th><?php echo esc_html(tx_an_total_label()); ?></th><td><?php echo number_format_i18n($totals['instalaciones']); ?></td><td><?php echo number_format_i18n($totals['voluntarias']); ?></td><td class="andb-inv-group andb-inv-total"><strong><?php echo number_format_i18n($totals['involuntarias']); ?></strong></td><td class="andb-inv-group"><strong><?php echo number_format_i18n($totals['organicas']); ?></strong></td><td class="andb-inv-group"><strong><?php echo number_format_i18n($totals['traslado_p']); ?></strong></td><td class="andb-inv-group"><?php echo number_format_i18n($totals['traslado_f']); ?></td><td><?php echo number_format_i18n($totals['cancelaciones']); ?></td><td><?php echo number_format_i18n($totals['add_netas']); ?></td><td><?php echo number_format_i18n($totals['objetivo']); ?></td><td><strong class="<?php echo $reach>=100?'good':($reach>=80?'mid':'bad'); ?>"><?php echo number_format_i18n($reach,1); ?>%</strong></td></tr>
    </tbody></table></div>
<div class="andb-exec-footer"><span>ADD Netas <?php echo esc_html(self::VERSION); ?> · Forecast <?php echo esc_html(self::FORECAST_ALGORITHM_VERSION); ?></span><span>Fuente de lectura: Xpedient / captura local ADD Netas</span></div>
  </article>
<?php
        return ob_get_clean();
    }

    public static function shortcode_real($atts = array()) {
        if (!is_user_logged_in() || !self::can_view()) return '<section class="krn-core-public-card"><strong>Acceso restringido</strong></section>';

        $ctx = self::dashboard_context();
        $prev_ctx = self::dashboard_previous_context($ctx);
        $selected_day = self::dashboard_selected_day($ctx);
        $metric_ctx = self::dashboard_context_to_day($ctx, $selected_day);
        $metric_prev_ctx = self::dashboard_previous_context_to_same_day($ctx, $prev_ctx, $selected_day);
        $current = self::dashboard_period_metrics($metric_ctx);
        $previous = self::dashboard_period_metrics($metric_prev_ctx);
        $districts = tx_an_allowed_districts();

        $requested_district = sanitize_text_field(wp_unslash((string)($_GET['add_district'] ?? '')));
        $selected_district = self::normalize_district($requested_district);
        if (strtolower(trim((string)($_SESSION['rol']??''))) === 'director_distrital') {
            // GET no puede cambiar la adscripción autenticada.
            $selected_district = tx_an_scope_district();
        } elseif ($selected_district !== '' && !in_array($selected_district, $districts, true)) {
            $selected_district = '';
        }
        $is_region = $selected_district === '';
        $scope_label = $is_region ? 'Región SUR' : $selected_district;
        $cur = $is_region ? $current['totals'] : $current['rows'][$selected_district];
        $pre = $is_region ? $previous['totals'] : $previous['rows'][$selected_district];

        $sales_delta = self::dashboard_delta($cur['sales'], $pre['sales']);
        $install_delta = self::dashboard_delta($cur['installations'], $pre['installations']);
        $cancel_delta = self::dashboard_delta($cur['cancellations'], $pre['cancellations']);
        $gain_delta = self::dashboard_delta($cur['gain'], $pre['gain']);
        $reach = (int)$cur['objective'] !== 0 ? round(($cur['gain'] / $cur['objective']) * 100, 1) : 0;
        $pre_reach = (int)$pre['objective'] !== 0 ? round(($pre['gain'] / $pre['objective']) * 100, 1) : 0;
        $reach_diff = round($reach - $pre_reach, 1);

        $urls = self::period_urls($ctx);
        if ($ctx['mode'] === 'semanal') {
            $tz = wp_timezone(); $now = new DateTime('now', $tz);
            if ((int)$now->format('N') === 1) {
                $p = clone $now; $p->modify('-7 days');
                $base = remove_query_arg(array('add_mode','add_year','add_week','add_month','add_day'));
                $urls['active'] = add_query_arg(array('add_mode'=>'semanal','add_year'=>(int)$p->format('o'),'add_week'=>(int)$p->format('W')), $base);
            }
        }

        $report_args = array('action'=>'krn_addnetas_executive_pdf','add_mode'=>$ctx['mode'],'add_view'=>'real');
        if ($ctx['mode'] === 'semanal') { $report_args['add_year']=$ctx['year']; $report_args['add_week']=$ctx['week']; if ($selected_day) { $report_args['add_day']=$selected_day; } }
        else { $report_args['add_month']=$ctx['month']; }
        $report_url = wp_nonce_url(add_query_arg($report_args, admin_url('admin-post.php')), 'krn_addnetas_executive_pdf');
        $executive_report = self::executive_report_summary($metric_ctx, 'real');

        // Minigráficos KPI: S01 → semana del periodo seleccionado.
        $weekly_history = self::dashboard_weekly_history($metric_ctx);
        $spark_history = array();
        foreach ($weekly_history as $p) {
            $x = $is_region ? ($p['region'] ?? array()) : ($p['rows'][$selected_district] ?? array());
            $spark_history[] = array(
                'label'=>(string)($p['label'] ?? ''),
                'sales'=>(int)($x['sales'] ?? 0),
                'installations'=>(int)($x['installations'] ?? 0),
                'cancellations'=>(int)($x['cancellations'] ?? 0),
                'gain'=>(int)($x['gain'] ?? 0),
                'reach'=>(float)($x['reach'] ?? 0),
                'selected'=>!empty($p['selected']),
            );
        }
        $spark_caption = $spark_history
            ? (($spark_history[0]['label'] ?? 'S01') . ' → ' . ($spark_history[count($spark_history)-1]['label'] ?? ''))
            : 'Histórico semanal';

        // Gráfica principal: 6 semanas/meses visibles con cifras.
        $period_history = self::dashboard_history($metric_ctx, 6);
        $main_history = array();
        foreach ($period_history as $p) {
            $x = $is_region ? ($p['region'] ?? array()) : ($p['rows'][$selected_district] ?? array());
            $main_history[] = array(
                'label'=>(string)($p['label'] ?? ''),
                'sales'=>(int)($x['sales'] ?? 0),
                'installations'=>(int)($x['installations'] ?? 0),
                'cancellations'=>(int)($x['cancellations'] ?? 0),
                'gain'=>(int)($x['gain'] ?? 0),
                'selected'=>!empty($p['selected']),
            );
        }

        $best_gain=''; $best_gain_value=null; $worst_cancel=''; $worst_cancel_value=-1;
        foreach ($districts as $d) {
            $g=(int)$current['rows'][$d]['gain']; $c=(int)$current['rows'][$d]['cancellations'];
            if ($best_gain_value===null || $g>$best_gain_value) { $best_gain=$d; $best_gain_value=$g; }
            if ($c>$worst_cancel_value) { $worst_cancel=$d; $worst_cancel_value=$c; }
        }

        $fmt=function($n){return number_format_i18n((int)$n);};
        $pctfmt=function($d){return $d['pct']===null?'—':(($d['pct']>=0?'+':'').number_format_i18n($d['pct'],1).'%');};
        $difffmt=function($d){return ($d['diff']>=0?'+':'').number_format_i18n($d['diff']);};
        if ($selected_day) {
            $tz = wp_timezone();
            $selected_dt = new DateTime($selected_day, $tz);
            $previous_dt = new DateTime((string)$metric_prev_ctx['end'], $tz);
            $period_compare = 'S'.$ctx['week'].' al '.wp_date('d M', $selected_dt->getTimestamp(), $tz).' vs S'.$prev_ctx['week'].' al '.wp_date('d M', $previous_dt->getTimestamp(), $tz);
            $pre_label = wp_date('d M', $previous_dt->getTimestamp(), $tz);
        } else {
            $period_compare=$ctx['mode']==='mensual'?($ctx['label'].' vs '.$prev_ctx['label']):('S'.$ctx['week'].' vs S'.$prev_ctx['week'].' · '.$ctx['year']);
            $pre_label=$ctx['mode']==='mensual'?$prev_ctx['label']:('S'.$prev_ctx['week']);
        }

        // Escalas para el comparativo horizontal por distrito (actual vs periodo anterior).
        $max_sales=1; $max_install=1; $max_cancel=1; $gain_min=0; $gain_max=0;
        foreach ($districts as $d) {
            $a=$current['rows'][$d]; $b=$previous['rows'][$d];
            $max_sales=max($max_sales,(int)$a['sales'],(int)$b['sales']);
            $max_install=max($max_install,(int)$a['installations'],(int)$b['installations']);
            $max_cancel=max($max_cancel,(int)$a['cancellations'],(int)$b['cancellations']);
            $gain_min=min($gain_min,(int)$a['gain'],(int)$b['gain']);
            $gain_max=max($gain_max,(int)$a['gain'],(int)$b['gain']);
        }
        if ($gain_min===$gain_max) { $gain_min=min(0,$gain_min-1); $gain_max=max(1,$gain_max+1); }
        $gain_span=max(1,$gain_max-$gain_min);
        $gain_zero=(0-$gain_min)/$gain_span*100;

        // Escala global para Ganancia vs Meta.
        $max_goal=1;
        foreach ($districts as $d) { $max_goal=max($max_goal,max(0,(int)$current['rows'][$d]['gain']),(int)$current['rows'][$d]['objective']); }

        ob_start(); ?>
<section class="andb-shell" data-dashboard-addnetas>
  <header class="andb-header">
    <div><span class="andb-eyebrow"><?php echo esc_html(tx_an_scope_label()); ?> · ADD NETAS</span><h1>Dashboard Add Netas</h1><p>Comparativo por semana o por mes · vista regional y por distrito</p></div>
    <div class="andb-cut"><strong><?php echo esc_html($ctx['label']); ?></strong><small><?php if($selected_day): ?>Corte seleccionado: <?php echo esc_html($selected_day); ?> · <?php endif; ?>Corte real: <?php echo esc_html(min($ctx['end'],self::cutoff_date())); ?></small></div>
  </header>

  <div class="andb-controls <?php echo $ctx['mode']==='semanal'?'has-day':''; ?>">
    <div class="andb-tabs"><a class="<?php echo $ctx['mode']==='semanal'?'is-active':''; ?>" href="<?php echo esc_url($urls['weekly']); ?>">Semanal</a><a class="<?php echo $ctx['mode']==='mensual'?'is-active':''; ?>" href="<?php echo esc_url($urls['monthly']); ?>">Mensual</a></div>
    <?php if($ctx['mode']==='semanal'): ?>
    <div class="andb-week-nav" aria-label="Navegador de semanas">
      <a class="andb-week-arrow" href="<?php echo esc_url($urls['previous']); ?>" aria-label="Ir a la semana anterior">‹</a>
      <div class="andb-week-current"><strong>Semana <?php echo esc_html($ctx['week']); ?> · <?php echo esc_html($ctx['year']); ?></strong><small>vs S<?php echo esc_html($prev_ctx['week']); ?></small></div>
      <?php if(!empty($urls['has_next'])): ?><a class="andb-week-arrow" href="<?php echo esc_url($urls['next']); ?>" aria-label="Ir a la semana siguiente">›</a><?php else: ?><span class="andb-week-arrow is-disabled" aria-hidden="true">›</span><?php endif; ?>
    </div>
    <?php else: ?>
    <a class="andb-period" href="<?php echo esc_url($urls['previous']); ?>">← <?php echo esc_html($period_compare); ?></a>
    <?php endif; ?>
    <?php if($ctx['mode']==='semanal'): $day_max=min($ctx['end'],self::cutoff_date()); ?>
    <div class="andb-day-filter"><span>Día</span><input type="date" data-dashboard-day min="<?php echo esc_attr($ctx['start']); ?>" max="<?php echo esc_attr($day_max); ?>" value="<?php echo esc_attr($selected_day); ?>" aria-label="Seleccionar día de la semana"><?php if($selected_day): ?><button type="button" data-dashboard-day-clear aria-label="Volver a semana completa">×</button><?php endif; ?></div>
    <?php endif; ?>
    <label class="andb-filter"><span>Distrito</span><select data-dashboard-district><?php if(tx_an_scope_label()==='REGIÓN SUR'):?><option value="">Región SUR</option><?php endif;?><?php foreach($districts as $d):?><option value="<?php echo esc_attr($d); ?>" <?php selected($selected_district,$d); ?>><?php echo esc_html($d); ?></option><?php endforeach;?></select></label>
    <a class="andb-export" href="<?php echo esc_url($report_url); ?>" target="_blank" rel="noopener">Exportar</a>
  </div>

  <?php if(!empty($current['warnings'])):?><div class="andb-warning"><?php echo esc_html(implode(' · ',array_unique($current['warnings']))); ?></div><?php endif;?>

  <div class="andb-kpis" data-kpi-carousel tabindex="0" aria-label="Indicadores generales">
    <article data-kpi-slide data-kpi-name="Ventas">
      <div class="andb-icon sales">↗</div>
      <div class="andb-kpi-copy"><span>VENTAS</span><strong><?php echo $fmt($cur['sales']); ?></strong><small>vs <?php echo esc_html($pre_label); ?>: <?php echo $fmt($pre['sales']); ?> <b class="<?php echo $sales_delta['diff']>=0?'good':'bad'; ?>"><?php echo esc_html($pctfmt($sales_delta)); ?></b></small></div>
      <div class="andb-kpi-spark"><svg data-spark="sales" viewBox="0 0 190 52" preserveAspectRatio="none"></svg><em><?php echo esc_html($spark_caption); ?></em></div>
    </article>
    <article data-kpi-slide data-kpi-name="Instalaciones">
      <div class="andb-icon install">⌁</div>
      <div class="andb-kpi-copy"><span>INSTALACIONES</span><strong><?php echo $fmt($cur['installations']); ?></strong><small>vs <?php echo esc_html($pre_label); ?>: <?php echo $fmt($pre['installations']); ?> <b class="<?php echo $install_delta['diff']>=0?'good':'bad'; ?>"><?php echo esc_html($pctfmt($install_delta)); ?></b></small></div>
      <div class="andb-kpi-spark"><svg data-spark="installations" viewBox="0 0 190 52" preserveAspectRatio="none"></svg><em><?php echo esc_html($spark_caption); ?></em></div>
    </article>
    <article data-kpi-slide data-kpi-name="Cancelaciones">
      <div class="andb-icon cancel">×</div>
      <div class="andb-kpi-copy"><span>CANCELACIONES</span><strong><?php echo $fmt($cur['cancellations']); ?></strong><small>vs <?php echo esc_html($pre_label); ?>: <?php echo $fmt($pre['cancellations']); ?> <b class="<?php echo $cancel_delta['diff']<=0?'good':'bad'; ?>"><?php echo esc_html($pctfmt($cancel_delta)); ?></b></small></div>
      <div class="andb-kpi-spark"><svg data-spark="cancellations" viewBox="0 0 190 52" preserveAspectRatio="none"></svg><em><?php echo esc_html($spark_caption); ?></em></div>
    </article>
    <article data-kpi-slide data-kpi-name="Ganancia · Add Netas">
      <div class="andb-icon gain">＋</div>
      <div class="andb-kpi-copy"><span>GANANCIA · ADD NETAS</span><strong><?php echo $fmt($cur['gain']); ?></strong><small>vs <?php echo esc_html($pre_label); ?>: <?php echo $fmt($pre['gain']); ?> <b class="<?php echo $gain_delta['diff']>=0?'good':'bad'; ?>"><?php echo esc_html($difffmt($gain_delta)); ?></b></small></div>
      <div class="andb-kpi-spark"><svg data-spark="gain" viewBox="0 0 190 52" preserveAspectRatio="none"></svg><em><?php echo esc_html($spark_caption); ?></em></div>
    </article>
    <article data-kpi-slide data-kpi-name="% Alcance">
      <div class="andb-icon reach">◎</div>
      <div class="andb-kpi-copy"><span>% ALCANCE</span><strong><?php echo esc_html(number_format_i18n($reach,1).'%'); ?></strong><small>vs <?php echo esc_html($pre_label); ?>: <?php echo esc_html(number_format_i18n($pre_reach,1).'%'); ?> <b class="<?php echo $reach_diff>=0?'good':'bad'; ?>"><?php echo ($reach_diff>=0?'+':'').esc_html(number_format_i18n($reach_diff,1)); ?> pp</b></small></div>
      <div class="andb-kpi-spark"><svg data-spark="reach" viewBox="0 0 190 52" preserveAspectRatio="none"></svg><em><?php echo esc_html($spark_caption); ?></em></div>
    </article>
  </div>
  <div class="andb-kpi-nav" data-kpi-carousel-nav aria-label="Navegación de indicadores">
    <button type="button" class="andb-kpi-arrow" data-kpi-prev aria-label="Indicador anterior">‹</button>
    <div class="andb-kpi-dots" aria-label="Seleccionar indicador">
      <button type="button" class="is-active" data-kpi-dot="0" aria-label="Ver Ventas" aria-current="true"></button>
      <button type="button" data-kpi-dot="1" aria-label="Ver Instalaciones"></button>
      <button type="button" data-kpi-dot="2" aria-label="Ver Cancelaciones"></button>
      <button type="button" data-kpi-dot="3" aria-label="Ver Ganancia · Add Netas"></button>
      <button type="button" data-kpi-dot="4" aria-label="Ver % Alcance"></button>
    </div>
    <button type="button" class="andb-kpi-arrow" data-kpi-next aria-label="Siguiente indicador">›</button>
  </div>

  <div class="andb-main-grid">
    <article class="andb-card andb-hero-chart">
      <div class="andb-card-head"><div><span>DESEMPEÑO COMPARATIVO POR <?php echo $ctx['mode']==='mensual'?'MES':'SEMANA'; ?></span><h2><?php echo $ctx['mode']==='mensual'?'Evolución por mes':'Evolución por semana'; ?> · <?php echo esc_html($scope_label); ?></h2></div><small>Ventas · Instalaciones · Cancelaciones · Ganancia</small></div>
      <div class="andb-mobile-hint">Desliza o usa las flechas para recorrer todos los periodos.</div>
      <div class="andb-chart-mobile-nav" data-main-chart-mobile-nav><button type="button" data-main-chart-dir="left" aria-label="Ver periodos anteriores">‹</button><span data-main-chart-position>Recorrer periodos</span><button type="button" data-main-chart-dir="right" aria-label="Ver periodos siguientes">›</button></div>
      <div class="andb-chart" data-main-chart></div>
      <div class="andb-chart-scrollbar" data-chart-scrollbar hidden><input type="range" min="0" max="0" value="0" step="1" aria-label="Deslizar desempeño comparativo por periodo"></div>
      <div class="andb-values-table"><table><thead><tr><th>Indicador</th><?php foreach($main_history as $p):?><th class="<?php echo !empty($p['selected'])?'is-selected':''; ?>"><?php echo esc_html($p['label']); ?></th><?php endforeach;?></tr></thead><tbody>
        <tr><th><i class="legend-sales"></i>Ventas</th><?php foreach($main_history as $p):?><td class="<?php echo !empty($p['selected'])?'is-selected':''; ?>"><?php echo $fmt($p['sales']); ?></td><?php endforeach;?></tr>
        <tr><th><i class="legend-install"></i>Instalaciones</th><?php foreach($main_history as $p):?><td class="<?php echo !empty($p['selected'])?'is-selected':''; ?>"><?php echo $fmt($p['installations']); ?></td><?php endforeach;?></tr>
        <tr><th><i class="legend-cancel"></i>Cancelaciones</th><?php foreach($main_history as $p):?><td class="<?php echo !empty($p['selected'])?'is-selected':''; ?>"><?php echo $fmt($p['cancellations']); ?></td><?php endforeach;?></tr>
        <tr><th><i class="legend-gain"></i>Ganancia</th><?php foreach($main_history as $p):?><td class="<?php echo !empty($p['selected'])?'is-selected':''; ?>"><strong><?php echo $fmt($p['gain']); ?></strong></td><?php endforeach;?></tr>
      </tbody></table></div>
    </article>
    <aside class="andb-insights"><h3>INSIGHTS EJECUTIVOS</h3><article class="goodbox"><span>Mayor ganancia</span><strong><?php echo esc_html($best_gain); ?></strong><b><?php echo $fmt($best_gain_value); ?> Add Netas</b></article><article class="badbox"><span>Mayor cancelación</span><strong><?php echo esc_html($worst_cancel); ?></strong><b><?php echo $fmt($worst_cancel_value); ?> cancelaciones</b></article></aside>
  </div>

  <div class="andb-two andb-two-merged">
    <article class="andb-card andb-card-combined">
      <div class="andb-card-head"><div><span>COMPARATIVO POR DISTRITO VS META</span><h2><?php echo esc_html($period_compare); ?></h2></div><small>Actual vs anterior · panorama operativo, ganancia y cumplimiento</small></div>
      <div class="andb-dc-legend"><span><i class="sales-now"></i>Ventas actual</span><span><i class="sales-prev"></i>Ventas anterior</span><span><i class="install-now"></i>Instalaciones actual</span><span><i class="install-prev"></i>Instalaciones anterior</span><span><i class="cancel-now"></i>Cancelaciones actual</span><span><i class="cancel-prev"></i>Cancelaciones anterior</span><span><i class="gain-now"></i>Ganancia actual</span><span><i class="gain-prev"></i>Ganancia anterior</span><span><i class="goal-mark"></i>Meta</span></div>
      <div class="andb-dc-wrap andb-dc-wrap-combined">
        <div class="andb-dc-head andb-dc-head-combined"><span>Distrito</span><span>Ventas</span><span>Instalaciones</span><span>Cancelaciones</span><span>Ganancia</span><span>Meta / avance</span><span>% Alcance</span></div>
        <?php foreach($districts as $d): $a=$current['rows'][$d]; $b=$previous['rows'][$d]; $sp=max(0,min(100,((int)$a['sales']/$max_sales)*100)); $spp=max(0,min(100,((int)$b['sales']/$max_sales)*100)); $ip=max(0,min(100,((int)$a['installations']/$max_install)*100)); $ipp=max(0,min(100,((int)$b['installations']/$max_install)*100)); $cp=max(0,min(100,((int)$a['cancellations']/$max_cancel)*100)); $cpp=max(0,min(100,((int)$b['cancellations']/$max_cancel)*100)); $gp=(((int)$a['gain']-$gain_min)/$gain_span)*100; $gpp=(((int)$b['gain']-$gain_min)/$gain_span)*100; $pct=$a['objective']>0?round(($a['gain']/$a['objective'])*100,1):0; $w=max(0,min(100,(max(0,(int)$a['gain'])/$max_goal)*100)); $mw=max(0,min(100,((int)$a['objective']/$max_goal)*100)); $gain_vs_meta=(int)$a['gain']-(int)$a['objective']; ?>
        <div class="andb-dc-row andb-dc-row-combined">
          <strong><?php echo esc_html($d); ?></strong>
          <div class="andb-dualmetric metric-sales"><div class="andb-htrack sales"><i class="previous" style="width:<?php echo esc_attr($spp); ?>%"></i><i class="current" style="width:<?php echo esc_attr($sp); ?>%"></i></div><div class="andb-dc-nums"><b><?php echo $fmt($a['sales']); ?></b><small>Ant. <?php echo $fmt($b['sales']); ?></small></div></div>
          <div class="andb-dualmetric metric-install"><div class="andb-htrack install"><i class="previous" style="width:<?php echo esc_attr($ipp); ?>%"></i><i class="current" style="width:<?php echo esc_attr($ip); ?>%"></i></div><div class="andb-dc-nums"><b><?php echo $fmt($a['installations']); ?></b><small>Ant. <?php echo $fmt($b['installations']); ?></small></div></div>
          <div class="andb-dualmetric metric-cancel"><div class="andb-htrack cancel"><i class="previous" style="width:<?php echo esc_attr($cpp); ?>%"></i><i class="current" style="width:<?php echo esc_attr($cp); ?>%"></i></div><div class="andb-dc-nums"><b><?php echo $fmt($a['cancellations']); ?></b><small>Ant. <?php echo $fmt($b['cancellations']); ?></small></div></div>
          <div class="andb-gainmetric"><div class="andb-gain-axis"><span class="zero" style="left:<?php echo esc_attr($gain_zero); ?>%"></span><i class="previous" style="left:<?php echo esc_attr(max(0,min(100,$gpp))); ?>%"></i><i class="current" style="left:<?php echo esc_attr(max(0,min(100,$gp))); ?>%"></i></div><div class="andb-dc-nums"><b class="<?php echo (int)$a['gain']>=0?'good':'bad'; ?>"><?php echo $fmt($a['gain']); ?></b><small>Ant. <?php echo $fmt($b['gain']); ?></small></div></div>
          <div class="andb-goalmetric"><div class="andb-goal-track"><i style="width:<?php echo esc_attr($w); ?>%"></i><b style="left:<?php echo esc_attr($mw); ?>%" title="Meta <?php echo esc_attr($fmt($a['objective'])); ?>"></b></div><div class="andb-dc-nums"><b>Meta <?php echo $fmt($a['objective']); ?></b><small><?php echo ($gain_vs_meta>=0?'+':'').$fmt(abs($gain_vs_meta)); ?> vs meta</small></div></div>
          <div class="andb-goalpct"><strong class="<?php echo $pct>=100?'good':($pct>=80?'mid':'bad'); ?>"><?php echo number_format_i18n($pct,1); ?>%</strong><small><?php echo $fmt($a['gain']); ?> / <?php echo $fmt($a['objective']); ?></small></div>
        </div>
        <?php endforeach; ?>
      </div>
    </article>
  </div>

  <article class="andb-card andb-table-card"><div class="andb-card-head"><div><span>RESUMEN EJECUTIVO POR DISTRITO</span><h2><?php echo esc_html($ctx['label']); ?></h2></div></div><div class="andb-table-wrap"><table><thead><tr><th>Distrito</th><th>Ventas</th><th>Ant.</th><th>Var. Ventas</th><th>Cancel.</th><th>Ant.</th><th>Var. Cancel.</th><th>Ganancia</th><th>Ant.</th><th>Δ Ganancia</th><th>Meta</th><th>% Alcance</th></tr></thead><tbody><?php foreach($districts as $d):$a=$current['rows'][$d];$b=$previous['rows'][$d];$dv=self::dashboard_delta($a['sales'],$b['sales']);$dc=self::dashboard_delta($a['cancellations'],$b['cancellations']);$dg=self::dashboard_delta($a['gain'],$b['gain']);$rp=$a['objective']>0?round(($a['gain']/$a['objective'])*100,1):0;?><tr><th><?php echo esc_html($d); ?></th><td><?php echo $fmt($a['sales']); ?></td><td><?php echo $fmt($b['sales']); ?></td><td><span class="pill <?php echo $dv['diff']>=0?'good':'bad'; ?>"><?php echo esc_html($pctfmt($dv)); ?></span></td><td><?php echo $fmt($a['cancellations']); ?></td><td><?php echo $fmt($b['cancellations']); ?></td><td><span class="pill <?php echo $dc['diff']<=0?'good':'bad'; ?>"><?php echo esc_html($pctfmt($dc)); ?></span></td><td><strong><?php echo $fmt($a['gain']); ?></strong></td><td><?php echo $fmt($b['gain']); ?></td><td><span class="pill <?php echo $dg['diff']>=0?'good':'bad'; ?>"><?php echo esc_html($difffmt($dg)); ?></span></td><td><?php echo $fmt($a['objective']); ?></td><td><strong class="<?php echo $rp>=100?'good':($rp>=80?'mid':'bad'); ?>"><?php echo number_format_i18n($rp,1); ?>%</strong></td></tr><?php endforeach;?></tbody></table></div></article>

  <?php echo self::render_executive_report_embed($executive_report); ?>
</section>
<style>
.andb-shell{--ink:#1f2840;--muted:#77839b;--line:#e8e5f2;--purple:#7c3aed;--purple2:#a855f7;--pink:#ff3f7f;--blue:#2563eb;--green:#17b26a;--red:#d64550;--orange:#c27a00;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);max-width:1500px;margin:0 auto;padding:20px}.andb-shell *{box-sizing:border-box}.andb-header,.andb-controls,.andb-card,.andb-kpis article,.andb-insights article{background:#fff;border:1px solid var(--line);border-radius:20px}.andb-header{padding:26px 30px;display:flex;justify-content:space-between;gap:20px;align-items:center;background:linear-gradient(110deg,#fff 0,#fbf8ff 100%)}.andb-eyebrow,.andb-card-head span{font-size:11px;font-weight:800;letter-spacing:.12em;color:#8b77a8}.andb-header h1{font-size:30px;margin:5px 0 4px}.andb-header p{margin:0;color:var(--muted)}.andb-cut{text-align:right}.andb-cut strong,.andb-cut small{display:block}.andb-cut small{color:var(--muted);margin-top:5px}.andb-controls{margin-top:14px;padding:10px;display:grid;grid-template-columns:auto 1fr minmax(220px,320px) auto;gap:12px;align-items:center}.andb-controls.has-day{grid-template-columns:auto minmax(210px,1fr) minmax(180px,230px) minmax(190px,260px) auto}.andb-tabs{display:flex;gap:5px}.andb-tabs a,.andb-period,.andb-export{padding:11px 18px;border-radius:12px;text-decoration:none;font-weight:750;color:#5c5870;background:#f7f5fb}.andb-tabs a.is-active{color:#fff;background:linear-gradient(90deg,#6d28d9,#d600a8)}.andb-period{text-align:center}.andb-week-nav{height:43px;display:grid;grid-template-columns:42px minmax(0,1fr) 42px;align-items:stretch;border-radius:12px;background:#f7f5fb;overflow:hidden}.andb-week-arrow{display:flex;align-items:center;justify-content:center;text-decoration:none;color:#6d28d9;font-size:24px;font-weight:850;line-height:1;transition:background .16s ease,transform .16s ease}.andb-week-arrow:hover{background:#eee8fb}.andb-week-arrow:active{transform:scale(.96)}.andb-week-arrow.is-disabled{color:#bdb7ca;background:#f7f5fb;cursor:default}.andb-week-current{min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4px 8px;border-left:1px solid #ebe6f3;border-right:1px solid #ebe6f3;text-align:center}.andb-week-current strong{max-width:100%;font-size:12px;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.andb-week-current small{margin-top:2px;color:var(--muted);font-size:9px;font-weight:700;line-height:1}.andb-export{color:#6d28d9;background:#fff;border:1px solid #d9cff4;text-align:center}.andb-filter{display:flex;align-items:center;gap:10px;padding:0 12px;border:1px solid var(--line);border-radius:12px;height:43px}.andb-filter span{font-size:11px;color:var(--muted);font-weight:700}.andb-filter select{width:100%;border:0;background:transparent;font-weight:700;color:var(--ink);outline:none}.andb-day-filter{display:flex;align-items:center;gap:8px;padding:0 9px 0 12px;border:1px solid var(--line);border-radius:12px;height:43px;min-width:0;background:#fff}.andb-day-filter span{font-size:11px;color:var(--muted);font-weight:700;white-space:nowrap}.andb-day-filter input{min-width:0;width:100%;border:0;background:transparent;color:var(--ink);font:inherit;font-size:12px;font-weight:700;outline:none}.andb-day-filter button{flex:0 0 28px;width:28px;height:28px;border:0;border-radius:9px;background:#f5f1fb;color:#6d28d9;font-size:18px;line-height:1;cursor:pointer}.andb-warning{margin-top:12px;padding:10px 14px;border-radius:12px;background:#fff7e8;color:#8a5b00;border:1px solid #ffe0a6}
.andb-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:14px 0}.andb-kpis article{min-height:150px;padding:16px;display:grid;grid-template-columns:44px minmax(0,1fr);grid-template-areas:"icon copy" "spark spark";gap:10px 12px;align-items:start;overflow:hidden}.andb-icon{grid-area:icon;width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-size:23px;font-weight:800}.andb-icon.sales{background:#f1ecff;color:#6d28d9}.andb-icon.install{background:#eef9ff;color:#1687c9}.andb-icon.cancel{background:#fff0f5;color:#ef326f}.andb-icon.gain{background:#eef4ff;color:#2563eb}.andb-icon.reach{background:#eaf9f1;color:#17a965}.andb-kpi-copy{grid-area:copy;min-width:0}.andb-kpis span{font-size:11px;font-weight:800;letter-spacing:.05em}.andb-kpis strong{font-size:28px;display:block;margin:3px 0}.andb-kpis small{color:var(--muted);display:block;font-size:11px}.andb-kpis small b{margin-left:4px}.andb-kpi-spark{grid-area:spark;border-top:1px solid #f1edf7;padding-top:8px;min-width:0}.andb-kpi-spark svg{display:block;width:100%;height:52px}.andb-kpi-spark em{display:block;color:#929aac;font-style:normal;font-size:9px;margin-top:2px}.andb-spark-line{fill:none;stroke-width:2.1;vector-effect:non-scaling-stroke;stroke-linecap:round;stroke-linejoin:round}.andb-spark-area{opacity:.10}.andb-spark-dot{stroke:#fff;stroke-width:1.6;vector-effect:non-scaling-stroke}.andb-spark-grid{stroke:#ece8f3;stroke-width:.7;vector-effect:non-scaling-stroke}.andb-spark-zero{stroke:#c9c1d8;stroke-width:.8;stroke-dasharray:3 3;vector-effect:non-scaling-stroke}.andb-kpi-nav{display:none}
.good{color:#118a4e!important}.bad{color:#d64550!important}.mid{color:#c27a00!important}.andb-main-grid{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:14px}.andb-card{padding:18px}.andb-hero-chart{min-width:0;max-width:100%;overflow:hidden}.andb-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:10px}.andb-card-head h2{font-size:18px;margin:3px 0}.andb-card-head small{color:var(--muted);text-align:right}.andb-chart{height:360px}.andb-insights{display:grid;gap:12px;align-content:start}.andb-insights h3{font-size:12px;color:#7b66a3;margin:7px 2px 0;letter-spacing:.08em}.andb-insights article{padding:22px}.andb-insights span{display:block;color:var(--muted);font-size:14px}.andb-insights strong{display:block;font-size:22px;margin:8px 0}.andb-insights b{font-size:13px}.goodbox{background:#f4fff8!important;border-color:#d7f0e2!important}.badbox{background:#fff5f6!important;border-color:#ffd9df!important}
.andb-svg{width:100%;height:100%;display:block}.andb-axis{stroke:#e5e9f0;stroke-width:1}.andb-zero-axis{stroke:#b7bfce;stroke-width:1.2;stroke-dasharray:4 4}.andb-label{fill:#79849a;font-size:11px}.andb-value{fill:#465166;font-size:10px;font-weight:750}.andb-bar-sales{fill:#7c3aed}.andb-bar-install{fill:#1687c9}.andb-bar-cancel{fill:#ff4d82}.andb-gain-line{fill:none;stroke:#2563eb;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}.andb-gain-dot{fill:#fff;stroke:#2563eb;stroke-width:2}.andb-selected{fill:#f7f1ff}.andb-legend{display:flex;gap:15px;flex-wrap:wrap;margin:4px 0 6px;font-size:11px;color:#687386}.andb-legend i{width:9px;height:9px;display:inline-block;border-radius:3px;margin-right:5px}
.andb-mobile-hint{display:none}.andb-chart-mobile-nav{display:none}.andb-chart-scrollbar{display:none;align-items:center;gap:10px;margin:6px 2px 0}.andb-chart-scrollbar input[type=range]{width:100%;appearance:none;-webkit-appearance:none;height:6px;border-radius:999px;background:linear-gradient(90deg,#ede7f8 0%,#ddd4f8 100%);outline:none}.andb-chart-scrollbar input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#7c3aed 0%,#c026d3 100%);border:2px solid #fff;box-shadow:0 6px 18px rgba(124,58,237,.28);cursor:pointer}.andb-chart-scrollbar input[type=range]::-moz-range-thumb{width:22px;height:22px;border:none;border-radius:50%;background:linear-gradient(135deg,#7c3aed 0%,#c026d3 100%);box-shadow:0 6px 18px rgba(124,58,237,.28);cursor:pointer}.andb-chart-scrollbar input[type=range]::-moz-range-track{height:6px;border:none;border-radius:999px;background:linear-gradient(90deg,#ede7f8 0%,#ddd4f8 100%)}.andb-values-table{overflow-x:auto;-webkit-overflow-scrolling:touch;margin-top:6px;border-top:1px solid #eeeaf5}.andb-values-table table{border-collapse:collapse;width:100%;min-width:620px;font-size:11px}.andb-values-table th,.andb-values-table td{padding:7px 9px;text-align:right;border-bottom:1px solid #f1eef6}.andb-values-table th:first-child{text-align:left}.andb-values-table .is-selected{background:#f7f1ff;font-weight:800}.andb-values-table i{width:8px;height:8px;border-radius:2px;display:inline-block;margin-right:6px}.legend-sales{background:#7c3aed}.legend-install{background:#1687c9}.legend-cancel{background:#ff4d82}.legend-gain{background:#2563eb}
.andb-two{margin-top:14px;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:14px}.andb-two.andb-two-merged{grid-template-columns:minmax(0,1fr)}.andb-two.andb-two-merged > .andb-card{width:100%;grid-column:1 / -1}.andb-dc-legend{display:flex;gap:12px;flex-wrap:wrap;color:#788398;font-size:10px;margin:0 0 10px}.andb-dc-legend i{display:inline-block;margin-right:5px}.andb-dc-legend .sales-now,.andb-dc-legend .sales-prev,.andb-dc-legend .install-now,.andb-dc-legend .install-prev,.andb-dc-legend .cancel-now,.andb-dc-legend .cancel-prev{width:14px;height:7px;border-radius:2px}.sales-now{background:#7c3aed}.sales-prev{background:#d8c7fb}.install-now{background:#1687c9}.install-prev{background:#c8e8fb}.cancel-now{background:#ff4d82}.cancel-prev{background:#ffd0df}.andb-dc-legend .gain-now,.andb-dc-legend .gain-prev{width:8px;height:8px;border-radius:50%}.gain-now{background:#2563eb}.gain-prev{background:#fff;border:2px solid #93b8f5}.goal-mark{width:2px;height:14px;background:#25a5ff;border-radius:2px}.andb-dc-wrap{overflow:hidden}.andb-dc-head,.andb-dc-row{display:grid;grid-template-columns:125px repeat(3,minmax(145px,1fr)) minmax(150px,1.05fr);gap:12px;align-items:center}.andb-dc-head{color:#7c879b;font-size:10px;font-weight:800;padding:0 0 8px;border-bottom:1px solid #eeeaf5}.andb-dc-row{min-height:58px;border-bottom:1px solid #f1eef6}.andb-dc-row>strong{font-size:12px}.andb-dc-wrap-combined{overflow-x:auto;-webkit-overflow-scrolling:touch}.andb-dc-head-combined,.andb-dc-row-combined{grid-template-columns:125px repeat(3,minmax(145px,1fr)) minmax(150px,1.05fr) minmax(180px,1.15fr) 96px;min-width:1240px}.andb-dc-row-combined{padding:4px 0}.andb-dualmetric,.andb-gainmetric,.andb-goalmetric,.andb-goalpct{min-width:0}.andb-htrack{height:17px;position:relative;background:#f5f2fa;border-radius:999px;overflow:hidden}.andb-htrack i{position:absolute;left:0;border-radius:999px}.andb-htrack .previous{top:3px;height:11px}.andb-htrack .current{top:5px;height:7px}.andb-htrack.sales .previous{background:#d8c7fb}.andb-htrack.sales .current{background:#7c3aed}.andb-htrack.install .previous{background:#c8e8fb}.andb-htrack.install .current{background:#1687c9}.andb-htrack.cancel .previous{background:#ffd0df}.andb-htrack.cancel .current{background:#ff4d82}.andb-dc-nums{display:flex;justify-content:space-between;gap:8px;margin-top:3px;font-size:10px}.andb-dc-nums b{font-size:11px}.andb-dc-nums small{color:#8b95a7}.andb-gain-axis{height:18px;position:relative;background:linear-gradient(180deg,transparent 47%,#e8e4ef 48%,#e8e4ef 53%,transparent 54%)}.andb-gain-axis .zero{position:absolute;top:0;bottom:0;border-left:1px dashed #aab2c1}.andb-gain-axis i{position:absolute;top:50%;transform:translate(-50%,-50%);border-radius:50%}.andb-gain-axis .previous{width:10px;height:10px;background:#fff;border:2px solid #93b8f5}.andb-gain-axis .current{width:9px;height:9px;background:#2563eb;border:2px solid #fff;box-shadow:0 0 0 1px #2563eb}.andb-goalmetric .andb-goal-track{height:16px;background:#f3eff9;border-radius:999px;position:relative;overflow:visible}.andb-goalmetric .andb-goal-track i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#7c3aed,#a855f7)}.andb-goalmetric .andb-goal-track b{position:absolute;top:-4px;width:2px;height:24px;background:#25a5ff;border-radius:2px;transform:translateX(-50%)}.andb-goalpct{text-align:right}.andb-goalpct strong{display:block;font-size:14px;line-height:1.1}.andb-goalpct small{display:block;color:#8b95a7;font-size:10px;margin-top:4px}.andb-exec-card{margin-top:14px;padding:18px}.andb-card-head-label{display:block;font-size:11px;font-weight:800;letter-spacing:.12em;color:#8b77a8}.andb-exec-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding-bottom:14px;border-bottom:1px solid #eee6f5}.andb-exec-top h2{margin:6px 0 4px;font-size:24px;line-height:1.1}.andb-exec-top p{margin:0;color:#768299}.andb-exec-meta{text-align:right;display:grid;gap:4px;color:#758093;font-size:12px}.andb-exec-meta strong{color:#1f2840;font-size:14px}.andb-exec-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.andb-exec-kpi{padding:14px;border:1px solid #ece6f5;border-radius:16px;background:#fff}.andb-exec-kpi span{display:block;font-size:11px;color:#788398}.andb-exec-kpi strong{display:block;margin-top:5px;font-size:24px}.andb-exec-real{display:block;margin-top:3px;color:#7b8794;font-size:9px;font-weight:700}.andb-exec-kpi.reach.good{background:#eaf8ef}.andb-exec-kpi.reach.mid{background:#fff7dc}.andb-exec-kpi.reach.low{background:#fff0f2}.andb-exec-table-head{margin:4px 0 8px;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6b5a7d}.andb-exec-table-wrap table{min-width:1080px}.andb-exec-table-wrap .andb-inv-group{background:#eef4ff}.andb-exec-table-wrap .andb-inv-total{background:#dfeaff;font-weight:800;color:#233b69}.andb-exec-fcst{display:block;margin-top:3px;color:#6d28d9;font-size:9px;font-weight:800;white-space:nowrap}.andb-exec-total th,.andb-exec-total td{background:#f8eefd;font-weight:800}.andb-exec-total .andb-inv-group{background:#e8e8ff}.andb-exec-total .andb-inv-total{background:#d7defd}.andb-exec-notes{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.andb-exec-note{padding:12px;border-radius:12px;background:#f3f8fb;color:#5f6b7a;font-size:11px;line-height:1.45}.andb-exec-footer{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;padding-top:10px;border-top:1px solid #ece7f1;color:#7b8794;font-size:10px}
.andb-goal-head,.andb-goal-row{display:grid;grid-template-columns:105px 65px minmax(150px,1fr) 62px 58px;gap:9px;align-items:center}.andb-goal-head{color:#7c879b;font-size:9px;font-weight:800;padding:2px 0 7px}.andb-goal-chart{display:grid;gap:13px;padding-top:4px}.andb-goal-row{font-size:12px}.andb-goal-track{height:15px;background:#f3eff9;border-radius:999px;position:relative;overflow:visible}.andb-goal-track i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#7c3aed,#a855f7)}.andb-goal-track b{position:absolute;top:-4px;width:2px;height:23px;background:#25a5ff;border-radius:2px;transform:translateX(-50%)}.andb-goal-row strong,.andb-goal-row em,.andb-goal-row small{font-style:normal;text-align:right}.andb-goal-row small{color:#596579;font-weight:700}.andb-table-card{margin-top:14px}.andb-table-wrap{overflow:auto;-webkit-overflow-scrolling:touch}.andb-table-wrap table{border-collapse:collapse;width:100%;min-width:1180px;font-size:12px}.andb-table-wrap th,.andb-table-wrap td{padding:11px 10px;border-bottom:1px solid #eeeaf5;text-align:right}.andb-table-wrap th:first-child,.andb-table-wrap td:first-child{text-align:left}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#f5f4f8;font-weight:750}.pill.good{background:#eaf9f1}.pill.bad{background:#fff0f2}
@media(max-width:1100px){.andb-main-grid,.andb-two{grid-template-columns:1fr}.andb-insights{grid-template-columns:1fr 1fr}.andb-dc-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}.andb-dc-head,.andb-dc-row{min-width:920px}.andb-dc-head-combined,.andb-dc-row-combined{min-width:1240px}.andb-exec-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:980px){.andb-controls,.andb-controls.has-day{grid-template-columns:1fr 1fr}.andb-week-nav{grid-column:1/-1}.andb-kpis{grid-template-columns:1fr 1fr}}
@media(max-width:700px){
  .andb-shell{padding:8px;max-width:100%;overflow-x:hidden}.andb-shell>*{min-width:0}
  .andb-header{padding:17px;align-items:flex-start;flex-direction:column;border-radius:18px}.andb-header h1{font-size:24px;line-height:1.08}.andb-header p{font-size:12px;line-height:1.45}.andb-cut,.andb-cut small{font-size:11px;text-align:left}
  .andb-controls{grid-template-columns:1fr 1fr;gap:8px;padding:8px;border-radius:16px}.andb-tabs{grid-column:1/-1;width:100%}.andb-tabs a{flex:1;text-align:center;padding:10px 8px}.andb-period,.andb-week-nav{grid-column:1/-1}.andb-period{padding:10px 8px;white-space:normal}.andb-week-nav{height:44px}.andb-week-arrow{font-size:25px}.andb-week-current strong{font-size:12px}.andb-day-filter{grid-column:1/-1;height:42px}.andb-day-filter span{font-size:10px}.andb-filter{min-width:0;height:42px;padding:0 9px}.andb-filter span{display:none}.andb-filter select{font-size:12px}.andb-export{padding:11px 8px}
  .andb-kpis{display:flex;position:relative;grid-template-columns:none;gap:12px;margin:10px 0 4px;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior-inline:contain;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none;align-items:stretch;touch-action:pan-x pan-y;outline:none}.andb-kpis::-webkit-scrollbar{display:none}.andb-kpis::after{content:"";flex:0 0 14%;height:1px}.andb-kpis article{flex:0 0 86%;min-width:0;min-height:142px;padding:11px;grid-template-columns:34px minmax(0,1fr);gap:7px 8px;border-radius:16px;scroll-snap-align:start;scroll-snap-stop:always;align-self:stretch}.andb-icon{width:34px;height:34px;border-radius:10px;font-size:18px}.andb-kpis strong{font-size:22px;line-height:1.05}.andb-kpis span{font-size:9px}.andb-kpis small{font-size:9px;line-height:1.35}.andb-kpi-spark{padding-top:6px}.andb-kpi-spark svg{height:44px}.andb-kpi-spark em{font-size:8px}.andb-kpi-nav{display:grid;grid-template-columns:44px minmax(0,1fr) 44px;align-items:center;gap:8px;margin:0 0 10px}.andb-kpi-arrow{width:44px;height:44px;border:1px solid #d9c6f5;border-radius:13px;background:#faf8fe;color:#6d28d9;font-size:24px;font-weight:800;line-height:1;display:grid;place-items:center;touch-action:manipulation;cursor:pointer}.andb-kpi-arrow:disabled{opacity:.3;cursor:default}.andb-kpi-dots{display:flex;align-items:center;justify-content:center;gap:7px;min-width:0}.andb-kpi-dots button{width:8px;height:8px;padding:0;border:0;border-radius:999px;background:#d9d1e6;transition:width .18s ease,background .18s ease,transform .18s ease;cursor:pointer;touch-action:manipulation}.andb-kpi-dots button.is-active{width:24px;background:linear-gradient(90deg,#7c3aed,#d600a8)}.andb-kpi-dots button:focus-visible,.andb-kpi-arrow:focus-visible{outline:2px solid #7c3aed;outline-offset:2px}
  .andb-main-grid,.andb-two{grid-template-columns:1fr;gap:10px}.andb-card{padding:12px;border-radius:16px}.andb-card-head{flex-direction:column;gap:4px;margin-bottom:7px}.andb-card-head h2{font-size:16px;line-height:1.25}.andb-card-head small{text-align:left;font-size:10px}.andb-mobile-hint{display:block;margin:-1px 0 7px;color:#7a6a96;font-size:10px;font-weight:650}.andb-chart-mobile-nav{display:grid;grid-template-columns:40px minmax(0,1fr) 40px;gap:7px;align-items:center;margin:4px 0 8px}.andb-chart-mobile-nav button{width:40px;height:36px;border:1px solid #d9c6f5;border-radius:10px;background:#f8f5fd;color:#6d28d9;font-size:20px;line-height:1;touch-action:manipulation}.andb-chart-mobile-nav button:disabled{opacity:.32}.andb-chart-mobile-nav span{text-align:center;color:#748198;font-size:10px;line-height:1.25}
  .andb-chart{display:block;width:100%;max-width:100%;height:286px;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior-inline:contain;padding-bottom:3px;scrollbar-width:thin;scrollbar-color:#9f7aea #ece8f8}.andb-chart::-webkit-scrollbar{height:8px}.andb-chart::-webkit-scrollbar-track{background:#ece8f8;border-radius:999px}.andb-chart::-webkit-scrollbar-thumb{background:linear-gradient(90deg,#7c3aed 0%,#c026d3 100%);border-radius:999px}.andb-chart .andb-svg{width:720px;min-width:720px;height:278px}.andb-chart-scrollbar{display:flex}.andb-values-table{margin:5px -2px 0}.andb-values-table table{min-width:640px;font-size:10px}.andb-values-table th,.andb-values-table td{padding:6px 7px}
  .andb-insights{grid-template-columns:1fr 1fr;gap:8px}.andb-insights h3{grid-column:1/-1;margin:3px 1px 0}.andb-insights article{padding:13px;min-width:0}.andb-insights span{font-size:10px}.andb-insights strong{font-size:16px;margin:5px 0;overflow-wrap:anywhere}.andb-insights b{font-size:10px}
  .andb-dc-legend{gap:7px;font-size:9px}.andb-dc-wrap{overflow:visible}.andb-dc-head{display:none}.andb-dc-row{min-width:0;display:grid;grid-template-columns:1fr;gap:8px;padding:11px 0;align-items:stretch}.andb-dc-row>strong{font-size:13px}.andb-dualmetric,.andb-gainmetric{display:grid;gap:3px}.andb-dualmetric::before,.andb-gainmetric::before{color:#7c879b;font-size:9px;font-weight:800;letter-spacing:.06em}.andb-dualmetric.metric-sales::before{content:'VENTAS · ACTUAL VS ANTERIOR'}.andb-dualmetric.metric-install::before{content:'INSTALACIONES · ACTUAL VS ANTERIOR'}.andb-dualmetric.metric-cancel::before{content:'CANCELACIONES · ACTUAL VS ANTERIOR'}.andb-gainmetric::before{content:'GANANCIA · PUNTO ACTUAL VS ANTERIOR'}.andb-htrack{height:16px}.andb-dc-nums{font-size:9px}.andb-dc-nums b{font-size:11px}.andb-exec-card{padding:14px}.andb-exec-top{flex-direction:column}.andb-exec-top h2{font-size:20px}.andb-exec-meta{text-align:left}.andb-exec-kpis{grid-template-columns:1fr 1fr;gap:8px}.andb-exec-kpi{padding:12px;border-radius:14px}.andb-exec-kpi strong{font-size:22px}.andb-exec-table-wrap{max-width:calc(100vw - 38px)}.andb-exec-notes{grid-template-columns:1fr}.andb-exec-footer{font-size:9px}
  .andb-goal-head{display:none}.andb-goal-chart{gap:7px}.andb-goal-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;grid-template-areas:'district gain reach' 'track track track' 'metaLabel meta meta';gap:5px 8px;padding:10px;border:1px solid #eeeaf5;border-radius:12px;font-size:11px}.andb-goal-row>span{grid-area:district;font-weight:800}.andb-goal-row>strong{grid-area:gain}.andb-goal-row>.andb-goal-track{grid-area:track}.andb-goal-row>small{grid-area:meta}.andb-goal-row>small::before{content:'Meta ';color:#8a94a6;font-weight:500}.andb-goal-row>em{grid-area:reach}.andb-goal-track{height:14px}.andb-goal-row strong,.andb-goal-row em,.andb-goal-row small{text-align:right}
  .andb-table-wrap{margin:0 -3px;max-width:calc(100vw - 38px);border-top:1px solid #f0ecf6}.andb-table-wrap table{min-width:1080px;font-size:10px}.andb-table-wrap th,.andb-table-wrap td{padding:8px 7px;white-space:nowrap}
}
@media(max-width:390px){.andb-insights{grid-template-columns:1fr}.andb-controls{grid-template-columns:1fr}.andb-tabs,.andb-period,.andb-week-nav{grid-column:1}.andb-day-filter,.andb-filter,.andb-export,.andb-week-nav{width:100%}.andb-kpis article{flex-basis:88%;min-height:138px}.andb-kpis::after{flex-basis:12%}.andb-chart .andb-svg{width:680px;min-width:680px}}
</style>
<script>
(function(){
const root=document.querySelector('[data-dashboard-addnetas]');if(!root)return;
const sparkHistory=<?php echo wp_json_encode($spark_history); ?>;
const history=<?php echo wp_json_encode($main_history); ?>;
const fmt=new Intl.NumberFormat('es-MX');
const sel=root.querySelector('[data-dashboard-district]');if(sel)sel.addEventListener('change',()=>{const u=new URL(window.location.href);if(sel.value)u.searchParams.set('add_district',sel.value);else u.searchParams.delete('add_district');window.location.href=u.toString();});
const day=root.querySelector('[data-dashboard-day]');if(day)day.addEventListener('change',()=>{const u=new URL(window.location.href);if(day.value)u.searchParams.set('add_day',day.value);else u.searchParams.delete('add_day');window.location.href=u.toString();});const dayClear=root.querySelector('[data-dashboard-day-clear]');if(dayClear)dayClear.addEventListener('click',()=>{const u=new URL(window.location.href);u.searchParams.delete('add_day');window.location.href=u.toString();});
const palette={sales:'#7c3aed',installations:'#1687c9',cancellations:'#ff4d82',gain:'#2563eb',reach:'#17b26a'};
function smoothPath(points){let d='';points.forEach((p,i)=>{if(i===0)d='M '+p[0]+' '+p[1];else{const a=points[i-1],mx=(a[0]+p[0])/2;d+=' C '+mx+' '+a[1]+', '+mx+' '+p[1]+', '+p[0]+' '+p[1];}});return d;}
function renderSpark(metric){const svg=root.querySelector('[data-spark="'+metric+'"]');if(!svg||!sparkHistory.length)return;const W=190,H=52,PX=4,PY=5;const vals=sparkHistory.map(r=>Number(r[metric]||0));let min=Math.min(...vals),max=Math.max(...vals);if(min===max){min-=1;max+=1;}if(metric!=='gain')min=Math.min(0,min);const span=Math.max(.0001,max-min),step=(W-PX*2)/Math.max(1,vals.length-1);const pts=vals.map((v,i)=>[PX+i*step,PY+(max-v)/span*(H-PY*2)]);const path=smoothPath(pts),color=palette[metric];let h='<line class="andb-spark-grid" x1="0" y1="'+(H-7)+'" x2="'+W+'" y2="'+(H-7)+'"/>';if(min<0&&max>0){const zy=PY+(max/span)*(H-PY*2);h+='<line class="andb-spark-zero" x1="0" y1="'+zy+'" x2="'+W+'" y2="'+zy+'"/>';}h+='<path class="andb-spark-area" d="'+path+' L '+pts[pts.length-1][0]+' '+(H-PY)+' L '+pts[0][0]+' '+(H-PY)+' Z" fill="'+color+'"/><path class="andb-spark-line" d="'+path+'" stroke="'+color+'"/>';pts.forEach((p,i)=>{h+='<circle class="andb-spark-dot" cx="'+p[0]+'" cy="'+p[1]+'" r="2.2" fill="'+color+'"><title>'+sparkHistory[i].label+' · '+fmt.format(vals[i])+'</title></circle>';});svg.innerHTML=h;}
['sales','installations','cancellations','gain','reach'].forEach(renderSpark);
function initKpiCarousel(){
  const track=root.querySelector('[data-kpi-carousel]'),nav=root.querySelector('[data-kpi-carousel-nav]');
  if(!track||!nav||track.dataset.kpiCarouselReady==='1')return;
  const slides=Array.from(track.querySelectorAll('[data-kpi-slide]')),dots=Array.from(nav.querySelectorAll('[data-kpi-dot]')),prev=nav.querySelector('[data-kpi-prev]'),next=nav.querySelector('[data-kpi-next]');
  if(!slides.length)return;
  track.dataset.kpiCarouselReady='1';
  let active=0,ticking=false;
  const isMobile=()=>window.matchMedia('(max-width: 700px)').matches;
  const clampIndex=i=>Math.max(0,Math.min(slides.length-1,i));
  const setActive=i=>{active=clampIndex(i);dots.forEach((dot,idx)=>{const on=idx===active;dot.classList.toggle('is-active',on);if(on)dot.setAttribute('aria-current','true');else dot.removeAttribute('aria-current');});if(prev)prev.disabled=active===0;if(next)next.disabled=active===slides.length-1;};
  const goTo=(i,behavior='smooth')=>{const idx=clampIndex(i);if(!isMobile()){setActive(idx);return;}track.scrollTo({left:slides[idx].offsetLeft,behavior});setActive(idx);};
  const detectActive=()=>{if(!isMobile()){setActive(0);return;}const box=track.getBoundingClientRect();let best=0,bestDistance=Infinity;slides.forEach((slide,idx)=>{const distance=Math.abs(slide.getBoundingClientRect().left-box.left);if(distance<bestDistance){bestDistance=distance;best=idx;}});setActive(best);};
  track.addEventListener('scroll',()=>{if(ticking)return;ticking=true;window.requestAnimationFrame(()=>{detectActive();ticking=false;});},{passive:true});
  if(prev)prev.addEventListener('click',()=>goTo(active-1));
  if(next)next.addEventListener('click',()=>goTo(active+1));
  dots.forEach((dot,idx)=>dot.addEventListener('click',()=>goTo(idx)));
  track.addEventListener('keydown',event=>{if(!isMobile())return;if(event.key==='ArrowLeft'){event.preventDefault();goTo(active-1);}if(event.key==='ArrowRight'){event.preventDefault();goTo(active+1);}});
  window.addEventListener('resize',()=>window.requestAnimationFrame(detectActive),{passive:true});
  setActive(0);
}
initKpiCarousel();
function mainChart(){const el=root.querySelector('[data-main-chart]');if(!el||!history.length)return;const W=1120,H=340,L=58,R=62,T=38,B=46;const maxBars=Math.max(1,...history.flatMap(p=>[Number(p.sales||0),Number(p.installations||0),Number(p.cancellations||0)]));let minGain=Math.min(0,...history.map(p=>Number(p.gain||0))),maxGain=Math.max(1,...history.map(p=>Number(p.gain||0)));if(minGain===maxGain){minGain-=1;maxGain+=1;}const gainSpan=maxGain-minGain;const y=v=>T+(maxBars-v)/maxBars*(H-T-B),gy=v=>T+(maxGain-v)/gainSpan*(H-T-B),step=(W-L-R)/history.length,bw=Math.min(25,step*.15),gap=5;let s='<svg class="andb-svg" viewBox="0 0 '+W+' '+H+'">';for(let i=0;i<=4;i++){const v=maxBars*i/4,yy=y(v);s+='<line class="andb-axis" x1="'+L+'" y1="'+yy+'" x2="'+(W-R)+'" y2="'+yy+'"/><text class="andb-label" x="'+(L-8)+'" y="'+(yy+4)+'" text-anchor="end">'+fmt.format(Math.round(v))+'</text>';}if(minGain<0&&maxGain>0){const zy=gy(0);s+='<line class="andb-zero-axis" x1="'+L+'" y1="'+zy+'" x2="'+(W-R)+'" y2="'+zy+'"/>';}for(let i=0;i<=4;i++){const v=minGain+(gainSpan*i/4),yy=gy(v);s+='<text class="andb-label" x="'+(W-R+8)+'" y="'+(yy+4)+'">'+fmt.format(Math.round(v))+'</text>';}const pts=[];history.forEach((p,i)=>{const x=L+step*(i+.5);if(p.selected)s+='<rect class="andb-selected" x="'+(x-step*.43)+'" y="'+T+'" width="'+(step*.86)+'" height="'+(H-T-B)+'" rx="9"/>';const sy=y(p.sales),iy=y(p.installations),cy=y(p.cancellations),base=y(0),salesX=x-(bw*1.5)-gap,installX=x-(bw*.5),cancelX=x+(bw*.5)+gap;s+='<rect class="andb-bar-sales" x="'+salesX+'" y="'+sy+'" width="'+bw+'" height="'+Math.max(0,base-sy)+'" rx="4"><title>'+p.label+' · Ventas '+fmt.format(p.sales)+'</title></rect><rect class="andb-bar-install" x="'+installX+'" y="'+iy+'" width="'+bw+'" height="'+Math.max(0,base-iy)+'" rx="4"><title>'+p.label+' · Instalaciones '+fmt.format(p.installations)+'</title></rect><rect class="andb-bar-cancel" x="'+cancelX+'" y="'+cy+'" width="'+bw+'" height="'+Math.max(0,base-cy)+'" rx="4"><title>'+p.label+' · Cancelaciones '+fmt.format(p.cancellations)+'</title></rect>';s+='<text class="andb-value" x="'+(salesX+bw/2)+'" y="'+Math.max(12,sy-7)+'" text-anchor="middle">'+fmt.format(p.sales)+'</text><text class="andb-value" x="'+(installX+bw/2)+'" y="'+Math.max(12,iy-7)+'" text-anchor="middle">'+fmt.format(p.installations)+'</text><text class="andb-value" x="'+(cancelX+bw/2)+'" y="'+Math.max(12,cy-7)+'" text-anchor="middle">'+fmt.format(p.cancellations)+'</text>';pts.push([x,gy(p.gain),p]);s+='<text class="andb-label" x="'+x+'" y="'+(H-17)+'" text-anchor="middle">'+p.label+'</text>';});s+='<path class="andb-gain-line" d="'+smoothPath(pts)+'"/>';pts.forEach(pt=>{const p=pt[2];s+='<circle class="andb-gain-dot" cx="'+pt[0]+'" cy="'+pt[1]+'" r="4.5"><title>'+p.label+' · Ganancia '+fmt.format(p.gain)+'</title></circle><text class="andb-value" x="'+pt[0]+'" y="'+Math.max(12,pt[1]-10)+'" text-anchor="middle">'+fmt.format(p.gain)+'</text>';});s+='</svg><div class="andb-legend"><span><i style="background:#7c3aed"></i>Ventas</span><span><i style="background:#1687c9"></i>Instalaciones</span><span><i style="background:#ff4d82"></i>Cancelaciones</span><span><i style="background:#2563eb"></i>Ganancia / Add Netas</span></div>';el.innerHTML=s;}
mainChart();
function syncMainChartScroll(){const wrap=root.querySelector('[data-main-chart]');const rail=root.querySelector('[data-chart-scrollbar]');const nav=root.querySelector('[data-main-chart-mobile-nav]');if(!wrap||!rail)return;const input=rail.querySelector('input');if(!input)return;const navLabel=nav?nav.querySelector('[data-main-chart-position]'):null;const prevBtn=nav?nav.querySelector('[data-main-chart-dir="left"]'):null;const nextBtn=nav?nav.querySelector('[data-main-chart-dir="right"]'):null;let ticking=false;const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));const periodStep=()=>{const svg=wrap.querySelector('.andb-svg');if(!svg||!history.length)return Math.max(120,wrap.clientWidth*.65);return Math.max(90,svg.getBoundingClientRect().width/history.length);};const updateNav=()=>{const max=Math.max(0,Math.round(wrap.scrollWidth-wrap.clientWidth));if(prevBtn)prevBtn.disabled=wrap.scrollLeft<=2;if(nextBtn)nextBtn.disabled=wrap.scrollLeft>=max-2;if(navLabel){if(max<=0){navLabel.textContent='Todos los periodos visibles';return;}const step=periodStep(),center=wrap.scrollLeft+wrap.clientWidth/2,index=clamp(Math.round(center/step-.5),0,Math.max(0,history.length-1));navLabel.textContent=(history[index]?.label||'Periodo')+' · usa flechas o desliza';}};const refresh=(jumpToSelection=false)=>{const max=Math.max(0,Math.round(wrap.scrollWidth-wrap.clientWidth));if(max<=0){rail.hidden=true;input.max=0;input.value=0;updateNav();return;}rail.hidden=false;input.max=max;if(jumpToSelection){const selected=wrap.querySelector('.andb-selected');let target=max;if(selected){const svg=wrap.querySelector('.andb-svg');const vb=(svg&&svg.viewBox&&svg.viewBox.baseVal)?svg.viewBox.baseVal:null;const svgWidth=svg?svg.getBoundingClientRect().width:0;const scale=vb&&vb.width?svgWidth/vb.width:1;const sx=parseFloat(selected.getAttribute('x')||'0')*scale;const sw=parseFloat(selected.getAttribute('width')||'0')*scale;target=clamp(Math.round(sx+sw-wrap.clientWidth+24),0,max);}wrap.scrollLeft=target;}input.value=Math.round(wrap.scrollLeft);updateNav();};const onWrapScroll=()=>{if(ticking)return;ticking=true;window.requestAnimationFrame(()=>{input.value=Math.round(wrap.scrollLeft);updateNav();ticking=false;});};const onInput=()=>{wrap.scrollLeft=Number(input.value||0);updateNav();};input.addEventListener('input',onInput);wrap.addEventListener('scroll',onWrapScroll,{passive:true});if(nav)nav.querySelectorAll('[data-main-chart-dir]').forEach(btn=>btn.addEventListener('click',()=>{const dir=btn.dataset.mainChartDir==='left'?-1:1;wrap.scrollBy({left:dir*periodStep(),behavior:'smooth'});}));window.addEventListener('resize',()=>window.requestAnimationFrame(()=>refresh(false)));window.requestAnimationFrame(()=>refresh(window.matchMedia('(max-width: 700px)').matches));}
syncMainChartScroll();
})();
</script>
<?php return ob_get_clean();
    }

    private static function real_rows(array $rows) {
        foreach ($rows as $d => $row) {
            $install = max(0, (int)($row['instalaciones_reales'] ?? 0));
            $vol = max(0, (int)($row['voluntarias_reales'] ?? 0));
            $inv = max(0, (int)($row['involuntarias_reales'] ?? 0));
            $trp = min($inv, max(0, (int)($row['traslado_p_reales'] ?? 0)));
            $trf = max(0, (int)($row['traslado_f'] ?? 0));
            // Descomposición real conciliada: INV = ORG + P - F => ORG = INV - P + F.
            $org = max(0, $inv - $trp + $trf);
            $rows[$d]['instalaciones'] = $install;
            $rows[$d]['instalaciones_fcst'] = 0;
            $rows[$d]['voluntarias'] = $vol;
            $rows[$d]['voluntarias_fcst'] = 0;
            $rows[$d]['involuntarias'] = $inv;
            $rows[$d]['involuntarias_fcst'] = 0;
            $rows[$d]['organicas'] = $org;
            $rows[$d]['organicas_reales'] = $org;
            $rows[$d]['organicas_fcst'] = 0;
            $rows[$d]['organicas_vencidas'] = 0;
            $rows[$d]['traslado_p'] = $trp;
            $rows[$d]['traslado_p_fcst'] = 0;
            $rows[$d]['traslado_p_vencidos'] = 0;
            // Traslado F es el ajuste oficial Corporativo usado para descomponer las involuntarias.
            $rows[$d]['traslado_f'] = $trf;
            $rows[$d]['cancelaciones_reales'] = $vol + $inv;
            $rows[$d]['cancelaciones_fcst'] = 0;
            $rows[$d]['cancelaciones'] = $vol + $inv;
            $rows[$d]['add_netas'] = $install - ($vol + $inv);
        }
        return $rows;
    }

    public static function shortcode($atts = array()) {
        if (!is_user_logged_in() || !self::can_view()) return '<section class="krn-core-public-card"><strong>Acceso restringido</strong></section>';
        $atts = is_array($atts) ? $atts : array();
        $view = sanitize_key((string)($atts['view'] ?? 'fcst')) === 'real' ? 'real' : 'fcst';
        $is_real = $view === 'real';

        // REAL ADD NETAS: los lunes, la lectura operativa por defecto es la semana cerrada anterior.
        // Si el usuario navega explícitamente a otra semana histórica, se respeta su selección.
        $ctx_args = array();
        if ($is_real) {
            $tz = wp_timezone();
            $now = new DateTime('now', $tz);
            $requested_mode = sanitize_key(wp_unslash((string)($_GET['add_mode'] ?? 'semanal')));
            if ($requested_mode !== 'mensual' && (int)$now->format('N') === 1) {
                $active_year_now = (int)$now->format('o');
                $active_week_now = (int)$now->format('W');
                $requested_year = isset($_GET['add_year']) ? (int)$_GET['add_year'] : $active_year_now;
                $requested_week = isset($_GET['add_week']) ? (int)$_GET['add_week'] : $active_week_now;
                if ($requested_year === $active_year_now && $requested_week === $active_week_now) {
                    $previous_real = clone $now;
                    $previous_real->modify('-7 days');
                    $ctx_args = array(
                        'add_mode' => 'semanal',
                        'add_year' => (int)$previous_real->format('o'),
                        'add_week' => (int)$previous_real->format('W'),
                    );
                }
            }
        }

        $ctx=self::context($ctx_args); $data=self::data($ctx); $rows=$data['rows'];
        if ($is_real) { $rows = self::real_rows($rows); }
        $districts=tx_an_allowed_districts();
        $totals=array('instalaciones'=>0,'cancelaciones'=>0,'voluntarias'=>0,'involuntarias'=>0,'organicas'=>0,'organicas_vencidas'=>0,'traslado_p'=>0,'traslado_p_vencidos'=>0,'traslado_p_programado'=>0,'traslado_f'=>0,'objetivo'=>0,'add_netas'=>0);
        foreach($districts as $d){
            // Regla v1.8.16: Involuntarias es el total rector; Orgánicas ya llegó deducida desde data()/real_rows().
            $rows[$d]['involuntarias']=max(0,(int)($rows[$d]['involuntarias']??0));
            $rows[$d]['cancelaciones']=(int)$rows[$d]['voluntarias']+(int)$rows[$d]['involuntarias'];
            $rows[$d]['add_netas']=(int)$rows[$d]['instalaciones']-(int)$rows[$d]['cancelaciones'];
            foreach($totals as $k=>$v)$totals[$k]+=(int)($rows[$d][$k]??0);
        }
        $urls=self::period_urls($ctx);
        if ($is_real && $ctx['mode']==='semanal') {
            $tz = wp_timezone();
            $now = new DateTime('now', $tz);
            if ((int)$now->format('N') === 1) {
                $previous_real = clone $now;
                $previous_real->modify('-7 days');
                $base_real = remove_query_arg(array('add_mode','add_year','add_week','add_month'));
                $urls['active'] = add_query_arg(array(
                    'add_mode'=>'semanal',
                    'add_year'=>(int)$previous_real->format('o'),
                    'add_week'=>(int)$previous_real->format('W'),
                ), $base_real);
            }
        }
        $editable=!$is_real&&self::can_edit()&&$ctx['mode']==='semanal'; $instance='addnetas-'.wp_rand(1000,999999);
        $report_args=array('action'=>'krn_addnetas_executive_pdf','add_mode'=>$ctx['mode'],'add_view'=>$view); if($ctx['mode']==='semanal'){$report_args['add_year']=$ctx['year'];$report_args['add_week']=$ctx['week'];}else{$report_args['add_month']=$ctx['month'];} $report_url=wp_nonce_url(add_query_arg($report_args,admin_url('admin-post.php')),'krn_addnetas_executive_pdf');
        $reach=$totals['objetivo']>0?round(($totals['add_netas']/$totals['objetivo'])*100,1):0;
        $using_pronostico=($data['forecast_source']??'')==='pronostico_sac_cobranza';
        $chart_rows=array();
        foreach($districts as $d){
            $chart_rows[$d]=array(
                'installations'=>(int)$rows[$d]['instalaciones'],
                'installations_real'=>(int)$rows[$d]['instalaciones_reales'],
                'voluntary'=>(int)$rows[$d]['voluntarias'],
                'voluntary_real'=>(int)$rows[$d]['voluntarias_reales'],
                'organic'=>(int)$rows[$d]['organicas'],
                'transfer_p'=>(int)$rows[$d]['traslado_p'],
                'transfer_f'=>(int)$rows[$d]['traslado_f'],
                'involuntary'=>(int)$rows[$d]['involuntarias'],
                'cancellations'=>(int)$rows[$d]['cancelaciones'],
                'add_netas'=>(int)$rows[$d]['add_netas'],
            );
        }
        $history=is_array($data['history']??null)?$data['history']:array();
        ob_start(); ?>
<section id="<?php echo esc_attr($instance); ?>" class="addnetas-shell" data-addnetas-module><span id="<?php echo esc_attr($instance); ?>-top" class="addnetas-anchor" aria-hidden="true"></span>
<header class="addnetas-hero"><div><span><?php echo esc_html(tx_an_scope_label()); ?> · <?php echo $is_real?'REAL ADD NETAS':'FCST ADD NETAS'; ?></span><h1><?php echo esc_html($ctx['label']); ?></h1><p><?php echo $is_real?'Lectura real acumulada del periodo. Los lunes inicia automáticamente en la última semana cerrada (Semana Actual − 1); el resto de la navegación histórica permanece disponible.':'Pantalla de proyección basada en datos reales y sugerencias SAC/Cobranza. Los componentes pueden ajustarse antes de guardar.'; ?></p></div><div class="addnetas-period"><strong><?php echo esc_html($is_real?($data['real_days'].' días con corte real'):($data['projected_days'].' días proyectados')); ?></strong><small><?php echo esc_html($ctx['start'].' — '.$ctx['end']); ?></small></div></header>
<nav class="addnetas-nav"><a class="<?php echo $ctx['mode']==='semanal'?'is-active':''; ?>" href="<?php echo esc_url($urls['weekly']); ?>">Semanal</a><a class="<?php echo $ctx['mode']==='mensual'?'is-active':''; ?>" href="<?php echo esc_url($urls['monthly']); ?>">Mensual</a><a href="<?php echo esc_url($urls['previous']); ?>">← Anterior</a><a class="is-current" href="<?php echo esc_url($urls['active']); ?>">Periodo actual</a><a class="addnetas-pdf" href="<?php echo esc_url($report_url); ?>" target="_blank" rel="noopener" data-role="executive-pdf">Generar PDF ejecutivo</a></nav>
<div class="addnetas-mobile-jump" aria-label="Navegación móvil"><a href="#<?php echo esc_attr($instance); ?>-matrix">Matriz</a><a href="#<?php echo esc_attr($instance); ?>-charts">Gráficas</a><a href="#<?php echo esc_attr($instance); ?>-top">Inicio</a></div>
<div class="addnetas-kpis"><article><span><?php echo $is_real?'Instalaciones reales':'Instalaciones esperadas'; ?></span><strong data-kpi="instalaciones"><?php echo number_format_i18n($totals['instalaciones']); ?></strong><small><?php echo $is_real?'Acumulado real':'Real + FCST'; ?></small></article><article><span><?php echo $is_real?'Cancelaciones reales':'Cancelaciones esperadas'; ?></span><strong data-kpi="cancelaciones"><?php echo number_format_i18n($totals['cancelaciones']); ?></strong><small>Voluntarias + Involuntarias</small></article><article><span><?php echo $is_real?'ADD Netas real':'ADD Netas esperado'; ?></span><strong data-kpi="add_netas"><?php echo number_format_i18n($totals['add_netas']); ?></strong><small>Instalaciones − Cancelaciones</small></article><article class="reach <?php echo $reach>=100?'green':($reach>=80?'yellow':'red'); ?>"><span>% Alcance <?php echo tx_an_scope_label()==="REGIÓN SUR"?"regional":"distrital"; ?></span><strong data-kpi="alcance"><?php echo esc_html(number_format_i18n($reach,1).'%'); ?></strong><small><?php echo $is_real?'ADD Netas real / Objetivo':'ADD Netas / Objetivo'; ?></small></article></div>
<?php if(!empty($data['error'])):?><div class="addnetas-alert"><?php echo esc_html($data['error']); ?></div><?php endif; ?>
<?php if(!empty($data['warnings'])):?><div class="addnetas-alert addnetas-warning"><?php echo esc_html(implode(' · ',array_unique($data['warnings']))); ?></div><?php endif; ?>
<div class="addnetas-matrix-mobile-nav" aria-label="Navegación por distrito de la matriz">
  <button type="button" class="addnetas-matrix-nav-btn" data-matrix-prev aria-label="Distrito anterior">‹</button>
  <label class="addnetas-matrix-select-wrap">
    <span>Distrito</span>
    <select data-matrix-select aria-label="Seleccionar distrito">
      <?php foreach($districts as $d):?><option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option><?php endforeach; ?>
      <option value="REGIÓN SUR"><?php echo esc_html(tx_an_total_label()); ?></option>
    </select>
  </label>
  <button type="button" class="addnetas-matrix-nav-btn" data-matrix-next aria-label="Distrito siguiente">›</button>
  <small data-matrix-position aria-live="polite">1 / <?php echo esc_html(count($districts)+1); ?></small>
</div>
<div id="<?php echo esc_attr($instance); ?>-matrix" class="addnetas-table-wrap" tabindex="0" aria-label="<?php echo $is_real?'Matriz real por distrito':'Matriz de proyección por distrito'; ?>"><table><thead><tr><th>Indicador</th><?php foreach($districts as $d):?><th><?php echo esc_html($d); ?></th><?php endforeach;?><th><?php echo esc_html(tx_an_total_label()); ?></th></tr></thead><tbody>
<?php
$input_cell=function($d,$field,$value,$negative=false,$real=null,$fcst=null,$extra='',$director_fcst=null) use($editable,$is_real){
    $detail = ($real !== null && $fcst !== null)
        ? '<small class="fcst-detail">'.($is_real
            ? 'Real '.esc_html(number_format_i18n($real))
            : ('Real '.esc_html(number_format_i18n($real)).' + FCST '.esc_html(number_format_i18n($fcst)).' = '.esc_html(number_format_i18n(((int)$real)+((int)$fcst))))).'</small>'
        : '';
    $hint = $extra !== '' ? '<small class="fcst-hint">'.esc_html($extra).'</small>' : '';
    $director = (!$is_real && $field==='installations')
        ? '<small class="fcst-director">FCST Director: '.($director_fcst===null?'N/D':esc_html(number_format_i18n($director_fcst))).'</small>'
        : '';
    if($editable){echo '<td><div class="editable-cell"><span class="input-wrap">'.($negative?'<b>−</b>':'').'<input class="addnetas-input" type="number" min="0" step="1" value="'.esc_attr($value).'" data-district="'.esc_attr($d).'" data-field="'.esc_attr($field).'"></span>'.$detail.$director.$hint.'</div></td>';}
    else echo '<td><strong>'.($negative&&$value?'−':'').esc_html(number_format_i18n($value)).'</strong>'.$detail.$director.$hint.'</td>';
};
$derived_cell=function($d,$value,$negative=false,$extra='') use($is_real){
    $hint = $extra !== '' ? '<small class="fcst-hint">'.esc_html($extra).'</small>' : '';
    if($is_real){
        echo '<td><strong>'.($negative&&$value?'−':'').esc_html(number_format_i18n($value)).'</strong>'.$hint.'</td>';
        return;
    }
    echo '<td><div class="editable-cell derived-cell"><span class="input-wrap derived-wrap">'.($negative?'<b>−</b>':'').'<input class="addnetas-derived" type="number" min="0" step="1" value="'.esc_attr($value).'" data-derived-organic="'.esc_attr($d).'" readonly disabled aria-label="Orgánicas calculadas automáticamente"></span>'.$hint.'</div></td>';
};
$fixed_cell=function($value,$negative=false,$real=null,$fcst=null,$extra=''){
    echo '<td><strong>'.($negative&&$value?'−':'').esc_html(number_format_i18n($value)).'</strong>';
    if($real!==null&&$fcst!==null)echo '<small class="fcst-detail">Real '.esc_html(number_format_i18n($real)).' + FCST '.esc_html(number_format_i18n($fcst)).' = '.esc_html(number_format_i18n(((int)$real)+((int)$fcst))).'</small>';
    if($extra!=='')echo '<small class="fcst-risk">'.esc_html($extra).'</small>';
    echo '</td>';
};
?>
<?php $director_values=array_filter(array_map(function($r){return $r['director_fcst']??null;},$rows),function($v){return $v!==null;}); $director_total=array_sum($director_values); $director_count=count($director_values); ?>
<?php $region_inst_real=array_sum(array_column($rows,'instalaciones_reales')); $region_inst_fcst=array_sum(array_column($rows,'instalaciones_fcst')); ?>
<tr class="row-inst"><th>INSTALACIONES</th><?php foreach($districts as $d)$input_cell($d,'installations',$rows[$d]['instalaciones'],false,$rows[$d]['instalaciones_reales'],$rows[$d]['instalaciones_fcst'],'',$rows[$d]['director_fcst']??null);?><td data-region="installations"><strong><?php echo number_format_i18n($totals['instalaciones']); ?></strong><small class="fcst-detail"><?php echo $is_real?'Real '.number_format_i18n($region_inst_real):('Real '.number_format_i18n($region_inst_real).' + FCST '.number_format_i18n($region_inst_fcst).' = '.number_format_i18n($region_inst_real+$region_inst_fcst)); ?></small><?php if(!$is_real):?><small class="fcst-director">FCST Directores: <?php echo $director_count?number_format_i18n($director_total):'N/D'; ?><?php echo ($director_count>0&&$director_count<count($districts))?' · '.$director_count.'/'.count($districts).' distritos':''; ?></small><?php endif;?></td></tr>
<?php $region_cancel_real=array_sum(array_column($rows,'cancelaciones_reales')); $region_cancel_fcst=array_sum(array_column($rows,'cancelaciones_fcst')); ?>
<tr class="row-cancel group-parent"><th>CANCELACIONES<small>Voluntarias + Involuntarias</small></th><?php foreach($districts as $d): $cancel_detail_total=(int)$rows[$d]['cancelaciones_reales']+(int)$rows[$d]['cancelaciones_fcst']; ?><td data-cancel="<?php echo esc_attr($d); ?>"><strong>−<?php echo number_format_i18n($rows[$d]['cancelaciones']); ?></strong><small class="fcst-detail"><?php echo $is_real?'Real '.number_format_i18n($rows[$d]['cancelaciones_reales']):('Real '.number_format_i18n($rows[$d]['cancelaciones_reales']).' + FCST '.number_format_i18n($rows[$d]['cancelaciones_fcst']).' = '.number_format_i18n($cancel_detail_total)); ?></small></td><?php endforeach;?><td data-region="cancelaciones"><strong>−<?php echo number_format_i18n($totals['cancelaciones']); ?></strong><small class="fcst-detail"><?php echo $is_real?'Real '.number_format_i18n($region_cancel_real):('Real '.number_format_i18n($region_cancel_real).' + FCST '.number_format_i18n($region_cancel_fcst).' = '.number_format_i18n($region_cancel_real+$region_cancel_fcst)); ?></small></td></tr>
<?php $region_vol_real=array_sum(array_column($rows,'voluntarias_reales')); $region_vol_fcst=array_sum(array_column($rows,'voluntarias_fcst')); ?>
<tr class="group-child"><th>VOLUNTARIAS</th><?php foreach($districts as $d)$input_cell($d,'voluntary',$rows[$d]['voluntarias'],true,$rows[$d]['voluntarias_reales'],$rows[$d]['voluntarias_fcst']);?><td data-region="voluntary"><strong>−<?php echo number_format_i18n($totals['voluntarias']); ?></strong><small class="fcst-detail"><?php echo $is_real?'Real '.number_format_i18n($region_vol_real):('Real '.number_format_i18n($region_vol_real).' + FCST '.number_format_i18n($region_vol_fcst).' = '.number_format_i18n($region_vol_real+$region_vol_fcst)); ?></small></td></tr>
<?php $region_inv_real=array_sum(array_column($rows,'involuntarias_reales')); $region_inv_fcst=array_sum(array_column($rows,'involuntarias_fcst')); ?>
<tr class="row-invol group-child group-parent-secondary"><th>INVOLUNTARIAS<small><?php echo $is_real?'Orgánicas + Traslado P − Traslado F':'Editable · Orgánicas + Traslado P − Traslado F'; ?></small></th><?php foreach($districts as $d): $inv_detail_total=(int)$rows[$d]['involuntarias_reales']+(int)$rows[$d]['involuntarias_fcst']; ?><td data-invol="<?php echo esc_attr($d); ?>"><?php if($editable): ?><div class="editable-cell"><span class="input-wrap"><b>−</b><input class="addnetas-input" type="number" min="0" step="1" value="<?php echo esc_attr($rows[$d]['involuntarias']); ?>" data-district="<?php echo esc_attr($d); ?>" data-field="involuntary"></span><small class="fcst-detail" data-invol-detail="<?php echo esc_attr($d); ?>" data-real="<?php echo esc_attr((int)$rows[$d]['involuntarias_reales']); ?>">Real <?php echo number_format_i18n($rows[$d]['involuntarias_reales']); ?> + FCST <?php echo number_format_i18n(max(0,(int)$rows[$d]['involuntarias']-(int)$rows[$d]['involuntarias_reales'])); ?> = <?php echo number_format_i18n($rows[$d]['involuntarias']); ?></small></div><?php else: ?><strong>−<?php echo number_format_i18n($rows[$d]['involuntarias']); ?></strong><small class="fcst-detail"><?php echo $is_real?'Real '.number_format_i18n($rows[$d]['involuntarias_reales']):('Real '.number_format_i18n($rows[$d]['involuntarias_reales']).' + FCST '.number_format_i18n($rows[$d]['involuntarias_fcst']).' = '.number_format_i18n($inv_detail_total)); ?></small><?php endif; ?></td><?php endforeach;?><td data-region="involuntary"><strong>−<?php echo number_format_i18n($totals['involuntarias']); ?></strong><small class="fcst-detail" data-region-invol-detail data-real="<?php echo esc_attr((int)$region_inv_real); ?>"><?php echo $is_real?'Real '.number_format_i18n($region_inv_real):('Real '.number_format_i18n($region_inv_real).' + FCST '.number_format_i18n(max(0,(int)$totals['involuntarias']-(int)$region_inv_real)).' = '.number_format_i18n($totals['involuntarias'])); ?></small></td></tr>
<tr class="group-grandchild row-organic"><th>ORGÁNICAS<small><?php echo $is_real?'Deducidas: Involuntarias − Traslado P + Traslado F':'Calculadas automáticamente: Involuntarias − Traslado P + Traslado F'; ?></small></th><?php foreach($districts as $d)$derived_cell($d,$rows[$d]['organicas'],true,$is_real?'Deducción conciliada':'No editable · se ajusta con P y F');?><td data-region="organic"><strong>−<?php echo number_format_i18n($totals['organicas']); ?></strong><small class="fcst-hint"><?php echo $is_real?'Deducción conciliada':'Cálculo automático'; ?></small></td></tr>
<tr class="group-grandchild row-transfer"><th>TRASLADOS P<small><?php echo $is_real?'Materializados reales identificados contra Pronóstico SAC':'Semana activa · editable · requiere confirmación operativa'; ?></small></th><?php foreach($districts as $d){$input_cell($d,'transfer_p',$rows[$d]['traslado_p'],true,null,null,$is_real?'Materializado real':'Programado SAC: '.number_format_i18n($rows[$d]['traslado_p_programado']).' · pendiente de confirmación');}?><td data-region="transfer_p"><strong>−<?php echo number_format_i18n($totals['traslado_p']); ?></strong><small class="fcst-hint"><?php echo $is_real?'Materializados reales':('Programado SAC: '.number_format_i18n($totals['traslado_p_programado'])); ?></small></td></tr>
<tr class="row-transfer-risk"><th><small><?php echo $is_real?'No aplica en lectura real':'Vencidas no materializadas'; ?></small></th><?php foreach($districts as $d):?><td><small class="fcst-risk">−<?php echo number_format_i18n($rows[$d]['traslado_p_vencidos']); ?></small></td><?php endforeach;?><td><small class="fcst-risk">−<?php echo number_format_i18n($totals['traslado_p_vencidos']); ?></small></td></tr>
<tr class="group-grandchild row-transfer-f"><th>TRASLADOS F<small><?php echo $is_real?'Dato Corporativo aplicado a la descomposición real':'Dato Corporativo · inicia en 0 · editable'; ?></small></th><?php foreach($districts as $d)$input_cell($d,'transfer_f',$rows[$d]['traslado_f'],false,null,null,$is_real?'Dato Corporativo':'Captura oficial Corporativo');?><td data-region="transfer_f"><strong><?php echo number_format_i18n($totals['traslado_f']); ?></strong><small class="fcst-hint"><?php echo $is_real?'Dato Corporativo':'Captura Corporativo'; ?></small></td></tr>
<tr class="row-net"><th>ADD NETAS</th><?php foreach($districts as $d):?><td data-net="<?php echo esc_attr($d); ?>"><strong><?php echo number_format_i18n($rows[$d]['add_netas']); ?></strong></td><?php endforeach;?><td data-region="add_netas"><strong><?php echo number_format_i18n($totals['add_netas']); ?></strong></td></tr>
<tr class="row-objective"><th>OBJETIVO ADD NETAS</th><?php foreach($districts as $d)$input_cell($d,'objective',$rows[$d]['objetivo']);?><td data-region="objective"><strong><?php echo number_format_i18n($totals['objetivo']); ?></strong></td></tr>
<tr class="row-reach"><th>% ALCANCE</th><?php foreach($districts as $d): $pct=$rows[$d]['objetivo']>0?round(($rows[$d]['add_netas']/$rows[$d]['objetivo'])*100,1):0;?><td data-reach="<?php echo esc_attr($d); ?>" class="<?php echo $pct>=100?'green':($pct>=80?'yellow':'red'); ?>"><strong><?php echo number_format_i18n($pct,1); ?>%</strong></td><?php endforeach;?><td data-region="alcance" class="<?php echo $reach>=100?'green':($reach>=80?'yellow':'red'); ?>"><strong><?php echo number_format_i18n($reach,1); ?>%</strong></td></tr>
</tbody></table></div>
<?php if($editable):?><div class="save-projection-bar"><div class="save-projection-copy"><strong>Proyección semanal</strong><small data-role="status">Los campos editables se guardan al salir; este botón confirma la proyección completa.</small></div><button type="button" class="save-projection-btn" data-role="save-all">Guardar proyección</button></div><?php endif;?>
<?php if($ctx['mode']==='semanal'): ?>
<section id="<?php echo esc_attr($instance); ?>-charts" class="addnetas-visuals" aria-label="<?php echo $is_real?'Evolución real semanal':'Evolución y proyección semanal'; ?>">
  <div class="addnetas-visuals-head">
    <div><span>LECTURA EJECUTIVA</span><h2><?php echo $is_real?'Evolución real · últimas 10 semanas':'Evolución y proyección · últimas 10 semanas'; ?></h2></div>
    <p><?php echo $is_real?'Las diez semanas se muestran con información real disponible; la semana seleccionada refleja únicamente el acumulado real al corte.':'Las nueve semanas anteriores muestran información real. La última semana corresponde al periodo seleccionado y se actualiza con el FCST editable de la tabla para comparar la proyección contra su comportamiento reciente.'; ?></p>
  </div>
  <div class="addnetas-chart-mobile-nav" aria-label="Navegación entre gráficas">
    <button type="button" class="addnetas-chart-nav-btn" data-chart-prev aria-label="Gráfica anterior">‹</button>
    <label class="addnetas-chart-select-wrap">
      <span>Gráfica</span>
      <select data-chart-select aria-label="Seleccionar gráfica">
        <option value="installations">Instalaciones</option>
        <option value="cancellations">Cancelaciones</option>
        <option value="voluntary">Voluntarias</option>
        <option value="involuntary">Involuntarias</option>
        <option value="add_netas">Adiciones Netas</option>
      </select>
    </label>
    <button type="button" class="addnetas-chart-nav-btn" data-chart-next aria-label="Gráfica siguiente">›</button>
    <small data-chart-position aria-live="polite">1 / 5</small>
  </div>
  <div class="addnetas-chart-stack">
    <article class="addnetas-chart-card-wide is-mobile-active" data-chart-card="installations">
      <div class="addnetas-chart-title"><div><h3>Instalaciones</h3><small>Barras por distrito · línea <?php echo esc_html(tx_an_scope_label()); ?></small></div><span><?php echo $is_real?'Real histórico':'Real histórico → FCST actual'; ?></span></div>
      <div class="addnetas-chart" data-chart="installations"></div>
    </article>
    <article class="addnetas-chart-card-wide" data-chart-card="cancellations">
      <div class="addnetas-chart-title"><div><h3>Cancelaciones</h3><small>Barras por distrito · línea <?php echo esc_html(tx_an_scope_label()); ?></small></div><span>Voluntarias + Involuntarias</span></div>
      <div class="addnetas-chart" data-chart="cancellations"></div>
    </article>
    <article class="addnetas-chart-card-wide" data-chart-card="voluntary">
      <div class="addnetas-chart-title"><div><h3>Cancelaciones Voluntarias</h3><small>Evolución semanal por distrito</small></div><span>Últimas 10 semanas</span></div>
      <div class="addnetas-chart" data-chart="voluntary"></div>
    </article>
    <article class="addnetas-chart-card-wide" data-chart-card="involuntary">
      <div class="addnetas-chart-title"><div><h3>Cancelaciones Involuntarias</h3><small>Evolución semanal por distrito</small></div><span>Últimas 10 semanas</span></div>
      <div class="addnetas-chart" data-chart="involuntary"></div>
    </article>
    <article class="addnetas-chart-card-wide" data-chart-card="add_netas">
      <div class="addnetas-chart-title"><div><h3>Adiciones Netas</h3><small>Barras por distrito · línea <?php echo esc_html(tx_an_scope_label()); ?></small></div><span>Instalaciones − Cancelaciones</span></div>
      <div class="addnetas-chart" data-chart="add_netas"></div>
    </article>
  </div>
</section>
<?php endif; ?>
<?php if($is_real): ?>
<div class="addnetas-note"><strong>Lectura REAL:</strong> instalaciones y cancelaciones muestran únicamente registros materializados al corte. Traslado P/F se usa solo para descomponer las involuntarias; no modifica el total real de Qlik.</div>
<div class="addnetas-note"><strong>Involuntarias reales:</strong> se concilian contra Qlik. Su composición es Orgánicas + Traslado P − Traslado F; por ello Orgánicas se obtiene por deducción.</div>
<?php endif; ?>
</section>
<style>
.addnetas-shell{display:grid;gap:16px;color:var(--krn-text,#172033)}.addnetas-hero{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:26px;border:1px solid #e4d8ff;border-radius:26px;background:linear-gradient(135deg,#fff,#faf5ff)}.addnetas-hero span{font-size:11px;letter-spacing:.14em;color:#7d6997}.addnetas-hero h1{margin:7px 0;font-size:38px;font-weight:500;color:#29164c}.addnetas-hero p,.addnetas-period small{color:#748198}.addnetas-period{text-align:right}.addnetas-nav{display:flex;gap:8px;padding:8px;border:1px solid #e8ddfb;border-radius:18px}.addnetas-nav a{padding:10px 15px;border-radius:12px;text-decoration:none;color:#554663;background:#f6f2fa}.addnetas-nav .is-active,.addnetas-nav .is-current{color:#fff;background:linear-gradient(135deg,#7224d7,#dd009d)}.addnetas-nav .addnetas-pdf{margin-left:auto;border:1px solid #d9c6f5;background:#fff;color:#6d28d9;font-weight:700}.addnetas-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.addnetas-kpis article{padding:17px;border:1px solid #e2e6ef;border-radius:18px;background:#fff}.addnetas-kpis span,.addnetas-kpis small{display:block;color:#748198;font-size:12px}.addnetas-kpis strong{display:block;margin:6px 0;font-size:28px;font-weight:500}.reach.green,.green{background:#eaf8ef!important;color:#147a3e}.reach.yellow,.yellow{background:#fff7dc!important;color:#9a6900}.reach.red,.red{background:#fff0f2!important;color:#b4233c}.addnetas-table-wrap{overflow:auto;border:1px solid #dde3ed;border-radius:22px;background:#fff}.addnetas-table-wrap table{width:100%;min-width:1120px;border-collapse:collapse}.addnetas-table-wrap th,.addnetas-table-wrap td{padding:14px 12px;border-right:1px solid #e6eaf0;border-bottom:1px solid #e6eaf0;text-align:center}.addnetas-table-wrap tbody th{text-align:left;font-weight:500}.addnetas-table-wrap th small{display:block;margin-top:5px;color:#5e6b7d;font-size:11px;font-weight:600;line-height:1.35}.group-parent{background:#fbf8ff}.group-child th{padding-left:26px!important}.group-parent-secondary{background:#f8f4ff}.group-grandchild th{padding-left:46px!important}.row-organic{background:#fcfbff}.row-transfer{background:#eaf8ff}.row-transfer-f{background:#f3fbff}.row-transfer-risk{background:#f8fcff}.row-transfer-risk th{padding-left:58px!important;color:#9a6900}.row-net{background:#f8eefd}.row-objective{background:#f5f2ff}.editable-cell{display:flex;flex-direction:column;align-items:center;gap:5px}.input-wrap{display:inline-flex;align-items:center;gap:4px}.fcst-detail{display:block;margin-top:5px;color:#556274;font-size:12px;font-weight:600;line-height:1.35;white-space:normal}.fcst-director{display:block;margin-top:4px;color:#0d78c2;font-size:12px;font-weight:800;line-height:1.35;white-space:normal}.fcst-risk{display:block;margin-top:4px;color:#9a6400;font-size:11px;font-weight:600;line-height:1.35;white-space:normal}.input-wrap b{font-size:18px}.addnetas-input{width:76px;height:38px;border:1px solid #cdd5e1;border-radius:10px;text-align:center;background:#fff}.addnetas-derived{width:76px;height:38px;border:1px solid #cbd2dc;border-radius:10px;text-align:center;background:#eef1f5;color:#586273;font-weight:700;opacity:1;-webkit-text-fill-color:#586273}.derived-wrap b{color:#667085}.derived-cell .fcst-hint{color:#6b7280}.addnetas-input.is-saving{opacity:.55}.addnetas-input.is-saved{border-color:#2fa66a}.addnetas-input.is-error{border-color:#d5414f}.fcst-hint{display:block;margin-top:4px;color:#556274;font-size:11px;font-weight:600;line-height:1.35;white-space:normal}.addnetas-visuals{display:grid;gap:14px;padding:16px;border:1px solid #e2e6ef;border-radius:22px;background:#fff}.addnetas-visuals-head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px}.addnetas-visuals-head span{font-size:10px;letter-spacing:.13em;color:#7c5fa3}.addnetas-visuals-head h2{margin:4px 0 0;font-size:22px;font-weight:600;color:#2b1a4b}.addnetas-visuals-head p{max-width:720px;margin:0;color:#748198;font-size:12px;line-height:1.45}.addnetas-chart-stack{display:grid;grid-template-columns:1fr;gap:14px}.addnetas-chart-card-wide{padding:18px;border:1px solid #dfe5ef;border-radius:18px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04)}.addnetas-chart-mobile-nav{display:none}.an-mobile-scroll-controls{display:none}.addnetas-chart-title{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.addnetas-chart-title h3{margin:0;font-size:16px;color:#2d2141}.addnetas-chart-title small{display:block;margin-top:3px;color:#7b879a;font-size:10px}.addnetas-chart-title>span{padding:5px 9px;border-radius:999px;background:#f5f1ff;color:#7455a4;font-size:9px;white-space:nowrap}.addnetas-chart{margin-top:12px;min-height:360px}.an-chart-scroll{overflow-x:auto;overflow-y:hidden;padding:2px 0 6px}.an-svg{display:block;width:100%;min-width:940px;height:auto}.an-legend{display:flex;flex-wrap:wrap;gap:14px;margin:0 0 10px;color:#667085;font-size:11px}.an-legend-item{display:inline-flex;align-items:center;gap:5px}.an-legend-swatch{width:10px;height:10px;border-radius:2px;display:inline-block}.an-legend-line{width:20px;height:0;border-top:2px solid #26324a;display:inline-block}.an-chart-empty{display:flex;align-items:center;justify-content:center;min-height:260px;color:#7b879a;font-size:12px;border:1px dashed #d8dee9;border-radius:14px;background:#fbfcfe}.an-svg text{font-family:inherit}.an-grid{stroke:#e6ebf2;stroke-width:1}.an-zero{stroke:#aeb8c7;stroke-width:1.25}.an-axis-label{fill:#667085;font-size:10px}.an-week-label{fill:#4c566a;font-size:10px;font-weight:600}.an-week-fcst{fill:#7a2fd0;font-size:9px;font-weight:700}.an-region-line{fill:none;stroke:#26324a;stroke-width:2.15;stroke-linejoin:round;stroke-linecap:round;opacity:.96}.an-region-dot{fill:#fff;stroke:#26324a;stroke-width:1.8}.an-region-value{fill:#26324a;font-size:10px;font-weight:700}.an-fcst-zone{display:none}.an-fcst-border{display:none}.an-series-line{fill:none;stroke-width:1.85;stroke-linejoin:round;stroke-linecap:round;opacity:.94}.an-series-dot{stroke-width:1.5;fill:#fff}.an-series-dot-current{stroke-width:2.2;fill:#fff}.an-bar-segment,.an-region-dot,.an-series-dot,.an-series-dot-current{cursor:pointer}.an-bar-segment{opacity:.98}.an-bar-segment:hover{opacity:1;filter:brightness(1.03)}.an-sign-note{fill:#8a94a6;font-size:9px}.an-fcst-marker{fill:#7a2fd0;font-size:9px;font-weight:700}.an-top-cap{stroke:#d8dee9;stroke-width:1}.an-selected-bar{stroke:#7a2fd0;stroke-width:2;fill:none;stroke-dasharray:4 3}.an-selected-fcst-box{stroke:#7a2fd0;stroke-width:2;fill:none;stroke-dasharray:6 4;opacity:.95}.an-tooltip{position:fixed;z-index:99999;display:none;max-width:260px;padding:10px 12px;border-radius:10px;background:rgba(17,24,39,.94);color:#fff;font-size:12px;line-height:1.35;box-shadow:0 10px 30px rgba(0,0,0,.22);pointer-events:none}.an-tooltip b{font-weight:700}.an-tooltip small{display:block;margin-top:4px;color:#d1d5db;font-size:11px}@media(max-width:900px){.addnetas-visuals-head{align-items:flex-start;flex-direction:column}.addnetas-chart-title{flex-direction:column}.addnetas-chart-title>span{align-self:flex-start}.addnetas-chart{min-height:300px}}.save-projection-bar{display:flex;justify-content:flex-end;align-items:center;gap:16px;margin-top:10px;padding:12px 14px;border:1px solid #e5daf7;border-radius:16px;background:#fff}.save-projection-copy{margin-right:auto;display:flex;flex-direction:column;gap:2px}.save-projection-copy strong{font-size:12px;color:#34224f}.save-projection-copy small{color:#6b7280;font-size:9px;line-height:1.3}.save-projection-btn{min-width:230px;border:0;border-radius:12px;padding:12px 18px;color:#fff;background:linear-gradient(135deg,#7224d7,#dd009d);font-weight:800;cursor:pointer;box-shadow:0 6px 16px rgba(114,36,215,.16);transition:transform .15s ease,filter .15s ease}.save-projection-btn:hover{filter:brightness(1.04);transform:translateY(-1px);cursor:pointer}.save-projection-btn:disabled{opacity:.55;cursor:wait;transform:none}.addnetas-note,.addnetas-alert{padding:12px 15px;border-radius:14px;background:#edf9fd;color:#64748b;font-size:12px}.addnetas-alert{background:#fff0f2;color:#a42b38}.addnetas-warning{background:#fff8e6;color:#8a5a00}@media(max-width:900px){.addnetas-kpis{grid-template-columns:1fr 1fr}.addnetas-visuals-head{align-items:flex-start;flex-direction:column}.addnetas-hero{align-items:flex-start;flex-direction:column}.addnetas-period{text-align:left}}

.addnetas-anchor{position:absolute;display:block;transform:translateY(-14px)}
.addnetas-mobile-jump{display:none}
.addnetas-matrix-mobile-nav{display:none}
.addnetas-swipe-hint{display:none;text-align:center;color:#748198;font-size:11px;margin-top:-8px;padding:2px 0 0}
.addnetas-table-wrap{overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#cbb6ee #f7f5fb}
.addnetas-table-wrap thead th{position:sticky;top:0;z-index:4;background:#fff}
.addnetas-table-wrap thead th:first-child{left:0;z-index:7}
.addnetas-table-wrap tbody th{position:sticky;left:0;z-index:3;background:#fff;box-shadow:1px 0 0 #e6eaf0}
.addnetas-table-wrap tbody tr.group-parent th{background:#fbf8ff}.addnetas-table-wrap tbody tr.group-parent-secondary th{background:#f8f4ff}.addnetas-table-wrap tbody tr.row-organic th{background:#fcfbff}.addnetas-table-wrap tbody tr.row-transfer th{background:#eaf8ff}.addnetas-table-wrap tbody tr.row-transfer-f th{background:#f3fbff}.addnetas-table-wrap tbody tr.row-transfer-risk th{background:#f8fcff}.addnetas-table-wrap tbody tr.row-net th{background:#f8eefd}.addnetas-table-wrap tbody tr.row-objective th{background:#f5f2ff}
.an-chart-scroll{overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#cbb6ee #f7f5fb}
@media(max-width:760px){
  .addnetas-shell{gap:12px;min-width:0;width:100%}
  .addnetas-hero{padding:17px 15px;border-radius:18px;gap:10px}
  .addnetas-hero h1{font-size:27px;line-height:1.08;margin:6px 0}
  .addnetas-hero p{font-size:12px;line-height:1.45;margin:0}
  .addnetas-period{display:flex;gap:7px;align-items:baseline;flex-wrap:wrap}
  .addnetas-period strong{font-size:13px}.addnetas-period small{font-size:11px}
  .addnetas-nav{overflow-x:auto;flex-wrap:nowrap;white-space:nowrap;padding:6px;border-radius:14px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .addnetas-nav::-webkit-scrollbar{display:none}
  .addnetas-nav a{flex:0 0 auto;padding:9px 11px;font-size:12px}
  .addnetas-nav .addnetas-pdf{margin-left:0}
  .addnetas-mobile-jump{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;position:sticky;top:6px;z-index:30;padding:6px;border:1px solid #e8ddfb;border-radius:14px;background:rgba(255,255,255,.96);backdrop-filter:blur(10px)}
  .addnetas-mobile-jump a{text-align:center;text-decoration:none;color:#5b4778;background:#f7f3fb;padding:9px 7px;border-radius:10px;font-size:12px;font-weight:700}
  .addnetas-mobile-jump a:nth-child(1),.addnetas-mobile-jump a:nth-child(2){color:#fff;background:linear-gradient(135deg,#7224d7,#dd009d)}
  .addnetas-kpis{grid-template-columns:1fr 1fr;gap:8px}
  .addnetas-kpis article{padding:12px;border-radius:14px;min-width:0}
  .addnetas-kpis span,.addnetas-kpis small{font-size:10px;line-height:1.25}
  .addnetas-kpis strong{font-size:22px;margin:4px 0}
  .addnetas-matrix-mobile-nav{display:grid;grid-template-columns:42px minmax(0,1fr) 42px;grid-template-areas:"prev select next" "pos pos pos";gap:6px 8px;align-items:end;margin-bottom:8px;padding:9px;border:1px solid #e5daf7;border-radius:14px;background:#fff}
  .addnetas-matrix-nav-btn{width:42px;height:42px;border:1px solid #d9c6f5;border-radius:12px;background:#fff;color:#6d28d9;font-size:25px;line-height:1;touch-action:manipulation}
  .addnetas-matrix-nav-btn[data-matrix-prev]{grid-area:prev}.addnetas-matrix-nav-btn[data-matrix-next]{grid-area:next}
  .addnetas-matrix-select-wrap{grid-area:select;display:grid;gap:3px;min-width:0}.addnetas-matrix-select-wrap span{font-size:9px;font-weight:700;letter-spacing:.08em;color:#7c5fa3;text-transform:uppercase}.addnetas-matrix-select-wrap select{width:100%;min-width:0;height:42px;border:1px solid #d9c6f5;border-radius:11px;background:#fff;color:#34224f;padding:0 10px;font-size:13px;font-weight:700}
  .addnetas-matrix-mobile-nav>[data-matrix-position]{grid-area:pos;text-align:center;color:#7b879a;font-size:10px}
  .addnetas-table-wrap{border-radius:14px;max-width:100%;overflow-x:hidden!important;overflow-y:visible!important;scroll-snap-type:none}
  .addnetas-table-wrap table{width:100%;min-width:0!important;table-layout:fixed;font-size:11px}
  .addnetas-table-wrap th,.addnetas-table-wrap td{padding:10px 8px;min-width:0!important;max-width:none!important;scroll-snap-align:none;overflow-wrap:anywhere}
  .addnetas-table-wrap th:first-child,.addnetas-table-wrap td:first-child{width:46%;min-width:0!important;max-width:none!important}
  .addnetas-table-wrap tr>*:not(:first-child){width:54%}
  .addnetas-table-wrap thead th,.addnetas-table-wrap tbody th{position:static!important;left:auto!important;top:auto!important;box-shadow:none!important}
  .addnetas-table-wrap .addnetas-mobile-col-hidden{display:none!important}
  .addnetas-table-wrap tbody th{font-size:11px;line-height:1.25}
  .group-child th{padding-left:18px!important}.group-grandchild th{padding-left:28px!important}.row-transfer-risk th{padding-left:34px!important}
  .addnetas-input{width:66px;height:36px;font-size:14px}
  .fcst-detail{font-size:11px;color:#556274;font-weight:600;white-space:normal;line-height:1.35}.fcst-director{font-size:11px;color:#0d78c2;font-weight:800;line-height:1.35}.fcst-hint,.fcst-risk{font-size:10px;color:#556274;font-weight:600;line-height:1.35;white-space:normal}.fcst-risk{color:#9a6400}
  .addnetas-swipe-hint{display:block}
  .addnetas-visuals{padding:12px 10px;border-radius:16px;gap:12px}
  .addnetas-visuals-head h2{font-size:19px}.addnetas-visuals-head p{font-size:11px;line-height:1.4}
  .addnetas-chart-mobile-nav{display:grid;grid-template-columns:42px minmax(0,1fr) 42px;grid-template-areas:"prev select next" "pos pos pos";gap:6px 8px;align-items:end;position:sticky;top:58px;z-index:22;padding:9px;border:1px solid #e5daf7;border-radius:14px;background:rgba(255,255,255,.97);backdrop-filter:blur(9px)}
  .addnetas-chart-nav-btn{width:42px;height:42px;border:1px solid #d9c6f5;border-radius:12px;background:#fff;color:#6d28d9;font-size:25px;line-height:1;box-shadow:0 1px 2px rgba(16,24,40,.04);touch-action:manipulation}
  .addnetas-chart-nav-btn[data-chart-prev]{grid-area:prev}.addnetas-chart-nav-btn[data-chart-next]{grid-area:next}
  .addnetas-chart-select-wrap{grid-area:select;display:grid;gap:3px;min-width:0}.addnetas-chart-select-wrap span{font-size:9px;font-weight:700;letter-spacing:.08em;color:#7c5fa3;text-transform:uppercase}.addnetas-chart-select-wrap select{width:100%;min-width:0;height:42px;border:1px solid #d9c6f5;border-radius:11px;background:#fff;color:#34224f;padding:0 10px;font-size:13px;font-weight:700}
  .addnetas-chart-mobile-nav>[data-chart-position]{grid-area:pos;text-align:center;color:#7b879a;font-size:10px}
  .addnetas-chart-stack{display:block}
  .addnetas-chart-card-wide{display:none;padding:12px 10px;border-radius:14px;box-shadow:none;min-width:0;overflow:hidden}
  .addnetas-chart-card-wide.is-mobile-active{display:block}
  .addnetas-chart-title{gap:7px}.addnetas-chart-title h3{font-size:15px}.addnetas-chart-title small{font-size:9px}
  .addnetas-chart{min-height:260px;margin-top:9px;min-width:0}
  .an-legend{gap:8px 12px;font-size:10px;margin-bottom:7px}
  .an-chart-scroll{display:block;width:100%;max-width:100%;overflow:hidden!important;touch-action:manipulation}
  .an-svg{display:block;width:100%!important;min-width:0!important;max-width:100%!important;height:auto}
  .an-mobile-scroll-controls{display:grid;grid-template-columns:40px minmax(0,1fr) 40px;gap:7px;align-items:center;margin:4px 0 8px;width:100%}
  .an-mobile-scroll-controls span{text-align:center;color:#748198;font-size:10px;line-height:1.25;white-space:normal}
  .an-mobile-scroll-controls button{width:40px;height:36px;border:1px solid #d9c6f5;border-radius:10px;background:#f8f5fd;color:#6d28d9;font-size:20px;line-height:1;touch-action:manipulation}
  .an-mobile-scroll-controls button:disabled{opacity:.3;color:#9ca3af}
  .an-axis-label,.an-week-label{font-size:9px}.an-region-value{font-size:9px}.an-week-fcst{font-size:8px}
  .addnetas-actions{flex-direction:column;align-items:stretch;gap:9px;padding:12px;border-radius:14px;font-size:11px}
  .addnetas-actions button{width:100%;min-height:44px}
  .addnetas-note,.addnetas-alert{font-size:11px;line-height:1.4;padding:10px 12px;border-radius:12px}
  .an-tooltip{max-width:min(250px,calc(100vw - 28px));font-size:11px;padding:9px 10px}
}
@media(max-width:420px){
  .addnetas-kpis{grid-template-columns:1fr 1fr}
  .addnetas-hero h1{font-size:24px}
  .addnetas-table-wrap table{min-width:0!important}
  .addnetas-table-wrap th,.addnetas-table-wrap td{min-width:0!important;padding:9px 7px}
  .addnetas-table-wrap th:first-child,.addnetas-table-wrap td:first-child{min-width:0!important;max-width:none!important}
  .an-svg{width:100%!important;min-width:0!important;max-width:100%!important}
  .addnetas-mobile-jump a{font-size:11px;padding:8px 5px}
}
</style>
<script>
(function(){
  const root=document.getElementById(<?php echo wp_json_encode($instance); ?>);if(!root)return;
  const labels=<?php echo wp_json_encode(array_merge($districts,array('REGIÓN SUR'))); ?>;
  let active=0;
  const select=root.querySelector('[data-matrix-select]'),prev=root.querySelector('[data-matrix-prev]'),next=root.querySelector('[data-matrix-next]'),pos=root.querySelector('[data-matrix-position]'),table=root.querySelector('.addnetas-table-wrap table');
  function apply(index){
    active=(index+labels.length)%labels.length;
    if(select)select.value=labels[active];if(pos)pos.textContent=(active+1)+' / '+labels.length+' · '+labels[active];
    if(!table)return;
    const mobile=window.matchMedia('(max-width:760px)').matches;
    table.querySelectorAll('tr').forEach(row=>{
      [...row.children].forEach((cell,i)=>{
        if(i===0){cell.classList.remove('addnetas-mobile-col-hidden');return;}
        if(!mobile){cell.classList.remove('addnetas-mobile-col-hidden');return;}
        cell.classList.toggle('addnetas-mobile-col-hidden',i!==active+1);
      });
    });
  }
  if(prev)prev.addEventListener('click',()=>apply(active-1));
  if(next)next.addEventListener('click',()=>apply(active+1));
  if(select)select.addEventListener('change',()=>{const i=labels.indexOf(select.value);if(i>=0)apply(i);});
  let timer=null;window.addEventListener('resize',()=>{clearTimeout(timer);timer=setTimeout(()=>apply(active),100);});
  apply(0);
})();
</script>
<?php if($ctx['mode']==='semanal'):?><script>
(function(){
  const root=document.getElementById(<?php echo wp_json_encode($instance); ?>); if(!root)return;
  const districts=<?php echo wp_json_encode($districts); ?>;
  const initial=<?php echo wp_json_encode($chart_rows); ?>;
  const history=<?php echo wp_json_encode($history); ?>;
  const isRealView=<?php echo wp_json_encode($is_real); ?>;
  const selectedIsActive=<?php echo wp_json_encode(((int)$ctx['year']===(int)$ctx['active_year']&&(int)$ctx['week']===(int)$ctx['active_week'])); ?>;
  const fmt=new Intl.NumberFormat('es-MX');
  const palette={
    'CANCÚN':'#13B5EA',
    'COATZA / MINA':'#F0008B',
    'MÉRIDA':'#21B659',
    'TUXTLA':'#F59E0B',
    'VILLAHERMOSA':'#7C3AED'
  };
  const shortLabels={'CANCÚN':'Cancún','COATZA / MINA':'Coatza / Mina','MÉRIDA':'Mérida','TUXTLA':'Tuxtla','VILLAHERMOSA':'Villahermosa'};
  const regionColor='#26324a';
  const scopeLabel=<?php echo wp_json_encode(tx_an_scope_label()); ?>;
  const esc=(s)=>String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const n=(v)=>Number.isFinite(+v)?+v:0;
  const metricLabel={installations:'Instalaciones',cancellations:'Cancelaciones',voluntary:'Voluntarias',involuntary:'Involuntarias',add_netas:'Adiciones Netas'};
  const MOBILE_WEEK_COUNT=5;
  let mobileWeekStart=null,lastRenderedData=initial;
  const isMobile=()=>window.matchMedia('(max-width:760px)').matches;
  function visibleSeries(all){
    if(!isMobile())return all;
    const count=Math.min(MOBILE_WEEK_COUNT,all.length),maxStart=Math.max(0,all.length-count);
    if(mobileWeekStart===null)mobileWeekStart=maxStart;
    mobileWeekStart=Math.max(0,Math.min(maxStart,mobileWeekStart));
    return all.slice(mobileWeekStart,mobileWeekStart+count);
  }
  function tipHtml(lines){
    return lines.filter(Boolean).map((line,idx)=>idx===0?('<b>'+esc(line)+'</b>'):(idx===1&&line.indexOf(':')>-1?('<div>'+esc(line)+'</div>'):('<small>'+esc(line)+'</small>'))).join('');
  }
  function tipAttr(lines){ return ' data-tip="'+esc(lines.filter(Boolean).join(' || '))+'"'; }
  function pointLabel(p){ return (p.label||('S'+p.week)) + (p.selected ? (isRealView?' · REAL':(selectedIsActive?' · FCST':' · selección')) : ''); }
  function ensureTooltip(){
    if(root.__anTooltip)return root.__anTooltip;
    const el=document.createElement('div');el.className='an-tooltip';document.body.appendChild(el);root.__anTooltip=el;
    let hideTimer=null;
    const fill=(target)=>{
      const raw=String(target?.getAttribute('data-tip')||'');
      if(!raw)return false;
      el.innerHTML=raw.split(' || ').map((line,idx)=>idx===0?('<b>'+esc(line)+'</b>'):(idx===1&&line.indexOf(':')>-1?('<div>'+esc(line)+'</div>'):('<small>'+esc(line)+'</small>'))).join('');
      el.style.display='block'; return true;
    };
    const place=(x,y)=>{
      const pad=12,rect=el.getBoundingClientRect();let left=x+16,top=y+16;
      if(left+rect.width>window.innerWidth-pad)left=Math.max(pad,x-rect.width-16);
      if(top+rect.height>window.innerHeight-pad)top=Math.max(pad,y-rect.height-16);
      el.style.left=left+'px';el.style.top=top+'px';
    };
    root.addEventListener('mousemove',function(e){
      if(e.pointerType==='touch')return;
      const target=e.target.closest('[data-tip]');
      if(!target||!root.contains(target)){el.style.display='none';return;}
      if(fill(target))place(e.clientX,e.clientY);
    });
    root.addEventListener('click',function(e){
      const target=e.target.closest('[data-tip]');if(!target||!root.contains(target))return;
      e.stopPropagation();if(!fill(target))return;
      const r=target.getBoundingClientRect();place(r.left+r.width/2,r.top+r.height/2);
      clearTimeout(hideTimer);hideTimer=setTimeout(()=>{el.style.display='none';},3200);
    });
    document.addEventListener('click',function(e){if(!root.contains(e.target))el.style.display='none';});
    root.addEventListener('mouseleave',()=>{if(!matchMedia('(hover:none)').matches)el.style.display='none';});
    root.addEventListener('scroll',()=>{el.style.display='none';},true);
    return el;
  }

  function currentMetric(row,type){
    if(type==='installations')return n(row.installations);
    if(type==='cancellations')return n(row.cancellations);
    if(type==='voluntary')return n(row.voluntary);
    if(type==='involuntary')return n(row.involuntary);
    if(type==='add_netas')return n(row.add_netas);
    return 0;
  }
  function seriesWithProjection(data){
    const out=Array.isArray(history)?JSON.parse(JSON.stringify(history)):[];
    if(!out.length)return out;
    let idx=out.findIndex(x=>x&&x.selected); if(idx<0)idx=out.length-1;
    const point=out[idx];
    point.rows=point.rows||{}; point.region=point.region||{};
    ['installations','cancellations','voluntary','involuntary','add_netas'].forEach(type=>{
      let total=0;
      districts.forEach(d=>{
        point.rows[d]=point.rows[d]||{};
        const value=currentMetric(data[d]||{},type);
        point.rows[d][type]=value; total+=value;
      });
      point.region[type]=total;
    });
    point.fcst=true;
    return out;
  }
  function legendBar(){
    const parts=districts.map(d=>'<span class="an-legend-item"><i class="an-legend-swatch" style="background:'+palette[d]+'"></i>'+esc(shortLabels[d])+'</span>');
    parts.push('<span class="an-legend-item"><i class="an-legend-line"></i>'+esc(scopeLabel)+'</span>');
    return '<div class="an-legend">'+parts.join('')+'</div>';
  }
  function legendLines(series,metric){
    return '<div class="an-legend">'+districts.map(d=>{
      const last=series.length?n(series[series.length-1]?.rows?.[d]?.[metric]):0;
      return '<span class="an-legend-item"><i class="an-legend-line" style="border-color:'+palette[d]+'"></i>'+esc(shortLabels[d])+' <strong>'+fmt.format(last)+'</strong></span>';
    }).join('')+'</div>';
  }
  function tickValues(min,max,count=5){
    if(max===min)return [min];
    const step=(max-min)/count,vals=[];
    for(let i=0;i<=count;i++)vals.push(min+step*i);
    return vals;
  }
  function chartFrame(series,minY,maxY){
    const width=isMobile()?Math.max(620,series.length*118):Math.max(1000,series.length*105),height=355;
    const left=62,right=24,top=25,bottom=58,plotW=width-left-right,plotH=height-top-bottom;
    const range=Math.max(1,maxY-minY);
    const y=(v)=>top+((maxY-v)/range)*plotH;
    const step=plotW/Math.max(1,series.length);
    const x=(i)=>left+step*(i+.5);
    return {width,height,left,right,top,bottom,plotW,plotH,y,step,x};
  }
  function smoothPath(points,tension=0.18){
    if(!points.length)return '';
    if(points.length===1)return 'M '+points[0].x+' '+points[0].y;
    let d='M '+points[0].x+' '+points[0].y;
    for(let i=0;i<points.length-1;i++){
      const p0=points[i-1]||points[i];
      const p1=points[i];
      const p2=points[i+1];
      const p3=points[i+2]||p2;
      const cp1x=p1.x+((p2.x-p0.x)*tension);
      const cp1y=p1.y+((p2.y-p0.y)*tension);
      const cp2x=p2.x-((p3.x-p1.x)*tension);
      const cp2y=p2.y-((p3.y-p1.y)*tension);
      d+=' C '+cp1x+' '+cp1y+', '+cp2x+' '+cp2y+', '+p2.x+' '+p2.y;
    }
    return d;
  }
  function selectedBand(f,series){ return ''; }
  function axes(f,minY,maxY,series){
    let svg=selectedBand(f,series);
    tickValues(minY,maxY,5).forEach(v=>{
      const yy=f.y(v);
      svg+='<line class="an-grid" x1="'+f.left+'" y1="'+yy+'" x2="'+(f.width-f.right)+'" y2="'+yy+'"></line>';
      svg+='<text class="an-axis-label" x="'+(f.left-9)+'" y="'+(yy+3)+'" text-anchor="end">'+esc(fmt.format(Math.round(v)))+'</text>';
    });
    if(minY<0&&maxY>0){const zy=f.y(0);svg+='<line class="an-zero" x1="'+f.left+'" y1="'+zy+'" x2="'+(f.width-f.right)+'" y2="'+zy+'"></line>';}
    series.forEach((p,i)=>{
      const xx=f.x(i),isCurrent=!!p.selected;
      svg+='<text class="an-week-label" x="'+xx+'" y="'+(f.height-28)+'" text-anchor="middle">'+esc(p.label||('S'+p.week))+'</text>';
      if(isCurrent)svg+='<text class="an-week-fcst" x="'+xx+'" y="'+(f.height-14)+'" text-anchor="middle">'+(isRealView?'REAL':(selectedIsActive?'FCST':'SELECCIÓN'))+'</text>';
    });
    return svg;
  }
  function drawBarLine(type,data){
    const box=root.querySelector('[data-chart="'+type+'"]');if(!box)return;
    const allSeries=seriesWithProjection(data);if(!allSeries.length){box.innerHTML='<div class="an-chart-empty">No hay historia semanal disponible.</div>';return;} const series=visibleSeries(allSeries);
    let maxY=0,minY=0;
    series.forEach(p=>{
      let pos=0,neg=0;
      districts.forEach(d=>{const v=n(p.rows?.[d]?.[type]);if(v>=0)pos+=v;else neg+=v;});
      maxY=Math.max(maxY,pos,n(p.region?.[type]));minY=Math.min(minY,neg,n(p.region?.[type]));
    });
    if(type!=='add_netas')minY=0;
    const span=Math.max(1,maxY-minY),pad=span*.10;maxY+=pad;minY-=type==='add_netas'?pad:0;
    const f=chartFrame(series,minY,maxY);let svg=axes(f,minY,maxY,series);
    const barW=Math.min(48,f.step*.52),points=[];
    series.forEach((p,i)=>{
      const xx=f.x(i),region=n(p.region?.[type]);points.push({x:xx,y:f.y(region)});
      let pos=0,neg=0;
      districts.forEach(d=>{
        const v=n(p.rows?.[d]?.[type]);if(v===0)return;
        const from=v>=0?pos:neg,to=from+v; if(v>=0)pos=to;else neg=to;
        const y1=f.y(from),y2=f.y(to),ry=Math.min(y1,y2),rh=Math.max(1,Math.abs(y2-y1));
        svg+='<rect class="an-bar-segment"'+tipAttr([pointLabel(p), shortLabels[d]+': '+fmt.format(v)+' '+metricLabel[type], scopeLabel+': '+fmt.format(region)])+' x="'+(xx-barW/2)+'" y="'+ry+'" width="'+barW+'" height="'+rh+'" fill="'+palette[d]+'"><title>'+esc((p.label||'')+' · '+shortLabels[d]+' · '+metricLabel[type]+': '+fmt.format(v))+'</title></rect>';
      });
      if(p.selected){svg+='<rect class="an-selected-bar" x="'+(xx-barW/2-6)+'" y="'+(f.top+6)+'" width="'+(barW+12)+'" height="'+(f.plotH-12)+'" rx="6"></rect>'; }
    });
    svg+='<path class="an-region-line" d="'+smoothPath(points,0.16)+'"></path>';
    series.forEach((p,i)=>{
      const xx=f.x(i),region=n(p.region?.[type]),yy=f.y(region);
      svg+='<circle class="an-region-dot"'+tipAttr([pointLabel(p), scopeLabel+': '+fmt.format(region)+' '+metricLabel[type], 'Total semanal'])+' cx="'+xx+'" cy="'+yy+'" r="4"><title>'+esc((p.label||'')+' · '+scopeLabel+': '+fmt.format(region))+'</title></circle>';
      const dy=region>=0?-9:14;
      svg+='<text class="an-region-value" x="'+xx+'" y="'+(yy+dy)+'" text-anchor="middle">'+esc(fmt.format(region))+'</text>';
    });
    box.innerHTML=legendBar()+mobileWeekControls(allSeries)+'<div class="an-chart-scroll"><svg class="an-svg" viewBox="0 0 '+f.width+' '+f.height+'" role="img" aria-label="'+esc(metricLabel[type]+' últimas 10 semanas')+'">'+svg+'</svg></div>'; wireWeekControls(box);
  }
  function drawLine(type,data){
    const box=root.querySelector('[data-chart="'+type+'"]');if(!box)return;
    const allSeries=seriesWithProjection(data);if(!allSeries.length){box.innerHTML='<div class="an-chart-empty">No hay historia semanal disponible.</div>';return;} const series=visibleSeries(allSeries);
    let maxY=0;series.forEach(p=>districts.forEach(d=>{maxY=Math.max(maxY,n(p.rows?.[d]?.[type]));}));maxY=Math.max(1,maxY*1.12);
    const minY=0,f=chartFrame(series,minY,maxY);let svg=axes(f,minY,maxY,series);
    const activeIdx=series.findIndex(p=>p&&p.selected);
    if(activeIdx>=0&&!isRealView&&selectedIsActive&&(type==='voluntary'||type==='involuntary')){
      const xx=f.x(activeIdx),boxW=Math.min(86,f.step*.72),boxX=xx-boxW/2,boxY=f.top+5,boxH=Math.max(40,f.plotH-10);
      svg+='<rect class="an-selected-fcst-box" x="'+boxX+'" y="'+boxY+'" width="'+boxW+'" height="'+boxH+'" rx="8"'+tipAttr([metricLabel[type]+' · FCST', 'Periodo seleccionado: '+esc(series[activeIdx].label||('S'+series[activeIdx].week)), 'Recuadro punteado = proyección activa'])+'></rect>';
    }
    districts.forEach(d=>{
      const pts=series.map((p,i)=>({x:f.x(i),y:f.y(n(p.rows?.[d]?.[type]))}));
      svg+='<path class="an-series-line" d="'+smoothPath(pts,0.18)+'" stroke="'+palette[d]+'"></path>';
      series.forEach((p,i)=>{
        const val=n(p.rows?.[d]?.[type]),current=!!p.selected,cls=current?'an-series-dot-current':'an-series-dot';
        svg+='<circle class="'+cls+'"'+tipAttr([pointLabel(p), shortLabels[d]+': '+fmt.format(val)+' '+metricLabel[type], 'Evolución semanal'])+' cx="'+f.x(i)+'" cy="'+f.y(val)+'" r="'+(current?4.5:3)+'" stroke="'+palette[d]+'"><title>'+esc((p.label||'')+' · '+shortLabels[d]+': '+fmt.format(val))+'</title></circle>';
      });
    });
    box.innerHTML=legendLines(series,type)+mobileWeekControls(allSeries)+'<div class="an-chart-scroll"><svg class="an-svg" viewBox="0 0 '+f.width+' '+f.height+'" role="img" aria-label="'+esc(metricLabel[type]+' por distrito últimas 10 semanas')+'">'+svg+'</svg></div>'; wireWeekControls(box);
  }
  function mobileWeekControls(allSeries){
    if(!isMobile())return '';
    const count=Math.min(MOBILE_WEEK_COUNT,allSeries.length),maxStart=Math.max(0,allSeries.length-count);
    if(mobileWeekStart===null)mobileWeekStart=maxStart;
    mobileWeekStart=Math.max(0,Math.min(maxStart,mobileWeekStart));
    const shown=allSeries.slice(mobileWeekStart,mobileWeekStart+count);
    const first=shown[0]?.label||'',last=shown[shown.length-1]?.label||'';
    return '<div class="an-mobile-scroll-controls"><button type="button" data-week-dir="left" '+(mobileWeekStart<=0?'disabled':'')+' aria-label="Ver semanas anteriores">‹</button><span>'+esc(first)+' — '+esc(last)+'<br>Usa las flechas para recorrer las 10 semanas</span><button type="button" data-week-dir="right" '+(mobileWeekStart>=maxStart?'disabled':'')+' aria-label="Ver semanas siguientes">›</button></div>';
  }
  function wireWeekControls(box){
    box.querySelectorAll('[data-week-dir]').forEach(btn=>btn.addEventListener('click',()=>{
      if(btn.disabled)return;
      mobileWeekStart+=(btn.dataset.weekDir==='left'?-1:1);
      render(lastRenderedData);
    }));
  }
  const chartKeys=['installations','cancellations','voluntary','involuntary','add_netas'];
  let activeChartIndex=0;
  function showMobileChart(index,scrollSection){
    activeChartIndex=(index+chartKeys.length)%chartKeys.length;
    const key=chartKeys[activeChartIndex];
    root.querySelectorAll('[data-chart-card]').forEach(card=>card.classList.toggle('is-mobile-active',card.dataset.chartCard===key));
    const select=root.querySelector('[data-chart-select]');if(select)select.value=key;
    const pos=root.querySelector('[data-chart-position]');if(pos)pos.textContent=(activeChartIndex+1)+' / '+chartKeys.length;
    const card=root.querySelector('[data-chart-card="'+key+'"]');
    if(card){
      if(scrollSection){const section=root.querySelector('.addnetas-chart-mobile-nav');if(section)section.scrollIntoView({behavior:'smooth',block:'start'});}
    }
  }
  function setupMobileChartNavigation(){
    const prev=root.querySelector('[data-chart-prev]'),next=root.querySelector('[data-chart-next]'),select=root.querySelector('[data-chart-select]');
    if(prev)prev.addEventListener('click',()=>showMobileChart(activeChartIndex-1,false));
    if(next)next.addEventListener('click',()=>showMobileChart(activeChartIndex+1,false));
    if(select)select.addEventListener('change',()=>{const idx=chartKeys.indexOf(select.value);if(idx>=0)showMobileChart(idx,false);});
    showMobileChart(0,false);
  }
  function render(data){
    lastRenderedData=data;
    drawBarLine('installations',data);
    drawBarLine('cancellations',data);
    drawLine('voluntary',data);
    drawLine('involuntary',data);
    drawBarLine('add_netas',data);
    showMobileChart(activeChartIndex,false);
  }
  ensureTooltip();
  setupMobileChartNavigation();
  let chartResizeTimer=null;window.addEventListener('resize',()=>{clearTimeout(chartResizeTimer);chartResizeTimer=setTimeout(()=>{if(isMobile()&&mobileWeekStart===null){mobileWeekStart=Math.max(0,(Array.isArray(history)?history.length:0)-MOBILE_WEEK_COUNT);}render(lastRenderedData);},120);});
  root.querySelectorAll('.addnetas-mobile-jump a').forEach(a=>a.addEventListener('click',function(e){
    const id=this.getAttribute('href');const target=id&&document.querySelector(id);if(!target)return;
    e.preventDefault();target.scrollIntoView({behavior:'smooth',block:'start'});
  }));
  root.__renderAddNetasCharts=render;render(initial);
})();
</script><?php endif; ?>
<?php if($editable):?><script>
(function(){const root=document.getElementById(<?php echo wp_json_encode($instance); ?>);if(!root)return;const inputs=[...root.querySelectorAll('.addnetas-input')],status=root.querySelector('[data-role="status"]'),saveAll=root.querySelector('[data-role="save-all"]'),pdfLink=root.querySelector('[data-role="executive-pdf"]'),nonce=<?php echo wp_json_encode(wp_create_nonce('krn_addnetas_save')); ?>,url=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,year=<?php echo (int)$ctx['year']; ?>,week=<?php echo (int)$ctx['week']; ?>,fmt=new Intl.NumberFormat('es-MX'),fixed=<?php echo wp_json_encode(array_map(function($r){return array('involuntary'=>(int)$r['involuntarias'],'transfer_p'=>(int)$r['traslado_p'],'transfer_f'=>(int)$r['traslado_f']);},$rows)); ?>,base=<?php echo wp_json_encode($chart_rows); ?>;
function val(d,f){const x=inputs.find(i=>i.dataset.district===d&&i.dataset.field===f);if(x)return Math.max(0,parseInt(x.value||'0',10)||0);return Math.max(0,parseInt(fixed[d]?.[f]||'0',10)||0)}
function paint(cell,pct){cell.classList.remove('green','yellow','red');cell.classList.add(pct>=100?'green':(pct>=80?'yellow':'red'))}
function recalc(){
  let t={installations:0,voluntary:0,organic:0,involuntary:0,transfer_p:0,transfer_f:0,objective:0,add_netas:0};
  const districts=<?php echo wp_json_encode($districts); ?>, chartData={};
  districts.forEach(d=>{
    const i=val(d,'installations'),v=val(d,'voluntary'),iv=val(d,'involuntary'),p=val(d,'transfer_p'),f=val(d,'transfer_f'),org=Math.max(0,iv-p+f),o=val(d,'objective'),c=v+iv,n=i-c,pct=o?Math.round(n/o*1000)/10:0;
    t.installations+=i;t.voluntary+=v;t.organic+=org;t.transfer_p+=p;t.transfer_f+=f;t.involuntary+=iv;t.objective+=o;t.add_netas+=n;
    const orgInput=root.querySelector('[data-derived-organic="'+d+'"]');if(orgInput)orgInput.value=String(org);
    const invCell=root.querySelector('[data-invol="'+d+'"] strong');if(invCell)invCell.textContent='−'+fmt.format(iv);
    const invDetail=root.querySelector('[data-invol-detail="'+d+'"]');if(invDetail){const real=Math.max(0,parseInt(invDetail.dataset.real||'0',10)||0),fcst=Math.max(0,iv-real);invDetail.textContent='Real '+fmt.format(real)+' + FCST '+fmt.format(fcst)+' = '+fmt.format(iv);}
    const cancelCell=root.querySelector('[data-cancel="'+d+'"] strong');if(cancelCell)cancelCell.textContent='−'+fmt.format(c);
    const netCell=root.querySelector('[data-net="'+d+'"] strong');if(netCell)netCell.textContent=fmt.format(n);
    const rc=root.querySelector('[data-reach="'+d+'"]');if(rc){rc.querySelector('strong').textContent=pct.toLocaleString('es-MX',{maximumFractionDigits:1})+'%';paint(rc,pct)}
    chartData[d]={
      installations:i,installations_real:Math.max(0,parseInt(base[d]?.installations_real||0,10)||0),
      voluntary:v,voluntary_real:Math.max(0,parseInt(base[d]?.voluntary_real||0,10)||0),
      organic:org,transfer_p:p,transfer_f:f,involuntary:iv,cancellations:c,add_netas:n
    };
  });
  t.cancelaciones=t.voluntary+t.involuntary;
  const signed={voluntary:true,organic:true,involuntary:true,transfer_p:true,transfer_f:false};
  [['installations','installations'],['voluntary','voluntary'],['organic','organic'],['involuntary','involuntary'],['transfer_p','transfer_p'],['transfer_f','transfer_f'],['objective','objective'],['add_netas','add_netas']].forEach(([key,region])=>{const el=root.querySelector('[data-region="'+region+'"] strong');if(el)el.textContent=(signed[key]?'−':'')+fmt.format(t[key])});
  const regionInvDetail=root.querySelector('[data-region-invol-detail]');if(regionInvDetail){const real=Math.max(0,parseInt(regionInvDetail.dataset.real||'0',10)||0),fcst=Math.max(0,t.involuntary-real);regionInvDetail.textContent='Real '+fmt.format(real)+' + FCST '+fmt.format(fcst)+' = '+fmt.format(t.involuntary);}
  const cancelRegion=root.querySelector('[data-region="cancelaciones"] strong');if(cancelRegion)cancelRegion.textContent='−'+fmt.format(t.cancelaciones);
  const pct=t.objective?Math.round(t.add_netas/t.objective*1000)/10:0,rr=root.querySelector('[data-region="alcance"]');if(rr){rr.querySelector('strong').textContent=pct.toLocaleString('es-MX',{maximumFractionDigits:1})+'%';paint(rr,pct)}
  root.querySelector('[data-kpi="instalaciones"]').textContent=fmt.format(t.installations);root.querySelector('[data-kpi="cancelaciones"]').textContent=fmt.format(t.cancelaciones);root.querySelector('[data-kpi="add_netas"]').textContent=fmt.format(t.add_netas);root.querySelector('[data-kpi="alcance"]').textContent=pct.toLocaleString('es-MX',{maximumFractionDigits:1})+'%';
  if(root.__renderAddNetasCharts)root.__renderAddNetasCharts(chartData);
}
async function save(input){input.value=Math.max(0,parseInt(input.value||'0',10)||0);input.classList.add('is-saving');const body=new URLSearchParams({action:'krn_addnetas_save',nonce,year:String(year),week:String(week),district:input.dataset.district,field:input.dataset.field,value:input.value,involuntary_total:String(val(input.dataset.district,'involuntary'))});try{const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const j=await r.json();if(!j.success)throw new Error(j.data?.message||'Error');input.classList.add('is-saved');setTimeout(()=>input.classList.remove('is-saved'),1000);if(status)status.textContent='Guardado: '+input.dataset.district;}catch(e){input.classList.add('is-error');if(status)status.textContent=e.message}finally{input.classList.remove('is-saving');recalc()}}
inputs.forEach(i=>{i.addEventListener('input',recalc);i.addEventListener('blur',()=>save(i))});saveAll?.addEventListener('click',async()=>{saveAll.disabled=true;for(const i of inputs)await save(i);saveAll.disabled=false;if(status)status.textContent='Proyección completa guardada.'});pdfLink?.addEventListener('click',async(e)=>{e.preventDefault();const href=pdfLink.href,popup=window.open('about:blank','_blank');pdfLink.setAttribute('aria-busy','true');if(status)status.textContent='Guardando proyección antes del reporte...';for(const i of inputs)await save(i);pdfLink.removeAttribute('aria-busy');if(status)status.textContent='Proyección guardada. Generando reporte ejecutivo...';if(popup){popup.location=href}else{window.location.href=href}});recalc()})();
</script><?php endif; ?>
<?php return ob_get_clean();
    }

}


/** Rutas autónomas: POST guardado / GET informe, ambas con control de sesión. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
    in_array((string)($_POST['action'] ?? ''),['krn_addnetas_save','krn_xpedient_addnetas_save'],true)) {
    tx_an_require_access('edit');
    if (!tx_an_ensure_fcst_table()) {
        wp_send_json_error(['message'=>'No se pudo preparar la tabla local de FCST. Revisar permisos SQL.'],503);
    }
    KRN_Add_Netas_Standalone::save_ajax();
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' &&
    ($_GET['action'] ?? '') === 'krn_addnetas_executive_pdf') {
    tx_an_require_access('view');
    KRN_Add_Netas_Standalone::executive_pdf_report();
    exit;
}

$tx_fcst_table_ok = tx_an_ensure_fcst_table();
header('Content-Type: text/html; charset=UTF-8');
$tx_is_fcst=TX_AN_ENTRY_VIEW==='fcst';
$tx_title=$tx_is_fcst?'FCST Add Netas':'Add Netas';
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($tx_title); ?> · TotalExpedient</title>
<style>body{margin:0;background:#f7f5fb;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif}main{max-width:1600px;margin:auto;padding:12px 16px 36px}.tx-alert{padding:12px 16px;margin:12px 0;background:#fff4e6;border:1px solid #eea854;color:#703500;border-radius:8px;font-weight:600}.tx-page-nav{display:flex;gap:8px;flex-wrap:wrap;margin:7px 0 13px}.tx-page-nav a{background:#fff;border:1px solid #d8dce7;padding:7px 12px;border-radius:10px;color:#334155;text-decoration:none;font-size:13px}.tx-page-nav a.active{background:#172554;color:#fff;border-color:#172554}</style>
</head><body><main>
<nav class="tx-page-nav"><a href="<?php echo esc_attr(tx_an_view_path('real')); ?>" class="<?php echo $tx_is_fcst?'':'active'; ?>">Add Netas</a><a href="<?php echo esc_attr(tx_an_view_path('fcst')); ?>" class="<?php echo $tx_is_fcst?'active':''; ?>">FCST Add Netas</a></nav>
<?php
if (!$tx_fcst_table_ok) echo '<div class="tx-alert">La tabla de capturas FCST no está disponible. Las cifras reales pueden consultarse; guardar proyecciones requiere permisos para crear la tabla tx_addnetas_transfers.</div>';
try {
    echo $tx_is_fcst ? KRN_Add_Netas_Standalone::shortcode(['view'=>'fcst']) : KRN_Add_Netas_Standalone::shortcode_real([]);
} catch (Throwable $e) {
    error_log('Add Netas render: '.$e->getMessage());
    http_response_code(500);
    echo '<div class="tx-alert">No fue posible cargar Add Netas con la estructura de datos actual. Revisa el registro de errores PHP.</div>';
}
?></main></body></html>
