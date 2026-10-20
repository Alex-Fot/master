<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// 0. КЭШИРОВАННЫЕ ХЕЛПЕРЫ (ОПТИМИЗАЦИЯ БЕЗ ПОТЕРИ ФУНКЦИОНАЛА)
// ============================================================

/**
* ОПТИМИЗАЦИЯ: Кэшированные настройки.
* Читает get_option('csc_main_settings') один раз за запрос.
* Все последующие вызовы возвращают из статической памяти.
*/
if (!function_exists('csc_get_settings_cached')) {
    function csc_get_settings_cached() {
        static $settings = null;
        if ($settings === null) {
            $settings = get_option('csc_main_settings', []);
        }
        return $settings;
    }
}

/**
* ОПТИМИЗАЦИЯ: Единая функция чтения заказов со статическим кэшем.
* Приоритет: кастомная таблица → wp_options (фолбэк).
* Статический кэш устраняет повторные чтения БД в рамках одного запроса.
*
* @param bool $force_refresh Принудительно обновить кэш
* @return array Ассоциативный массив заказов [order_number => data]
*/
if (!function_exists('csc_get_all_orders')) {
    function csc_get_all_orders($force_refresh = false) {
        static $cached_orders = null;
        if (!$force_refresh && $cached_orders !== null) {
            return $cached_orders;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);
        if ($table_exists) {
            $results = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
            $orders = [];
            foreach ($results as $row) {
                $order_number = $row['order_number'];
                $orders[$order_number] = csc_db_row_to_order_array($row);
            }
            $cached_orders = $orders;
            return $orders;
        }
        // Фолбэк: читаем из wp_options (для сайтов до миграции)
        $orders = get_option('csc_all_orders', []);
        $cached_orders = $orders;
        return $orders;
    }
}

/**
* Преобразование строки БД в массив заказа (десериализация JSON-полей)
*/
if (!function_exists('csc_db_row_to_order_array')) {
    function csc_db_row_to_order_array($row) {
        $order = [
            'order_number'        => $row['order_number'] ?? '',
            'name'                => $row['name'] ?? '',
            'phone'               => $row['phone'] ?? '',
            'email'               => $row['email'] ?? '',
            'address'             => $row['address'] ?? '',
            'comment'             => $row['comment'] ?? '',
			'client_lat'          => isset($row['client_lat']) ? floatval($row['client_lat']) : null,
            'client_lng'          => isset($row['client_lng']) ? floatval($row['client_lng']) : null,
            'geocode_status'      => $row['geocode_status'] ?? '',
            'city'                => $row['city'] ?? '',
            'city_name'           => $row['city_name'] ?? '',
            'zone'                => $row['zone'] ?? '',
            'date'                => $row['date'] ?? '',
            'time'                => $row['time'] ?? '',
            'duration'            => intval($row['duration'] ?? 0),
            'total'               => floatval($row['total'] ?? 0),
            'base_total'          => floatval($row['base_total'] ?? 0),
            'discount'            => floatval($row['discount'] ?? 0),
            'discount_info'       => $row['discount_info'] ?? '',
            'commission'          => floatval($row['commission'] ?? 0),
            'master_income'       => floatval($row['master_income'] ?? 0),
            'payment_method'      => $row['payment_method'] ?? 'cash',
            'services'            => json_decode($row['services'] ?? '[]', true) ?: [],
            'services_for_master' => json_decode($row['services_for_master'] ?? '[]', true) ?: [],
            'files'               => json_decode($row['files'] ?? '[]', true) ?: [],
            'completion_files'    => json_decode($row['completion_files'] ?? '[]', true) ?: [],
            'master_id'           => $row['master_id'] ?? '',
            'current_candidate'   => $row['current_candidate'] ?? null,
            'status'              => $row['status'] ?? 'ожидает',
            'routing_status'      => $row['routing_status'] ?? '',
            'rejected_by'         => json_decode($row['rejected_by'] ?? '[]', true) ?: [],
            'accepted_by'         => $row['accepted_by'] ?? null,
            'offer_time'          => !empty($row['offer_time']) ? intval($row['offer_time']) : null,
            'tg_message_id'       => !empty($row['tg_message_id']) ? intval($row['tg_message_id']) : null,
            'routing_attempts'    => intval($row['routing_attempts'] ?? 0),
			'routing_circle'      => intval($row['routing_circle'] ?? 1),
            'routing_started_at'  => $row['routing_started_at'] ?? null,
            'auth_token'          => $row['auth_token'] ?? '',
            'review'              => !empty($row['review']) ? json_decode($row['review'], true) : null,
            'additional_items'    => !empty($row['additional_items']) ? json_decode($row['additional_items'], true) : null,
            'parent_order'        => $row['parent_order'] ?? null,
            'is_child'            => !empty($row['is_child']),
            'has_child_order'     => $row['has_child_order'] ?? null,
            'cancel_reason'       => $row['cancel_reason'] ?? null,
            'master_released'     => !empty($row['master_released']),
            'created_at'          => $row['created_at'] ?? '',
            'completed_at'        => $row['completed_at'] ?? null,
            'cancelled_at'        => $row['cancelled_at'] ?? null,
            'started_at'          => $row['started_at'] ?? null,
        ];
        return $order;
    }
}

