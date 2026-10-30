<?php
/**
 * Дымной тест активации плагина CSC без WordPress.
 * Эмулирует минимум WP-API, загружает основной файл плагина и проверяет,
 * что активационный колбэк выполняется без фатальных ошибок.
 *
 * Запуск: php tools/smoke-activation.php [путь_к_файлу_плагина]
 */

$plugin = realpath($argv[1] ?? dirname(__DIR__) . '/custom-service-calculator.php');
if (!$plugin || !is_file($plugin)) { fwrite(STDERR, "Plugin file not found\n"); exit(1); }

// --- минимальная эмуляция WordPress ---
$GLOBALS['__smoke_options'] = [];
define('ABSPATH', dirname($plugin) . '/');
define('WPINC', 'wp-includes');

function add_action($h, $cb, $p = 10, $a = 1) {}
function add_filter($h, $cb, $p = 10, $a = 1) {}
function register_activation_hook($f, $cb) { $GLOBALS['__smoke_activate'] = $cb; }
function register_deactivation_hook($f, $cb) { $GLOBALS['__smoke_deactivate'] = $cb; }
function plugin_dir_path($f) { return rtrim(dirname($f), '/\\') . '/'; }
function plugin_dir_url($f) { return 'http://example.test/wp-content/plugins/' . basename(dirname($f)) . '/'; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function load_plugin_textdomain($d, $dep, $dir) { return true; }
function is_admin() { return false; }
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $r, $h) {}
function wp_clear_scheduled_hook($h) {}
function get_option($k, $def = false) { return $GLOBALS['__smoke_options'][$k] ?? $def; }
function update_option($k, $v, $a = null) { $GLOBALS['__smoke_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__smoke_options'][$k]); return true; }
function add_shortcode($t, $c) {}
function wp_register_script(...$a) {}
function wp_enqueue_script(...$a) {}
function wp_enqueue_style(...$a) {}
function wp_localize_script(...$a) {}
function wp_add_inline_script(...$a) {}
function register_rest_route(...$a) {}
function rest_ensure_response($x) { return $x; }
function wp_create_nonce($a = -1) { return 'nonce'; }
function wp_verify_nonce($n, $a = -1) { return 1; }
function current_user_can($c) { return true; }
function esc_html__($s, $d = 'default') { return $s; }
function esc_attr__($s, $d = 'default') { return $s; }
function __($s, $d = 'default') { return $s; }
function _e($s, $d = 'default') { echo $s; }
function _x($s, $c, $d = 'default') { return $s; }
function _n($s, $p, $n, $d = 'default') { return $n == 1 ? $s : $p; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return $s; }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : $s; }
function sanitize_email($s) { return $s; }
function absint($v) { return abs((int)$v); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function home_url($p = '') { return 'http://example.test' . $p; }
function admin_url($p = '') { return 'http://example.test/wp-admin/' . $p; }
function site_url($p = '') { return 'http://example.test' . $p; }
function get_bloginfo($k) { return 'Example Site'; }
function wp_mail($to, $s, $m, $h = []) { return true; }
function wp_upload_dir() { return ['basedir' => sys_get_temp_dir() . '/csc-uploads', 'baseurl' => 'http://example.test/uploads', 'error' => false]; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function add_menu_page(...$a) {}
function add_submenu_page(...$a) {}
function register_setting(...$a) {}
function settings_fields($g) {}
function do_settings_sections($p) {}
function wp_die($m = '') { throw new RuntimeException((string)$m); }
function did_action($t) { return 0; }
function do_action($t, ...$a) {}
function apply_filters($t, $v, ...$a) { return $v; }
function wp_kses_post($s) { return $s; }
function number_format_i18n($n) { return number_format((float)$n, 0, ',', ' '); }
function date_i18n($f, $t = null) { return date($f, $t ?: time()); }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return new stdClass(); }
function is_wp_error($t) { return $t instanceof WP_Error; }
class WP_Error { public function __construct($c = '', $m = '') {} public function get_error_message() { return ''; } }
function rest_api_init($cb) {}
function wp_safe_redirect($u, $s = 302) {}
function wp_remote_get($u, $a = []) { return ['response' => ['code' => 200], 'body' => '{}']; }
function wp_remote_post($u, $a = []) { return ['response' => ['code' => 200], 'body' => '{}']; }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['response']['code'] ?? 0) : 0; }
function set_query_var(...$a) {}
function get_query_var($k, $d = '') { return $d; }
function wp_load_alloptions() { return []; }
function status_header($c) {}
function nocache_headers() {}
function wp_send_json($d) { throw new RuntimeException('json:' . json_encode($d)); }
function wp_send_json_success($d = null) { throw new RuntimeException('json_ok'); }
function wp_send_json_error($d = null) { throw new RuntimeException('json_err'); }
function wp_generate_password($l = 12, $sp = true) { return substr(str_repeat('aB3c', (int)ceil($l/4)), 0, $l); }
function wp_hash($d) { return md5($d); }
function wp_salt($s = 'auth') { return 'salt'; }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim($s, '/\\'); }
function wp_kses($s, $a) { return $s; }
function selected($a, $b = true, $r = true) { return ''; }
function checked($a, $b = true, $r = true) { return ''; }
function disabled($a, $b = true, $r = true) { return ''; }
function submit_button(...$a) { return ""; }
function wp_dropdown_pages(...$a) {}
function add_query_arg(...$a) { return 'http://example.test/?' . http_build_query(is_array($a[0] ?? null) ? $a[0] : array_slice($a, 0, 2)); }
function remove_query_arg(...$a) { return ''; }
function wp_strip_all_tags($s) { return strip_tags((string)$s); }
function mysql2date($f, $dt) { return $dt; }
function current_time($t) { return time(); }
function wp_date($f, $ts = null) { return date($f, $ts ?: time()); }
function get_transient($k) { return false; }
function set_transient($k, $v, $e = 0) { return true; }
function delete_transient($k) { return true; }
function wp_schedule_single_event($t, $h, $a = []) {}
function wp_count_posts(...$a) { return new stdClass(); }
function get_posts(...$a) { return []; }
function wp_insert_post(...$a) { return 1; }
function wp_update_post(...$a) { return 1; }
function wp_delete_post(...$a) { return true; }
function get_permalink(...$a) { return 'http://example.test/?p=1'; }
function wp_is_mobile() { return false; }
function wp_send_json_error_msg(...$a) {}
function wp_scripts() { return null; }
function wp_style_loader_path(...$a) {}
function wp_resource_hint(...$a) {}


