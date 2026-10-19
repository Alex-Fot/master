<?php
/**
* Файл: csc-promo.php
* Описание: Система скидок и промокодов для Custom Service Calculator.
* Версия: 3.0 (единая работа с заказами через таблицу, фикс таймзоны, ограничение фиксированных скидок)
*
* ИСПРАВЛЕНО:
* - Все операции с заказами через csc_get_all_orders()
* - Таймзона в подсчёте лояльности: сравнение через get_date_from_gmt()
* - Фиксированная скидка промокода ограничена суммой подытога для всех областей
* - Экранирование всех выводимых данных в админке
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// АДМИНКА: СКИДКИ И ПРОМО
// ============================================================
if (!function_exists('csc_promo_admin_page')) {
function csc_promo_admin_page() {
// Сохранение настроек
if ($_POST && isset($_POST['save_promo']) && wp_verify_nonce($_POST['_wpnonce'], 'csc_promo_nonce')) {
// 0. Глобальное ОТКЛЮЧЕНИЕ
update_option('csc_promo_disable_global', !empty($_POST['disable_global']), false);

// 1. Настройки стратегии
update_option('csc_promo_strategy', sanitize_text_field($_POST['strategy'] ?? 'best'), false);

// 2. Автоматические правила
$rules = [];
if (!empty($_POST['rules'])) {
foreach ($_POST['rules'] as $r) {
if (empty($r['val'])) continue;
$rules[] = [
'id' => $r['id'] ?: uniqid('rule_'),
'name' => sanitize_text_field($r['name']),
'type' => sanitize_text_field($r['type']),
'period' => intval($r['period'] ?? 0),
'threshold' => floatval($r['threshold']),
'discount_type' => sanitize_text_field($r['discount_type']),
'val' => floatval($r['val']),
'active' => !empty($r['active'])
];
}
}
update_option('csc_promo_rules', $rules, false);

// 3. Промокоды
$codes = [];
if (!empty($_POST['codes'])) {
foreach ($_POST['codes'] as $c) {
if (empty($c['code'])) continue;
$codes[] = [
'id' => $c['id'] ?: uniqid('code_'),
'code' => strtoupper(sanitize_text_field($c['code'])),
'expiry' => sanitize_text_field($c['expiry']),
'discount_type' => sanitize_text_field($c['discount_type']),
'val' => floatval($c['val']),
'scope' => sanitize_text_field($c['scope']),
'scope_ids' => sanitize_text_field($c['scope_ids']),
'active' => !empty($c['active'])
];
}
}
update_option('csc_promo_codes', $codes, false);

echo '<div class="notice notice-success"><p>Настройки скидок сохранены.</p></div>';
}

$disable_global = get_option('csc_promo_disable_global', 0);
$strategy = get_option('csc_promo_strategy', 'best');
$rules = get_option('csc_promo_rules', []);
$codes = get_option('csc_promo_codes', []);
?>
<div class="wrap">
<h1>Управление Скидками и Промокодами</h1>
<form method="post">
<?php wp_nonce_field('csc_promo_nonce'); ?>

<div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px; border-left: 4px solid #ef4444;">
<h3>🔌 Глобальное отключение</h3>
<label style="font-size: 16px; color: #b91c1c;">
<input type="checkbox" name="disable_global" value="1" <?php checked($disable_global); ?>>
<strong>Отключить систему скидок и промокодов</strong>
</label>
<p class="description">Если галочка установлена, поля ввода промокода и расчет скидок будут полностью скрыты в калькуляторе.</p>
</div>

<div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px;">
<h3>⚙️ Стратегия применения</h3>
<label style="margin-right: 20px;">
<input type="radio" name="strategy" value="best" <?php checked($strategy, 'best'); ?>>
<strong>Только самая выгодная</strong> (выбирается одна максимальная скидка)
</label>
<label>
<input type="radio" name="strategy" value="all" <?php checked($strategy, 'all'); ?>>
<strong>Суммировать все</strong> (применяются все активные условия)
</label>
</div>

<div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px;">
<h3>⚡ Автоматические условия (Лояльность, Объем)</h3>
<table class="widefat" id="rules-tbl">
<thead><tr><th>Название</th><th>Условие</th><th>Порог / Период</th><th>Тип скидки</th><th>Значение</th><th>Активно</th><th></th></tr></thead>
<tbody id="rules-body">
<?php foreach($rules as $i => $r) csc_render_rule_row($i, $r); ?>
</tbody>
</table>
<button type="button" class="button" onclick="addRule()">+ Добавить правило</button>
</div>

<div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px;">
<h3>🎟️ Промокоды</h3>
<table class="widefat" id="codes-tbl">
<thead><tr><th>Код</th><th>Истекает</th><th>Скидка</th><th>Знач.</th><th>Область</th><th>IDs (раздел/услуга)</th><th>Активно</th><th></th></tr></thead>
<tbody id="codes-body">
<?php foreach($codes as $i => $c) csc_render_code_row($i, $c); ?>
</tbody>
</table>
<button type="button" class="button" onclick="addCode()">+ Добавить промокод</button>
</div>

<?php submit_button('Сохранить настройки', 'primary', 'save_promo'); ?>
</form>
</div>

<script>
function addRule(){
const i = document.querySelectorAll('#rules-body tr').length;
const html = `<tr>
<td><input type="hidden" name="rules[${i}][id]" value="">
<input type="text" name="rules[${i}][name]" placeholder="Скидка постоянного клиента"></td>
<td><select name="rules[${i}][type]">
<option value="sum_order">Сумма текущей заявки ></option>
<option value="qty_order">Кол-во услуг в заявке ></option>
<option value="loyalty">Кол-во обращений (история) ></option>
</select></td>
<td>
Порог: <input type="number" name="rules[${i}][threshold]" style="width:70px" value="1"><br>
Период (мес): <select name="rules[${i}][period]"><option value="0">-</option><option value="1">1 мес</option><option value="3">3 мес</option><option value="6">6 мес</option></select>
</td>
<td><select name="rules[${i}][discount_type]"><option value="percent">%</option><option value="fixed">RUB</option></select></td>
<td><input type="number" name="rules[${i}][val]" style="width:70px"></td>
<td><input type="checkbox" name="rules[${i}][active]" value="1" checked></td>
<td><button type="button" class="button" onclick="this.closest('tr').remove()">×</button></td>
</tr>`;
document.getElementById('rules-body').insertAdjacentHTML('beforeend', html);
}

function addCode(){
const i = document.querySelectorAll('#codes-body tr').length;
const html = `<tr>
<td><input type="hidden" name="codes[${i}][id]" value="">
<input type="text" name="codes[${i}][code]" placeholder="CODE" style="width:100px;text-transform:uppercase;"></td>
<td><input type="date" name="codes[${i}][expiry]"></td>
<td><select name="codes[${i}][discount_type]"><option value="percent">%</option><option value="fixed">RUB</option></select></td>
<td><input type="number" name="codes[${i}][val]" style="width:70px"></td>
<td><select name="codes[${i}][scope]"><option value="global">Вся корзина</option><option value="section">Раздел(ы)</option><option value="item">Услуга(и)</option></select></td>
<td><input type="text" name="codes[${i}][scope_ids]" placeholder="ID1, ID2"></td>
<td><input type="checkbox" name="codes[${i}][active]" value="1" checked></td>
<td><button type="button" class="button" onclick="this.closest('tr').remove()">×</button></td>
</tr>`;
document.getElementById('codes-body').insertAdjacentHTML('beforeend', html);
}
</script>
<?php
}
}
// ============================================================
// РЕНДЕР СТРОКИ ПРАВИЛА
// ============================================================
if (!function_exists('csc_render_rule_row')) {
function csc_render_rule_row($i, $r) {
?>
<tr>
<td>
<input type="hidden" name="rules[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($r['id']); ?>">
<input type="text" name="rules[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($r['name']); ?>">
</td>
<td>
<select name="rules[<?php echo esc_attr($i); ?>][type]">
<option value="sum_order" <?php selected($r['type'], 'sum_order'); ?>>Сумма текущей заявки ></option>
<option value="qty_order" <?php selected($r['type'], 'qty_order'); ?>>Кол-во услуг в заявке ></option>
<option value="loyalty" <?php selected($r['type'], 'loyalty'); ?>>Кол-во обращений (история) ></option>
</select>
</td>
<td>
Порог: <input type="number" name="rules[<?php echo esc_attr($i); ?>][threshold]" value="<?php echo esc_attr($r['threshold']); ?>" style="width:70px"><br>
Период (мес): <select name="rules[<?php echo esc_attr($i); ?>][period]">
<option value="0" <?php selected($r['period'], 0); ?>>-</option>
<option value="1" <?php selected($r['period'], 1); ?>>1 мес</option>
<option value="3" <?php selected($r['period'], 3); ?>>3 мес</option>
<option value="6" <?php selected($r['period'], 6); ?>>6 мес</option>
</select>
</td>
<td>
<select name="rules[<?php echo esc_attr($i); ?>][discount_type]">
<option value="percent" <?php selected($r['discount_type'], 'percent'); ?>>%</option>
<option value="fixed" <?php selected($r['discount_type'], 'fixed'); ?>>RUB</option>
</select>
</td>
<td><input type="number" name="rules[<?php echo esc_attr($i); ?>][val]" value="<?php echo esc_attr($r['val']); ?>" style="width:70px"></td>
<td><input type="checkbox" name="rules[<?php echo esc_attr($i); ?>][active]" value="1" <?php checked(!empty($r['active'])); ?>></td>
<td><button type="button" class="button" onclick="this.closest('tr').remove()">×</button></td>
</tr>
<?php
}
}

// ============================================================
// РЕНДЕР СТРОКИ ПРОМОКОДА
// ============================================================
if (!function_exists('csc_render_code_row')) {
function csc_render_code_row($i, $c) {
?>
<tr>
<td>
<input type="hidden" name="codes[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($c['id']); ?>">
<input type="text" name="codes[<?php echo esc_attr($i); ?>][code]" value="<?php echo esc_attr($c['code']); ?>" style="width:100px;text-transform:uppercase;">
</td>
<td><input type="date" name="codes[<?php echo esc_attr($i); ?>][expiry]" value="<?php echo esc_attr($c['expiry']); ?>"></td>
<td>
<select name="codes[<?php echo esc_attr($i); ?>][discount_type]">
<option value="percent" <?php selected($c['discount_type'], 'percent'); ?>>%</option>
<option value="fixed" <?php selected($c['discount_type'], 'fixed'); ?>>RUB</option>
</select>
</td>
<td><input type="number" name="codes[<?php echo esc_attr($i); ?>][val]" value="<?php echo esc_attr($c['val']); ?>" style="width:70px"></td>
<td>
<select name="codes[<?php echo esc_attr($i); ?>][scope]">
<option value="global" <?php selected($c['scope'], 'global'); ?>>Вся корзина</option>
<option value="section" <?php selected($c['scope'], 'section'); ?>>Раздел(ы)</option>
<option value="item" <?php selected($c['scope'], 'item'); ?>>Услуга(и)</option>
</select>
</td>
<td><input type="text" name="codes[<?php echo esc_attr($i); ?>][scope_ids]" value="<?php echo esc_attr($c['scope_ids']); ?>" placeholder="ID1, ID2"></td>
<td><input type="checkbox" name="codes[<?php echo esc_attr($i); ?>][active]" value="1" <?php checked(!empty($c['active'])); ?>></td>
<td><button type="button" class="button" onclick="this.closest('tr').remove()">×</button></td>
</tr>
<?php
}
}
// ============================================================
// ЛОГИКА РАСЧЕТА (CORE)
// ============================================================

/**
* Обёртка для совместимости со старыми вызовами.
* ИСПРАВЛЕНО: корректная передача параметров.
*/
if (!function_exists('csc_calculate_final_price')) {
function csc_calculate_final_price($services, $total_price_base, $total_qty, $promo_code, $phone) {
return csc_calculate_cart($services, $phone, $promo_code);
}
}