/**
* Преобразование массива заказа в данные для вставки в БД
*/
if (!function_exists('csc_order_array_to_db_row')) {
    function csc_order_array_to_db_row($order_number, $order) {
        return [
            'order_number'        => $order_number,
            'name'                => $order['name'] ?? '',
            'phone'               => $order['phone'] ?? '',
            'email'               => $order['email'] ?? '',
            'address'             => $order['address'] ?? '',
            'comment'             => $order['comment'] ?? '',
			'client_lat'          => isset($order['client_lat']) ? $order['client_lat'] : null,
            'client_lng'          => isset($order['client_lng']) ? $order['client_lng'] : null,
            'geocode_status'      => $order['geocode_status'] ?? '',
            'city'                => $order['city'] ?? '',
            'city_name'           => $order['city_name'] ?? '',
            'zone'                => $order['zone'] ?? '',
            'date'                => $order['date'] ?? '',
            'time'                => $order['time'] ?? '',
            'duration'            => intval($order['duration'] ?? 0),
            'total'               => floatval($order['total'] ?? 0),
            'base_total'          => floatval($order['base_total'] ?? 0),
            'discount'            => floatval($order['discount'] ?? 0),
            'discount_info'       => $order['discount_info'] ?? '',
            'commission'          => floatval($order['commission'] ?? 0),
            'master_income'       => floatval($order['master_income'] ?? 0),
            'payment_method'      => $order['payment_method'] ?? 'cash',
            'services'            => wp_json_encode($order['services'] ?? []),
            'services_for_master' => wp_json_encode($order['services_for_master'] ?? []),
            'files'               => wp_json_encode($order['files'] ?? []),
            'completion_files'    => wp_json_encode($order['completion_files'] ?? []),
            'master_id'           => $order['master_id'] ?? '',
            'current_candidate'   => $order['current_candidate'] ?? null,
            'status'              => $order['status'] ?? 'ожидает',
            'routing_status'      => $order['routing_status'] ?? '',
            'rejected_by'         => wp_json_encode($order['rejected_by'] ?? []),
            'accepted_by'         => $order['accepted_by'] ?? null,
            'offer_time'          => $order['offer_time'] ?? null,
            'tg_message_id'       => $order['tg_message_id'] ?? null,
            'routing_attempts'    => intval($order['routing_attempts'] ?? 0),
			'routing_circle'      => intval($order['routing_circle'] ?? 1),
            'routing_started_at'  => $order['routing_started_at'] ?? null,
            'auth_token'          => $order['auth_token'] ?? '',
            'review'              => isset($order['review']) ? wp_json_encode($order['review']) : null,
            'additional_items'    => isset($order['additional_items']) ? wp_json_encode($order['additional_items']) : null,
            'parent_order'        => $order['parent_order'] ?? null,
            'is_child'            => !empty($order['is_child']) ? 1 : 0,
            'has_child_order'     => $order['has_child_order'] ?? null,
            'cancel_reason'       => $order['cancel_reason'] ?? null,
            'master_released'     => !empty($order['master_released']) ? 1 : 0,
            'created_at'          => $order['created_at'] ?? current_time('mysql'),
            'completed_at'        => $order['completed_at'] ?? null,
            'cancelled_at'        => $order['cancelled_at'] ?? null,
            'started_at'          => $order['started_at'] ?? null,
        ];
    }
}

// ============================================================
// 1. УНИФИКАЦИЯ ДАННЫХ: НОРМАЛИЗАЦИЯ ТЕЛЕФОНА
// ============================================================
/**
* УНИВЕРСАЛЬНАЯ НОРМАЛИЗАЦИЯ ТЕЛЕФОНА
* Превращает любой формат (+7 (999) 000-00-00, 8999..., +7999...)
* в единый строковый формат для БД и сравнения: "79990000000"
*
* @param string $phone Входящий номер телефона в любом формате
* @return string Нормализованный номер (только цифры, начиная с 7 для РФ)
*/
if (!function_exists('csc_normalize_phone')) {
    function csc_normalize_phone($phone) {
        if (empty($phone)) return '';
        // 1. Удаляем всё, кроме цифр
        $clean = preg_replace('/[^0-9]/', '', $phone);
        // 2. Обработка российских кодов
        if (strlen($clean) === 11) {
            if ($clean[0] === '8') {
                return '7' . substr($clean, 1);
            } elseif ($clean[0] === '7') {
                return $clean;
            }
        }
        // 3. Обработка 10-значных номеров (добавляем 7)
        elseif (strlen($clean) === 10) {
            return '7' . $clean;
        }
        // 4. Обработка международных форматов (12-13 цифр с кодом 7)
        elseif (strlen($clean) === 12 || strlen($clean) === 13) {
            if (strpos($clean, '7') === 0) {
                return $clean;
            }
        }
        // 5. Возвращаем очищенный номер (для коротких номеров или других стран)
        return $clean;
    }
}

// ============================================================
// 1.1 ОПРЕДЕЛЕНИЕ ГОРОДА ПО ПОДДОМЕНУ (ДЛЯ ЗАКАЗОВ)
// ============================================================
/**
 * Автоматически определяет город по поддомену, на котором оформляется заказ.
 * Приоритет:
 * 1. Явная передача города через $_POST['city'] (для форм с выбором)
 * 2. Поддомен: moskva.site.ru → moskva
 * 3. Первый активный город из настроек
 *
 * @return array ['city_key' => string, 'city_name' => string] или пустые строки
 */
if (!function_exists('csc_detect_current_city')) {
    function csc_detect_current_city() {
        $cities = function_exists('csc_get_cities') ? csc_get_cities() : get_option('csc_cities', []);
        if (empty($cities)) {
            return ['city_key' => '', 'city_name' => ''];
        }

        // Приоритет 1: явная передача (форма калькулятора может передавать город)
        if (!empty($_POST['city'])) {
            $city_key = sanitize_title($_POST['city']);
            if (isset($cities[$city_key]) && !empty($cities[$city_key]['active'])) {
                return ['city_key' => $city_key, 'city_name' => $cities[$city_key]['name']];
            }
        }

        // Приоритет 2: поддомен
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // ИСПРАВЛЕНО: Убираем порт, если он есть (например, site.ru:8080), иначе explode сломается
        $host = explode(':', $host)[0]; 
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $subdomain = strtolower($parts[0]);
            if ($subdomain !== 'www') {
                foreach ($cities as $key => $city) {
                    if (($city['subdomain'] ?? '') === $subdomain && !empty($city['active'])) {
                        return ['city_key' => $key, 'city_name' => $city['name']];
                    }
                }
            }
        }

        // Приоритет 3: первый активный город
        foreach ($cities as $key => $city) {
            if (!empty($city['active'])) {
                return ['city_key' => $key, 'city_name' => $city['name']];
            }
        }

        return ['city_key' => '', 'city_name' => ''];
    }
}

