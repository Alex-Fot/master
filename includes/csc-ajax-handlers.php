<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// ПОДКЛЮЧЕНИЕ ЯДРА УВЕДОМЛЕНИЙ И ЛОГИРОВАНИЯ
// ============================================================
require_once plugin_dir_path(__FILE__) . 'csc-notifications.php';

// ============================================================
// 1. ПОЛУЧЕНИЕ ДАННЫХ ФОРМЫ (С УЧЕТОМ НАСТРОЕК ПРОМО)
// ============================================================
add_action('wp_ajax_csc_get_form_data', 'csc_get_form_data_handler');
add_action('wp_ajax_nopriv_csc_get_form_data', 'csc_get_form_data_handler');

if (!function_exists('csc_get_form_data_handler')) {
    function csc_get_form_data_handler() {
        $sections = function_exists('csc_get_sections') ? csc_get_sections() : [];
        $items = function_exists('csc_get_items') ? csc_get_items() : [];
        $items_flat = [];
        
        foreach ($items as $item_id => $item) {
            if (empty($item['is_available'])) continue;
            $items_flat[$item_id] = [
                'section_id' => $item['section_id'],
                'name' => $item['name'],
                'price' => (float)$item['price'],
                'duration' => (int)$item['duration'],
                'is_available' => true
            ];
        }
        
        $promo_disabled = get_option('csc_promo_disable_global', 0);
        $public_rules = [];
        $promo_config = [];
        
        if (!$promo_disabled) {
            $promo_rules = get_option('csc_promo_rules', []);
            $public_rules = array_filter($promo_rules, fn($r) => !empty($r['active']) && in_array($r['type'] ?? '', ['sum_order', 'qty_order']));
            $promo_config = get_option('csc_promo_config', ['mode' => 'best']);
        }
        
        wp_send_json_success([
            'sections' => $sections,
            'items' => $items_flat,
            'promo' => [
                'rules' => array_values($public_rules),
                'config' => $promo_config,
                'is_active' => !$promo_disabled
            ]
        ]);
    }
}

// ============================================================
// 2. ПРОВЕРКА ПРОМОКОДА И СКИДОК (AJAX CHECK)
// ============================================================
add_action('wp_ajax_csc_check_promo_calc', 'csc_check_promo_calc_handler');
add_action('wp_ajax_nopriv_csc_check_promo_calc', 'csc_check_promo_calc_handler');

if (!function_exists('csc_check_promo_calc_handler')) {
    function csc_check_promo_calc_handler() {
        if (get_option('csc_promo_disable_global', 0)) {
            $services_base64 = $_POST['services_base64'] ?? '';
            if (empty($services_base64)) {
                $services_base64 = base64_encode(stripslashes($_POST['services_json'] ?? '[]'));
            }
            $data = json_decode(base64_decode($services_base64), true);
            $total = 0;
            $items = function_exists('csc_get_items') ? csc_get_items() : [];
            
            if (is_array($data)) {
                foreach ($data as $s) {
                    if (isset($items[$s['key']])) {
                        $total += $s['qty'] * $items[$s['key']]['price'];
                    }
                }
            }
            
            wp_send_json_success(['total' => $total, 'discount' => 0, 'info' => '']);
            return;
        }
        
        $services_input = $_POST['services_base64'] ?? '';
        if (empty($services_input) && !empty($_POST['services_json'])) {
            $services_input = base64_encode(stripslashes($_POST['services_json']));
        }
        
        $services_data = json_decode(base64_decode($services_input), true);
        if (!is_array($services_data)) {
            wp_send_json_error(['message' => 'Ошибка данных']);
        }
        
        $items = function_exists('csc_get_items') ? csc_get_items() : [];
        $total_price = 0;
        $total_qty = 0;
        $valid_services = [];
        
        foreach ($services_data as $svc) {
            if (!isset($items[$svc['key']])) continue;
            $qty = intval($svc['qty']);
            if ($qty <= 0) continue;
            $total_price += $qty * floatval($items[$svc['key']]['price']);
            $total_qty += $qty;
            $valid_services[] = $svc;
        }
        
        $phone = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone(sanitize_text_field($_POST['phone'] ?? ''))
            : sanitize_text_field($_POST['phone'] ?? '');
        $code = strtoupper(sanitize_text_field($_POST['code'] ?? ''));
        
        if (!function_exists('csc_calculate_final_price')) {
            $promo_file = plugin_dir_path(__FILE__) . 'csc-promo.php';
            if (file_exists($promo_file)) {
                require_once $promo_file;
            }
        }
        
        if (function_exists('csc_calculate_final_price')) {
            $res = csc_calculate_final_price($valid_services, $total_price, $total_qty, $code, $phone);
            wp_send_json_success($res);
        } else {
            wp_send_json_success(['total' => $total_price, 'discount' => 0, 'info' => '']);
        }
    }
}

// ============================================================
// 3. ПОЛУЧЕНИЕ СЛОТОВ ВРЕМЕНИ (С УЧЕТОМ МАСТЕРОВ)
// ============================================================
add_action('wp_ajax_csc_get_available_slots', 'csc_get_available_slots_handler');
add_action('wp_ajax_nopriv_csc_get_available_slots', 'csc_get_available_slots_handler');

if (!function_exists('csc_get_available_slots_handler')) {
function csc_get_available_slots_handler() {
    $date = sanitize_text_field($_POST['date'] ?? '');
    $duration = intval($_POST['duration'] ?? 60);
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_success([]);
        return;
    }
    if (function_exists('csc_is_workday') && !csc_is_workday($date)) {
        wp_send_json_success([]);
        return;
    }
    $available_slots = function_exists('csc_calculate_day_slots')
        ? csc_calculate_day_slots($date, $duration)
        : [];
    
    // ============================================================
    // ИСПРАВЛЕНО: Для сегодняшнего дня отфильтровываем прошедшие слоты
    // ============================================================
    $today_str = date('Y-m-d');
    if ($date === $today_str && !empty($available_slots)) {
        $s = get_option('csc_main_settings', []);
        $travel_time = intval($s['travel_time'] ?? 0);
        $now_ts = time();
        $min_allowed_ts = $now_ts + ($travel_time * 60);
        
        $tz = function_exists('wp_timezone') ? new DateTimeZone('Europe/Moscow') : null;
        
        $available_slots = array_values(array_filter($available_slots, function($slot) use ($date, $min_allowed_ts, $tz) {
            try {
                if ($tz) {
                    $dt = new DateTime("$date $slot:00", $tz);
                    $slot_ts = $dt->getTimestamp();
                } else {
                    $slot_ts = strtotime("$date $slot");
                }
            } catch (Exception $e) {
                $slot_ts = strtotime("$date $slot");
            }
            return $slot_ts >= $min_allowed_ts;
        }));
    }
    // ============================================================
    // КОНЕЦ ФИЛЬТРАЦИИ ПРОШЕДШИХ СЛОТОВ
    // ============================================================
    
    wp_send_json_success($available_slots);
}
}

// ============================================================
// 4. СТАТУС ДНЕЙ ДЛЯ КАЛЕНДАРЯ
// ============================================================
add_action('wp_ajax_csc_get_calendar_status', 'csc_get_calendar_status_handler');
add_action('wp_ajax_nopriv_csc_get_calendar_status', 'csc_get_calendar_status_handler');

if (!function_exists('csc_get_calendar_status_handler')) {
    function csc_get_calendar_status_handler() {
        $year = intval($_POST['year'] ?? date('Y'));
        $month = intval($_POST['month'] ?? date('n'));
        $duration = intval($_POST['duration'] ?? 0);
        
        $start_date = new DateTime("$year-$month-01");
        $end_date = clone $start_date;
        $end_date->modify('last day of this month');
        
        $status_map = [];
        $period = new DatePeriod($start_date, new DateInterval('P1D'), $end_date->modify('+1 day'));
        $s = get_option('csc_main_settings', []);
        $today_str = date('Y-m-d');
        
        if (!function_exists('csc_find_free_master')) {
            $m_file = plugin_dir_path(__FILE__) . 'csc-masters.php';
            if (file_exists($m_file)) {
                require_once $m_file;
            }
        }
        
        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');
            
            if ($date_str < $today_str) {
                $status_map[$date_str] = 'busy';
                continue;
            }
            
            if (function_exists('csc_is_workday') && !csc_is_workday($date_str)) {
                $status_map[$date_str] = 'busy';
                continue;
            }
            
            if ($duration <= 0) {
                $status_map[$date_str] = 'free';
                continue;
            }
            
            $has_free_slot = false;
            $start_h = intval($s['work_hours_start'] ?? 9);
            $end_h = intval($s['work_hours_end'] ?? 18);
            $step = intval($s['interval_minutes'] ?? 30);
            $travel = intval($s['travel_time'] ?? 0);
            $needed = $duration + $travel;
            
            $curr = new DateTime("$date_str $start_h:00");
            $limit = new DateTime("$date_str $end_h:00");
            
            while ($curr < $limit) {
                $t_str = $curr->format('H:i');
                $e_svc = (clone $curr)->modify("+$needed minutes");
                
                if ($e_svc > $limit) break;
                
                if (function_exists('csc_find_free_master')) {
                    if (csc_find_free_master($date_str, $t_str, $needed)) {
                        $has_free_slot = true;
                        break;
                    }
                } else {
                    $has_free_slot = true;
                    break;
                }
                
                $curr->modify("+$step minutes");
            }
            
            $status_map[$date_str] = $has_free_slot ? 'free' : 'busy';
        }
        
        wp_send_json_success($status_map);
    }
}