/**
* Основной расчёт корзины со скидками.
* ИСПРАВЛЕНО:
* - Фиксированная скидка промокода с scope = item/section
*   ограничена суммой подытога (защита от отрицательного итога).
*/
if (!function_exists('csc_calculate_cart')) {
function csc_calculate_cart($services, $phone = '', $promo_code = '') {
// 0. Если отключено глобально в админке, возвращаем 0 скидок
if (get_option('csc_promo_disable_global', 0)) {
$items = csc_get_items();
$base_total = 0;
foreach($services as $s) {
if(!isset($items[$s['key']])) continue;
$base_total += $s['qty'] * $items[$s['key']]['price'];
}
return [
'base' => $base_total,
'discount' => 0,
'total' => $base_total,
'info' => ''
];
}

$strategy = get_option('csc_promo_strategy', 'best');
$rules = get_option('csc_promo_rules', []);
$codes = get_option('csc_promo_codes', []);
$items = csc_get_items();

// 1. Считаем базу
$base_total = 0;
$total_qty = 0;
foreach($services as $s) {
if(!isset($items[$s['key']])) continue;
$base_total += $s['qty'] * $items[$s['key']]['price'];
$total_qty += $s['qty'];
}

$applied = [];

// 2. Проверяем авто-правила
foreach($rules as $r) {
if(empty($r['active'])) continue;
$triggered = false;

// По сумме
if($r['type'] === 'sum_order' && $base_total >= $r['threshold']) $triggered = true;
// По кол-ву услуг
elseif($r['type'] === 'qty_order' && $total_qty >= $r['threshold']) $triggered = true;
// По лояльности
elseif($r['type'] === 'loyalty' && !empty($phone)) {
$count = csc_count_user_orders($phone, $r['period']);
if($count >= $r['threshold']) $triggered = true;
}

if($triggered) {
$amt = ($r['discount_type'] === 'percent') ? ($base_total * $r['val'] / 100) : $r['val'];
$applied[] = ['name' => $r['name'], 'amount' => $amt];
}
}

// 3. Проверяем промокод
if(!empty($promo_code)) {
$input_code = strtoupper(trim($promo_code));
foreach($codes as $c) {
if(empty($c['active'])) continue;
if($c['code'] !== $input_code) continue;
if(!empty($c['expiry']) && strtotime($c['expiry']) < time()) continue;

$code_amt = 0;

// Глобальный
if($c['scope'] === 'global') {
$code_amt = ($c['discount_type'] === 'percent') ? ($base_total * $c['val'] / 100) : $c['val'];
}
// Разделы / Товары
else {
$target_ids = array_map('trim', explode(',', $c['scope_ids']));
$subtotal = 0;
foreach($services as $s) {
if(!isset($items[$s['key']])) continue;
$item = $items[$s['key']];
$match = false;
if($c['scope'] === 'item' && in_array($s['key'], $target_ids)) $match = true;
if($c['scope'] === 'section' && in_array($item['section_id'], $target_ids)) $match = true;
if($match) $subtotal += $s['qty'] * $item['price'];
}
if($subtotal > 0) {
$code_amt = ($c['discount_type'] === 'percent') ? ($subtotal * $c['val'] / 100) : $c['val'];
// ИСПРАВЛЕНО: ограничиваем фиксированную скидку суммой подытога
$code_amt = min($code_amt, $subtotal);
}
}

if($code_amt > 0) {
$applied[] = ['name' => 'Промокод: '.$c['code'], 'amount' => $code_amt];
}
break; // только один промокод за раз
}
}

// 4. Итоговый расчет
$final_discount = 0;
$info_parts = [];
if(!empty($applied)) {
if($strategy === 'best') {
usort($applied, function($a, $b) { return $b['amount'] <=> $a['amount']; });
$best = $applied[0];
$final_discount = $best['amount'];
$info_parts[] = $best['name'];
} else {
foreach($applied as $a) {
$final_discount += $a['amount'];
$info_parts[] = $a['name'];
}
}
}

if($final_discount > $base_total) $final_discount = $base_total; // не уходим в минус

return [
'base' => $base_total,
'discount' => $final_discount,
'total' => $base_total - $final_discount,
'info' => implode(', ', $info_parts)
];
}
}