// ============================================================
// 2. ХРАНЕНИЕ ДАННЫХ: СЕКЦИИ И УСЛУГИ (С КЭШИРОВАНИЕМ)
// ============================================================

/**
* ОПТИМИЗАЦИЯ: статический кэш секций и услуг.
* Читает get_option один раз за запрос.
*/
if (!function_exists('csc_get_sections')) {
    function csc_get_sections($force_refresh = false) {
        // ИСПРАВЛЕНО: добавлена инвалидация статического кэша (см. csc_cache_delete_items)
        static $sections = null;
        if ($force_refresh || $sections === null) {
            $data = get_option('csc_service_sections', []);
            $sections = is_array($data) ? $data : [];
        }
        return $sections;
    }
}

if (!function_exists('csc_get_items')) {
    function csc_get_items($force_refresh = false) {
        // ИСПРАВЛЕНО: статический кэш инвалидируется через csc_cache_delete_items()
        // (раньше кэш на весь запрос не сбрасывался даже при force_refresh)
        static $items = null;
        if ($force_refresh || $items === null) {
            $data = get_option('csc_service_items', []);
            $items = is_array($data) ? $data : [];
        }
        return $items;
    }
}

// ============================================================
// 0b. ИНВАЛИДАЦИЯ КЭША (реализация функций, вызываемых из админа/модулей)
// ============================================================
if (!function_exists('csc_cache_delete_items')) {
    /**
     * Полный сброс кэшей услуг/секций (статический + object cache + транзиенты).
     * Вызывается из csc-admin-pages.php при сохранении каталога услуг.
     */
    function csc_cache_delete_items() {
        csc_get_sections(true);
        csc_get_items(true);
        foreach (['csc_items_all', 'csc_items_cache', 'csc_get_items_cache', 'csc_service_items_cache'] as $key) {
            wp_cache_delete($key);
            delete_transient($key);
        }
        wp_cache_delete('csc_service_items', 'options');
        do_action('csc_cache_invalidated', 'items');
    }
}

if (!function_exists('csc_cache_delete_masters')) {
    /**
     * Сброс кэша списка мастеров (wp_cache + статика csc_get_settings... нет, только список).
     */
    function csc_cache_delete_masters() {
        wp_cache_delete('csc_masters_list_v2');
        delete_transient('csc_masters_list_v2');
        if (function_exists('csc_invalidate_ratings_cache')) {
            csc_invalidate_ratings_cache();
        }
        do_action('csc_cache_invalidated', 'masters');
    }
}

if (!function_exists('csc_cache_delete_orders')) {
    /**
     * Сброс кэша заказов (единая точка для всех модулей).
     */
    function csc_cache_delete_orders() {
        csc_get_all_orders(true);
        wp_cache_delete('csc_orders_list');
        do_action('csc_cache_invalidated', 'orders');
    }
}

function csc_save_sections($sections) {
    // ИСПРАВЛЕНО: Убран array_filter, который удалял валидные falsy-значения (0, false, '')
    update_option('csc_service_sections', is_array($sections) ? $sections : [], false);
    // ИСПРАВЛЕНО: инвалидация статического кэша после сохранения
    if (function_exists('csc_cache_delete_items')) {
        csc_cache_delete_items();
    } else {
        csc_get_sections(true);
    }
}

function csc_save_items($items) {
    // ИСПРАВЛЕНО: Убран array_filter
    update_option('csc_service_items', is_array($items) ? $items : [], false);
    // ИСПРАВЛЕНО: инвалидация статического кэша после сохранения
    if (function_exists('csc_cache_delete_items')) {
        csc_cache_delete_items();
    } else {
        csc_get_items(true);
    }
}

// ============================================================
// 3. ЕДИНАЯ ФУНКЦИЯ СОХРАНЕНИЯ ЗАКАЗА
// ============================================================
/**
* Сохранить новый заказ.
* Работает с таблицей и с wp_options (фолбэк).
*
* @param string $order_number Номер заказа
* @param array  $order_data   Данные заказа
* @return bool Успех операции
*/
if (!function_exists('csc_save_order')) {
    function csc_save_order($order_number, $order_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);
        if ($table_exists) {
            $row = csc_order_array_to_db_row($order_number, $order_data);
            $result = $wpdb->insert($table, $row);
            // Сброс статического кэша
            csc_get_all_orders(true);
            return $result !== false;
        }
        // Фолбэк: сохраняем в wp_options
        $all_orders = get_option('csc_all_orders', []);
        $all_orders[$order_number] = $order_data;
        update_option('csc_all_orders', $all_orders, false);
        // Сброс статического кэша
        csc_get_all_orders(true);
        return true;
    }
}

/**
* Обновить существующий заказ.
*
* @param string $order_number Номер заказа
* @param array  $order_data   Полные данные заказа (замена)
* @return bool Успех операции
*/
if (!function_exists('csc_update_order')) {
    function csc_update_order($order_number, $order_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);
        if ($table_exists) {
            $row = csc_order_array_to_db_row($order_number, $order_data);
            $result = $wpdb->update($table, $row, ['order_number' => $order_number]);
            csc_get_all_orders(true);
            return $result !== false;
        }
        // Фолбэк
        $all_orders = get_option('csc_all_orders', []);
        $all_orders[$order_number] = $order_data;
        update_option('csc_all_orders', $all_orders, false);
        csc_get_all_orders(true);
        return true;
    }
}