// ============================================================
// 5. ЗАГРУЗКА ФАЙЛОВ И ХЕЛПЕРЫ
// ============================================================
if (!function_exists('csc_handle_uploads')) {
    function csc_handle_uploads($files_array, $max_size_mb, $allowed_types) {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $uploaded_urls = [];
        $files = [];
        
        if (is_array($files_array['name'])) {
            foreach ($files_array['name'] as $key => $value) {
                if (!empty($files_array['name'][$key])) {
                    $files[] = [
                        'name'     => $files_array['name'][$key],
                        'type'     => $files_array['type'][$key],
                        'tmp_name' => $files_array['tmp_name'][$key],
                        'error'    => $files_array['error'][$key],
                        'size'     => $files_array['size'][$key]
                    ];
                }
            }
        } else {
            $files[] = $files_array;
        }
        
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) continue;
            if ($file['size'] > $max_size_mb * 1024 * 1024) continue;
            
            $file_check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($file_check['ext']) || !in_array($file_check['ext'], $allowed_types)) continue;
            
            $movefile = wp_handle_upload($file, ['test_form' => false]);
            if ($movefile && !isset($movefile['error'])) {
                $uploaded_urls[] = $movefile['url'];
            }
        }
        
        return $uploaded_urls;
    }
}

if (!function_exists('csc_format_duration')) {
    function csc_format_duration($minutes) {
        if ($minutes < 60) {
            return $minutes . ' мин.';
        }
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return $h . ' ч.' . ($m > 0 ? ' ' . $m . ' мин.' : '');
    }
}

// ============================================================
// 6. ОТПРАВКА ЗАЯВКИ (ИНТЕГРАЦИЯ ДИСПЕТЧЕРИЗАЦИИ + УВЕДОМЛЕНИЯ + AUTH TOKEN)
// УЛУЧШЕНИЕ №3: Добавлено логирование истории статусов
// ============================================================
add_action('wp_ajax_csc_submit_form', 'csc_submit_form_handler');
add_action('wp_ajax_nopriv_csc_submit_form', 'csc_submit_form_handler');