function remove_action($h, $cb, $p = 10) {}
function remove_filter($h, $cb, $p = 10) {}
function dbDelta($sql = '') { return []; }
class __SmokeWpdb {
    public $prefix = 'wp_';
    public $last_insert_id = 1;
    public $insert_id = 1;
    public $num_rows = 0;
    public $results = [];
    public $base_prefix = 'wp_';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function prepare($q, ...$a) { return $q; }
    public function get_var($q = null) { return null; }
    public function get_row($q = null, $o = null) { return null; }
    public function get_results($q = null, $o = null) { return []; }
    public function get_col($q = null) { return []; }
    public function query($q) { return true; }
    public function insert($t, $d, $f = null) { return 1; }
    public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
    public function delete($t, $w, $f = null) { return 1; }
    public function replace($t, $d, $f = null) { return 1; }
    public function get_table_name($k = 1) { return $this->prefix . 'posts'; }
    public function tables($scope = 'all') { return []; }
}
$GLOBALS['wpdb'] = new __SmokeWpdb();

$checks = 0; $failed = 0;
function ok($cond, $msg) {
    global $checks, $failed;
    $checks++;
    if ($cond) { echo "  OK  $msg\n"; }
    else { $failed++; echo "  FAIL $msg\n"; }
}

echo "SMOKE: loading plugin $plugin\n";
ob_start();
try {
    require $plugin;
    ob_end_clean();
    ok(true, 'plugin file loads without fatal errors');
} catch (Throwable $e) {
    ob_end_clean();
    ok(false, 'plugin load threw: ' . $e->getMessage());
}

ok(defined('CSC_PLUGIN_VERSION'), 'CSC_PLUGIN_VERSION defined (' . (defined('CSC_PLUGIN_VERSION') ? constant('CSC_PLUGIN_VERSION') : '-') . ')');
ok(isset($GLOBALS['__smoke_activate']), 'activation hook registered');

if (isset($GLOBALS['__smoke_activate'])) {
    try {
        call_user_func($GLOBALS['__smoke_activate']);
        ok(true, 'activation callback runs without fatal errors');
    } catch (Throwable $e) {
        ok(false, 'activation callback threw: ' . $e->getMessage());
    }
}

foreach (['csc_hash_token', 'csc_verify_token', 'csc_issue_master_login_token', 'csc_nominatim_search', 'csc_status_labels_js', 'csc_t', 'csc_log'] as $fn) {
    ok(function_exists($fn), "function $fn exists");
}

echo "\nChecks: $checks, failed: $failed\n";
exit($failed === 0 ? 0 : 1);