/**
* Получить один заказ по номеру.
*
* @param string $order_number
* @return array|null
*/
if (!function_exists('csc_get_order')) {
    function csc_get_order($order_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);
        
        // ИСПРАВЛЕНО: Прямой запрос в БД вместо загрузки ВСЕХ заказов в память (предотвращает OutOfMemory)
        if ($table_exists) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_number = %s", $order_number), ARRAY_A);
            return $row ? csc_db_row_to_order_array($row) : null;
        }
        
        $all = csc_get_all_orders();
        return $all[$order_number] ?? null;
    }
}

/**
* Сохранить весь массив заказов (для совместимости со старым кодом).
* Предпочтительно использовать csc_save_order / csc_update_order.
*/
if (!function_exists('csc_save_all_orders')) {
    function csc_save_all_orders($all_orders) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);
        if ($table_exists) {
            foreach ($all_orders as $order_number => $order_data) {
                $row = csc_order_array_to_db_row($order_number, $order_data);
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE order_number = %s", $order_number
                ));
                if ($existing) {
                    $wpdb->update($table, $row, ['order_number' => $order_number]);
                } else {
                    $wpdb->insert($table, $row);
                }
            }
            csc_get_all_orders(true);
            return true;
        }
        // Фолбэк
        update_option('csc_all_orders', $all_orders, false);
        csc_get_all_orders(true);
        return true;
    }
}

// ============================================================
// 4. ГЕНЕРАЦИЯ УНИКАЛЬНОГО НОМЕРА ЗАКАЗА (АТОМАРНОСТЬ СОХРАНЕНА)
// ============================================================
/**
* Генерация уникального номера заказа.
* ИСПРАВЛЕНО: Использует атомарные запросы БД вместо wp_cache_add (который не работает без Redis/Memcached).
*/
if (!function_exists('csc_generate_unique_order_number')) {
    function csc_generate_unique_order_number() {
        global $wpdb;
        $date_prefix = wp_date('Ymd'); // ИСПРАВЛЕНО: используем wp_date для корректного TZ
        $counter_key = 'csc_order_counter_' . $date_prefix;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);

        // АТОМАРНАЯ БЛОКИРОВКА ЧЕРЕЗ БД (работает без внешних кэшей)
        $lock_key = 'csc_order_lock_' . $date_prefix;
        $lock_acquired = false;
        $waited = 0;
        
        while (!$lock_acquired && $waited < 2000) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                '_transient_' . $lock_key, time()
            ));
            
            if ($wpdb->rows_affected > 0) {
                $lock_acquired = true;
                break;
            }
            
            // Проверяем, не истекла ли старая блокировка (зависший процесс)
            $lock_time = get_transient($lock_key);
            if ($lock_time && (time() - $lock_time) > 5) {
                delete_transient($lock_key);
            }
            
            usleep(100000); // 100ms
            $waited += 100;
        }

        // Атомарное инкрементирование счетчика в БД
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) 
             VALUES (%s, '1', 'no') 
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $counter_key
        ));
        
        $counter = (int) get_option($counter_key, 0);

        do {
            $candidate = 'CSC-' . $date_prefix . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);
            $counter++; 
            
            if ($table_exists) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE order_number = %s", $candidate));
            } else {
                $all_orders = csc_get_all_orders();
                $exists = isset($all_orders[$candidate]);
            }
        } while ($exists);

        // Обновляем финальное значение счетчика, если были коллизии
        if ($counter > (int)get_option($counter_key, 0)) {
             update_option($counter_key, $counter, false);
        }

        // Снимаем блокировку
        delete_transient($lock_key);

        return $candidate;
    }
}