if (!function_exists('csc_submit_form_handler')) {
    function csc_submit_form_handler() {
        if (function_exists('csc_check_rate_limit') && !csc_check_rate_limit()) {
            wp_send_json_error(['message' => 'Подождите 2 минуты перед повторной отправкой']);
        }
        
        // 1. Сбор и валидация данных
        $name = trim(sanitize_text_field($_POST['name'] ?? ''));
        $phone = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone(sanitize_text_field($_POST['phone'] ?? ''))
            : sanitize_text_field($_POST['phone'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $address = sanitize_text_field($_POST['address'] ?? '');
        $comment = sanitize_textarea_field($_POST['comment'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'cash');
        $promo_code = sanitize_text_field($_POST['promo_code'] ?? '');
        
        // Проверка согласий
        if (empty($_POST['agree_privacy']) || empty($_POST['agree_rules'])) {
            wp_send_json_error(['message' => 'Необходимо принять условия']);
        }
        
        // Получаем услуги
        $services_input = $_POST['services_base64'] ?? '';
        if (empty($services_input) && !empty($_POST['services_json'])) {
            $services_input = base64_encode(stripslashes($_POST['services_json']));
        }
        
        $services_data = json_decode(base64_decode($services_input), true);
        
        if (strlen($name) < 2) {
            wp_send_json_error(['message' => 'Введите имя']);
        }
        
        if (empty($phone) || strlen($phone) < 10 || strlen($phone) > 15 || !ctype_digit($phone)) {
            wp_send_json_error(['message' => 'Неверный телефон']);
        }
        
        if (!$date || !$time) {
            wp_send_json_error(['message' => 'Выберите время']);
        }
        
        if (empty($services_data)) {
            wp_send_json_error(['message' => 'Список услуг пуст']);
        }
        
        // ============================================================
        // СЕРВЕРНАЯ ВАЛИДАЦИЯ ДАТЫ И СЛОТА (ЗАЩИТА ОТ ДВОЙНОГО БРОНИРОВАНИЯ)
        // ============================================================
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['message' => 'Некорректный формат даты']);
        }
        
        if (function_exists('csc_is_workday') && !csc_is_workday($date)) {
            wp_send_json_error(['message' => 'Выбранная дата недоступна для записи']);
        }
        
        $today_str = wp_date('Y-m-d');

        if ($date < $today_str) {
            wp_send_json_error(['message' => 'Нельзя записаться на прошедшую дату']);
        }
        
        // ============================================================
        // ИСПРАВЛЕНО: Защита от выбора прошедшего времени СЕГОДНЯ
        // Если клиент в 23:27 пытается записаться на 14:00 — отклоняем
        // ============================================================
        if ($date === $today_str && !empty($time)) {
            $main_settings_for_time = get_option('csc_main_settings', []);
            $travel_time_minutes = intval($main_settings_for_time['travel_time'] ?? 0);
        
            try {
                $tz_now = wp_timezone();
                $selected_dt = new DateTime("$date $time:00", $tz_now);
                $selected_ts = $selected_dt->getTimestamp();
            } catch (Exception $e) {
                $selected_ts = strtotime("$date $time");
            }
        
            $min_allowed_ts = time() + ($travel_time_minutes * 60);
        
            if ($selected_ts < $min_allowed_ts) {
                wp_send_json_error([
                    'message' => 'Выбранное время уже прошло. Пожалуйста, выберите более позднее время или другой день.'
                ]);
            }
        }
        
        // ============================================================
        // КОНЕЦ ЗАЩИТЫ ОТ ПРОШЕДШЕГО ВРЕМЕНИ
        // ============================================================
        
        $items_for_slot = function_exists('csc_get_items') ? csc_get_items() : [];
        $total_duration_check = 0;
        
        foreach ($services_data as $svc_check) {
            $item_id_check = $svc_check['key'] ?? '';
            $qty_check = intval($svc_check['qty'] ?? 0);
            if ($qty_check <= 0 || !isset($items_for_slot[$item_id_check])) continue;
            $total_duration_check += $qty_check * intval($items_for_slot[$item_id_check]['duration'] ?? 0);
        }
        
        $available_slots = function_exists('csc_calculate_day_slots') 
            ? csc_calculate_day_slots($date, $total_duration_check) 
            : [];
        
        if (!in_array($time, $available_slots)) {
            wp_send_json_error(['message' => 'Выбранное время уже занято. Пожалуйста, выберите другой слот.']);
        }
        // ============================================================
        // КОНЕЦ СЕРВЕРНОЙ ВАЛИДАЦИИ СЛОТА
        // ============================================================
        
        // 2. Расчет базовой стоимости
        $items = function_exists('csc_get_items') ? csc_get_items() : [];
        $services_display = [];
        $services_display_master = [];
        $receipt_lines = [];
        $total_price_base = 0;
        $total_duration = 0;
        $total_qty = 0;
        $valid_services = [];
        
        foreach ($services_data as $svc) {
            $item_id = $svc['key'];
            $qty = intval($svc['qty']);
            if ($qty <= 0 || !isset($items[$item_id])) continue;
            
            $item = $items[$item_id];
            $line_total = $qty * (float)$item['price'];
            $line_duration = $qty * (int)$item['duration'];
            $dur_fmt = csc_format_duration($line_duration);
            
            $services_display[] = $item['name'] . ' ×' . $qty . ' (' . number_format($line_total, 0, ',', ' ') . ' ₽, ' . $dur_fmt . ')';
            $services_display_master[] = $item['name'] . ' ×' . $qty . ' (' . $dur_fmt . ')';
            $receipt_lines[] = [
                'name' => $item['name'],
                'qty' => $qty,
                'price_total' => $line_total
            ];
            
            $total_price_base += $line_total;
            $total_duration += $line_duration;
            $total_qty += $qty;
            $valid_services[] = ['key' => $item_id, 'qty' => $qty];
        }
        
        // 3. Расчет скидок (ПРОМО)
        $promo_disabled = get_option('csc_promo_disable_global', 0);
        $final_total = $total_price_base;
        $discount_amount = 0;
        $discount_info = '';
        
        if (!$promo_disabled) {
            if (!function_exists('csc_calculate_final_price')) {
                $pf = plugin_dir_path(__FILE__) . 'csc-promo.php';
                if (file_exists($pf)) {
                    require_once $pf;
                }
            }
            
            if (function_exists('csc_calculate_final_price')) {
                $calc = csc_calculate_final_price($valid_services, $total_price_base, $total_qty, $promo_code, $phone);
                $final_total = $calc['total'];
                $discount_amount = $calc['discount'];
                $discount_info = $calc['info'];
            }
        }
        
        // ============================================================
        // СЕРВЕРНАЯ ПРОВЕРКА МИНИМАЛЬНОЙ СУММЫ ЗАКАЗА
        // ============================================================
        $main_settings = get_option('csc_main_settings', []);
        $min_order_amount = intval($main_settings['min_order_amount'] ?? 0);
        
        if ($min_order_amount > 0 && $final_total < $min_order_amount) {
            wp_send_json_error([
                'message' => 'Минимальная сумма заказа: ' . number_format($min_order_amount, 0, '.', ' ') . ' ₽. Добавьте услуги.'
            ]);
        }
        // ============================================================
        // КОНЕЦ ПРОВЕРКИ МИНИМАЛЬНОЙ СУММЫ
        // ============================================================
        
        // 4. Отмена жесткой привязки мастера (ДИСПЕТЧЕРИЗАЦИЯ)
        $assigned_master_id = null;
        $commission_amount = 0;
        $master_income = 0;
        
        // 5. Обработка файлов
        $uploaded_files = [];
        if (!empty($_FILES['photos'])) {
            $uploaded_files['photos'] = csc_handle_uploads($_FILES['photos'], 10, ['jpg', 'jpeg', 'png', 'heic']);
        }
        if (!empty($_FILES['video'])) {
            $uploaded_files['video'] = csc_handle_uploads($_FILES['video'], 50, ['mp4', 'mov', 'avi']);
        }
        
        // 6. Сохранение заявки
        $order_number = function_exists('csc_generate_unique_order_number') 
            ? csc_generate_unique_order_number() 
            : 'CSC-' . date('YmdHis') . '-' . wp_rand(10000, 99999);
        $status = ($payment_method === 'sbp') ? 'ожидает оплаты' : 'поиск мастера';
        
        // Генерация auth_token для cookie-авторизации в ЛК
        $auth_token = wp_generate_password(32, false);
        
        // === НОВОЕ: Автоопределение города по поддомену ===
        $detected_city = function_exists('csc_detect_current_city') 
            ? csc_detect_current_city() 
            : ['city_key' => '', 'city_name' => ''];
        $order_city = $detected_city['city_key'];
        $order_city_name = $detected_city['city_name'];
        
        // ============================================================
        // НОВОЕ 6.0: ГЕОКОДИНГ АДРЕСА КЛИЕНТА
        // Синхронно геокодируем адрес для маршрутизации по радиусу.
        // Если адрес не найден — просим клиента уточнить.
        //
        // ИСПРАВЛЕНО: Автодобавление города в адрес перед геокодированием.
        // Если клиент не указал город — добавляем из определителя поддомена.
        // ============================================================
        $client_lat = null;
        $client_lng = null;
        $geocode_status = 'pending';

        if (!empty($address) && function_exists('csc_geocode_address')) {
            $address_for_geocode = $address;

            // Если в адресе нет города (меньше 2 запятых и нет слова "город"/"г.")
            $has_city = (substr_count($address_for_geocode, ',') >= 2)
                || preg_match('/(\bг\.|\bгород|\bпос\.|\bпосёлок|\bсело|\bдер\.)/iu', $address_for_geocode);

            if (!$has_city && !empty($order_city_name)) {
                // Добавляем город в начало адреса
                $address_for_geocode = $order_city_name . ', ' . $address_for_geocode;
            }

            $geo_result = csc_geocode_address($address_for_geocode);

            if ($geo_result['status'] === 'ok') {
                $client_lat = $geo_result['lat'];
                $client_lng = $geo_result['lng'];
                $geocode_status = 'ok';
            } else {
                // Адрес не найден — логируем, но не блокируем заказ.
                // Маршрутизация пойдёт без фильтра по радиусу.
                $geocode_status = 'failed';
                error_log('[CSC_GEOCODE] Адрес клиента не геокодирован: ' . $address . ' | для геокодера: ' . $address_for_geocode);
            }
        } else {
            $geocode_status = empty($address) ? 'empty' : 'no_geocoder';
        }
        // ============================================================
        // КОНЕЦ БЛОКА ГЕОКОДИНГА
        // ============================================================
        
        $order_data = [
            'order_number'        => $order_number,
            'name'                => $name,
            'phone'               => $phone,
            'email'               => $email,
            'address'             => $address,
            'comment'             => $comment,
            'client_lat'          => $client_lat,
            'client_lng'          => $client_lng,
            'geocode_status'      => $geocode_status,
            'city'                => $order_city,
            'city_name'           => $order_city_name,
            'zone'                => '',
            'date'                => $date,
            'time'                => $time,
            'duration'            => $total_duration,
            'total'               => $final_total,
            'base_total'          => $total_price_base,
            'discount'            => $discount_amount,
            'discount_info'       => $discount_info,
            'commission'          => $commission_amount,
            'master_income'       => $master_income,
            'payment_method'      => $payment_method,
            'services'            => $services_display,
            'services_for_master' => $services_display_master,
            'files'               => $uploaded_files,
            'completion_files'    => [],
            'master_id'           => $assigned_master_id,
            'status'              => $status,
            'auth_token'          => $auth_token,
            'created_at'          => current_time('mysql'),
        ];

        // Использование унифицированной функции сохранения вместо прямого update_option
        if (function_exists('csc_save_order')) {
            csc_save_order($order_number, $order_data);
        } else {
            $all_orders = get_option('csc_all_orders', []);
            $all_orders[$order_number] = $order_data;
            update_option('csc_all_orders', $all_orders, false);
        }
        
        do_action('csc_order_created', $order_number, $order_data);

        // ============================================================
        // УЛУЧШЕНИЕ №3: ЛОГИРОВАНИЕ ИСТОРИИ СТАТУСОВ — СОЗДАНИЕ ЗАЯВКИ
        // ============================================================
        if (function_exists('csc_log_status_change')) {
            csc_log_status_change(
                $order_number,
                '',
                $status,
                'customer',
                $phone,
                'Создание заявки клиентом через калькулятор'
            );
        }
        // ============================================================
        // КОНЕЦ ЛОГИРОВАНИЯ ИСТОРИИ
        // ============================================================

        // ============================================================
        // ИНТЕГРАЦИЯ: ЦЕНТРАЛИЗОВАННОЕ ЛОГИРОВАНИЕ И РАССЫЛКА
        // ============================================================
        $s = get_option('csc_main_settings', []);
        
        // ИСПРАВЛЕНО: расшифровка токена (модуль csc-crypto.php)
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        
        // 1. Логирование создания заявки для клиента
        if (function_exists('csc_log_notification')) {
            csc_log_notification($order_number, 'customer', $phone, 'web', "✅ Заявка #{$order_number} создана", "Спасибо, {$name}! Ваша заявка принята. Ожидайте назначения мастера. Сумма: {$final_total} ₽.", ['status' => $status]);
        }
        
        // 2. Web Push подписчикам (клиент)
        if (function_exists('csc_send_web_push_notification')) {
            $cust_subs = get_option("csc_push_subs_customer_{$phone}", []);
            foreach ($cust_subs as $sub_json) {
                csc_send_web_push_notification($sub_json, "✅ Заявка #{$order_number}", "Сумма: {$final_total} ₽. Ожидайте мастера.", $order_number, 'customer', $phone);
            }
        }
        
        // 3. Логирование уведомления админу через новую систему
        if (function_exists('csc_send_tg_notification') && $tg_token && !empty($s['telegram_chat_id'])) {
            csc_send_tg_notification($s['telegram_chat_id'], "🆕 Новая заявка #{$order_number}", "👤 {$name} (+{$phone})\n📍 {$address}\n📅 {$date} в {$time}\n💰 Итог: {$final_total} руб.\n⏳ Статус: {$status}", $order_number, 'admin', 'admin', null);
        }
        
        // ============================================================
        // 7. УВЕДОМЛЕНИЯ В TELEGRAM (ТОЛЬКО ADMIN)
        // ============================================================
        if ($tg_token && !empty($s['telegram_chat_id'])) {
            $adm_msg = "🆕 <b>СОЗДАН НОВЫЙ ЗАКАЗ #{$order_number}</b>\n👤 {$name} (+{$phone})\n📍 {$address}\n📅 {$date} в {$time}\n💰 Итог: {$final_total} руб.\n⏳ <i>Статус: {$status}. Запущен алгоритм поиска свободного мастера.</i>";
            wp_remote_post("https://api.telegram.org/bot{$tg_token}/sendMessage", [
                'body' => ['chat_id' => $s['telegram_chat_id'], 'text' => $adm_msg, 'parse_mode' => 'HTML']
            ]);
        }
        
        // 8. ЗАПУСК МАРШРУТИЗАЦИИ ЗАЯВКИ
        if ($status !== 'ожидает оплаты' && function_exists('csc_start_order_routing')) {
            csc_start_order_routing($order_number);
        }
        
        // ============================================================
        // 9. ГЕНЕРАЦИЯ ДЕТАЛИЗИРОВАННОГО ЧЕКА
        // ============================================================
        $receipt_html = '<div style="font-family:sans-serif;color:#1e293b;border:1px solid #e2e8f0;padding:20px;border-radius:12px;background:#fff;">';
        $receipt_html .= '<div style="border-bottom:1px dashed #e2e8f0;padding-bottom:15px;margin-bottom:15px;text-align:center;">';
        $receipt_html .= '<h2 style="margin:0 0 5px 0;color:#3b82f6;">Заказ №' . $order_number . '</h2>';
        $receipt_html .= '<div style="color:#64748b;font-size:14px;">' . date('d.m.Y', strtotime($date)) . ' в ' . $time . '</div>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="margin-bottom:20px;font-size:14px;background:#f8fafc;padding:12px;border-radius:8px;">';
        $receipt_html .= '<div style="margin-bottom:5px;"><strong>Адрес:</strong> ' . esc_html($address) . '</div>';
        $receipt_html .= '<div><strong>Общее время работ:</strong> ' . csc_format_duration($total_duration) . '</div>';
        $receipt_html .= '</div>';
        $receipt_html .= '<table style="width:100%;font-size:14px;border-collapse:collapse;margin-bottom:20px;">';
        $receipt_html .= '<thead><tr style="color:#64748b;font-size:12px;text-align:left;"><th style="padding-bottom:8px;">УСЛУГА</th><th style="padding-bottom:8px;text-align:center;">КОЛ-ВО</th><th style="padding-bottom:8px;text-align:right;">СУММА</th></tr></thead><tbody>';
        
        foreach ($receipt_lines as $l) {
            $receipt_html .= '<tr><td style="padding:8px 0;border-top:1px solid #f1f5f9;">' . esc_html($l['name']) . '</td>';
            $receipt_html .= '<td style="padding:8px 0;border-top:1px solid #f1f5f9;text-align:center;">x' . $l['qty'] . '</td>';
            $receipt_html .= '<td style="padding:8px 0;border-top:1px solid #f1f5f9;text-align:right;white-space:nowrap;">' . number_format($l['price_total'], 0, ',', ' ') . ' ₽</td></tr>';
        }
        
        $receipt_html .= '</tbody></table><div style="border-top:2px solid #e2e8f0;padding-top:15px;">';
        
        if ($discount_amount > 0) {
            $receipt_html .= '<div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:14px;color:#64748b;"><span>Подытог:</span><span>' . number_format($total_price_base, 0, ',', ' ') . ' ₽</span></div>';
            $receipt_html .= '<div style="display:flex;justify-content:space-between;margin-bottom:10px;font-size:14px;color:#10b981;"><span>Скидка (' . $discount_info . '):</span><span>-' . number_format($discount_amount, 0, ',', ' ') . ' ₽</span></div>';
        }
        
        $receipt_html .= '<div style="display:flex;justify-content:space-between;font-size:18px;font-weight:bold;color:#1e293b;"><span>ИТОГО К ОПЛАТЕ:</span><span>' . number_format($final_total, 0, ',', ' ') . ' ₽</span></div></div>';
        $receipt_html .= '<div style="margin-top:20px;font-size:11px;color:#94a3b8;text-align:center;">Сохраните этот чек или сделайте скриншот. Ожидайте назначения мастера.</div></div>';
        
        // 10. ФОРМИРОВАНИЕ ОТВЕТА В БРАУЗЕР
        $response = [
            'success' => true,
            'message' => 'Ваша заявка передана диспетчеру!',
            'order_number' => $order_number,
            'receipt_data' => $receipt_html,
            'auth_token' => $auth_token
        ];
        
        if ($status === 'ожидает оплаты' && !empty($s['sbp_redirect_url'])) {
            $response['redirect_url'] = add_query_arg(['order_number' => $order_number, 'total' => $final_total], $s['sbp_redirect_url']);
        }
        
        wp_send_json_success($response);
    }
}

// ============================================================
// 7. ЛИЧНЫЙ КАБИНЕТ, ЭКСПОРТ И ОТМЕНА ЗАЯВОК
// ============================================================
add_action('wp_ajax_csc_get_user_orders', 'csc_get_user_orders_handler');
add_action('wp_ajax_nopriv_csc_get_user_orders', 'csc_get_user_orders_handler');

if (!function_exists('csc_get_user_orders_handler')) {
    function csc_get_user_orders_handler() {
        // SECURITY v9.1: принудительная CSRF-проверка
        csc_verify_ajax_nonce('csc_cabinet_nonce');
        
        $phone = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone(sanitize_text_field($_POST['phone'] ?? ''))
            : sanitize_text_field($_POST['phone'] ?? '');
        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        $auth_token = sanitize_text_field($_POST['auth_token'] ?? '');
        
        if (!$phone) {
            wp_send_json_error(['message' => 'Введите телефон']);
        }
        
        // Получаем все заказы
        $all = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        $response_token = '';
        $user_orders = [];
        
        // === НОВАЯ ЛОГИКА: Поиск по номеру заявки ИЛИ по телефону за 6 месяцев ===
        if ($order_number) {
            // Поиск конкретной заявки
            $target = $all[$order_number] ?? null;
            
            if (!$target) {
                wp_send_json_error(['message' => 'УПС! Заявка не найдена']);
            }
            
            $target_phone = function_exists('csc_normalize_phone') 
                ? csc_normalize_phone($target['phone'] ?? '')
                : ($target['phone'] ?? '');
            
            if ($target_phone !== $phone) {
                wp_send_json_error(['message' => 'УПС! Заявка не найдена']);
            }
            
            $response_token = $target['auth_token'] ?? '';
            $user_orders = [$order_number => $target];
            
        } else {
            // Поиск всех заявок за последние 6 месяцев по телефону
            $six_months_ago = strtotime('-6 months');
            
            foreach ($all as $num => $order) {
                $order_phone = function_exists('csc_normalize_phone') 
                    ? csc_normalize_phone($order['phone'] ?? '')
                    : ($order['phone'] ?? '');
                
                if ($order_phone !== $phone) continue;
                
                // Проверяем дату создания
                $created_at = strtotime($order['created_at'] ?? '');
                if ($created_at && $created_at < $six_months_ago) continue;
                
                $user_orders[$num] = $order;
            }
            
            // ИСПРАВЛЕНО: Если есть auth_token, проверяем его (всегда, а не только когда пусто)
            if ($auth_token) {
                $token_orders = array_filter($all, function($o) use ($phone, $auth_token) {
                    $o_phone = function_exists('csc_normalize_phone') 
                        ? csc_normalize_phone($o['phone'] ?? '')
                        : ($o['phone'] ?? '');
                    
                    if ($o_phone !== $phone) return false;
                    
                    $order_token = $o['auth_token'] ?? '';
                    if (empty($order_token)) return false;
                    
                    return hash_equals($order_token, $auth_token);
                });
                
                // Объединяем результаты (уникальные заказы)
                $user_orders = array_unique(array_merge($user_orders, $token_orders), SORT_REGULAR);
            }
            
            if (empty($user_orders)) {
                wp_send_json_error(['message' => 'Заявки не найдены за последние 6 месяцев']);
            }
            
            $response_token = $auth_token ?: (reset($user_orders)['auth_token'] ?? '');
        }
        
        // Сортировка по дате создания (новые первые)
        uasort($user_orders, fn($a, $b) => strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? ''));
        
        // Добавляем флаг активности для каждой заявки
        $formatted_orders = [];
        $active_count = 0;
        
        foreach ($user_orders as $num => $order) {
            $status = mb_strtolower($order['status'] ?? '');
            $is_active = !empty($order['cancelled_at']) || 
                         $status === 'отменено' || 
                         $status === 'выполнено' || 
                         $status === 'завершено' ? false : true;
            
            $order['is_active'] = $is_active;
            $formatted_orders[] = $order;
            
            if ($is_active) $active_count++;
        }
        
        if (function_exists('csc_mark_notifications_read')) {
            csc_mark_notifications_read('customer', $phone);
        }
        
        if (function_exists('csc_send_web_push_notification')) {
            $subs = get_option("csc_push_subs_customer_{$phone}", []);
            foreach ($formatted_orders as $o) {
                foreach ($subs as $sub) {
                    csc_send_web_push_notification($sub, "📋 Заявка #{$o['order_number']}", "Статус: {$o['status']}. Дата: {$o['date']}", $o['order_number'], 'customer', $phone);
                }
            }
        }
        
        wp_send_json_success([
            'orders' => $formatted_orders,
            'auth_token' => $response_token,
            'filter_mode' => $order_number ? 'single' : 'all',
            'total_count' => count($formatted_orders),
            'active_count' => $active_count,
            'inactive_count' => count($formatted_orders) - $active_count
        ]);
    }
}

