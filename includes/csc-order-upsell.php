<?php
/**
* Файл: csc-order-upsell.php
* Описание: Расширение чека мастером (Продление текущего заказа ИЛИ Доп. выезд).
* Версия: 3.0
*
* ИСПРАВЛЕНО:
* - Убран постоянный токен из URL. Используется одноразовый токен через транзиент.
* - Все операции с заказами через csc_get_all_orders() / csc_update_order().
* - Синхронизация кэшей через csc_cache_delete_orders().
* - esc_url() для всех URL.
* - esc_js() для всех PHP-переменных в JS.
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. БЕЗОПАСНОСТЬ И ГЕНЕРАЦИЯ ТОКЕНОВ
// ============================================================

/**
* ИСПРАВЛЕНО: генерация одноразового токена для ссылки расширения чека.
* Токен хранится в транзиенте на 24 часа и удаляется после первого использования.
*
* @param string $order_number Номер заказа
* @return string Одноразовый токен
*/
if (!function_exists('csc_get_upsell_token')) {
function csc_get_upsell_token($order_number) {
// Генерируем одноразовый токен
$one_time_token = wp_generate_password(32, false);
$transient_key = 'csc_upsell_token_' . md5($one_time_token);
set_transient($transient_key, $order_number, DAY_IN_SECONDS);
return $one_time_token;
}
}

/**
* ИСПРАВЛЕНО: проверка одноразового токена расширения чека.
*
* @param string $order_number Номер заказа
* @param string $token Одноразовый токен
* @return bool true если токен валиден
*/
if (!function_exists('csc_verify_upsell_token')) {
function csc_verify_upsell_token($order_number, $token) {
$transient_key = 'csc_upsell_token_' . md5($token);
$stored_order = get_transient($transient_key);
if ($stored_order === false || $stored_order !== $order_number) {
return false;
}
// Удаляем одноразовый токен после проверки
delete_transient($transient_key);
return true;
}
}

/**
* ИСПРАВЛЕНО: генерация ссылки на расширение чека без постоянного токена.
* Использует одноразовый токен через транзиент.
*
* @param string $order_number Номер заказа
* @return string URL расширения чека
*/
if (!function_exists('csc_get_upsell_link')) {
function csc_get_upsell_link($order_number) {
$token = csc_get_upsell_token($order_number);
// ИСПРАВЛЕНО: используем esc_url()
$url = add_query_arg([
'csc_upsell' => $order_number,
'token' => $token
], esc_url(site_url('/')));
return $url;
}
}

// ============================================================
// 2. СИНХРОНИЗАЦИЯ КАЛЕНДАРЯ (ЗАЩИТА ОТ ОВЕРБУКИНГА)
// ============================================================
if (!function_exists('csc_sync_booked_ranges_for_date')) {
function csc_sync_booked_ranges_for_date($date) {
// ИСПРАВЛЕНО: единая функция чтения заказов
$all_orders = csc_get_all_orders();
$s = get_option('csc_main_settings', []);
$travel_time = intval($s['travel_time'] ?? 0);
$ranges = [];
foreach ($all_orders as $o) {
if (($o['status'] ?? '') === 'отменено') continue;
if (($o['date'] ?? '') !== $date) continue;
$mid = $o['master_id'] ?? 'master_default';
$start_time = $o['time'] ?? '';
$dur = intval($o['duration'] ?? 0);
if ($start_time === '') continue;
$total_dur = $dur + $travel_time;
$start_ts = strtotime("$date $start_time");
if (!$start_ts) continue;
$end_ts = $start_ts + ($total_dur * 60);
$end_time = date('H:i', $end_ts);
$ranges[] = "{$start_time}-{$end_time}|{$mid}";
}
update_option('csc_booked_ranges_' . $date, $ranges, false);
}
}
// ============================================================
// 3. AJAX: ПРОВЕРКА ВОЗМОЖНОСТИ ПРОДЛИТЬ СЕЙЧАС ИЛИ СЛОТОВ НА ДРУГОЙ ДЕНЬ
// ============================================================
add_action('wp_ajax_csc_check_upsell_time', 'csc_check_upsell_time_handler');
add_action('wp_ajax_nopriv_csc_check_upsell_time', 'csc_check_upsell_time_handler');