// ============================================================
// 5. ПРОВЕРКА ПРАЗДНИКОВ И РАБОЧИХ ДНЕЙ (С КЭШИРОВАНИЕМ)
// ============================================================
/**
* Проверка на праздник.
* Поддержка форматов: ГГГГ-ММ-ДД и ДД.ММ.ГГГГ.
* ОПТИМИЗАЦИЯ: статический кэш результатов для одинаковых дат.
*/
if (!function_exists('csc_is_holiday')) {
    function csc_is_holiday($date_str) {
        // ОПТИМИЗАЦИЯ: кэш результатов
        static $holiday_cache = [];
        if (isset($holiday_cache[$date_str])) {
            return $holiday_cache[$date_str];
        }
        // ОПТИМИЗАЦИЯ: используем кэшированные настройки
        $s = csc_get_settings_cached();
        $holidays = (array)($s['holidays'] ?? []);
        
        // ИСПРАВЛЕНО: Используем DateTime + new DateTimeZone('Europe/Moscow') для корректного часового пояса
        try {
            $tz = new DateTimeZone('Europe/Moscow');
            $dt = new DateTime($date_str, $tz);
            $ymd = $dt->format('Y-m-d');
            $dmy = $dt->format('d.m.Y');
        } catch (Exception $e) {
            $holiday_cache[$date_str] = false;
            return false;
        }
        
        $result = false;
        foreach ($holidays as $h) {
            $h = trim((string)$h);
            if ($h === '') continue;
            if ($h === $ymd || $h === $dmy) {
                $result = true;
                break;
            }
            try {
                $dt_h = new DateTime($h, $tz);
                if ($dt_h->format('Y-m-d') === $ymd) {
                    $result = true;
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        $holiday_cache[$date_str] = $result;
        return $result;
    }
}

/**
* Проверка на рабочий день (с учётом праздников).
* ИСПРАВЛЕНО: строгое сравнение строк для совместимости с date('N').
* ОПТИМИЗАЦИЯ: статический кэш результатов.
*/
if (!function_exists('csc_is_workday')) {
    function csc_is_workday($date_str) {
        // ОПТИМИЗАЦИЯ: кэш результатов
        static $workday_cache = [];
        if (isset($workday_cache[$date_str])) {
            return $workday_cache[$date_str];
        }
        // ОПТИМИЗАЦИЯ: используем кэшированные настройки
        $s = csc_get_settings_cached();
        $work_days = (array)($s['work_days'] ?? ['1','2','3','4','5']);
        $work_days_strings = array_map('strval', $work_days);
        
        // ИСПРАВЛЕНО: wp_date вместо date для учета часового пояса WordPress
        try {
            $tz = new DateTimeZone('Europe/Moscow');
            $dt = new DateTime($date_str, $tz);
            $current_day = $dt->format('N');
        } catch (Exception $e) {
            $workday_cache[$date_str] = false;
            return false;
        }
        
        $result = in_array($current_day, $work_days_strings, true) && !csc_is_holiday($date_str);
        $workday_cache[$date_str] = $result;
        return $result;
    }
}

// ============================================================
// 6. RATE LIMIT: ОТПРАВКА ЗАЯВОК (С ПОДДЕРЖКОЙ ПРОКСИ)
// ============================================================
/**
* Защита от спама при отправке заявок.
* ИСПРАВЛЕНО: поддержка X-Forwarded-For для работы за балансировщиком.
*/
if (!function_exists('csc_check_rate_limit')) {
    function csc_check_rate_limit() {
        $ip = csc_get_client_ip();
        $key = 'csc_submission_' . md5($ip);
        if (get_transient($key)) return false;
        $timeout = defined('MINUTE_IN_SECONDS') ? (2 * MINUTE_IN_SECONDS) : 120;
        set_transient($key, 1, $timeout);
        return true;
    }
}

/**
* ИСПРАВЛЕНО: Лимит попыток входа в личный кабинет.
* 5 попыток на один телефон за 15 минут.
*
* @param string $phone Нормализованный телефон
* @return bool true если попытка разрешена, false если лимит исчерпан
*/
if (!function_exists('csc_check_login_rate_limit')) {
    function csc_check_login_rate_limit($phone) {
        if (empty($phone)) return false;
        $key = 'csc_login_attempts_' . md5($phone);
        $data = get_transient($key);
        
        $max_attempts = 5;
        $window = 15 * MINUTE_IN_SECONDS;
        
        if (!$data) {
            set_transient($key, ['attempts' => 1, 'start_time' => time()], $window);
            return true;
        }
        
        $attempts = $data['attempts'] ?? 0;
        if ($attempts >= $max_attempts) {
            return false;
        }
        
        // ИСПРАВЛЕНО: Не сбрасываем TTL, обновляем только счетчик (строгое окно 15 минут)
        $data['attempts'] = $attempts + 1;
        $remaining_ttl = $window - (time() - ($data['start_time'] ?? time()));
        if ($remaining_ttl > 0) {
            set_transient($key, $data, $remaining_ttl);
        }
        return true;
    }
}

/**
* Сброс счётчика попыток входа (после успешной авторизации).
*/
if (!function_exists('csc_reset_login_rate_limit')) {
    function csc_reset_login_rate_limit($phone) {
        if (empty($phone)) return;
        delete_transient('csc_login_attempts_' . md5($phone));
    }
}

/**
* Получить IP клиента с учётом прокси / балансировщика.
* ИСПРАВЛЕНО: поддержка X-Forwarded-For, X-Real-IP.
*/
if (!function_exists('csc_get_client_ip')) {
    function csc_get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Проверяем заголовки прокси (только если сервер за балансировщиком)
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!empty($forwarded)) {
            $ips = explode(',', $forwarded);
            $first_ip = trim($ips[0]);
            if (filter_var($first_ip, FILTER_VALIDATE_IP)) {
                $ip = $first_ip;
            }
        }
        $real_ip = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if (!empty($real_ip) && filter_var($real_ip, FILTER_VALIDATE_IP)) {
            $ip = $real_ip;
        }
        return $ip;
    }
}

// ============================================================
// 7. ЕДИНАЯ ФУНКЦИЯ РАСЧЁТА СЛОТОВ
// ИСПРАВЛЕНО: фильтрация прошедших слотов для сегодняшнего дня
// ============================================================
if (!function_exists('csc_calculate_day_slots')) {
function csc_calculate_day_slots($date, $duration_minutes) {
$cache_key = 'csc_slots_' . md5($date . '_' . $duration_minutes);
$cached = get_transient($cache_key);
if ($cached !== false) {
return $cached;
}
if (!csc_is_workday($date)) {
set_transient($cache_key, [], 30);
return [];
}
$s = csc_get_settings_cached();
$start_hour = intval($s['work_hours_start'] ?? 9);
$end_hour = intval($s['work_hours_end'] ?? 18);
$interval = intval($s['interval_minutes'] ?? 30);
$travel_time = intval($s['travel_time'] ?? 0);
$total_required_time = intval($duration_minutes) + $travel_time;
if ($total_required_time <= 0) {
set_transient($cache_key, [], 30);
return [];
}
$slots = [];
// ИСПРАВЛЕНО: Используем DateTime с часовым поясом WordPress
$tz = new DateTimeZone('Europe/Moscow');
try {
$current = new DateTime($date . ' ' . str_pad($start_hour, 2, '0', STR_PAD_LEFT) . ':00:00', $tz);
$end = new DateTime($date . ' ' . str_pad($end_hour, 2, '0', STR_PAD_LEFT) . ':00:00', $tz);
} catch (Exception $e) {
error_log('[CSC_SLOTS] Ошибка парсинга даты: ' . $e->getMessage());
return [];
}
// ============================================================
// ИСПРАВЛЕНО: Для сегодняшнего дня вычисляем минимальное время.
// Клиент не может записаться на слот, который уже прошёл.
// Добавлен запас на дорогу (travel_time).
// ============================================================
$today_str = wp_date('Y-m-d');
$min_allowed_ts = 0;
if ($date === $today_str) {
$now = new DateTime('now', $tz);
$min_allowed_ts = $now->getTimestamp() + ($travel_time * 60);
}
// ============================================================
// КОНЕЦ БЛОКА ФИЛЬТРАЦИИ ПРОШЕДШЕГО ВРЕМЕНИ
// ============================================================
if (!function_exists('csc_get_masters')) {
$m_file = dirname(__FILE__) . '/csc-masters.php';
if (file_exists($m_file)) require_once $m_file;
}
$masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
$active_masters = array_filter($masters, function($m) {
return !empty($m['active']) && ($m['status'] ?? 'active') === 'active' && (isset($m['is_online']) ? !empty($m['is_online']) : true);
});
// ИСПРАВЛЕНО: Запрашиваем только заказы за текущую дату, а не всю таблицу (предотвращает OutOfMemory)
$all_orders = [];
if (get_option('csc_orders_migrated', false)) {
global $wpdb;
$table = $wpdb->prefix . 'csc_orders';
$results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE date = %s", $date), ARRAY_A);
foreach ($results as $row) {
$all_orders[] = csc_db_row_to_order_array($row);
}
} else {
$all_orders_raw = csc_get_all_orders();
foreach ($all_orders_raw as $order) {
if (($order['date'] ?? '') === $date) {
$all_orders[] = $order;
}
}
}
// Карта занятости
$busy = [];
$busy_all = [];
foreach ($all_orders as $order) {
if (($order['status'] ?? '') === 'отменено') continue;
$order_start_str = ($order['date'] ?? '') . ' ' . ($order['time'] ?? '00:00');
try {
$dt_start = new DateTime($order_start_str, $tz);
$order_start = $dt_start->getTimestamp();
} catch (Exception $e) {
continue;
}
$order_end = $order_start + ((intval($order['duration'] ?? 0) + $travel_time) * 60);
$master_id = $order['master_id'] ?? '';
$candidate = $order['current_candidate'] ?? '';
$routing_status = $order['routing_status'] ?? '';
if ($routing_status === 'searching' && $candidate && !$master_id) {
$busy[$candidate][] = [$order_start, $order_end];
} elseif ($master_id) {
$busy[$master_id][] = [$order_start, $order_end];
} elseif (!$candidate) {
$busy_all[] = [$order_start, $order_end];
}
}
// День недели для проверки расписания мастеров (ИСПРАВЛЕНО: wp_date)
try {
$dt_req = new DateTime($date, $tz);
$req_day_of_week = (int)$dt_req->format('N'); // 1=Пн ... 7=Вс
} catch (Exception $e) {
return [];
}
$day_api_keys = [1=>'monday', 2=>'tuesday', 3=>'wednesday', 4=>'thursday', 5=>'friday', 6=>'saturday', 7=>'sunday'];
$day_key = $day_api_keys[$req_day_of_week] ?? '';
while ($current < $end) {
$slot_start = $current->getTimestamp();
$slot_end = $slot_start + ($total_required_time * 60);
if ($slot_end > $end->getTimestamp()) break;
// ============================================================
// ИСПРАВЛЕНО: Пропускаем слоты, которые уже прошли (для сегодня)
// ============================================================
if ($min_allowed_ts > 0 && $slot_start < $min_allowed_ts) {
$current->modify('+' . $interval . ' minutes');
continue;
}
// ============================================================
$is_free = false;
if (empty($active_masters)) {
$is_free = true;
foreach ($busy_all as $range) {
if ($slot_start < $range[1] && $slot_end > $range[0]) {
$is_free = false;
break;
}
}
} else {
foreach ($active_masters as $master_id => $master) {
$master_free = true;
// === НОВОЕ: Проверка дня недели мастера ===
if (!empty($master['work_days']) && is_array($master['work_days'])) {
if (!in_array($req_day_of_week, array_map('intval', $master['work_days']))) {
continue; // Мастер не работает в этот день
}
}
// === НОВОЕ: Проверка детального расписания (schedule) ===
$slot_hour = (int)$current->format('H');
$slot_minute = (int)$current->format('i');
$slot_decimal = $slot_hour + ($slot_minute / 60);
if (isset($master['schedule']) && is_array($master['schedule']) && $day_key) {
$day_schedule = $master['schedule'][$day_key] ?? null;
if ($day_schedule && !empty($day_schedule['enabled'])) {
$from_parts = explode(':', $day_schedule['from'] ?? '09:00');
$to_parts = explode(':', $day_schedule['to'] ?? '18:00');
$from_decimal = intval($from_parts[0]) + (intval($from_parts[1] ?? 0) / 60);
$to_decimal = intval($to_parts[0]) + (intval($to_parts[1] ?? 0) / 60);
if ($slot_decimal < $from_decimal || $slot_decimal >= $to_decimal) {
continue; // Мастер не работает в этот час
}
// Проверяем что заказ закончится до конца смены мастера
$slot_end_decimal = $slot_decimal + ($total_required_time / 60);
if ($slot_end_decimal > $to_decimal) {
continue;
}
} else {
continue; // День выключен в расписании
}
}
// === Фолбэк на work_hours_start/end ===
elseif (isset($master['work_hours_start']) && isset($master['work_hours_end'])) {
$m_start_h = intval($master['work_hours_start']);
$m_end_h = intval($master['work_hours_end']);
$m_start_decimal = $m_start_h;
$m_end_decimal = $m_end_h;
if ($slot_decimal < $m_start_decimal || $slot_decimal >= $m_end_decimal) {
continue;
}
$slot_end_decimal = $slot_decimal + ($total_required_time / 60);
if ($slot_end_decimal > $m_end_decimal) {
continue;
}
}
// Глобальная занятость
foreach ($busy_all as $range) {
if ($slot_start < $range[1] && $slot_end > $range[0]) {
$master_free = false;
break;
}
}
// Индивидуальная занятость
if ($master_free && !empty($busy[$master_id])) {
foreach ($busy[$master_id] as $range) {
if ($slot_start < $range[1] && $slot_end > $range[0]) {
$master_free = false;
break;
}
}
}
if ($master_free) {
$is_free = true;
break;
}
}
}
if ($is_free) {
$slots[] = $current->format('H:i');
}
$current->modify('+' . $interval . ' minutes');
}
set_transient($cache_key, $slots, 30);
return $slots;
}
}

// ============================================================
// НОВОЕ 5.0: ГЕОМЕТРИЯ И ГЕОКОДИНГ
// ============================================================

/**
 * НОВОЕ 5.0: Расстояние между двумя точками по формуле Хаверсина.
 *
 * @param float $lat1 Широта точки 1
 * @param float $lng1 Долгота точки 1
 * @param float $lat2 Широта точки 2
 * @param float $lng2 Долгота точки 2
 * @return float Расстояние в километрах
 */
if (!function_exists('csc_haversine_km')) {
    function csc_haversine_km($lat1, $lng1, $lat2, $lng2) {
        $R = 6371; // радиус Земли в км
        $lat1 = (float)$lat1;
        $lng1 = (float)$lng1;
        $lat2 = (float)$lat2;
        $lng2 = (float)$lng2;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($R * $c, 3);
    }
}

/**
 * НОВОЕ 5.0: Кеш геокодинга.
 * Хранит результаты в wp_options с TTL.
 * Три уровня: точный кеш, нечёткий кеш, отрицательный кеш.
 */
if (!function_exists('csc_get_geocode_cache')) {
    function csc_get_geocode_cache($address) {
        $key = 'csc_geocache_' . md5(mb_strtolower(trim($address)));
        $cached = get_transient($key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        return null;
    }
}

if (!function_exists('csc_save_geocode_cache')) {
    function csc_save_geocode_cache($address, $result, $ttl = 86400) {
        $key = 'csc_geocache_' . md5(mb_strtolower(trim($address)));
        set_transient($key, $result, $ttl);
    }
}

/**
 * НОВОЕ 5.0: Геокодинг адреса через OpenStreetMap Nominatim.
 * Бесплатно, без API-ключа.
 *
 * Кеш:
 * 1. Точный кеш — адрес найден ранее → вернуть координаты
 * 2. Нечёткий кеш — убрать номер дома и попробовать снова
 * 3. Отрицательный кеш — адрес не найден ранее → не дёргать API повторно
 *
 * @param string $address Адрес для геокодирования
 * @param string $country Код страны для ограничения поиска (по умолчанию 'ru')
 * @return array ['lat' => float, 'lng' => float, 'display_name' => string, 'status' => 'ok'|'failed']
 */
/**
 * НОВОЕ 5.2: Геокодинг адреса через OpenStreetMap Nominatim.
 * МНОГОУРОВНЕВЫЙ ПОИСК: полный адрес → без деталей → без дома → город+улица
 * Бесплатно, без API-ключа. Лимит: 1 запрос/секунда.
 *
 * @param string $address Адрес для геокодирования
 * @param string $country Код страны для ограничения поиска (по умолчанию 'ru')
 * @return array ['lat' => float, 'lng' => float, 'display_name' => string, 'status' => 'ok'|'failed', 'message' => string]
 */
if (!function_exists('csc_geocode_address')) {
function csc_geocode_address($address, $country = 'ru') {
    if (empty($address)) {
        return ['lat' => null, 'lng' => null, 'display_name' => '', 'status' => 'failed', 'message' => 'Пустой адрес'];
    }
    
    $original_address = trim($address);
    
    // ============================================================
    // УРОВЕНЬ 1: КЭШ
    // ============================================================
    $cached = csc_get_geocode_cache($original_address);
    if ($cached !== null) {
        return $cached;
    }
    
    // ============================================================
    // УРОВЕНЬ 2: ПОДГОТОВКА ВАРИАНТОВ АДРЕСА
    // Пробуем несколько вариантов от полного к упрощённому
    // ============================================================
    $address_variants = csc_prepare_address_variants($original_address);
    
    foreach ($address_variants as $index => $variant) {
        // Задержка между запросами к Nominatim (лимит 1 запрос/сек)
        if ($index > 0) {
            usleep(1100000); // 1.1 секунды
        }
        
        $result = csc_nominatim_search($variant, $country);
        
        if ($result['status'] === 'ok' && csc_validate_coordinates($result['lat'], $result['lng'])) {
            // Успех — кэшируем результат для всех вариантов
            csc_save_geocode_cache($original_address, $result, 90 * DAY_IN_SECONDS);
            foreach ($address_variants as $v) {
                if ($v !== $variant) {
                    csc_save_geocode_cache($v, $result, 90 * DAY_IN_SECONDS);
                }
            }
            return $result;
        }
    }
    
    // ============================================================
    // УРОВЕНЬ 3: НИЧЕГО НЕ НАЙДЕНО — отрицательный кэш на 1 час
    // ============================================================
    $negative = [
        'lat' => null,
        'lng' => null,
        'display_name' => '',
        'status' => 'failed',
        'message' => 'Адрес не найден после ' . count($address_variants) . ' попыток: ' . $original_address
    ];
    csc_save_geocode_cache($original_address, $negative, 3600); // 1 час вместо 24
    
    error_log('[CSC_GEOCODE] FAILED: ' . $original_address);
    return $negative;
}
}

/**
 * Подготовка вариантов адреса для многоуровневого поиска
 */
if (!function_exists('csc_prepare_address_variants')) {
function csc_prepare_address_variants($address) {
    $variants = [];
    
    // Вариант 1: Полный адрес (безопасное упрощение)
    $clean = preg_replace('/^\d{6},?\s*/', '', trim($address)); // убрать индекс в начале
    $clean = preg_replace('/,\s*(кв\.?|квартира|офис|оф\.?|эт\.?|этаж)\s*\d+[а-яА-Я]?/iu', '', $clean); // убрать квартиру/офис
    $clean = preg_replace('/,\s*(лит\.?\s*[а-яА-Я]+)/iu', '', $clean); // убрать литеру
    $clean = trim($clean);
    if (!empty($clean)) $variants[] = $clean;
    
    // Вариант 2: Без номера дома/строения/корпуса
    $no_house = preg_replace('/,\s*(дом|д\.?|дом\.)?\s*\d+[а-яА-Я]?(\/\d+)?/iu', '', $clean);
    $no_house = preg_replace('/\s+(стр\.?|корп\.?|строение|корпус)\s*\d+/iu', '', $no_house);
    $no_house = preg_replace('/\s+\/\s*\d+$/', '', trim($no_house));
    $no_house = trim($no_house);
    if (!empty($no_house) && $no_house !== $clean && !in_array($no_house, $variants)) {
        $variants[] = $no_house;
    }
    
    // Вариант 3: Оставить максимум 4 части адреса (город, улица, дом)
    $parts = array_map('trim', explode(',', $clean));
    if (count($parts) > 4) {
        $short = implode(', ', array_slice($parts, 0, 4));
        if (!empty($short) && !in_array($short, $variants)) {
            $variants[] = $short;
        }
    }
    
    // Вариант 4: Последние 3 части (часто там улица и дом)
    if (count($parts) > 3) {
        $tail = implode(', ', array_slice($parts, -3));
        if (!empty($tail) && !in_array($tail, $variants)) {
            $variants[] = $tail;
        }
    }
    
    return $variants;
}
}

/**
 * Запрос к Nominatim API с правильными заголовками
 */
if (!function_exists('csc_nominatim_search')) {
function csc_nominatim_search($query, $country = 'ru') {
    $url = add_query_arg([
        'format'     => 'json',
        'q'          => $query,
        'countrycodes' => $country,
        'limit'      => 1,
        'addressdetails' => 1,
    ], 'https://nominatim.openstreetmap.org/search');
    
    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => [
            // Nominatim требует осмысленный User-Agent
            'User-Agent' => 'CSC-Booking/9.0 (WordPress plugin; contact: ' . get_option('admin_email', 'admin@example.com') . ')',
            'Accept'     => 'application/json',
            'Accept-Language' => 'ru,en',
        ],
    ]);
    
    if (is_wp_error($response)) {
        error_log('[CSC_GEOCODE] Network error: ' . $response->get_error_message() . ' | Query: ' . $query);
        return ['lat' => null, 'lng' => null, 'display_name' => '', 'status' => 'failed', 'message' => 'Ошибка сети'];
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 429) {
        // Rate limit — Nominatim просит замедлиться
        error_log('[CSC_GEOCODE] Rate limited (429)');
        sleep(2);
        return ['lat' => null, 'lng' => null, 'display_name' => '', 'status' => 'failed', 'message' => 'Rate limited'];
    }
    if ($code !== 200) {
        return ['lat' => null, 'lng' => null, 'display_name' => '', 'status' => 'failed', 'message' => 'HTTP ' . $code];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body) || !is_array($body) || !isset($body[0]['lat'])) {
        return ['lat' => null, 'lng' => null, 'display_name' => '', 'status' => 'failed', 'message' => 'Не найдено'];
    }
    
    return [
        'lat'          => (float)$body[0]['lat'],
        'lng'          => (float)$body[0]['lon'],
        'display_name' => $body[0]['display_name'] ?? $query,
        'status'       => 'ok',
        'message'      => '',
    ];
}
}