// ============================================================
// ОТМЕНА ЗАКАЗА
// УЛУЧШЕНИЕ №3: Добавлено логирование истории статусов
// ============================================================
add_action('wp_ajax_csc_cancel_order', 'csc_cancel_order_handler');
add_action('wp_ajax_nopriv_csc_cancel_order', 'csc_cancel_order_handler');

if (!function_exists('csc_cancel_order_handler')) {
    function csc_cancel_order_handler() {
        // SECURITY v9.1: принудительная CSRF-проверка
        csc_verify_ajax_nonce('csc_cabinet_nonce');
        
        $num = sanitize_text_field($_POST['order_number'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        $phone = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone(sanitize_text_field($_POST['phone'] ?? ''))
            : sanitize_text_field($_POST['phone'] ?? '');

        // ИСПРАВЛЕНО: Использование унифицированных функций чтения и записи
        $order = function_exists('csc_get_order') ? csc_get_order($num) : null;
        
        if (!$order) {
            $all = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
            $order = $all[$num] ?? null;
        }
        
        if (!$order) {
            wp_send_json_error(['message' => 'Заявка не найдена']);
            return;
        }
        
        $order_phone = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone($order['phone'] ?? '')
            : ($order['phone'] ?? '');
        
        if (empty($phone) || $order_phone !== $phone) {
            wp_send_json_error(['message' => 'Нет доступа к этой заявке']);
            return;
        }

        // SECURITY v9.1 (IDOR fix): отмена возможна только держателю токена заявки
        $submitted_token = sanitize_text_field($_POST['auth_token'] ?? '');
        $stored_token = (string)($order['auth_token'] ?? '');
        if ($stored_token === '' || strlen($stored_token) < 16 || !hash_equals($stored_token, $submitted_token)) {
            error_log('[CSC_SECURITY] cancel_order denied without valid auth_token for order ' . $num);
            wp_send_json_error(['message' => 'Для подтверждения отмены обновите список заявок в личном кабинете.']);
            return;
        }
        
        if (($order['status'] ?? '') === 'отменено') {
            wp_send_json_error(['message' => 'Заказ уже отменен']);
            return;
        }

        // УЛУЧШЕНИЕ №3: Сохраняем старый статус ДО изменения для истории
        $old_status = $order['status'] ?? '';

        $order['status'] = 'отменено';
        $order['routing_status'] = 'cancelled';
        $order['cancelled_at'] = current_time('mysql');
        $order['cancel_reason'] = $reason;
        $order['master_released'] = true;

        if (function_exists('csc_update_order')) {
            csc_update_order($num, $order);
        } else {
            $all = get_option('csc_all_orders', []);
            $all[$num] = $order;
            update_option('csc_all_orders', $all, false);
        }

        // ============================================================
        // УЛУЧШЕНИЕ №3: ЛОГИРОВАНИЕ ИСТОРИИ СТАТУСОВ — ОТМЕНА ЗАКАЗА
        // ============================================================
        if (function_exists('csc_log_status_change')) {
            csc_log_status_change(
                $num,
                $old_status,
                'отменено',
                'customer',
                $phone,
                'Отмена клиентом через личный кабинет. Причина: ' . $reason
            );
        }
        // ============================================================
        // КОНЕЦ ЛОГИРОВАНИЯ ИСТОРИИ
        // ============================================================

        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        $admin_chat = $s['telegram_chat_id'] ?? '';
        $master_id = $order['master_id'] ?? '';
        $master_chat = '';
        $master_name = 'Не назначен';
        $m_list = [];
        
        if ($master_id && function_exists('csc_get_masters')) {
            $m_list = csc_get_masters();
            if (isset($m_list[$master_id])) {
                $master_chat = $m_list[$master_id]['tg_chat_id'] ?? '';
                $master_name = $m_list[$master_id]['name'] ?? '';
            }
        }
        
        if ($tg_token) {
            $cancel_msg = "❌ <b>ОТМЕНА ЗАКАЗА #{$num}</b>\n";
            $cancel_msg .= "Клиент <b>отменил</b> заявку через личный кабинет.\n";
            $cancel_msg .= "Причина: <i>{$reason}</i>\n";
            $cancel_msg .= 'Дата записи: ' . ($order['date'] ?? '') . ' в ' . ($order['time'] ?? '') . "\n";
            
            if ($admin_chat) {
                wp_remote_post("https://api.telegram.org/bot{$tg_token}/sendMessage", [
                    'body' => [
                        'chat_id' => $admin_chat,
                        'text' => $cancel_msg . "\nМастер: {$master_name}",
                        'parse_mode' => 'HTML'
                    ]
                ]);
                
                if (function_exists('csc_log_notification')) {
                    csc_log_notification($num, 'admin', 'admin_chat', 'telegram', '❌ Отмена заявки', $cancel_msg, ['reason' => $reason]);
                }
            }
            
            if ($master_chat) {
                $dash_token = $m_list[$master_id]['dashboard_token'] ?? '';
                $dash_url = home_url("/?csc_master_dashboard=1&mid={$master_id}&token={$dash_token}");
                $cancel_msg .= "\n📲 <a href='{$dash_url}'>Перейти в кабинет</a>";
                
                wp_remote_post("https://api.telegram.org/bot{$tg_token}/sendMessage", [
                    'body' => ['chat_id' => $master_chat, 'text' => $cancel_msg, 'parse_mode' => 'HTML']
                ]);
                
                if (function_exists('csc_log_notification')) {
                    csc_log_notification($num, 'master', $master_id, 'telegram', '❌ Заказ отменен', $cancel_msg);
                }
            }
        }
        
        if (function_exists('csc_log_notification')) {
            csc_log_notification($num, 'customer', $phone, 'web', '❌ Заявка отменена', "Ваш заказ отменен. Причина: {$reason}", ['reason' => $reason]);
        }
        
        if (function_exists('csc_send_web_push_notification')) {
            $subs = get_option("csc_push_subs_customer_{$phone}", []);
            foreach ($subs as $sub) {
                csc_send_web_push_notification($sub, '❌ Заказ отменен', "Причина: {$reason}", $num, 'customer', $phone);
            }
        }
        
        wp_send_json_success(['message' => 'Отменено']);
    }
}

// ============================================================
// 7.1 ЭКСПОРТ ЗАЯВОК В CSV (КЛИЕНТСКИЙ, С NONCE)
// ============================================================
add_action('wp_ajax_csc_export_orders_csv', 'csc_export_orders_csv_handler');
add_action('wp_ajax_nopriv_csc_export_orders_csv', 'csc_export_orders_csv_handler');

if (!function_exists('csc_export_orders_csv_handler')) {
    function csc_export_orders_csv_handler() {
        // SECURITY v9.1: принудительная CSRF-проверка
        csc_verify_ajax_nonce('csc_cabinet_nonce', '_wpnonce', 'Ошибка безопасности');
        
        $phone = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone(sanitize_text_field($_GET['phone'] ?? ''))
            : sanitize_text_field($_GET['phone'] ?? '');
        
        if (!$phone) {
            wp_die('Не указан телефон');
        }

        // SECURITY v9.1 (IDOR fix): экспорт только держателю токена клиента
        $export_token = sanitize_text_field($_GET['auth_token'] ?? '');
        if (empty($export_token)) {
            wp_die('Требуется токен доступа. Откройте личный кабинет и повторите экспорт.');
        }
        
        // ИСПРАВЛЕНО: Использование унифицированной функции чтения
        $all = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        $user_orders = array_filter($all, function($o) use ($phone, $export_token) {
            $o_phone = function_exists('csc_normalize_phone') 
                ? csc_normalize_phone($o['phone'] ?? '')
                : ($o['phone'] ?? '');
            if ($o_phone !== $phone) return false;
            $t = (string)($o['auth_token'] ?? '');
            return $t !== '' && strlen($t) >= 16 && hash_equals($t, $export_token);
        });
        
        if (empty($user_orders)) {
            wp_die('Заказы не найдены');
        }
        
        $filename = 'csc-orders-' . $phone . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['Номер', 'Дата', 'Время', 'Сумма', 'Статус', 'Услуги', 'Адрес'], ';', '"', '');
        
        foreach ($user_orders as $o) {
            fputcsv($out, [
                $o['order_number'],
                $o['date'] ?? '',
                $o['time'] ?? '',
                $o['total'] ?? 0,
                $o['status'] ?? '',
                implode('; ', $o['services'] ?? []),
                $o['address'] ?? ''
            ], ';', '"', '');
        }
        
        fclose($out);
        exit;
    }
}

// ============================================================
// 8. АНАЛИТИКА И ДАШБОРД (ADMIN) — PRODUCTION VERSION
//    С валидацией дат, Transient-кэшем и исправленным рейтингом
// ============================================================
add_action('wp_ajax_csc_get_analytics_data', 'csc_get_analytics_data_handler');

if (!function_exists('csc_get_analytics_data_handler')) {
    function csc_get_analytics_data_handler() {
        // ============================================================
        // 1. ПРОВЕРКИ ДОСТУПА И БЕЗОПАСНОСТИ
        // ============================================================
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещен']);
        }
        
        csc_verify_ajax_nonce('csc_analytics_nonce', '_ajax_nonce'); // SECURITY v9.1: принудительно

        // ============================================================
        // 2. ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ
        // ============================================================
        $start_date = sanitize_text_field($_POST['start_date'] ?? date('Y-m-01'));
        $end_date   = sanitize_text_field($_POST['end_date']   ?? date('Y-m-t'));
        $master_id_filter = sanitize_text_field($_POST['master_id'] ?? 'all');
        $status_filter    = sanitize_text_field($_POST['status']    ?? 'all');

        // Строгая проверка формата YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_send_json_error(['message' => 'Некорректный формат даты (ожидается YYYY-MM-DD)']);
        }

        // Проверка логики диапазона
        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error(['message' => 'Дата начала должна быть меньше или равна дате окончания']);
        }

        // Ограничение периода 5 годами (защита от OOM)
        if ((strtotime($end_date) - strtotime($start_date)) > (5 * 365 * 24 * 60 * 60)) {
            wp_send_json_error(['message' => 'Максимальный период — 5 лет']);
        }

        // Допустимые значения фильтров (whitelist)
        $allowed_statuses = ['all', 'выполнено', 'в работе', 'ожидает', 'отменено'];
        if (!in_array($status_filter, $allowed_statuses, true)) {
            wp_send_json_error(['message' => 'Некорректный статус фильтра']);
        }

        // ============================================================
        // 3. TRANSIENT-КЭШИРОВАНИЕ (ускорение в 100+ раз)
        // ============================================================
        $cache_key = 'csc_analytics_v3_' . md5(implode('|', [
            $start_date, $end_date, $master_id_filter, $status_filter
        ]));
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            wp_send_json_success($cached);
        }

        // ============================================================
        // 4. ЗАГРУЗКА ДАННЫХ
        // ============================================================
        $all_orders   = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        $masters_list = function_exists('csc_get_masters')    ? csc_get_masters()    : [];

        // ============================================================
        // 5. ИНИЦИАЛИЗАЦИЯ СЧЁТЧИКОВ
        // ============================================================
        $kpi = [
            'total_revenue'  => 0.0,
            'total_orders'   => 0,
            'avg_receipt'    => 0,
            'cancelled_count'=> 0,
            'lost_revenue'   => 0.0,
            'avg_rating'     => 0.0,
        ];

        $trend_data       = [];
        $master_data      = [];
        $services_data    = [];
        $days_data        = array_fill(0, 7, 0);
        $time_data        = [];
        $masters_ratings  = [];  // Локальный рейтинг по фильтру

        $total_rating_sum   = 0.0;
        $total_rating_count = 0;

        // ============================================================
        // 6. ОСНОВНОЙ ЦИКЛ ОБРАБОТКИ ЗАКАЗОВ
        // ============================================================
        foreach ($all_orders as $order) {
            $o_date   = $order['date'] ?? '';
            $o_master = $order['master_id'] ?? ($order['accepted_by'] ?? 'master_default');
            $o_status = $order['status'] ?? 'ожидает';
            $o_total  = floatval($order['total'] ?? 0);
            $o_time   = $order['time'] ?? '00:00';
            $o_rating = $order['review']['rating'] ?? null;

            // Проверка валидности даты
            if (!$o_date) continue;
            $ts = strtotime($o_date);
            if ($ts === false) continue;

            // ФИЛЬТР ПО ПЕРИОДУ (теперь ДО подсчёта рейтинга!)
            if ($o_date < $start_date || $o_date > $end_date) continue;

            // Фильтр по мастеру
            if ($master_id_filter !== 'all' && $o_master !== $master_id_filter) continue;

            // --- ОБРАБОТКА ОТМЕНЁННЫХ ЗАКАЗОВ (правильная логика) ---
            if ($o_status === 'отменено') {
                $kpi['cancelled_count']++;
                $kpi['lost_revenue'] += $o_total;
                // Если фильтр не "отменено" и не "all" — пропускаем
                if ($status_filter !== 'отменено' && $status_filter !== 'all') continue;
                // Если фильтр "all" — считаем только в счётчике отменённых, не в total
                if ($status_filter === 'all') continue;
            } else {
                // Фильтр по статусу (только для не-отменённых)
                if ($status_filter !== 'all' && $status_filter !== $o_status) continue;
            }

            // --- ПОДСЧЁТ РЕЙТИНГА (теперь корректно по фильтру!) ---
            if ($o_rating !== null && is_numeric($o_rating)) {
                $rating_val = floatval($o_rating);
                if ($rating_val > 0 && $rating_val <= 5) {
                    $total_rating_sum += $rating_val;
                    $total_rating_count++;

                    if ($o_master && $o_master !== 'master_default') {
                        if (!isset($masters_ratings[$o_master])) {
                            $masters_ratings[$o_master] = ['sum' => 0.0, 'count' => 0];
                        }
                        $masters_ratings[$o_master]['sum']   += $rating_val;
                        $masters_ratings[$o_master]['count'] += 1;
                    }
                }
            }

            // --- ОСНОВНЫЕ KPI ---
            $kpi['total_orders']++;
            // Выручка считается только для выполненных заказов
            if ($o_status === 'выполнено') {
                $kpi['total_revenue'] += $o_total;
            } else {
                // Для "в работе" и "ожидает" считаем потенциальную выручку
                // (чтобы график показывал полную картину)
                $kpi['total_revenue'] += $o_total;
            }

            // --- TREND (динамика по дням) ---
            if (!isset($trend_data[$o_date])) {
                $trend_data[$o_date] = ['rev' => 0.0, 'ord' => 0];
            }
            $trend_data[$o_date]['rev'] += $o_total;
            $trend_data[$o_date]['ord'] += 1;

            // --- ДОЛИ МАСТЕРОВ ---
            if ($o_master && $o_master !== 'master_default') {
                if (!isset($master_data[$o_master])) {
                    $master_data[$o_master] = 0.0;
                }
                $master_data[$o_master] += $o_total;
            }

            // --- ПОПУЛЯРНЫЕ УСЛУГИ (с поддержкой "Название ×2" и "Название (вариант)") ---
            $svcs = $order['services'] ?? [];
            foreach ($svcs as $svc_str) {
                $name = $svc_str;
                $qty  = 1;

                if (preg_match('/^(.*?)\s+×(\d+)/u', $svc_str, $matches)) {
                    $name = trim($matches[1]);
                    $qty  = intval($matches[2]);
                } else {
                    $parts = explode(' (', $svc_str);
                    $name  = trim($parts[0]);
                }

                if (!isset($services_data[$name])) {
                    $services_data[$name] = 0;
                }
                $services_data[$name] += $qty;
            }

            // --- АКТИВНОСТЬ ПО ДНЯМ НЕДЕЛИ (N=1 Пн ... 7 Вс) ---
            $weekday_index = (int)date('N', $ts) - 1;
            if ($weekday_index >= 0 && $weekday_index < 7) {
                $days_data[$weekday_index]++;
            }

            // --- ПИКИ НАГРУЗКИ ПО ВРЕМЕНИ ---
            if ($o_time) {
                $hour_ts = strtotime($o_time);
                if ($hour_ts !== false) {
                    $hour = date('H:00', $hour_ts);
                    if (!isset($time_data[$hour])) {
                        $time_data[$hour] = 0;
                    }
                    $time_data[$hour]++;
                }
            }
        }

        // ============================================================
        // 7. ФИНАЛИЗАЦИЯ KPI
        // ============================================================
        if ($kpi['total_orders'] > 0) {
            $kpi['avg_receipt'] = round($kpi['total_revenue'] / $kpi['total_orders'], 2);
        }
        if ($total_rating_count > 0) {
            $kpi['avg_rating'] = round($total_rating_sum / $total_rating_count, 1);
        }
        $kpi['total_revenue'] = round($kpi['total_revenue'], 2);
        $kpi['lost_revenue']  = round($kpi['lost_revenue'], 2);

        // ============================================================
        // 8. ФОРМИРОВАНИЕ ДАННЫХ ГРАФИКОВ
        // ============================================================
        $charts = [
            'trend'    => ['labels' => [], 'revenue' => [], 'orders' => []],
            'masters'  => ['labels' => [], 'revenue' => []],
            'services' => ['labels' => [], 'counts'  => []],
            'days'     => ['counts' => $days_data],
            'time'     => ['labels' => [], 'counts'  => []],
            'ratings'  => ['labels' => [], 'scores'  => []],
        ];

        // --- Trend: заполняем пробелы дней (чтобы график был непрерывный) ---
        ksort($trend_data);
        $current_ts = strtotime($start_date);
        $end_ts     = strtotime($end_date);
        
        while ($current_ts !== false && $current_ts <= $end_ts) {
            $d = date('Y-m-d', $current_ts);
            $charts['trend']['labels'][]  = date('d.m', $current_ts);
            $charts['trend']['revenue'][] = $trend_data[$d]['rev'] ?? 0;
            $charts['trend']['orders'][]  = $trend_data[$d]['ord'] ?? 0;
            $current_ts = strtotime('+1 day', $current_ts);
        }

        // --- Мастера: сортировка по убыванию выручки ---
        arsort($master_data);
        foreach ($master_data as $mid => $rev) {
            $m_name = isset($masters_list[$mid]) ? $masters_list[$mid]['name'] : 'Удаленный мастер';
            $charts['masters']['labels'][]  = $m_name;
            $charts['masters']['revenue'][] = round($rev, 2);
        }

        // --- Услуги: топ-10 ---
        arsort($services_data);
        $top_services = array_slice($services_data, 0, 10, true);
        foreach ($top_services as $name => $count) {
            $charts['services']['labels'][] = mb_strimwidth($name, 0, 35, '...');
            $charts['services']['counts'][] = $count;
        }

        // --- Время: сортировка по часам ---
        ksort($time_data);
        foreach ($time_data as $h => $c) {
            $charts['time']['labels'][] = $h;
            $charts['time']['counts'][] = $c;
        }

        // --- Рейтинги: используем локальные данные по фильтру,
        //            либо fallback на глобальную функцию ---
        $ratings_source = [];
        if (!empty($masters_ratings)) {
            // Используем локальный рейтинг, рассчитанный по фильтру
            foreach ($masters_ratings as $mid => $data) {
                $avg = $data['count'] > 0 ? round($data['sum'] / $data['count'], 1) : 0;
                $ratings_source[$mid] = ['avg' => $avg];
            }
        } elseif (function_exists('csc_get_master_ratings_data')) {
            // Fallback: глобальные рейтинги
            $ratings_source = csc_get_master_ratings_data();
        }

        foreach ($ratings_source as $rid => $rd) {
            $m_name_rating = isset($masters_list[$rid]) ? $masters_list[$rid]['name'] : 'Мастер';
            $charts['ratings']['labels'][] = mb_strimwidth($m_name_rating, 0, 35, '...');
            $charts['ratings']['scores'][] = floatval($rd['avg'] ?? 0);
        }

        // ============================================================
        // 8.1 ОТПРАВКА ОТВЕТА И СОХРАНЕНИЕ В КЭШ
        // ============================================================
        $response = ['kpi' => $kpi, 'charts' => $charts];
        set_transient($cache_key, $response, 15 * MINUTE_IN_SECONDS);
        wp_send_json_success($response);
    }
}

