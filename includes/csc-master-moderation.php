<?php
/**
* Файл: csc-master-moderation.php
* Описание: Админ-панель модерации заявок мастеров
* Версия: 1.3 (Добавлены внутрикабинетные уведомления + push при модерации)
*
* Функционал:
* - Список заявок мастеров с фильтрами
* - Просмотр документов
* - Одобрение/отклонение
* - Блокировка/разблокировка
* - Полная карточка мастера (Анкета, Работа, Документы, Статистика, Финансы, Отзывы, История)
* - НОВОЕ 1.3: внутрикабинетные уведомления мастеру при каждом действии модерации
* - НОВОЕ 1.3: push-уведомления мастеру при модерации
*/
if (!defined('ABSPATH')) exit;
// ============================================================
// 1. РЕГИСТРАЦИЯ СТРАНИЦЫ В АДМИНКЕ
// ============================================================
if (!function_exists('csc_add_master_moderation_page')) {
function csc_add_master_moderation_page() {
add_submenu_page(
'options-general.php',   // ← Правильный parent (Настройки WordPress)
'Модерация мастеров',
'👥 Модерация мастеров',
'manage_options',
'csc-master-moderation',
'csc_render_master_moderation_page'
);
}
}
add_action('admin_menu', 'csc_add_master_moderation_page');
// ============================================================
// НОВОЕ 1.3: ФУНКЦИЯ ВНУТРИКАБИНЕТНЫХ УВЕДОМЛЕНИЙ МАСТЕРА
// Хранит уведомления в wp_options по ключу мастера.
// Вызывается при каждом действии модерации.
// ============================================================
if (!function_exists('csc_add_master_notification')) {
function csc_add_master_notification($master_id, $type, $title, $message, $data = []) {
$option_key = 'csc_master_notifications_' . $master_id;
$notifications = get_option($option_key, []);
if (!is_array($notifications)) $notifications = [];
array_unshift($notifications, [
'id'         => 'n_' . wp_generate_password(8, false),
'type'       => $type,
'title'      => $title,
'message'    => $message,
'data'       => $data,
'read'       => false,
'created_at' => current_time('mysql'),
]);
// Храним максимум 50 уведомлений
$notifications = array_slice($notifications, 0, 50);
update_option($option_key, $notifications, false);
// Отправка push-уведомления мастеру если есть подписки
if (function_exists('csc_send_web_push_notification')) {
$subs = get_option("csc_push_subs_master_{$master_id}", []);
foreach ($subs as $sub) {
csc_send_web_push_notification($sub, $title, $message, '', 'master', $master_id);
}
}
// Логирование в систему уведомлений
if (function_exists('csc_log_notification')) {
csc_log_notification('', 'master', $master_id, 'internal', $title, $message, $data);
}
}
}
// ============================================================
// НОВОЕ 1.3: ПОЛУЧЕНИЕ УВЕДОМЛЕНИЙ МАСТЕРА (для дашборда)
// ============================================================
if (!function_exists('csc_get_master_notifications')) {
function csc_get_master_notifications($master_id) {
$option_key = 'csc_master_notifications_' . $master_id;
$notifications = get_option($option_key, []);
if (!is_array($notifications)) return [];
return $notifications;
}
}
// ============================================================
// НОВОЕ 1.3: ПОМЕТИТЬ УВЕДОМЛЕНИЯ МАСТЕРА КАК ПРОЧИТАННЫЕ
// ============================================================
if (!function_exists('csc_mark_master_notifications_read')) {
function csc_mark_master_notifications_read($master_id) {
$option_key = 'csc_master_notifications_' . $master_id;
$notifications = get_option($option_key, []);
if (!is_array($notifications)) return;
foreach ($notifications as &$n) {
$n['read'] = true;
}
unset($n);
update_option($option_key, $notifications, false);
}
}
// ============================================================
// 2. ОБРАБОТКА ДЕЙСТВИЙ МОДЕРАЦИИ
//    НОВОЕ 1.3: внутрикабинетные уведомления + push мастеру
// ============================================================
if (!function_exists('csc_handle_moderation_action')) {
function csc_handle_moderation_action() {
if (!isset($_POST['csc_moderation_action']) || !check_admin_referer('csc_moderation_action', 'csc_moderation_nonce')) {
return;
}
$action = sanitize_text_field($_POST['csc_moderation_action']);
$master_id = isset($_POST['master_id']) ? sanitize_text_field($_POST['master_id']) : '';
$reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
if (empty($master_id)) return;
$masters = function_exists('csc_get_masters') ? csc_get_masters() : get_option('csc_masters_list', array());
if (!isset($masters[$master_id])) return;
$master = $masters[$master_id];
switch ($action) {
case 'approve':
$masters[$master_id]['status'] = 'active';
$masters[$master_id]['is_online'] = true;
$masters[$master_id]['approved_at'] = current_time('mysql');
$masters[$master_id]['rejection_reason'] = '';
// Отправка ссылки мастеру (одноразовый креденшел вместо stored-хэша)
$login_cred = function_exists('csc_issue_master_login_token') ? csc_issue_master_login_token($master_id) : '';
$dashboard_url = home_url('/?csc_master_dashboard=1&mid=' . urlencode($master_id)) . ($login_cred !== '' ? '&token=' . urlencode($login_cred) : '');
$subject = '✅ Ваша заявка одобрена!';
$message = "Здравствуйте, {$master['name']}!

";
$message .= "Ваша заявка на регистрацию мастера успешно одобрена.
";
$message .= "Теперь вы можете начать принимать заказы.

";
$message .= "Ссылка на личный кабинет:
{$dashboard_url}

";
$message .= "Сохраните эту ссылку - она нужна для входа.
";
$message .= "Не забудьте настроить расписание работы в кабинете.";
$master_email = isset($master['email']) ? $master['email'] : '';
if (!empty($master_email)) {
wp_mail($master_email, $subject, $message);
}
// === НОВОЕ 1.3: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ + PUSH ===
if (function_exists('csc_add_master_notification')) {
csc_add_master_notification(
$master_id,
'approve',
'✅ Заявка одобрена!',
'Ваша заявка успешно прошла модерацию. Теперь вы можете принимать заказы.',
['dashboard_url' => $dashboard_url, 'action' => 'approve']
);
}
break;
case 'reject':
$masters[$master_id]['status'] = 'rejected';
$masters[$master_id]['is_online'] = false;
$masters[$master_id]['rejection_reason'] = !empty($reason) ? $reason : 'Причина не указана';
// Отправка уведомления мастеру
$subject = '❌ Заявка отклонена';
$reject_reason = !empty($reason) ? $reason : 'Причина не указана';
$message = "Здравствуйте, {$master['name']}!

";
$message .= "К сожалению, ваша заявка на регистрацию мастера была отклонена.

";
$message .= "Причина: " . $reject_reason . "

";
$message .= "Вы можете исправить указанные замечания и подать заявку повторно.";
$master_email = isset($master['email']) ? $master['email'] : '';
if (!empty($master_email)) {
wp_mail($master_email, $subject, $message);
}
// === НОВОЕ 1.3: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ + PUSH ===
if (function_exists('csc_add_master_notification')) {
csc_add_master_notification(
$master_id,
'reject',
'❌ Заявка отклонена',
'Причина: ' . $reject_reason,
['reason' => $reject_reason, 'action' => 'reject']
);
}
break;
case 'block':
$masters[$master_id]['status'] = 'blocked';
$masters[$master_id]['is_online'] = false;
// Уведомление мастеру о блокировке
$settings = function_exists('csc_get_registration_settings') ? csc_get_registration_settings() : array();
// ИСПРАВЛЕНО: было 3 скобки вместо 2
$notify_on_block = isset($settings['notify_master_on_block']) ? $settings['notify_master_on_block'] : false;
if (!empty($notify_on_block)) {
$penalties_total = isset($master['penalties_total']) ? intval($master['penalties_total']) : 0;
$penalties_amount = isset($master['penalties_amount']) ? floatval($master['penalties_amount']) : 0;
$subject = '🚫 Аккаунт заблокирован';
$message = "Здравствуйте, {$master['name']}!

";
$message .= "Ваш аккаунт был заблокирован из-за превышения лимита штрафов.
";
$message .= "Всего штрафов: " . $penalties_total . "
";
$message .= "Сумма штрафов: " . number_format($penalties_amount, 0, '.', ' ') . " ₽

";
$message .= "Для разблокировки свяжитесь с администрацией.";
$master_email = isset($master['email']) ? $master['email'] : '';
if (!empty($master_email)) {
wp_mail($master_email, $subject, $message);
}
}
// === НОВОЕ 1.3: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ + PUSH ===
if (function_exists('csc_add_master_notification')) {
$block_reason = !empty($reason) ? $reason : 'Нарушение правил';
csc_add_master_notification(
$master_id,
'block',
'🚫 Аккаунт заблокирован',
'Причина: ' . $block_reason . '. Свяжитесь с администрацией.',
['reason' => $block_reason, 'action' => 'block']
);
}
break;
case 'unblock':
$masters[$master_id]['status'] = 'active';
$masters[$master_id]['is_online'] = false;
$masters[$master_id]['penalties_total'] = 0;
$masters[$master_id]['penalties_amount'] = 0;
// === НОВОЕ 1.3: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ + PUSH ===
if (function_exists('csc_add_master_notification')) {
$login_cred = function_exists('csc_issue_master_login_token') ? csc_issue_master_login_token($master_id) : '';
$dashboard_url = home_url('/?csc_master_dashboard=1&mid=' . urlencode($master_id)) . ($login_cred !== '' ? '&token=' . urlencode($login_cred) : '');
csc_add_master_notification(
$master_id,
'unblock',
'🔓 Аккаунт разблокирован',
'Вы снова можете принимать заказы.',
['dashboard_url' => $dashboard_url, 'action' => 'unblock']
);
}
break;
case 'delete':
// Удаление файлов
if (!empty($master['photo'])) {
$upload_dir = wp_upload_dir();
$photo_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $master['photo']);
if (file_exists($photo_path)) @unlink($photo_path);
}
if (!empty($master['document_photo'])) {
$upload_dir = wp_upload_dir();
$doc_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $master['document_photo']);
if (file_exists($doc_path)) @unlink($doc_path);
}
unset($masters[$master_id]);
break;
case 'toggle_activity':
$current_online = !empty($masters[$master_id]['is_online']);
$masters[$master_id]['is_online'] = !$current_online;
$masters[$master_id]['status'] = !$current_online ? 'active' : 'inactive';
break;
case 'reset_penalties':
$masters[$master_id]['penalties_total'] = 0;
$masters[$master_id]['penalties_amount'] = 0;
$masters[$master_id]['rejections_count'] = 0;
$masters[$master_id]['no_shows_count'] = 0;
// === НОВОЕ 1.3: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ + PUSH ===
if (function_exists('csc_add_master_notification')) {
csc_add_master_notification(
$master_id,
'reset_penalties',
'🔄 Штрафы сброшены',
'Все штрафы были сброшены администратором.',
['action' => 'reset_penalties']
);
}
break;
}
update_option('csc_masters_list', $masters, false);
if (function_exists('csc_cache_delete_masters')) {
csc_cache_delete_masters();
}
// Логирование действий модерации
if (function_exists('csc_log_notification') && $action !== 'toggle_activity') {
$action_labels = array(
'approve' => '✅ Одобрен',
'reject' => '❌ Отклонён',
'block' => '🚫 Заблокирован',
'unblock' => '🔓 Разблокирован',
'delete' => '🗑️ Удалён',
'reset_penalties' => '🔄 Штрафы сброшены',
);
$action_label = isset($action_labels[$action]) ? $action_labels[$action] : $action;
$master_name = isset($master['name']) ? $master['name'] : '';
$master_city = isset($master['city_name']) ? $master['city_name'] : '';
$log_message = "Мастер {$master_name} ({$master_city}) - {$action_label}";
if (!empty($reason)) {
$log_message .= " | Причина: {$reason}";
}
csc_log_notification(
'',
'admin',
get_current_user_id(),
'web',
'Модерация: ' . $action_label,
$log_message,
array('master_id' => $master_id, 'action' => $action)
);
}
// ИСПРАВЛЕНО: используем wp_safe_redirect и проверяем headers_sent
$redirect_url = add_query_arg(array(
'page' => 'csc-master-moderation',
'moderation_done' => $action,
'master_id' => $master_id,
), admin_url('options-general.php'));