if (!function_exists('csc_check_upsell_time_handler')) {
function csc_check_upsell_time_handler() {
if (!check_ajax_referer('csc_upsell_nonce', '_wpnonce', false)) {
wp_send_json_error(['message' => 'Ошибка безопасности']);
}
$action_type = sanitize_text_field($_POST['type'] ?? '');
$order_number = sanitize_text_field($_POST['order_number'] ?? '');
$token = sanitize_text_field($_POST['token'] ?? '');
$duration = intval($_POST['duration'] ?? 0);
// ИСПРАВЛЕНО: проверка одноразового токена
// Для AJAX-проверок не удаляем токен (только для сохранения)
$transient_key = 'csc_upsell_token_' . md5($token);
$stored_order = get_transient($transient_key);
if ($stored_order === false || $stored_order !== $order_number) {
wp_send_json_error(['message' => 'Неверный токен доступа']);
}
// ИСПРАВЛЕНО: единая функция чтения заказов
$all_orders = csc_get_all_orders();
$order = $all_orders[$order_number] ?? null;
if (!$order) wp_send_json_error(['message' => 'Заказ не найден']);
$master_id = $order['master_id'] ?? 'master_default';
$s = get_option('csc_main_settings', []);
$travel_time = intval($s['travel_time'] ?? 0);
if ($action_type === 'check_extension') {
$date = $order['date'] ?? '';
if (!$date) wp_send_json_error(['message' => 'Некорректная дата заказа']);
csc_sync_booked_ranges_for_date($date);
$ranges = get_option('csc_booked_ranges_' . $date, []);
$current_start_ts = strtotime($date . ' ' . ($order['time'] ?? '00:00'));
if (!$current_start_ts) {
wp_send_json_error(['message' => 'Некорректное время заказа']);
}
$current_end_ts = $current_start_ts + (intval($order['duration'] ?? 0) * 60);
$proposed_end_ts = $current_end_ts + ($duration * 60);
$end_hour = intval($s['work_hours_end'] ?? 18);
$work_end_ts = strtotime($date . ' ' . str_pad($end_hour, 2, '0', STR_PAD_LEFT) . ':00:00');
if ($proposed_end_ts > $work_end_ts) {
wp_send_json_success(['can_extend' => false, 'reason' => 'out_of_hours']);
}
$can_extend = true;
foreach ($ranges as $r) {
$parts = explode('|', $r);
if (count($parts) !== 2) continue;
list($time_range, $r_mid) = $parts;
if ($r_mid !== $master_id) continue;
$times = explode('-', $time_range);
if (count($times) !== 2) continue;
$b_start = strtotime("$date $times[0]");
$b_end = strtotime("$date $times[1]");
if ($b_start === $current_start_ts && abs($b_end - ($current_end_ts + $travel_time * 60)) < 60) {
continue;
}
if ($b_start < $proposed_end_ts && $b_end > $current_end_ts) {
$can_extend = false;
break;
}
}
wp_send_json_success(['can_extend' => $can_extend]);
}
if ($action_type === 'get_slots') {
$date = sanitize_text_field($_POST['date'] ?? '');
if (!$date || (function_exists('csc_is_workday') && !csc_is_workday($date))) {
wp_send_json_success([]);
}
csc_sync_booked_ranges_for_date($date);
$ranges = get_option('csc_booked_ranges_' . $date, []);
$total_needed = $duration + $travel_time;
$start_h = intval($s['work_hours_start'] ?? 9);
$end_h = intval($s['work_hours_end'] ?? 18);
$step = intval($s['interval_minutes'] ?? 30);
$curr = new DateTime("$date " . str_pad($start_h, 2, '0', STR_PAD_LEFT) . ':00');
$end_work = new DateTime("$date " . str_pad($end_h, 2, '0', STR_PAD_LEFT) . ':00');
$slots = [];
while ($curr < $end_work) {
$slot_ts = $curr->getTimestamp();
if ($slot_ts + ($total_needed * 60) > $end_work->getTimestamp()) break;
$is_free = true;
foreach ($ranges as $r) {
$parts = explode('|', $r);
if (count($parts) !== 2) continue;
list($time_range, $r_mid) = $parts;
if ($r_mid !== $master_id) continue;
$times = explode('-', $time_range);
if (count($times) !== 2) continue;
$b_start = strtotime("$date $times[0]");
$b_end = strtotime("$date $times[1]");
$my_end = $slot_ts + ($total_needed * 60);
if (!($my_end <= $b_start || $slot_ts >= $b_end)) {
$is_free = false;
break;
}
}
if ($is_free) $slots[] = $curr->format('H:i');
$curr->modify("+$step minutes");
}
wp_send_json_success($slots);
}
wp_send_json_error(['message' => 'Неизвестный тип действия']);
}
}