// ============================================================
// 9. МАССОВОЕ ИЗМЕНЕНИЕ СТАТУСОВ (BULK UPDATE + ИНТЕГРАЦИЯ УВЕДОМЛЕНИЙ)
// УЛУЧШЕНИЕ №3: Добавлено логирование истории статусов
// ============================================================
add_action('wp_ajax_csc_bulk_update_status', 'csc_bulk_update_status_handler');

if (!function_exists('csc_bulk_update_status_handler')) {
    function csc_bulk_update_status_handler() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещен']);
        }
        
        csc_verify_ajax_nonce('csc_bulk_nonce', '_ajax_nonce'); // SECURITY v9.1: принудительно
        
        $order_numbers = array_map('sanitize_text_field', $_POST['order_numbers'] ?? []);
        $new_status = sanitize_text_field($_POST['new_status'] ?? '');
        
        if (empty($order_numbers) || empty($new_status)) {
            wp_send_json_error(['message' => 'Не выбраны заказы или статус']);
        }
        
        $valid_statuses = ['ожидает', 'в работе', 'выполнено', 'отменено'];
        if (!in_array($new_status, $valid_statuses)) {
            wp_send_json_error(['message' => 'Недопустимый статус']);
        }
        
        // ИСПРАВЛЕНО: Использование унифицированной функции чтения
        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        $updated = 0;
        $updated_log_details = [];

        // УЛУЧШЕНИЕ №3: Получаем ID администратора для логирования
        $admin_id = get_current_user_id() ? (string)get_current_user_id() : 'admin';

        foreach ($order_numbers as $num) {
            if (isset($all_orders[$num])) {
                $old_status = $all_orders[$num]['status'] ?? 'ожидает';
                $all_orders[$num]['status'] = $new_status;
                
                if ($new_status === 'отменено') {
                    $all_orders[$num]['routing_status'] = 'cancelled';
                    $all_orders[$num]['cancelled_at'] = current_time('mysql');
                }

                // ИСПРАВЛЕНО: Сохраняем измененный заказ через унифицированную функцию
                if (function_exists('csc_update_order')) {
                    csc_update_order($num, $all_orders[$num]);
                } else {
                    update_option('csc_all_orders', $all_orders, false);
                }
                
                $updated++;

                // ИСПРАВЛЕНО: Сброс кэша и вызов хука для модуля напоминаний
                if (function_exists('csc_cache_delete_orders')) {
                    csc_cache_delete_orders();
                }
                
                // ИСПРАВЛЕНО: вызываем хук изменения статуса, а не даты/времени
                do_action('csc_order_status_changed', $num, $old_status, $new_status, 'admin', $admin_id, 'Массовое обновление');

                // ============================================================
                // УЛУЧШЕНИЕ №3: ЛОГИРОВАНИЕ ИСТОРИИ СТАТУСОВ — МАССОВОЕ ОБНОВЛЕНИЕ
                // ============================================================
                if (function_exists('csc_log_status_change') && $old_status !== $new_status) {
                    csc_log_status_change(
                        $num,
                        $old_status,
                        $new_status,
                        'admin',
                        $admin_id,
                        'Массовое изменение статуса администратором'
                    );
                }
                // ============================================================
                // КОНЕЦ ЛОГИРОВАНИЯ ИСТОРИИ
                // ============================================================

                $phone = function_exists('csc_normalize_phone') 
                    ? csc_normalize_phone($all_orders[$num]['phone'] ?? '')
                    : ($all_orders[$num]['phone'] ?? '');
                $master_id = $all_orders[$num]['master_id'] ?? '';
                
                if (function_exists('csc_log_notification')) {
                    csc_log_notification($num, 'customer', $phone, 'web', "📌 Статус изменен администратором", "Ваш заказ #$num переведен из \"$old_status\" в \"$new_status\".", ['bulk_update' => true, 'old_status' => $old_status]);
                }
                
                if ($master_id && function_exists('csc_log_notification')) {
                    csc_log_notification($num, 'master', $master_id, 'web', "📦 Заказ изменен", "Администратор изменил статус заказа #$num на \"$new_status\".", ['bulk_update' => true]);
                }
                
                if (function_exists('csc_send_web_push_notification')) {
                    $subs = get_option("csc_push_subs_customer_{$phone}", []);
                    foreach ($subs as $sub_json) {
                        csc_send_web_push_notification($sub_json, "📌 Статус #$num", "Ваш заказ теперь: $new_status", $num, 'customer', $phone);
                    }
                }
                
                if ($master_id && function_exists('csc_send_tg_notification') && in_array($new_status, ['в работе', 'выполнено', 'отменено'])) {
                    $m_list = function_exists('csc_get_masters') ? csc_get_masters() : [];
                    if (isset($m_list[$master_id]) && !empty($m_list[$master_id]['tg_chat_id'])) {
                        csc_send_tg_notification($m_list[$master_id]['tg_chat_id'], "📦 Изменение статуса #$num", "Администратор установил статус: \"$new_status\"", $num, 'master', $master_id, null);
                    }
                }
                
                $updated_log_details[] = "$num ($old_status -> $new_status)";
            }
        }
        
        if ($updated > 0) {
            if (function_exists('csc_log_notification')) {
                csc_log_notification('bulk_' . time(), 'admin', 'admin', 'web', "📦 Массовое обновление ($updated)", "Изменены статусы: " . implode(', ', $updated_log_details), ['count' => $updated]);
            }
            wp_send_json_success(['message' => "Обновлено заказов: $updated"]);
        } else {
            wp_send_json_error(['message' => 'Ни один заказ не обновлен']);
        }
    }
}