if (!headers_sent()) {
wp_safe_redirect($redirect_url);
exit;
} else {
// Фолбэк: если заголовки уже отправлены, выводим сообщение и ссылку
echo '<div class="notice notice-success"><p>Действие выполнено. <a href="' . esc_url($redirect_url) . '">Вернуться к модерации</a></p></div>';
}
}
}

add_action('admin_init', 'csc_handle_moderation_action');
// ============================================================
// 3. РЕНДЕР СТРАНИЦЫ МОДЕРАЦИИ
// ============================================================
if (!function_exists('csc_render_master_moderation_page')) {
function csc_render_master_moderation_page() {
$masters = function_exists('csc_get_masters') ? csc_get_masters() : get_option('csc_masters_list', array());
$filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
// Уведомление об успешном действии
if (isset($_GET['moderation_done'])) {
$action_labels = array(
'approve' => 'Мастер одобрен и активирован',
'reject' => 'Заявка отклонена',
'block' => 'Мастер заблокирован',
'unblock' => 'Мастер разблокирован',
'delete' => 'Мастер удалён',
'toggle_activity' => 'Статус изменён',
'reset_penalties' => 'Штрафы сброшены',
);
$done_action = sanitize_text_field($_GET['moderation_done']);
$label = isset($action_labels[$done_action]) ? $action_labels[$done_action] : 'Действие выполнено';
echo '<div class="notice notice-success"><p>' . esc_html($label) . '</p></div>';
}
// Фильтрация
$filtered_masters = array();
foreach ($masters as $mid => $master) {
// Фильтр по статусу
$master_status = isset($master['status']) ? $master['status'] : 'pending';
if ($filter !== 'all' && $master_status !== $filter) {
continue;
}
// Поиск
if (!empty($search)) {
$search_lower = mb_strtolower($search);
$found = false;
$name = isset($master['name']) ? $master['name'] : '';
$phone = isset($master['phone']) ? $master['phone'] : '';
$email = isset($master['email']) ? $master['email'] : '';
$city = isset($master['city_name']) ? $master['city_name'] : '';
if (mb_stripos($name, $search_lower) !== false) $found = true;
if (mb_stripos($phone, $search_lower) !== false) $found = true;
if (mb_stripos($email, $search_lower) !== false) $found = true;
if (mb_stripos($city, $search_lower) !== false) $found = true;
if (!$found) continue;
}
$filtered_masters[$mid] = $master;
}
// Сортировка: новые заявки первыми
uasort($filtered_masters, function($a, $b) {
$status_order = array('pending' => 0, 'active' => 1, 'inactive' => 2, 'blocked' => 3, 'rejected' => 4);
$s_a_status = isset($a['status']) ? $a['status'] : 'pending';
$s_b_status = isset($b['status']) ? $b['status'] : 'pending';
$s_a = isset($status_order[$s_a_status]) ? $status_order[$s_a_status] : 99;
$s_b = isset($status_order[$s_b_status]) ? $status_order[$s_b_status] : 99;
if ($s_a === $s_b) {
$a_date = isset($a['created_at']) ? $a['created_at'] : '';
$b_date = isset($b['created_at']) ? $b['created_at'] : '';
return strcmp($b_date, $a_date);
}
return $s_a - $s_b;
});
// Подсчёт по статусам
$counts = array('all' => count($masters), 'pending' => 0, 'active' => 0, 'inactive' => 0, 'blocked' => 0, 'rejected' => 0);
foreach ($masters as $master) {
$status = isset($master['status']) ? $master['status'] : 'pending';
if (isset($counts[$status])) $counts[$status]++;
}
$status_labels = array(
'pending' => '⏳ На модерации',
'active' => '🟢 Активен',
'inactive' => '⚪ Неактивен',
'blocked' => '🚫 Заблокирован',
'rejected' => '❌ Отклонён',
);
?>
<div class="wrap">
<h1>Модерация мастеров</h1>
<!-- Фильтры -->
<div class="csc-moderation-filters" style="margin: 20px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
<form method="get" style="display: inline-flex; gap: 10px; align-items: center;">
<input type="hidden" name="page" value="csc-master-moderation">
<select name="status" onchange="this.form.submit()">
<option value="all" <?php selected($filter, 'all'); ?>>Все (<?php echo $counts['all']; ?>)</option>
<option value="pending" <?php selected($filter, 'pending'); ?>>⏳ На модерации (<?php echo $counts['pending']; ?>)</option>
<option value="active" <?php selected($filter, 'active'); ?>>🟢 Активные (<?php echo $counts['active']; ?>)</option>
<option value="inactive" <?php selected($filter, 'inactive'); ?>>⚪ Неактивные (<?php echo $counts['inactive']; ?>)</option>
<option value="blocked" <?php selected($filter, 'blocked'); ?>>🚫 Заблокированные (<?php echo $counts['blocked']; ?>)</option>
<option value="rejected" <?php selected($filter, 'rejected'); ?>>❌ Отклонённые (<?php echo $counts['rejected']; ?>)</option>
</select>
<input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="Поиск по имени, телефону, городу...">
<button type="submit" class="button">Найти</button>
</form>
</div>
<!-- Таблица мастеров -->
<?php if (empty($filtered_masters)): ?>
<div class="notice notice-info"><p>Нет мастеров по заданным фильтрам</p></div>
<?php else: ?>
<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
<thead>
<tr>
<th style="width: 60px;">Фото</th>
<th>Мастер</th>
<th>Контакты</th>
<th>Город</th>
<th>Специализация</th>
<th>Статус</th>
<th>Штрафы</th>
<th>Дата</th>
<th style="width: 250px;">Действия</th>
</tr>
</thead>
<tbody>
<?php foreach ($filtered_masters as $mid => $master):
$status = isset($master['status']) ? $master['status'] : 'pending';
$status_label = isset($status_labels[$status]) ? $status_labels[$status] : $status;
$master_name = isset($master['name']) ? $master['name'] : '';
$master_phone = isset($master['phone']) ? $master['phone'] : '';
$master_email = isset($master['email']) ? $master['email'] : '';
$master_city = isset($master['city_name']) ? $master['city_name'] : '—';
$master_specs = isset($master['specializations']) ? $master['specializations'] : array();
$master_photo = isset($master['photo']) ? $master['photo'] : '';
$self_registered = !empty($master['self_registered']);
$is_online = !empty($master['is_online']);
$penalties_total = isset($master['penalties_total']) ? intval($master['penalties_total']) : 0;
$penalties_amount = isset($master['penalties_amount']) ? floatval($master['penalties_amount']) : 0;
$created_at = isset($master['created_at']) ? $master['created_at'] : '';
?>
<tr>
<td>
<?php if (!empty($master_photo)): ?>
<a href="<?php echo esc_url($master_photo); ?>" target="_blank">
<img src="<?php echo esc_url($master_photo); ?>" alt="Фото" style="width:50px;height:50px;border-radius:50%;object-fit:cover;">
</a>
<?php else: ?>
<div style="width:50px;height:50px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;">👤</div>
<?php endif; ?>
</td>
<td>
<strong><?php echo esc_html($master_name); ?></strong><br>
<small style="color:#666;">
<?php if ($self_registered): ?>
📱 Саморегистрация
<?php else: ?>
👨‍💼 Добавлен админом
<?php endif; ?>
</small>
</td>
<td>
📞 <?php echo esc_html($master_phone); ?><br>
✉️ <?php echo esc_html($master_email); ?>
</td>
<td><?php echo esc_html($master_city); ?></td>
<td>
<?php
echo !empty($master_specs) ? esc_html(implode(', ', $master_specs)) : '—';
?>
</td>
<td>
<span class="csc-status-badge csc-status-<?php echo esc_attr($status); ?>">
<?php echo esc_html($status_label); ?>
</span>
<?php if ($is_online && $status !== 'blocked' && $status !== 'pending' && $status !== 'rejected'): ?>
<br><small style="color: green;">● Онлайн</small>
<?php endif; ?>
</td>
<td>
<?php if ($penalties_total > 0): ?>
<span style="color: #dc3232; font-weight: bold;"><?php echo $penalties_total; ?></span><br>
<small><?php echo number_format($penalties_amount, 0, '.', ' '); ?> ₽</small>
<?php else: ?>
<span style="color: green;">0</span>
<?php endif; ?>
</td>
<td>
<small><?php echo !empty($created_at) ? esc_html(date('d.m.Y', strtotime($created_at))) : '—'; ?></small>
</td>
<td>
<div style="display: flex; gap: 5px; flex-wrap: wrap;">
<!-- Просмотр -->
<button type="button" class="button button-small" onclick="cscViewMaster('<?php echo esc_js($mid); ?>')">👁️ Просмотр</button>
<?php if ($status === 'pending'): ?>
<!-- Одобрить -->
<form method="post" style="display: inline;">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" value="<?php echo esc_attr($mid); ?>">
<input type="hidden" name="csc_moderation_action" value="approve">
<button type="submit" class="button button-primary button-small" onclick="return confirm('Одобрить мастера <?php echo esc_js($master_name); ?>?')">✅ Одобрить</button>
</form>
<!-- Отклонить -->
<button type="button" class="button button-small" style="color: #dc3232; border-color: #dc3232;" onclick="cscRejectMaster('<?php echo esc_js($mid); ?>', '<?php echo esc_js($master_name); ?>')">❌ Отклонить</button>
<?php endif; ?>
<?php if ($status === 'active' || $status === 'inactive'): ?>
<!-- Переключить активность -->
<form method="post" style="display: inline;">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" value="<?php echo esc_attr($mid); ?>">
<input type="hidden" name="csc_moderation_action" value="toggle_activity">
<button type="submit" class="button button-small">
<?php echo $is_online ? '⏸️ Деактивировать' : '▶️ Активировать'; ?>
</button>
</form>
<?php endif; ?>
<?php if ($status !== 'blocked' && $status !== 'rejected' && $status !== 'pending'): ?>
<!-- Заблокировать -->
<form method="post" style="display: inline;">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" value="<?php echo esc_attr($mid); ?>">
<input type="hidden" name="csc_moderation_action" value="block">
<button type="submit" class="button button-small" style="color: #dc3232;" onclick="return confirm('Заблокировать мастера <?php echo esc_js($master_name); ?>?')">🚫 Заблокировать</button>
</form>
<?php endif; ?>
<?php if ($status === 'blocked'): ?>
<!-- Разблокировать -->
<form method="post" style="display: inline;">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" value="<?php echo esc_attr($mid); ?>">
<input type="hidden" name="csc_moderation_action" value="unblock">
<button type="submit" class="button button-small" style="color: #2271b1;">🔓 Разблокировать</button>
</form>
<?php endif; ?>
<?php if ($penalties_total > 0): ?>
<!-- Сбросить штрафы -->
<form method="post" style="display: inline;">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" value="<?php echo esc_attr($mid); ?>">
<input type="hidden" name="csc_moderation_action" value="reset_penalties">
<button type="submit" class="button button-small" onclick="return confirm('Сбросить все штрафы мастера?')">🔄 Сброс штрафов</button>
</form>
<?php endif; ?>
<!-- Удалить -->
<form method="post" style="display: inline;">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" value="<?php echo esc_attr($mid); ?>">
<input type="hidden" name="csc_moderation_action" value="delete">
<button type="submit" class="button button-small" style="color: #dc3232; border-color: #dc3232;" onclick="return confirm('УДАЛИТЬ мастера <?php echo esc_js($master_name); ?>? Это действие необратимо!')">🗑️ Удалить</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<!-- Старое модальное окно (оставлено для совместимости) -->
<div id="csc-master-modal" class="csc-admin-modal" style="display:none;">
<div class="csc-admin-modal-backdrop" onclick="cscCloseMasterModal()"></div>
<div class="csc-admin-modal-content">
<div class="csc-admin-modal-header">
<h2>Данные мастера</h2>
<button type="button" onclick="cscCloseMasterModal()">✕</button>
</div>
<div class="csc-admin-modal-body" id="csc-master-modal-body">
<!-- Заполняется через JS -->
</div>
</div>
</div>
<!-- Модальное окно отклонения -->
<div id="csc-reject-modal" class="csc-admin-modal" style="display:none;">
<div class="csc-admin-modal-backdrop" onclick="cscCloseRejectModal()"></div>
<div class="csc-admin-modal-content" style="max-width: 500px;">
<div class="csc-admin-modal-header">
<h2>Отклонить заявку</h2>
<button type="button" onclick="cscCloseRejectModal()">✕</button>
</div>
<div class="csc-admin-modal-body">
<form method="post">
<?php wp_nonce_field('csc_moderation_action', 'csc_moderation_nonce'); ?>
<input type="hidden" name="master_id" id="csc-reject-master-id" value="">
<input type="hidden" name="csc_moderation_action" value="reject">
<p>Укажите причину отклонения для мастера <strong id="csc-reject-master-name"></strong>:</p>
<textarea name="reason" rows="4" style="width: 100%;" placeholder="Причина отклонения..."></textarea>
<div style="margin-top: 15px; display: flex; gap: 10px;">
<button type="submit" class="button button-primary">Отклонить</button>
<button type="button" class="button" onclick="cscCloseRejectModal()">Отмена</button>
</div>
</form>
</div>
</div>
</div>
<!-- ============================================================
НОВОЕ: МОДАЛЬНОЕ ОКНО ПРОСМОТРА КАРТОЧКИ МАСТЕРА (V2)
============================================================ -->
<div id="csc-master-view-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:99998; align-items:center; justify-content:center; padding:20px; overflow-y:auto;">
<div style="background:#fff; border-radius:12px; width:100%; max-width:900px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,0.25);">
<div id="csc-mv-header" style="padding:20px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:#fff; z-index:10;">
<h2 id="csc-mv-title" style="margin:0; font-size:20px;">Загрузка...</h2>
<button type="button" id="csc-mv-close" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b; padding:4px 8px; line-height:1;">✕</button>
</div>
<div id="csc-mv-body" style="padding:24px;">
<div id="csc-mv-loading" style="text-align:center; padding:60px 20px;">
<div class="csc-spinner"></div>
<p style="margin-top:16px; color:#64748b;">Загрузка данных мастера...</p>
</div>
<div id="csc-mv-error" style="display:none; text-align:center; padding:40px 20px; color:#dc3232;">
<div style="font-size:48px; margin-bottom:16px;">⚠️</div>
<p id="csc-mv-error-text" style="margin:0; font-size:15px;"></p>
</div>
<div id="csc-mv-content" style="display:none;">
<div style="display:grid; grid-template-columns:140px 1fr; gap:24px; margin-bottom:24px; padding-bottom:24px; border-bottom:1px solid #e2e8f0;">
<div id="csc-mv-avatar" style="width:140px; height:140px; border-radius:12px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:48px; color:#64748b; overflow:hidden;">
<span>👤</span>
</div>
<div>
<h3 id="csc-mv-name" style="margin:0 0 8px 0; font-size:22px;"></h3>
<div id="csc-mv-status-badge" style="margin-bottom:12px;"></div>
<div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:8px 16px; font-size:14px;">
<div><span style="color:#64748b;">Телефон:</span> <span id="csc-mv-phone"></span></div>
<div><span style="color:#64748b;">Email:</span> <span id="csc-mv-email"></span></div>
<div><span style="color:#64748b;">Telegram:</span> <span id="csc-mv-tg"></span></div>
<div><span style="color:#64748b;">ID:</span> <code id="csc-mv-id"></code></div>
<div><span style="color:#64748b;">Регистрация:</span> <span id="csc-mv-created"></span></div>
<div><span style="color:#64748b;">Последний вход:</span> <span id="csc-mv-last-login"></span></div>
</div>
</div>
</div>
<div style="margin-bottom:16px; border-bottom:2px solid #e2e8f0; display:flex; gap:4px; overflow-x:auto;">
<button type="button" class="csc-mv-tab csc-mv-tab-active" data-tab="info">📋 Анкета</button>
<button type="button" class="csc-mv-tab" data-tab="work">🕒 Работа</button>
<button type="button" class="csc-mv-tab" data-tab="docs">📄 Документы</button>
<button type="button" class="csc-mv-tab" data-tab="stats">📊 Статистика</button>
<button type="button" class="csc-mv-tab" data-tab="finance">💰 Финансы</button>
<button type="button" class="csc-mv-tab" data-tab="reviews">⭐ Отзывы</button>
<button type="button" class="csc-mv-tab" data-tab="history">📜 История</button>
</div>
<div class="csc-mv-tab-content csc-mv-tab-active" data-tab="info">
<h4 style="margin-top:0;">О себе</h4>
<div id="csc-mv-about" style="padding:12px; background:#f8fafc; border-radius:6px; margin-bottom:16px; white-space:pre-wrap;"></div>
<h4>Специализации</h4>
<div id="csc-mv-specializations" style="margin-bottom:16px;"></div>
<h4>Опыт работы</h4>
<div id="csc-mv-experience" style="padding:12px; background:#f8fafc; border-radius:6px; margin-bottom:16px;"></div>
<h4>Зона обслуживания</h4>
<div id="csc-mv-zones" style="padding:12px; background:#f8fafc; border-radius:6px;"></div>
</div>
<div class="csc-mv-tab-content" data-tab="work" style="display:none;">
<h4 style="margin-top:0;">График работы</h4>
<div id="csc-mv-schedule" style="margin-bottom:16px;"></div>
<h4>Онлайн-статус</h4>
<div id="csc-mv-online" style="padding:12px; background:#f8fafc; border-radius:6px;"></div>
</div>
<div class="csc-mv-tab-content" data-tab="docs" style="display:none;">
<h4 style="margin-top:0;">Загруженные документы</h4>
<div id="csc-mv-documents" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:12px;"></div>
</div>
<div class="csc-mv-tab-content" data-tab="stats" style="display:none;">
<div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:24px;">
<div style="padding:16px; background:#eff6ff; border-radius:8px; text-align:center;">
<div id="csc-mv-stat-total" style="font-size:28px; font-weight:700; color:#1e40af;">0</div>
<div style="font-size:12px; color:#64748b; margin-top:4px;">Всего заказов</div>
</div>
<div style="padding:16px; background:#d1fae5; border-radius:8px; text-align:center;">
<div id="csc-mv-stat-completed" style="font-size:28px; font-weight:700; color:#065f46;">0</div>
<div style="font-size:12px; color:#64748b; margin-top:4px;">Выполнено</div>
</div>
<div style="padding:16px; background:#fee2e2; border-radius:8px; text-align:center;">
<div id="csc-mv-stat-cancelled" style="font-size:28px; font-weight:700; color:#991b1b;">0</div>
<div style="font-size:12px; color:#64748b; margin-top:4px;">Отменено</div>
</div>
<div style="padding:16px; background:#fef3c7; border-radius:8px; text-align:center;">
<div id="csc-mv-stat-rating" style="font-size:28px; font-weight:700; color:#92400e;">0.0</div>
<div style="font-size:12px; color:#64748b; margin-top:4px;">Рейтинг</div>
</div>
</div>
<h4>Последние заказы</h4>
<div id="csc-mv-recent-orders" style="margin-top:12px;"></div>
</div>
<div class="csc-mv-tab-content" data-tab="finance" style="display:none;">
<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:24px;">
<div style="padding:16px; background:#f0fdf4; border-radius:8px;">
<div style="font-size:12px; color:#64748b; margin-bottom:4px;">Тип комиссии</div>
<div id="csc-mv-comm-type" style="font-size:18px; font-weight:600; color:#166534;"></div>
</div>
<div style="padding:16px; background:#f0fdf4; border-radius:8px;">
<div style="font-size:12px; color:#64748b; margin-bottom:4px;">Заработано всего</div>
<div id="csc-mv-earned" style="font-size:18px; font-weight:600; color:#166534;"></div>
</div>
<div style="padding:16px; background:#fef2f2; border-radius:8px;">
<div style="font-size:12px; color:#64748b; margin-bottom:4px;">Штрафы</div>
<div id="csc-mv-penalties" style="font-size:18px; font-weight:600; color:#991b1b;"></div>
</div>
</div>
<h4>История выплат</h4>
<div id="csc-mv-payouts" style="margin-top:12px;"></div>
</div>
<div class="csc-mv-tab-content" data-tab="reviews" style="display:none;">
<div id="csc-mv-reviews" style="display:flex; flex-direction:column; gap:12px;"></div>
</div>
<div class="csc-mv-tab-content" data-tab="history" style="display:none;">
<div id="csc-mv-history-list" style="display:flex; flex-direction:column; gap:8px;"></div>
</div>
</div>
</div>
<div id="csc-mv-footer" style="padding:16px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; gap:8px; position:sticky; bottom:0;">
<div style="display:flex; gap:8px;">
<button type="button" class="button" id="csc-mv-approve-btn" style="background:#10b981; color:white; border-color:#10b981;">✅ Одобрить</button>
<button type="button" class="button" id="csc-mv-reject-btn" style="color:#dc3232;">❌ Отклонить</button>
<button type="button" class="button" id="csc-mv-block-btn" style="color:#dc3232;">🚫 Заблокировать</button>
</div>
<button type="button" class="button" id="csc-mv-close-bottom">Закрыть</button>
</div>
</div>
</div>
<style>
.csc-status-badge {
display: inline-block;
padding: 4px 10px;
border-radius: 12px;
font-size: 12px;
font-weight: 600;
}
.csc-status-pending { background: #fef3c7; color: #92400e; }
.csc-status-active { background: #d1fae5; color: #065f46; }
.csc-status-inactive { background: #f3f4f6; color: #4b5563; }
.csc-status-blocked { background: #fee2e2; color: #991b1b; }
.csc-status-rejected { background: #fee2e2; color: #991b1b; }
.csc-admin-modal {
position: fixed;
top: 0;
left: 0;
width: 100%;
height: 100%;
z-index: 100000;
}
.csc-admin-modal-backdrop {
position: absolute;
top: 0;
left: 0;
width: 100%;
height: 100%;
background: rgba(0,0,0,0.5);
}
.csc-admin-modal-content {
position: relative;
max-width: 800px;
margin: 50px auto;
background: white;
border-radius: 12px;
box-shadow: 0 20px 60px rgba(0,0,0,0.3);
max-height: 80vh;
overflow-y: auto;
}
.csc-admin-modal-header {
display: flex;
justify-content: space-between;
align-items: center;
padding: 20px;
border-bottom: 1px solid #e5e7eb;
}
.csc-admin-modal-header h2 {
margin: 0;
}
.csc-admin-modal-header button {
background: none;
border: none;
font-size: 24px;
cursor: pointer;
color: #6b7280;
}
.csc-admin-modal-body {
padding: 20px;
}
.csc-master-detail-grid {
display: grid;
grid-template-columns: 1fr 1fr;
gap: 15px;
}
.csc-master-detail-item {
padding: 10px;
background: #f9fafb;
border-radius: 8px;
}
.csc-master-detail-item label {
display: block;
font-size: 12px;
color: #6b7280;
margin-bottom: 4px;
}
.csc-master-detail-item .value {
font-weight: 500;
}
.csc-master-docs {
display: flex;
gap: 15px;
margin-top: 15px;
}
.csc-master-docs img {
max-width: 200px;
max-height: 200px;
border-radius: 8px;
border: 1px solid #e5e7eb;
}
/* Стили для нового окна просмотра */
@keyframes csc-spin {
to { transform: rotate(360deg); }
}
.csc-spinner {
display: inline-block;
width: 40px;
height: 40px;
border: 3px solid #e2e8f0;
border-top-color: #2563eb;
border-radius: 50%;
animation: csc-spin 1s linear infinite;
}
.csc-mv-tab {
background:none;
border:none;
padding:10px 16px;
cursor:pointer;
font-size:13px;
font-weight:500;
color:#64748b;
border-bottom:2px solid transparent;
margin-bottom:-2px;
white-space:nowrap;
}
.csc-mv-tab:hover { color:#2563eb; }
.csc-mv-tab.csc-mv-tab-active {
color:#2563eb;
border-bottom-color:#2563eb;
}
.csc-mv-history-item {
padding:10px 12px;
background:#f8fafc;
border-left:3px solid #cbd5e1;
border-radius:4px;
font-size:13px;
}
.csc-mv-history-item.approve { border-left-color:#10b981; }
.csc-mv-history-item.reject, .csc-mv-history-item.block { border-left-color:#dc3232; }
.csc-mv-history-date {
display:block;
font-size:11px;
color:#94a3b8;
margin-top:2px;
}
</style>
<script>
// Глобальные переменные для модального окна просмотра V2
var cscMVCurrentMasterId = '';
var cscMVCurrentMasterData = null;
var cscMastersNonce = '<?php echo wp_create_nonce("csc_masters_action"); ?>';
var cscAjaxUrl = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
// === СТАРЫЕ ФУНКЦИИ (Оставлены для совместимости) ===
function cscViewMaster(masterId) {
cscOpenMasterView(masterId);
}
function cscRejectMaster(masterId, masterName) {
document.getElementById('csc-reject-master-id').value = masterId;
document.getElementById('csc-reject-master-name').textContent = masterName;
document.getElementById('csc-reject-modal').style.display = 'block';
}
function cscCloseRejectModal() {
document.getElementById('csc-reject-modal').style.display = 'none';
}
function cscCloseMasterModal() {
document.getElementById('csc-master-modal').style.display = 'none';
}
// === НОВЫЕ ФУНКЦИИ ДЛЯ ПРОСМОТРА КАРТОЧКИ (V2) ===
function cscOpenMasterView(masterId) {
var modal = document.getElementById('csc-master-view-modal');
if (!modal) {
console.error('[CSC] Модальное окно #csc-master-view-modal не найдено');
return;
}
cscMVCurrentMasterId = masterId;
cscMVCurrentMasterData = null;
document.getElementById('csc-mv-loading').style.display = 'block';
document.getElementById('csc-mv-error').style.display = 'none';
document.getElementById('csc-mv-content').style.display = 'none';
document.getElementById('csc-mv-title').textContent = 'Загрузка...';
// Сброс вкладок
document.querySelectorAll('.csc-mv-tab').forEach(function(t, i) {
t.classList.toggle('csc-mv-tab-active', i === 0);
});
document.querySelectorAll('.csc-mv-tab-content').forEach(function(c, i) {
c.style.display = i === 0 ? 'block' : 'none';
});
modal.style.display = 'flex';
var fd = new FormData();
fd.append('action', 'csc_get_master_details');
fd.append('mid', masterId);
fd.append('_wpnonce', cscMastersNonce);
fetch(cscAjaxUrl, { method: 'POST', body: fd })
.then(function(r) { return r.json(); })
.then(function(res) {
if (res.success) {
cscMVCurrentMasterData = res.data.master;
cscRenderMasterView(cscMVCurrentMasterData);
} else {
cscShowViewError(res.data && res.data.message ? res.data.message : 'Не удалось загрузить данные');
}
})
.catch(function(err) {
cscShowViewError('Ошибка сети: ' + err.message);
});
}
function cscShowViewError(message) {
document.getElementById('csc-mv-loading').style.display = 'none';
document.getElementById('csc-mv-error').style.display = 'block';
document.getElementById('csc-mv-error-text').textContent = message;
}
function cscRenderMasterView(data) {
document.getElementById('csc-mv-loading').style.display = 'none';
document.getElementById('csc-mv-content').style.display = 'block';
document.getElementById('csc-mv-title').textContent = '👤 ' + data.name;
document.getElementById('csc-mv-name').textContent = data.name;
document.getElementById('csc-mv-id').textContent = data.id;
var statusMap = {
'pending': { label: '⏳ На модерации', color: '#fef3c7', text: '#92400e' },
'active': { label: '🟢 Одобрен', color: '#d1fae5', text: '#065f46' },
'blocked': { label: '🚫 Заблокирован', color: '#fee2e2', text: '#991b1b' },
'rejected': { label: '❌ Отклонён', color: '#fee2e2', text: '#991b1b' }
};
var st = statusMap[data.status] || statusMap['active'];
document.getElementById('csc-mv-status-badge').innerHTML =
'<span style="display:inline-block; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; background:' + st.color + '; color:' + st.text + ';">' + st.label + '</span>';
var approveBtn = document.getElementById('csc-mv-approve-btn');
var rejectBtn = document.getElementById('csc-mv-reject-btn');
var blockBtn = document.getElementById('csc-mv-block-btn');
approveBtn.style.display = (data.status === 'pending' || data.status === 'blocked' || data.status === 'rejected') ? 'inline-block' : 'none';
rejectBtn.style.display = data.status === 'pending' ? 'inline-block' : 'none';
blockBtn.style.display = data.status === 'active' ? 'inline-block' : 'none';
blockBtn.textContent = data.status === 'active' ? '🚫 Заблокировать' : '🔓 Разблокировать';
var avatarEl = document.getElementById('csc-mv-avatar');
if (data.avatar) {
avatarEl.innerHTML = '<img src="' + data.avatar + '" style="width:100%; height:100%; object-fit:cover;">';
} else {
var initials = data.name.split(' ').map(function(n) { return n[0]; }).join('').substring(0, 2).toUpperCase();
avatarEl.innerHTML = '<span style="font-size:42px; font-weight:600; color:#64748b;">' + initials + '</span>';
}
document.getElementById('csc-mv-phone').textContent = data.phone || '—';
document.getElementById('csc-mv-email').textContent = data.email || '—';
document.getElementById('csc-mv-tg').textContent = data.tg_chat_id || '—';
document.getElementById('csc-mv-created').textContent = data.created_at ? cscMVFormatDate(data.created_at) : '—';
document.getElementById('csc-mv-last-login').textContent = data.last_login ? cscMVFormatDate(data.last_login) : 'Никогда';
document.getElementById('csc-mv-about').textContent = data.about || 'Не указано';
document.getElementById('csc-mv-experience').textContent = data.experience || 'Не указано';
var specsHtml = Array.isArray(data.specializations) && data.specializations.length > 0
? data.specializations.map(function(s) { return '<span style="display:inline-block; padding:4px 10px; background:#eff6ff; color:#1e40af; border-radius:6px; font-size:12px; margin:0 4px 4px 0;">' + cscMVEscapeHtml(s) + '</span>'; }).join('')
: '<span style="color:#94a3b8;">Не указаны</span>';
document.getElementById('csc-mv-specializations').innerHTML = specsHtml;
var zonesHtml = Array.isArray(data.zones) && data.zones.length > 0
? data.zones.map(function(z) { return '<div style="padding:8px 12px; background:#f1f5f9; border-radius:4px; margin-bottom:4px;">📍 ' + cscMVEscapeHtml(z) + '</div>'; }).join('')
: '<span style="color:#94a3b8;">Зоны не указаны</span>';
document.getElementById('csc-mv-zones').innerHTML = zonesHtml;
var daysNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
var activeDays = Array.isArray(data.work_days) ? data.work_days : [];
var scheduleHtml = '<div style="display:flex; gap:4px; flex-wrap:wrap; margin-bottom:16px;">';
for (var i = 1; i <= 7; i++) {
var active = activeDays.indexOf(i) !== -1;
scheduleHtml += '<span style="padding:6px 10px; background:' + (active ? '#dbeafe' : '#f1f5f9') + '; color:' + (active ? '#1e40af' : '#94a3b8') + '; border-radius:6px; font-size:12px;">' + daysNames[i] + '</span>';
}
scheduleHtml += '</div>';
scheduleHtml += '<div style="padding:12px; background:#f8fafc; border-radius:6px;">';
scheduleHtml += '<strong>Часы работы:</strong> ' + String(data.work_hours_start).padStart(2, '0') + ':00 — ' + String(data.work_hours_end).padStart(2, '0') + ':00';
scheduleHtml += '</div>';
document.getElementById('csc-mv-schedule').innerHTML = scheduleHtml;
document.getElementById('csc-mv-online').innerHTML =
'<span style="display:inline-flex; align-items:center; gap:6px;">' +
'<span style="width:10px; height:10px; border-radius:50%; background:' + (data.is_online ? '#10b981' : '#94a3b8') + ';"></span>' +
'<span>' + (data.is_online ? 'Онлайн — принимает заказы' : 'Офлайн — заказы не принимает') + '</span></span>';
var docsHtml = '';
if (Array.isArray(data.documents) && data.documents.length > 0) {
data.documents.forEach(function(doc) {
docsHtml += '<a href="' + cscMVEscapeHtml(doc.url) + '" target="_blank" style="padding:12px; background:#f8fafc; border-radius:8px; text-decoration:none; color:inherit; display:block; border:1px solid #e2e8f0;">';
docsHtml += '<div style="font-size:24px; margin-bottom:4px;">📄</div>';
docsHtml += '<div style="font-size:13px; font-weight:500;">' + cscMVEscapeHtml(doc.name || 'Документ') + '</div>';
docsHtml += '<div style="font-size:11px; color:#64748b;">Открыть ↗</div>';
docsHtml += '</a>';
});
} else {
docsHtml = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;">Документы не загружены</div>';
}
document.getElementById('csc-mv-documents').innerHTML = docsHtml;
document.getElementById('csc-mv-stat-total').textContent = data.stats.total;
document.getElementById('csc-mv-stat-completed').textContent = data.stats.completed;
document.getElementById('csc-mv-stat-cancelled').textContent = data.stats.cancelled;
document.getElementById('csc-mv-stat-rating').textContent = data.stats.rating.toFixed(1) + ' ⭐';
var ordersHtml = '';
if (data.stats.recent_orders.length > 0) {
ordersHtml = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
ordersHtml += '<thead><tr style="background:#f8fafc;"><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Дата</th><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Клиент</th><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Услуга</th><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Статус</th></tr></thead><tbody>';
data.stats.recent_orders.forEach(function(o) {
ordersHtml += '<tr>';
ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscMVEscapeHtml((o.date || '') + ' ' + (o.time || '')) + '</td>';
ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscMVEscapeHtml(o.client_name || o.phone || '—') + '</td>';
ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscMVEscapeHtml(o.service || '—') + '</td>';
ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscMVEscapeHtml(o.status || '—') + '</td>';
ordersHtml += '</tr>';
});
ordersHtml += '</tbody></table>';
} else {
ordersHtml = '<div style="text-align:center; padding:20px; color:#94a3b8;">Заказов пока нет</div>';
}
document.getElementById('csc-mv-recent-orders').innerHTML = ordersHtml;
var commTypeLabel = data.comm_type === 'percent' ? (data.comm_val + '%') : (data.comm_val + ' ₽');
document.getElementById('csc-mv-comm-type').textContent = commTypeLabel;
document.getElementById('csc-mv-earned').textContent = cscMVFormatMoney(data.earned_total);
document.getElementById('csc-mv-penalties').textContent = cscMVFormatMoney(data.penalties_amount) + ' (' + data.penalties_total + ' шт.)';
var payoutsHtml = '';
if (Array.isArray(data.payouts) && data.payouts.length > 0) {
payoutsHtml = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
payoutsHtml += '<thead><tr style="background:#f8fafc;"><th style="padding:8px; text-align:left;">Дата</th><th style="padding:8px; text-align:right;">Сумма</th><th style="padding:8px; text-align:left;">Статус</th></tr></thead><tbody>';
data.payouts.forEach(function(p) {
payoutsHtml += '<tr>';
payoutsHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscMVEscapeHtml(p.date || '') + '</td>';
payoutsHtml += '<td style="padding:8px; text-align:right; border-bottom:1px solid #f1f5f9;">' + cscMVFormatMoney(p.amount) + '</td>';
payoutsHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscMVEscapeHtml(p.status || '') + '</td>';
payoutsHtml += '</tr>';
});
payoutsHtml += '</tbody></table>';
} else {
payoutsHtml = '<div style="text-align:center; padding:20px; color:#94a3b8;">Выплат пока нет</div>';
}
document.getElementById('csc-mv-payouts').innerHTML = payoutsHtml;
var reviewsHtml = '';
if (Array.isArray(data.reviews) && data.reviews.length > 0) {
data.reviews.forEach(function(r) {
var stars = '⭐'.repeat(Math.round(r.rating || 0));
reviewsHtml += '<div style="padding:12px; background:#f8fafc; border-radius:8px;">';
reviewsHtml += '<div style="display:flex; justify-content:space-between; margin-bottom:6px;"><strong>' + cscMVEscapeHtml(r.client_name || 'Клиент') + '</strong><span>' + stars + '</span></div>';
reviewsHtml += '<div style="font-size:13px; color:#475569; white-space:pre-wrap;">' + cscMVEscapeHtml(r.text || '') + '</div>';
reviewsHtml += '<div style="font-size:11px; color:#94a3b8; margin-top:6px;">' + cscMVFormatDate(r.date || '') + '</div>';
reviewsHtml += '</div>';
});
} else {
reviewsHtml = '<div style="text-align:center; padding:40px; color:#94a3b8;">Отзывов пока нет</div>';
}
document.getElementById('csc-mv-reviews').innerHTML = reviewsHtml;
var historyHtml = '';
var sortedHistory = data.history.slice().sort(function(a, b) { return new Date(b.date) - new Date(a.date); });
if (sortedHistory.length > 0) {
sortedHistory.forEach(function(h) {
historyHtml += '<div class="csc-mv-history-item ' + cscMVEscapeHtml(h.action || '') + '">';
historyHtml += '<div><strong>' + cscMVEscapeHtml(h.note || h.action || 'Действие') + '</strong></div>';
historyHtml += '<span class="csc-mv-history-date">' + cscMVFormatDate(h.date || '') + ' · ' + cscMVEscapeHtml(h.by || '') + '</span>';
historyHtml += '</div>';
});
} else {
historyHtml = '<div style="text-align:center; padding:40px; color:#94a3b8;">История пуста</div>';
}
document.getElementById('csc-mv-history-list').innerHTML = historyHtml;
}
function cscMVCloseMasterView() {
document.getElementById('csc-master-view-modal').style.display = 'none';
cscMVCurrentMasterId = '';
cscMVCurrentMasterData = null;
}
function cscMVModerateFromModal(masterId, action, reason) {
var fd = new FormData();
fd.append('action', 'csc_moderate_master');
fd.append('mid', masterId);
fd.append('moderation_action', action);
fd.append('reason', reason);
fd.append('_wpnonce', cscMastersNonce);
fetch(cscAjaxUrl, { method: 'POST', body: fd })
.then(function(r) { return r.json(); })
.then(function(res) {
if (res.success) {
cscMVCloseMasterView();
location.reload();
} else {
alert('Ошибка: ' + (res.data && res.data.message ? res.data.message : 'Неизвестная ошибка'));
}
})
.catch(function(err) {
alert('Ошибка сети: ' + err.message);
});
}
function cscMVFormatDate(str) {
if (!str) return '—';
var d = new Date(str);
if (isNaN(d.getTime())) return str;
return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function cscMVFormatMoney(val) {
var num = parseFloat(val) || 0;
return num.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 });
}
function cscMVEscapeHtml(str) {
if (str === null || str === undefined) return '';
return String(str)
.replace(/&/g, '&amp;')
.replace(/</g, '&lt;')
.replace(/>/g, '&gt;')
.replace(/"/g, '&quot;')
.replace(/'/g, '&#039;');
}
document.addEventListener('DOMContentLoaded', function() {
var modal = document.getElementById('csc-master-view-modal');
if (!modal) return;
document.getElementById('csc-mv-close').addEventListener('click', cscMVCloseMasterView);
document.getElementById('csc-mv-close-bottom').addEventListener('click', cscMVCloseMasterView);
modal.addEventListener('click', function(e) {
if (e.target === modal) cscMVCloseMasterView();
});
document.addEventListener('keydown', function(e) {
if (e.key === 'Escape' && modal.style.display === 'flex') cscMVCloseMasterView();
});
document.body.addEventListener('click', function(e) {
var tab = e.target.closest('.csc-mv-tab');
if (tab) {
var tabName = tab.getAttribute('data-tab');
document.querySelectorAll('.csc-mv-tab').forEach(function(t) { t.classList.remove('csc-mv-tab-active'); });
document.querySelectorAll('.csc-mv-tab-content').forEach(function(c) { c.style.display = 'none'; });
tab.classList.add('csc-mv-tab-active');
var content = document.querySelector('.csc-mv-tab-content[data-tab="' + tabName + '"]');
if (content) content.style.display = 'block';
}
});
document.getElementById('csc-mv-approve-btn').addEventListener('click', function() {
if (!cscMVCurrentMasterId) return;
if (confirm('Одобрить этого мастера?')) {
cscMVModerateFromModal(cscMVCurrentMasterId, 'approve', '');
}
});
document.getElementById('csc-mv-reject-btn').addEventListener('click', function() {
if (!cscMVCurrentMasterId) return;
var reason = prompt('Укажите причину отклонения:');
if (reason === null) return;
if (reason.trim() === '' && !confirm('Причина не указана. Продолжить?')) return;
cscMVModerateFromModal(cscMVCurrentMasterId, 'reject', reason);
});
document.getElementById('csc-mv-block-btn').addEventListener('click', function() {
if (!cscMVCurrentMasterId) return;
var reason = prompt('Укажите причину блокировки:');
if (reason === null) return;
if (reason.trim() === '' && !confirm('Причина не указана. Продолжить?')) return;
cscMVModerateFromModal(cscMVCurrentMasterId, 'block', reason);
});
});
</script>
<?php
}
}