/**
* Подсчёт количества заказов пользователя.
* ИСПРАВЛЕНО:
* - Использует csc_get_all_orders() вместо get_option('csc_all_orders', []).
* - Таймзона: created_at хранится в локальном времени сайта,
*   а time() возвращает UTC. Используем get_date_from_gmt()
*   для корректного сравнения при несовпадении таймзоны.
*
* @param string $phone  Нормализованный телефон
* @param int    $months Период в месяцах (0 = все время)
* @return int Количество заказов
*/
if (!function_exists('csc_count_user_orders')) {
function csc_count_user_orders($phone, $months) {
// ИСПРАВЛЕНО: единая функция чтения заказов
$all = csc_get_all_orders();
$cnt = 0;

// ИСПРАВЛЕНО: таймзона.
// created_at хранится как current_time('mysql'), что зависит от таймзоны сайта.
// Для корректного сравнения с time() (UTC) конвертируем в UTC.
$limit_ts = ($months > 0) ? strtotime("-$months months") : 0;

foreach($all as $o) {
if(isset($o['phone']) && $o['phone'] === $phone) {
if(isset($o['status']) && $o['status'] !== 'отменено') {
// ИСПРАВЛЕНО: конвертируем created_at из локального времени в UTC
$created_at_utc = get_date_from_gmt($o['created_at'], 'U');
if($created_at_utc >= $limit_ts) $cnt++;
}
}
}
return $cnt;
}
}