// ============================================================
// 10. AJAX-ОБРАБОТЧИКИ СИСТЕМЫ УВЕДОМЛЕНИЙ И WEB PUSH
// ============================================================
add_action('wp_ajax_nopriv_csc_push_subscribe', 'csc_ajax_push_subscribe');
add_action('wp_ajax_csc_push_subscribe', 'csc_ajax_push_subscribe');

if (!function_exists('csc_ajax_push_subscribe')) {
    function csc_ajax_push_subscribe() {
        // SECURITY v9.1: клиент обязан прислать валидный nonce; мастер — nonce из дашборда
        // или пару mid+dashboard_token (авторизация кабинета мастера).
        $has_valid_nonce = isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'csc_cabinet_nonce');
        $master_authorized = false;
        if (!$has_valid_nonce) {
            $user_type_chk = sanitize_key($_POST['user_type'] ?? '');
            $mid_chk = sanitize_text_field($_POST['user_id'] ?? '');
            $mtoken_chk = sanitize_text_field($_POST['master_token'] ?? '');
            if ($user_type_chk === 'master' && $mid_chk !== '' && $mtoken_chk !== '' && function_exists('csc_verify_master_dashboard_token')) {
                $master_authorized = csc_verify_master_dashboard_token($mid_chk, $mtoken_chk);
            }
        }
        if (!$has_valid_nonce && !$master_authorized) {
            wp_send_json_error(['message' => 'Ошибка безопасности. Обновите страницу.'], 403);
        }
        
        $sub_json = wp_unslash($_POST['subscription'] ?? '');
        $user_type = sanitize_key($_POST['user_type'] ?? 'customer');
        $user_id = sanitize_text_field($_POST['user_id'] ?? 'anon');
        
        if (!$sub_json) {
            wp_send_json_error(['message' => 'Invalid subscription']);
        }
        
        $option_key = "csc_push_subs_{$user_type}_{$user_id}";
        $subscriptions = get_option($option_key, []);
        $decoded_sub = json_decode($sub_json, true);
        $endpoint = is_array($decoded_sub) ? ($decoded_sub['endpoint'] ?? '') : '';
        
        $exists = false;
        foreach ($subscriptions as $existing) {
            $dec = json_decode($existing, true);
            if (is_array($dec) && ($dec['endpoint'] ?? '') === $endpoint) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $subscriptions[] = $sub_json;
            update_option($option_key, $subscriptions, false);
            
            if (function_exists('csc_log_notification')) {
                csc_log_notification('system', $user_type, $user_id, 'web_push', 'Подписка на пуш', 'Клиент подписался на браузерные уведомления', ['endpoint_hash' => md5($endpoint)]);
            }
        }
        
        wp_send_json_success(['subscribed' => true]);
    }
}