// ============================================================
// 4. AJAX: СОХРАНЕНИЕ ДАННЫХ В БД (ПРОДЛЕНИЕ ИЛИ НОВЫЙ ЗАКАЗ)
// ============================================================
add_action('wp_ajax_csc_save_order_upsell', 'csc_save_order_upsell_handler');
add_action('wp_ajax_nopriv_csc_save_order_upsell', 'csc_save_order_upsell_handler');

if (!function_exists('csc_save_order_upsell_handler')) {
function csc_save_order_upsell_handler() {
if (!check_ajax_referer('csc_upsell_nonce', '_wpnonce', false)) {
wp_send_json_error(['message' => 'Ошибка безопасности']);
}
$order_number = sanitize_text_field($_POST['order_number'] ?? '');
$token = sanitize_text_field($_POST['token'] ?? '');
$mode = sanitize_text_field($_POST['mode'] ?? 'extend');
$new_items = json_decode(stripslashes($_POST['new_items'] ?? '[]'), true);
// ИСПРАВЛЕНО: проверка одноразового токена с удалением
if (!csc_verify_upsell_token($order_number, $token)) {
wp_send_json_error(['message' => 'Неверный токен']);
}
if (!is_array($new_items) || empty($new_items)) {
wp_send_json_error(['message' => 'Пустой список услуг']);
}
// ИСПРАВЛЕНО: единая функция чтения заказов
$all_orders = csc_get_all_orders();
if (!isset($all_orders[$order_number])) {
wp_send_json_error(['message' => 'Заказ не найден']);
}
$order = $all_orders[$order_number];
if (($order['status'] ?? '') === 'отменено') {
wp_send_json_error(['message' => 'Заказ отменён и не может быть изменён']);
}
$master_id = $order['master_id'] ?? 'master_default';
$added_price = 0;
$added_duration = 0;
$services_display = [];
$clean_items = [];
foreach ($new_items as $item) {
if (!is_array($item)) continue;
$p = max(0, floatval($item['price'] ?? 0));
$d = max(0, intval($item['duration'] ?? 0));
$n = sanitize_text_field($item['name'] ?? '');
if ($n !== '' && $p > 0) {
$added_price += $p;
$added_duration += $d;
$services_display[] = "$n ×1 (" . number_format($p, 0, '.', ' ') . " ₽, {$d} мин.)";
$clean_items[] = [
'name' => $n,
'price' => $p,
'duration' => $d,
'added_at' => current_time('mysql')
];
}
}
if (empty($clean_items) || $added_price <= 0) {
wp_send_json_error(['message' => 'Сумма доп. работ должна быть больше нуля']);
}
if ($mode === 'extend') {
$lock_key = 'csc_upsell_lock_' . $order_number;
if (get_transient($lock_key)) {
wp_send_json_error(['message' => 'Заказ уже обрабатывается. Подождите.']);
}
set_transient($lock_key, 1, 15);
$s_extend = get_option('csc_main_settings', []);
$end_hour_extend = intval($s_extend['work_hours_end'] ?? 18);
$travel_time_extend = intval($s_extend['travel_time'] ?? 0);
$date_extend = $order['date'] ?? '';
if (!$date_extend) {
delete_transient($lock_key);
wp_send_json_error(['message' => 'Некорректная дата заказа']);
}
csc_sync_booked_ranges_for_date($date_extend);
$current_start_ts = strtotime($date_extend . ' ' . ($order['time'] ?? '00:00'));
if (!$current_start_ts) {
delete_transient($lock_key);
wp_send_json_error(['message' => 'Некорректная дата или время исходного заказа']);
}
$current_end_ts = $current_start_ts + (intval($order['duration'] ?? 0) * 60);
$proposed_end_ts = $current_end_ts + ($added_duration * 60);
$work_end_ts = strtotime($date_extend . ' ' . str_pad($end_hour_extend, 2, '0', STR_PAD_LEFT) . ':00:00');
if ($proposed_end_ts > $work_end_ts) {
delete_transient($lock_key);
wp_send_json_error(['message' => 'Продление выходит за пределы рабочего дня. Запланируйте доп. выезд.']);
}
$extend_ranges = get_option('csc_booked_ranges_' . $date_extend, []);
foreach ($extend_ranges as $extend_range) {
$extend_parts = explode('|', $extend_range);
if (count($extend_parts) !== 2) continue;
$extend_time_range = $extend_parts[0];
$extend_master_id = $extend_parts[1];
if ($extend_master_id !== $master_id) continue;
$extend_times = explode('-', $extend_time_range);
if (count($extend_times) !== 2) continue;
$b_start = strtotime($date_extend . ' ' . $extend_times[0]);
$b_end = strtotime($date_extend . ' ' . $extend_times[1]);
if ($b_start === $current_start_ts && abs($b_end - ($current_end_ts + $travel_time_extend * 60)) < 60) {
continue;
}
if ($b_start < $proposed_end_ts && $b_end > $current_end_ts) {
delete_transient($lock_key);
wp_send_json_error(['message' => 'Продление невозможно: время пересекается с другой записью мастера.']);
}
}
$order['total'] = floatval($order['total'] ?? 0) + $added_price;
$order['duration'] = intval($order['duration'] ?? 0) + $added_duration;
$ai = $order['additional_items'] ?? [];
$order['additional_items'] = array_merge($ai, $clean_items);
// ИСПРАВЛЕНО: сохранение через единую функцию
csc_update_order($order_number, $order);
csc_cache_delete_orders();
csc_sync_booked_ranges_for_date($date_extend);
delete_transient($lock_key);
// === ЛОГИРОВАНИЕ И УВЕДОМЛЕНИЯ ===
if (function_exists('csc_log_notification')) {
$customer_phone = csc_normalize_phone($order['phone'] ?? '');
csc_log_notification(
$order_number,
'admin',
'admin',
'web',
'🛠 Продление заказа мастером',
"Заказ #{$order_number} продлён на {$added_duration} мин. Добавлено: " . number_format($added_price, 0, '.', ' ') . " ₽.",
['mode' => 'extend', 'added_price' => $added_price, 'added_duration' => $added_duration]
);
if ($customer_phone) {
csc_log_notification(
$order_number,
'customer',
$customer_phone,
'web',
'🛠 Заказ дополнен',
"Ваш заказ #{$order_number} дополнен на месте. Добавлено: " . number_format($added_price, 0, '.', ' ') . " ₽, {$added_duration} мин.",
['mode' => 'extend']
);
}
}
// Web Push клиенту
$customer_phone = csc_normalize_phone($order['phone'] ?? '');
if ($customer_phone && function_exists('csc_send_web_push_notification')) {
$subs = get_option("csc_push_subs_customer_{$customer_phone}", []);
foreach ($subs as $sub) {
csc_send_web_push_notification(
$sub,
"🛠 Заказ #{$order_number} дополнен",
"Добавлено: " . number_format($added_price, 0, '.', ' ') . " ₽, {$added_duration} мин.",
$order_number,
'customer',
$customer_phone
);
}
}
wp_send_json_success(['message' => 'Текущий чек и время успешно увеличены!']);
} else {
// === РЕЖИМ: НОВЫЙ ДОП. ВЫЕЗД ===
// ИСПРАВЛЕНО 6.2: добавлена блокировка от race condition
$lock_key_visit = 'csc_upsell_visit_lock_' . $order_number;
if (get_transient($lock_key_visit)) {
wp_send_json_error(['message' => 'Доп. выезд уже создаётся. Подождите.']);
}
set_transient($lock_key_visit, 1, 30);

$date = sanitize_text_field($_POST['child_date'] ?? '');
$time = sanitize_text_field($_POST['child_time'] ?? '');
if (!$date || !$time) {
delete_transient($lock_key_visit);
wp_send_json_error(['message' => 'Не выбрана дата или время']);
}
csc_sync_booked_ranges_for_date($date);
$ranges = get_option('csc_booked_ranges_' . $date, []);
$s = get_option('csc_main_settings', []);
$travel = intval($s['travel_time'] ?? 0);
$req_start = strtotime("$date $time");
if (!$req_start) {
delete_transient($lock_key_visit);
wp_send_json_error(['message' => 'Некорректная дата или время']);
}
$req_end = $req_start + (($added_duration + $travel) * 60);
foreach ($ranges as $r) {
$parts = explode('|', $r);
if (count($parts) !== 2) continue;
list($t_range, $r_mid) = $parts;
if ($r_mid === $master_id) {
$times = explode('-', $t_range);
if (count($times) !== 2) continue;
$b_start = strtotime("$date $times[0]");
$b_end = strtotime("$date $times[1]");
if (!($req_end <= $b_start || $req_start >= $b_end)) {
delete_transient($lock_key_visit);
wp_send_json_error(['message' => 'Этот слот только что был занят. Выберите другое время.']);
}
}
}
// ПРАВКА: защита дочернего номера от коллизии
do {
$child_id = $order_number . '-DOP-' . strtoupper(substr(wp_generate_uuid4(), 0, 8));
} while (isset($all_orders[$child_id]));
$child_order = [
'order_number' => $child_id,
'parent_order' => $order_number,
'name' => $order['name'] ?? '',
'phone' => $order['phone'] ?? '',
'address' => $order['address'] ?? '',
'date' => $date,
'time' => $time,
'duration' => $added_duration,
'total' => $added_price,
'services' => $services_display,
'master_id' => $master_id,
'status' => 'в работе',
'created_at' => current_time('mysql'),
'is_child' => true,
'additional_items' => $clean_items
];
$all_orders[$order_number]['has_child_order'] = $child_id;
// ИСПРАВЛЕНО: сохранение через единую функцию
csc_update_order($order_number, $all_orders[$order_number]);
csc_save_order($child_id, $child_order);
csc_cache_delete_orders();
csc_sync_booked_ranges_for_date($date);
delete_transient($lock_key_visit);
// === ЛОГИРОВАНИЕ И УВЕДОМЛЕНИЯ ===
if (function_exists('csc_log_notification')) {
$customer_phone = csc_normalize_phone($order['phone'] ?? '');
csc_log_notification(
$order_number,
'admin',
'admin',
'web',
'📅 Создан доп. выезд',
"Для заказа #{$order_number} создан доп. выезд #{$child_id} на {$date} в {$time}. Сумма: " . number_format($added_price, 0, '.', ' ') . " ₽.",
['mode' => 'new_visit', 'child_id' => $child_id, 'date' => $date, 'time' => $time]
);
if ($customer_phone) {
csc_log_notification(
$order_number,
'customer',
$customer_phone,
'web',
'📅 Запланирован доп. выезд',
"Для вашего заказа #{$order_number} запланирован доп. выезд #{$child_id} на {$date} в {$time}.",
['mode' => 'new_visit', 'child_id' => $child_id]
);
}
}
wp_send_json_success(['message' => 'Создан дополнительный выезд: #' . $child_id]);
}
}
}
// ============================================================
// 5. ИНТЕРФЕЙС МАСТЕРА (ПЕРЕХВАТ URL / ВЕБ-ПРИЛОЖЕНИЕ)
// ============================================================
add_action('template_redirect', 'csc_render_upsell_interface');