/**
 * Валидация координат: проверка на (0,0) и нахождение в пределах России
 */
if (!function_exists('csc_validate_coordinates')) {
function csc_validate_coordinates($lat, $lng) {
    // Отсечение (0, 0) и мусорных значений
    if ($lat == 0 && $lng == 0) return false;
    if ($lat < -90 || $lat > 90) return false;
    if ($lng < -180 || $lng > 180) return false;
    
    // Грубая проверка: координаты в пределах России
    // Широта России: 41.2 — 81.9
    // Долгота России: 19.7 — 179.0 (и часть за -180)
    $in_russia_lat = ($lat >= 41.0 && $lat <= 82.0);
    $in_russia_lng = ($lng >= 19.0 && $lng <= 180.0) || ($lng >= -180.0 && $lng <= -168.0);
    
    if (!$in_russia_lat || !$in_russia_lng) {
        error_log("[CSC_GEOCODE] Координаты вне России: {$lat}, {$lng}");
        return false;
    }
    
    return true;
}
}

// ============================================================
// 9. PWA / WEB APP (ИСПРАВЛЕНО: esc_url для всех URL)
// ============================================================
add_action('wp_head', 'csc_pwa_head');
function csc_pwa_head() {
    if (defined('CSC_PLUGIN_URL')) {
        // ИСПРАВЛЕНО: все URL обёрнуты в esc_url()
        echo '<link rel="manifest" href="' . esc_url(CSC_PLUGIN_URL . 'assets/manifest.json') . '">';
        echo '<meta name="theme-color" content="#2271b1">';
        echo '<link rel="apple-touch-icon" href="' . esc_url(CSC_PLUGIN_URL . 'assets/icon-192.png') . '">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    }
}

add_action('init', 'csc_register_sw');
function csc_register_sw() {
    if (isset($_GET['csc-sw'])) {
        // ИСПРАВЛЕНО: Отключаем кэширование, чтобы браузер всегда получал актуальный Service Worker
        nocache_headers();
        header('Content-Type: application/javascript');
        echo "self.addEventListener('fetch', function(event) {});";
        exit;
    }
}

add_action('wp_head', 'csc_sw_loader');
function csc_sw_loader() {
    if (is_ssl()) {
        // ИСПРАВЛЕНО: home_url обёрнут в esc_url()
        $sw_url = esc_url(home_url('/?csc-sw=1'));
        echo "<script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('{$sw_url}', {scope: '/'})
                .catch(console.error);
            });
        }
        </script>";
    }
}