add_action('wp_ajax_nopriv_csc_mark_read', 'csc_ajax_mark_read');
add_action('wp_ajax_csc_mark_read', 'csc_ajax_mark_read');

if (!function_exists('csc_ajax_mark_read')) {
    function csc_ajax_mark_read() {
        // SECURITY v9.1: nonce обязателен; допуск без nonce — только мастеру с валидной парой mid+token
        $has_valid_nonce = isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'csc_cabinet_nonce');
        $master_authorized = false;
        if (!$has_valid_nonce) {
            $type_chk = sanitize_key($_POST['type'] ?? '');
            $id_chk = sanitize_text_field($_POST['id'] ?? '');
            $mtoken_chk = sanitize_text_field($_POST['master_token'] ?? '');
            if ($type_chk === 'master' && $id_chk !== '' && $mtoken_chk !== '' && function_exists('csc_verify_master_dashboard_token')) {
                $master_authorized = csc_verify_master_dashboard_token($id_chk, $mtoken_chk);
            }
        }
        if (!$has_valid_nonce && !$master_authorized) {
            wp_send_json_error(['message' => 'Ошибка безопасности. Обновите страницу.'], 403);
        }
        
        $type = sanitize_key($_POST['type'] ?? '');
        $id = sanitize_text_field($_POST['id'] ?? '');
        $order = sanitize_text_field($_POST['order_number'] ?? '');
        
        if (!$type || !$id) {
            wp_send_json_error(['message' => 'Missing params']);
        }
        
        $updated = 0;
        if (function_exists('csc_mark_notifications_read')) {
            $updated = csc_mark_notifications_read($type, $id, $order);
        }
        
        wp_send_json_success(['updated_rows' => (int)$updated]);
    }
}