if (!function_exists('csc_render_upsell_interface')) {
function csc_render_upsell_interface() {
if (!isset($_GET['csc_upsell']) || !isset($_GET['token'])) return;
$order_number = sanitize_text_field($_GET['csc_upsell']);
$token = sanitize_text_field($_GET['token']);
// ИСПРАВЛЕНО: проверка одноразового токена
// Для интерфейса не удаляем токен (только для сохранения)
$transient_key = 'csc_upsell_token_' . md5($token);
$stored_order = get_transient($transient_key);
if ($stored_order === false || $stored_order !== $order_number) {
wp_die('Ошибка доступа. Ссылка недействительна или истекла.', 'Доступ закрыт', ['response' => 403]);
}
// ИСПРАВЛЕНО: единая функция чтения заказов
$all_orders = csc_get_all_orders();
if (!isset($all_orders[$order_number])) {
wp_die('Заказ не найден.', 'Ошибка', ['response' => 404]);
}
$order = $all_orders[$order_number];
if (($order['status'] ?? '') === 'отменено') {
wp_die('Заказ отменён и не может быть изменён.', 'Ошибка', ['response' => 403]);
}
$services = function_exists('csc_get_items') ? csc_get_items() : [];
// Генерация nonce для AJAX
$ajax_nonce = wp_create_nonce('csc_upsell_nonce');
// ИСПРАВЛЕНО: единая переменная для AJAX URL с esc_url
$ajax_url = esc_url(admin_url('admin-ajax.php'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Изменение чека - <?php echo esc_html($order_number); ?></title>
<style>
:root { --p: #3b82f6; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); padding: 15px; margin: 0; }
.card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 15px; }
.item-row { display: flex; gap: 8px; margin-bottom: 10px; align-items: center; background: #f1f5f9; padding: 10px; border-radius: 8px; flex-wrap: wrap;}
.item-row input { padding: 8px; border: 1px solid var(--border); border-radius: 6px; flex: 1; min-width: 100px; }
.btn { background: var(--p); color: #fff; border: none; padding: 14px; border-radius: 8px; width: 100%; font-size: 16px; font-weight: 600; cursor: pointer; }
.btn-outline { background: transparent; color: var(--p); border: 2px dashed var(--p); }
.btn:disabled { opacity: 0.5; }
.remove-btn { color: #ef4444; background: none; border: none; font-size: 24px; cursor: pointer; }
.radio-group { display: flex; flex-direction: column; gap: 10px; margin-top: 15px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid var(--border); }
.radio-label { display: flex; align-items: center; gap: 10px; font-weight: 500; cursor: pointer;}
#schedule-block { display: none; margin-top: 15px; border-top: 1px dashed var(--border); padding-top: 15px; }
.form-input { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 10px; box-sizing: border-box;}
.alert { padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 14px; }
.alert-danger { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="card">
<h2 style="margin:0 0 5px 0;">Заказ #<?php echo esc_html($order_number); ?></h2>
<p style="margin:0; color:#64748b; font-size:14px;">Дата: <?php echo esc_html($order['date'] ?? ''); ?> в <?php echo esc_html($order['time'] ?? ''); ?></p>
</div>
<div class="card">
<h3 style="margin-top:0;">Доп. работы</h3>
<div id="upsell-container"></div>
<button class="btn btn-outline" id="add-row-btn">+ Добавить услугу</button>
<div style="font-size: 18px; font-weight: bold; text-align: right; margin-top: 15px;">
Сумма доп. работ: <span id="add-total-price" style="color:#10b981;">0</span> ₽<br>
<small style="color:#64748b; font-size:13px;">Доп. время: <span id="add-total-dur">0</span> мин.</small>
</div>
</div>
<div class="card" id="logic-card" style="display:none;">
<h3 style="margin-top:0;">Как выполняем?</h3>
<div id="availability-alert"></div>
<div class="radio-group">
<label class="radio-label">
<input type="radio" name="exec_mode" value="extend" id="mode-extend">
<span>Продлить текущий выезд (сделать сейчас)</span>
</label>
<label class="radio-label">
<input type="radio" name="exec_mode" value="new_visit" id="mode-schedule">
<span>Запланировать доп. выезд (другой день)</span>
</label>
</div>
<div id="schedule-block">
<label>Дата выезда:</label>
<input type="date" id="new-date" class="form-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
<label>Время:</label>
<select id="new-time" class="form-input"><option value="">Сначала выберите дату</option></select>
</div>
</div>
<div style="margin-top: 20px; display: flex; gap: 10px;">
<button class="btn" id="save-btn" disabled style="flex: 2;">Сохранить чек</button>
<button class="btn" id="cancel-btn" style="flex: 1; background: #94a3b8;">Отмена</button>
</div>
<script>
// ИСПРАВЛЕНО: все переменные из PHP обёрнуты в esc_js
const services = <?php echo wp_json_encode(array_values($services)); ?>;
const orderNum = '<?php echo esc_js($order_number); ?>';
const TOKEN = '<?php echo esc_js($token); ?>';
const NONCE = '<?php echo esc_js($ajax_nonce); ?>';
const AJAX_URL = '<?php echo esc_js($ajax_url); ?>';
let totalDur = 0;
let currentMode = '';
let isChanged = false;
document.addEventListener('change', (e) => {
if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') {
isChanged = true;
}
});
window.onbeforeunload = function() {
if (isChanged) return "У вас есть несохраненные изменения. Уверены, что хотите выйти?";
};
document.getElementById('cancel-btn').addEventListener('click', () => {
if (confirm('Уверены, что хотите отменить оформление? Все несохраненные данные будут удалены.')) {
isChanged = false;
if (window.Telegram && window.Telegram.WebApp) {
window.Telegram.WebApp.close();
} else {
window.close();
}
document.body.innerHTML = '<div class="card" style="text-align:center; margin-top:50px;"><h2>Оформление отменено</h2><p>Вы можете закрыть эту вкладку.</p></div>';
}
});
document.getElementById('add-row-btn').addEventListener('click', () => {
const row = document.createElement('div');
row.className = 'item-row';
const listId = 'sl-' + Date.now();
let datalist = `<datalist id="${listId}">`;
services.forEach(s => { if(s.is_available) datalist += `<option value="${s.name}" data-price="${s.price}" data-dur="${s.duration}">`; });
datalist += `</datalist>`;
row.innerHTML = `
${datalist}
<input type="text" placeholder="Услуга" list="${listId}" class="u-name" style="flex: 1 1 100%;">
<input type="number" placeholder="₽" class="u-price" style="flex: 1;" min="0">
<input type="number" placeholder="Мин." class="u-dur" style="flex: 1;" min="0">
<button class="remove-btn">×</button>
`;
row.querySelector('.u-name').addEventListener('input', (e) => {
const sel = services.find(s => s.name === e.target.value);
if (sel) {
row.querySelector('.u-price').value = sel.price;
row.querySelector('.u-dur').value = sel.duration;
recalc();
}
});
row.querySelectorAll('input').forEach(i => i.addEventListener('input', recalc));
row.querySelector('.remove-btn').addEventListener('click', () => { row.remove(); recalc(); });
document.getElementById('upsell-container').appendChild(row);
});
function recalc() {
let price = 0, dur = 0;
document.querySelectorAll('.item-row').forEach(r => {
const p = parseFloat(r.querySelector('.u-price').value) || 0;
const d = parseInt(r.querySelector('.u-dur').value) || 0;
if (p > 0) price += p;
if (d > 0) dur += d;
});
document.getElementById('add-total-price').innerText = price;
document.getElementById('add-total-dur').innerText = dur;
totalDur = dur;
const logicCard = document.getElementById('logic-card');
const saveBtn = document.getElementById('save-btn');
if (dur > 0 && price > 0) {
logicCard.style.display = 'block';
checkExtensionAvailability(dur);
} else {
logicCard.style.display = 'none';
saveBtn.disabled = true;
}
}
function checkExtensionAvailability(duration) {
const alertBox = document.getElementById('availability-alert');
alertBox.innerHTML = '<span style="color:#64748b">Проверка расписания...</span>';
document.getElementById('mode-extend').disabled = true;
const fd = new URLSearchParams({
action: 'csc_check_upsell_time',
type: 'check_extension',
order_number: orderNum,
duration: duration,
token: TOKEN,
_wpnonce: NONCE
});
fetch(AJAX_URL, { method: 'POST', body: fd })
.then(r => r.json())
.then(res => {
if (res.success && res.data.can_extend) {
alertBox.innerHTML = '';
document.getElementById('mode-extend').disabled = false;
document.getElementById('mode-extend').checked = true;
document.getElementById('schedule-block').style.display = 'none';
document.getElementById('save-btn').disabled = false;
currentMode = 'extend';
} else {
const reason = res.data?.reason || '';
const msg = reason === 'out_of_hours'
? 'Продление выходит за пределы рабочего дня. Запланируйте доп. выезд.'
: 'У вас нет времени на эти работы сегодня (запись пересекается с другим клиентом). Запланируйте доп. выезд.';
alertBox.innerHTML = '<div class="alert alert-danger">' + msg + '</div>';
document.getElementById('mode-extend').disabled = true;
document.getElementById('mode-schedule').checked = true;
document.getElementById('schedule-block').style.display = 'block';
document.getElementById('save-btn').disabled = true;
currentMode = 'new_visit';
}
})
.catch(e => {
alertBox.innerHTML = '<div class="alert alert-danger">Ошибка соединения с сервером</div>';
console.error('Check extension error:', e);
});
}
document.querySelectorAll('input[name="exec_mode"]').forEach(r => {
r.addEventListener('change', (e) => {
currentMode = e.target.value;
const sch = document.getElementById('schedule-block');
const btn = document.getElementById('save-btn');
if (currentMode === 'new_visit') {
sch.style.display = 'block';
btn.disabled = !document.getElementById('new-time').value;
} else {
sch.style.display = 'none';
btn.disabled = false;
}
});
});
document.getElementById('new-date').addEventListener('change', function() {
const date = this.value;
if(!date) return;
const timeSel = document.getElementById('new-time');
timeSel.innerHTML = '<option>Загрузка...</option>';
const fd = new URLSearchParams({
action: 'csc_check_upsell_time',
type: 'get_slots',
order_number: orderNum,
date: date,
duration: totalDur,
token: TOKEN,
_wpnonce: NONCE
});
fetch(AJAX_URL, { method: 'POST', body: fd })
.then(r => r.json())
.then(res => {
timeSel.innerHTML = '<option value="">Выберите время</option>';
if (res.success && res.data && res.data.length > 0) {
res.data.forEach(s => timeSel.innerHTML += `<option value="${s}">${s}</option>`);
} else {
timeSel.innerHTML = '<option value="">Нет свободных окон</option>';
}
})
.catch(e => {
timeSel.innerHTML = '<option value="">Ошибка загрузки</option>';
console.error('Get slots error:', e);
});
});
document.getElementById('new-time').addEventListener('change', function() {
document.getElementById('save-btn').disabled = !this.value;
});
document.getElementById('save-btn').addEventListener('click', () => {
const items = [];
document.querySelectorAll('.item-row').forEach(r => {
const name = r.querySelector('.u-name').value.trim();
const price = parseFloat(r.querySelector('.u-price').value) || 0;
const duration = parseInt(r.querySelector('.u-dur').value) || 0;
if (name && price > 0) {
items.push({ name, price, duration });
}
});
if (items.length === 0) {
alert('Добавьте хотя бы одну услугу с корректной ценой.');
return;
}
const fd = new URLSearchParams({
action: 'csc_save_order_upsell',
order_number: orderNum,
token: TOKEN,
mode: currentMode,
new_items: JSON.stringify(items),
child_date: document.getElementById('new-date') ? document.getElementById('new-date').value : '',
child_time: document.getElementById('new-time') ? document.getElementById('new-time').value : '',
_wpnonce: NONCE
});
document.getElementById('save-btn').disabled = true;
document.getElementById('save-btn').innerText = 'Сохранение...';
fetch(AJAX_URL, { method: 'POST', body: fd })
.then(r => r.json())
.then(res => {
if (res.success) {
isChanged = false;
alert('✅ ' + res.data.message);
if (window.Telegram && window.Telegram.WebApp) {
window.Telegram.WebApp.close();
} else {
window.close();
}
document.body.innerHTML = '<h2 style="text-align:center; margin-top:50px;">Данные успешно сохранены. Окно можно закрыть.</h2>';
} else {
alert('❌ ' + (res.data.message || 'Ошибка'));
document.getElementById('save-btn').disabled = false;
document.getElementById('save-btn').innerText = 'Сохранить изменения';
}
})
.catch(e => {
alert('Ошибка соединения с сервером');
document.getElementById('save-btn').disabled = false;
document.getElementById('save-btn').innerText = 'Сохранить изменения';
console.error('Save error:', e);
});
});
</script>
</body>
</html>
<?php
exit;
}
}
// ============================================================
// 6. ОТОБРАЖЕНИЕ В АДМИНКЕ (ИНЪЕКЦИЯ ДАННЫХ)
// ============================================================
add_action('admin_footer', 'csc_inject_upsell_into_admin_table');

if (!function_exists('csc_inject_upsell_into_admin_table')) {
function csc_inject_upsell_into_admin_table() {
if (!isset($_GET['page']) || $_GET['page'] !== 'csc_orders_admin') return;
// ИСПРАВЛЕНО: единая функция чтения заказов
$all_orders = csc_get_all_orders();
$upsell_data = [];
foreach ($all_orders as $num => $o) {
if (!empty($o['additional_items'])) {
$upsell_data[$num] = $o['additional_items'];
}
if (!empty($o['is_child'])) {
$upsell_data[$num] = 'child';
}
}
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
const upData = <?php echo wp_json_encode($upsell_data); ?>;
document.querySelectorAll('.wp-list-table tbody tr').forEach(tr => {
const numEl = tr.querySelector('strong');
if (!numEl) return;
const text = numEl.textContent.trim().replace('#', '');
if (upData[text] === 'child') {
const badge = document.createElement('span');
badge.innerHTML = ' <span style="background:#8b5cf6; color:#fff; padding:2px 6px; border-radius:4px; font-size:10px; vertical-align:middle;">ДОП. ВЫЕЗД</span>';
numEl.appendChild(badge);
}
else if (upData[text]) {
const svcsTd = tr.querySelectorAll('td')[4];
if (svcsTd) {
let html = '<div style="margin-top:5px; padding:5px; background:#fffbeb; border:1px solid #fcd34d; border-radius:4px; font-size:11px;">';
html += '<strong style="color:#d97706;">+ Добавлено на месте:</strong><br>';
upData[text].forEach(i => {
const rawName = String(i.name || 'Услуга');
const price = parseFloat(i.price) || 0;
// ИСПРАВЛЕНО: экранирование через replace
const safeName = rawName.replace(/[&<>"']/g, function(ch) {
if (ch === '&') return '&amp;';
if (ch === '<') return '&lt;';
if (ch === '>') return '&gt;';
if (ch === '"') return '&quot;';
return '&#039;';
});
html += '- ' + safeName + ' (' + price + ' ₽)<br>';
});
html += '</div>';
svcsTd.innerHTML += html;
}
}
});
});
</script>
<?php
}
}