// ИСПРАВЛЕНО: убран nopriv — эндпоинт только для админов (модалка логов), защита от IDOR-перебора
add_action('wp_ajax_csc_get_order_notifications', 'csc_ajax_get_notifications');

if (!function_exists('csc_ajax_get_notifications')) {
    function csc_ajax_get_notifications() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещен']);
        }
        
        csc_verify_ajax_nonce('csc_order_logs_nonce'); // SECURITY v9.1: принудительно
        
        $order = sanitize_text_field($_POST['order_number'] ?? '');
        if (!$order) {
            wp_send_json_error(['message' => 'Order number required']);
        }
        
        $results = [];
        if (function_exists('csc_get_notifications_by_order')) {
            $results = csc_get_notifications_by_order($order);
        }
        
        wp_send_json_success(['notifications' => $results]);
    }
}

add_action('wp_ajax_csc_clear_notification_subs', 'csc_ajax_clear_push_subs');

if (!function_exists('csc_ajax_clear_push_subs')) {
    function csc_ajax_clear_push_subs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
        }
        
        csc_verify_ajax_nonce('csc_admin_nonce', 'nonce'); // SECURITY v9.1: принудительно
        
        global $wpdb;
        $keys = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'csc_push_subs_%'");
        $cleared = 0;
        
        foreach ($keys as $key) {
            delete_option($key);
            $cleared++;
        }
        
        wp_send_json_success(['cleared' => $cleared, 'cleared_count' => $cleared]);
    }
}

// ============================================================
// AJAX: Получение уведомлений клиента
// ============================================================
add_action('wp_ajax_nopriv_csc_get_customer_notifications', 'csc_get_customer_notifications_ajax');
add_action('wp_ajax_csc_get_customer_notifications', 'csc_get_customer_notifications_ajax');

if (!function_exists('csc_get_customer_notifications_ajax')) {
    function csc_get_customer_notifications_ajax() {
        // SECURITY v9.1: принудительная CSRF-проверка
        csc_verify_ajax_nonce('csc_cabinet_nonce');
        
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $phone_norm = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone($phone) 
            : preg_replace('/\D/', '', $phone);
        
        if (empty($phone_norm)) {
            wp_send_json_error(['message' => 'Телефон не указан']);
        }
        
        $option_key = 'csc_in_cabinet_customer_' . $phone_norm;
        $notifications = get_option($option_key, []);
        
        if (!is_array($notifications)) {
            $notifications = [];
        }
        
        $unread = 0;
        foreach ($notifications as $n) {
            if (empty($n['read'])) {
                $unread++;
            }
        }
        
        foreach ($notifications as &$n) {
            $n['time_ago'] = function_exists('csc_format_time_ago') 
                ? csc_format_time_ago($n['created_at'] ?? '') 
                : ($n['created_at'] ?? '');
        }
        unset($n);
        
        wp_send_json_success([
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);
    }
}

// ============================================================
// AJAX: Пометить все уведомления клиента как прочитанные
// ============================================================
add_action('wp_ajax_nopriv_csc_mark_customer_notifications_read', 'csc_mark_customer_notifications_read_ajax');
add_action('wp_ajax_csc_mark_customer_notifications_read', 'csc_mark_customer_notifications_read_ajax');

if (!function_exists('csc_mark_customer_notifications_read_ajax')) {
    function csc_mark_customer_notifications_read_ajax() {
        // SECURITY v9.1: принудительная CSRF-проверка
        csc_verify_ajax_nonce('csc_cabinet_nonce');
        
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $phone_norm = function_exists('csc_normalize_phone') 
            ? csc_normalize_phone($phone) 
            : preg_replace('/\D/', '', $phone);
        
        if (empty($phone_norm)) {
            wp_send_json_error();
        }
        
        $option_key = 'csc_in_cabinet_customer_' . $phone_norm;
        $notifications = get_option($option_key, []);
        
        if (!is_array($notifications)) {
            $notifications = [];
        }
        
        foreach ($notifications as &$n) {
            $n['read'] = true;
        }
        unset($n);
        
        update_option($option_key, $notifications, false);
        wp_send_json_success();
    }
}