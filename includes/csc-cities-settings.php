<?php
/**
 * Файл: csc-cities-settings.php
 * Описание: Управление городами присутствия и зонами обслуживания
 * Версия: 1.1
 *
 * Функционал:
 * - CRUD городов (название, поддомен, часовой пояс, активность)
 * - CRUD зон обслуживания внутри каждого города (районы, микрорайоны)
 * - Поддержка координат для будущей работы с картами
 * - Drag & Drop сортировка зон
 * - Экспорт/Импорт городов в JSON
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 0. МИГРАЦИЯ: добавление zone_limit существующим городам
// ============================================================
if (!function_exists('csc_migrate_cities_zone_limit')) {
    function csc_migrate_cities_zone_limit() {
        if (get_option('csc_cities_zone_limit_migrated')) return;
        
        $cities = get_option('csc_cities', []);
        if (empty($cities) || !is_array($cities)) {
            update_option('csc_cities_zone_limit_migrated', true);
            return;
        }
        
        $changed = false;
        foreach ($cities as $key => &$city) {
            if (!array_key_exists('zone_limit', $city)) {
                $city['zone_limit'] = 0;
                $changed = true;
            }
        }
        unset($city);
        
        if ($changed) {
            update_option('csc_cities', $cities);
        }
        update_option('csc_cities_zone_limit_migrated', true);
    }
}
add_action('admin_init', 'csc_migrate_cities_zone_limit', 10);

// ============================================================
// 0.1 МИГРАЦИЯ: добавление координат (lat/lng) существующим городам
// ============================================================
if (!function_exists('csc_migrate_cities_coords')) {
    function csc_migrate_cities_coords() {
        if (get_option('csc_cities_coords_migrated')) return;
        
        $cities = get_option('csc_cities', []);
        if (empty($cities) || !is_array($cities)) {
            update_option('csc_cities_coords_migrated', true);
            return;
        }
        
        $changed = false;
        foreach ($cities as $key => &$city) {
            if (!array_key_exists('lat', $city)) {
                $city['lat'] = null;
                $changed = true;
            }
            if (!array_key_exists('lng', $city)) {
                $city['lng'] = null;
                $changed = true;
            }
        }
        unset($city);
        
        if ($changed) {
            update_option('csc_cities', $cities);
        }
        update_option('csc_cities_coords_migrated', true);
    }
}
add_action('admin_init', 'csc_migrate_cities_coords', 11);

// ============================================================
// 0.2 МИГРАЦИЯ: добавление sort_order существующим зонам
// ============================================================
if (!function_exists('csc_migrate_zones_sort_order')) {
    function csc_migrate_zones_sort_order() {
        if (get_option('csc_zones_sort_order_migrated')) return;
        
        $cities = get_option('csc_cities', []);
        if (empty($cities) || !is_array($cities)) {
            update_option('csc_zones_sort_order_migrated', true);
            return;
        }
        
        $changed = false;
        foreach ($cities as $city_key => &$city) {
            if (isset($city['zones']) && is_array($city['zones'])) {
                $sort_order = 0;
                foreach ($city['zones'] as $zone_id => &$zone) {
                    if (!array_key_exists('sort_order', $zone)) {
                        $zone['sort_order'] = $sort_order;
                        $changed = true;
                    }
                    $sort_order++;
                }
                unset($zone);
            }
        }
        unset($city);
        
        if ($changed) {
            update_option('csc_cities', $cities);
        }
        update_option('csc_zones_sort_order_migrated', true);
    }
}
add_action('admin_init', 'csc_migrate_zones_sort_order', 12);

// ============================================================
// 1. РЕГИСТРАЦИЯ СТРАНИЦЫ В АДМИНКЕ
// ============================================================
if (!function_exists('csc_add_cities_settings_page')) {
    function csc_add_cities_settings_page() {
        add_submenu_page(
            'options-general.php',
            'Города и зоны',
            '🌍 Города и зоны',
            'manage_options',
            'csc-cities-settings',
            'csc_render_cities_settings_page'
        );
    }
}
add_action('admin_menu', 'csc_add_cities_settings_page');

// ============================================================
// 2. AJAX: CRUD ГОРОДОВ
// ============================================================
add_action('wp_ajax_csc_save_city', 'csc_save_city_ajax');
if (!function_exists('csc_save_city_ajax')) {
    function csc_save_city_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $city_key = sanitize_title($_POST['city_key'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $subdomain = sanitize_title($_POST['subdomain'] ?? '');
        $timezone = sanitize_text_field($_POST['timezone'] ?? 'Europe/Moscow');
        $active = !empty($_POST['active']);
        
        if (empty($city_key) || empty($name) || empty($subdomain)) {
            wp_send_json_error(['message' => 'Заполните все обязательные поля']);
        }
        
        $zone_limit = intval($_POST['zone_limit'] ?? 0);
        if ($zone_limit < 0) $zone_limit = 0;
        if ($zone_limit > 100) $zone_limit = 100;
        
        // Координаты: широта и долгота
        $lat_input = $_POST['lat'] ?? '';
        $lng_input = $_POST['lng'] ?? '';
        $lat = ($lat_input !== '' && is_numeric($lat_input)) ? floatval($lat_input) : null;
        $lng = ($lng_input !== '' && is_numeric($lng_input)) ? floatval($lng_input) : null;
        
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            wp_send_json_error(['message' => 'Широта должна быть от -90 до 90']);
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            wp_send_json_error(['message' => 'Долгота должна быть от -180 до 180']);
        }
        
        $cities = csc_get_cities();
        
        // Валидация уникальности поддомена
        foreach ($cities as $existing_key => $existing_city) {
            if ($existing_key !== $city_key 
                && isset($existing_city['subdomain']) 
                && $existing_city['subdomain'] === $subdomain) {
                wp_send_json_error([
                    'message' => 'Поддомен "' . esc_html($subdomain) . '" уже используется городом "' . esc_html($existing_city['name']) . '"'
                ]);
            }
        }
        
        $cities[$city_key] = [
            'name'       => $name,
            'subdomain'  => $subdomain,
            'timezone'   => $timezone,
            'active'     => $active,
            'zone_limit' => $zone_limit,
            'lat'        => $lat,
            'lng'        => $lng,
            'zones'      => isset($cities[$city_key]['zones']) ? $cities[$city_key]['zones'] : [],
        ];
        update_option('csc_cities', $cities, false);
        wp_send_json_success(['message' => 'Город сохранён', 'city' => $cities[$city_key]]);
    }
}

add_action('wp_ajax_csc_delete_city', 'csc_delete_city_ajax');
if (!function_exists('csc_delete_city_ajax')) {
    function csc_delete_city_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $city_key = sanitize_title($_POST['city_key'] ?? '');
        if (empty($city_key)) wp_send_json_error(['message' => 'ID города не указан']);
        
        $cities = csc_get_cities();
        if (!isset($cities[$city_key])) wp_send_json_error(['message' => 'Город не найден']);
        
        unset($cities[$city_key]);
        update_option('csc_cities', $cities, false);
        wp_send_json_success(['message' => 'Город удалён']);
    }
}

// ============================================================
// 3. AJAX: CRUD ЗОН
// ============================================================
add_action('wp_ajax_csc_save_zone', 'csc_save_zone_ajax');
if (!function_exists('csc_save_zone_ajax')) {
    function csc_save_zone_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $city_key = sanitize_title($_POST['city_key'] ?? '');
        $zone_id = sanitize_key($_POST['zone_id'] ?? '');
        $zone_name = sanitize_text_field($_POST['zone_name'] ?? '');
        $zone_price_multiplier = floatval($_POST['zone_price_multiplier'] ?? 1.0);
        $zone_active = !empty($_POST['zone_active']);
        
        // НОВОЕ: Координаты зоны
        $lat_input = $_POST['lat'] ?? '';
        $lng_input = $_POST['lng'] ?? '';
        $lat = ($lat_input !== '' && is_numeric($lat_input)) ? floatval($lat_input) : null;
        $lng = ($lng_input !== '' && is_numeric($lng_input)) ? floatval($lng_input) : null;
        
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            wp_send_json_error(['message' => 'Широта должна быть от -90 до 90']);
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            wp_send_json_error(['message' => 'Долгота должна быть от -180 до 180']);
        }
        
        if (empty($city_key) || empty($zone_name)) {
            wp_send_json_error(['message' => 'Заполните все поля']);
        }
        
        $cities = csc_get_cities();
        if (!isset($cities[$city_key])) wp_send_json_error(['message' => 'Город не найден']);
        
        if (empty($zone_id)) {
            $zone_id = 'z_' . strtolower(wp_generate_password(6, false));
            $max_sort_order = -1;
            if (isset($cities[$city_key]['zones']) && is_array($cities[$city_key]['zones'])) {
                foreach ($cities[$city_key]['zones'] as $z) {
                    if (isset($z['sort_order']) && $z['sort_order'] > $max_sort_order) {
                        $max_sort_order = $z['sort_order'];
                    }
                }
            }
            $sort_order = $max_sort_order + 1;
        } else {
            $sort_order = isset($cities[$city_key]['zones'][$zone_id]['sort_order']) ? $cities[$city_key]['zones'][$zone_id]['sort_order'] : 0;
        }
        
        if (!isset($cities[$city_key]['zones']) || !is_array($cities[$city_key]['zones'])) {
            $cities[$city_key]['zones'] = [];
        }
        
        $cities[$city_key]['zones'][$zone_id] = [
            'id'                => $zone_id,
            'name'              => $zone_name,
            'price_multiplier'  => $zone_price_multiplier,
            'active'            => $zone_active,
            'sort_order'        => $sort_order,
            'lat'               => $lat,  // НОВОЕ
            'lng'               => $lng,  // НОВОЕ
        ];
        
        update_option('csc_cities', $cities, false);
        wp_send_json_success([
            'message' => 'Зона сохранена',
            'zone_id' => $zone_id,
            'zones'   => $cities[$city_key]['zones'],
        ]);
    }
}

add_action('wp_ajax_csc_delete_zone', 'csc_delete_zone_ajax');
if (!function_exists('csc_delete_zone_ajax')) {
    function csc_delete_zone_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        // ИСПРАВЛЕНО: sanitize_text_field вместо sanitize_title (сохраняет регистр)
        $city_key = sanitize_text_field($_POST['city_key'] ?? '');
        $zone_id = sanitize_text_field($_POST['zone_id'] ?? '');
        
        if (empty($city_key) || empty($zone_id)) wp_send_json_error(['message' => 'Недостаточно данных']);
        
        $cities = csc_get_cities();
        
        if (!isset($cities[$city_key]['zones'][$zone_id])) wp_send_json_error(['message' => 'Зона не найдена']);
        
        unset($cities[$city_key]['zones'][$zone_id]);
        
        // ИСПРАВЛЕНО: переиндексация массива
        $cities[$city_key]['zones'] = array_values($cities[$city_key]['zones']) 
            ? $cities[$city_key]['zones'] 
            : [];
        
        update_option('csc_cities', $cities, false);
        
        wp_send_json_success(['message' => 'Зона удалена', 'zones' => $cities[$city_key]['zones']]);
    }
}

// ============================================================
// 3.1 AJAX: МАССОВОЕ УДАЛЕНИЕ ЗОН
// ============================================================
add_action('wp_ajax_csc_bulk_delete_zones', 'csc_bulk_delete_zones_ajax');
if (!function_exists('csc_bulk_delete_zones_ajax')) {
    function csc_bulk_delete_zones_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $city_key = sanitize_title($_POST['city_key'] ?? '');
        $zone_ids = $_POST['zone_ids'] ?? [];
        
        if (empty($city_key) || !is_array($zone_ids) || empty($zone_ids)) {
            wp_send_json_error(['message' => 'Недостаточно данных или не выбраны зоны']);
        }
        
        $cities = csc_get_cities();
        if (!isset($cities[$city_key]['zones'])) wp_send_json_error(['message' => 'Город не найден']);
        
        $deleted = 0;
        foreach ($zone_ids as $zone_id) {
            $zone_id = sanitize_title($zone_id);
            if (isset($cities[$city_key]['zones'][$zone_id])) {
                unset($cities[$city_key]['zones'][$zone_id]);
                $deleted++;
            }
        }
        
        update_option('csc_cities', $cities, false);
        wp_send_json_success([
            'message' => 'Удалено зон: ' . $deleted,
            'zones'   => $cities[$city_key]['zones'],
        ]);
    }
}

// ============================================================
// 3.2 AJAX: СОРТИРОВКА ЗОН (Drag & Drop)
// ============================================================
add_action('wp_ajax_csc_sort_zones', 'csc_sort_zones_ajax');
if (!function_exists('csc_sort_zones_ajax')) {
    function csc_sort_zones_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $city_key = sanitize_title($_POST['city_key'] ?? '');
        $zone_order = $_POST['zone_order'] ?? [];
        
        if (empty($city_key) || !is_array($zone_order) || empty($zone_order)) {
            wp_send_json_error(['message' => 'Недостаточно данных']);
        }
        
        $cities = csc_get_cities();
        if (!isset($cities[$city_key]['zones'])) wp_send_json_error(['message' => 'Город не найден']);
        
        $sort_order = 0;
        foreach ($zone_order as $zone_id) {
            $zone_id = sanitize_title($zone_id);
            if (isset($cities[$city_key]['zones'][$zone_id])) {
                $cities[$city_key]['zones'][$zone_id]['sort_order'] = $sort_order;
                $sort_order++;
            }
        }
        
        update_option('csc_cities', $cities, false);
        wp_send_json_success(['message' => 'Порядок зон сохранён']);
    }
}

// ============================================================
// 3.3 AJAX: ЭКСПОРТ ГОРОДОВ В JSON
// ============================================================
add_action('wp_ajax_csc_export_cities', 'csc_export_cities_ajax');
if (!function_exists('csc_export_cities_ajax')) {
    function csc_export_cities_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $cities = csc_get_cities();
        
        wp_send_json_success([
            'message' => 'Экспорт завершён',
            'data' => $cities,
            'filename' => 'csc-cities-backup-' . date('Y-m-d-His') . '.json',
        ]);
    }
}

// ============================================================
// 3.4 AJAX: ИМПОРТ ГОРОДОВ ИЗ JSON
// ============================================================
add_action('wp_ajax_csc_import_cities', 'csc_import_cities_ajax');
if (!function_exists('csc_import_cities_ajax')) {
    function csc_import_cities_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        
        $json_data = $_POST['json_data'] ?? '';
        $import_mode = sanitize_text_field($_POST['import_mode'] ?? 'merge'); // merge или replace
        
        if (empty($json_data)) {
            wp_send_json_error(['message' => 'Не переданы данные для импорта']);
        }
        
        $imported_cities = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Ошибка парсинга JSON: ' . json_last_error_msg()]);
        }
        
        if (!is_array($imported_cities)) {
            wp_send_json_error(['message' => 'Некорректный формат данных']);
        }
        
        // Валидация структуры
        foreach ($imported_cities as $city_key => $city) {
            if (!is_array($city) || !isset($city['name']) || !isset($city['subdomain'])) {
                wp_send_json_error(['message' => 'Некорректная структура данных города "' . esc_html($city_key) . '"']);
            }
        }
        
        if ($import_mode === 'replace') {
            // Полная замена
            $cities = $imported_cities;
        } else {
            // Мерж с существующими данными
            $cities = csc_get_cities();
            foreach ($imported_cities as $city_key => $imported_city) {
                if (isset($cities[$city_key]) && isset($imported_city['zones'])) {
                    // Сохраняем существующие зоны при мерже
                    $imported_city['zones'] = array_merge(
                        isset($cities[$city_key]['zones']) ? $cities[$city_key]['zones'] : [],
                        $imported_city['zones']
                    );
                }
                $cities[$city_key] = $imported_city;
            }
        }
        
        update_option('csc_cities', $cities, false);
        
        wp_send_json_success([
            'message' => 'Импортировано городов: ' . count($imported_cities),
            'mode' => $import_mode,
        ]);
    }
}

// ============================================================
// 4. ХЕЛПЕР: Получить все активные зоны города
// ============================================================
if (!function_exists('csc_get_city_zones')) {
    function csc_get_city_zones($city_key, $active_only = true) {
        $cities = csc_get_cities();
        if (!isset($cities[$city_key]['zones'])) return [];
        $zones = $cities[$city_key]['zones'];
        
        // Сортировка по sort_order
        uasort($zones, function($a, $b) {
            $order_a = isset($a['sort_order']) ? $a['sort_order'] : 0;
            $order_b = isset($b['sort_order']) ? $b['sort_order'] : 0;
            return $order_a - $order_b;
        });
        
        if ($active_only) {
            $zones = array_filter($zones, function($z) { return !empty($z['active']); });
        }
        return $zones;
    }
}

// ============================================================
// 5. РЕНДЕР СТРАНИЦЫ
// ============================================================
if (!function_exists('csc_render_cities_settings_page')) {
    function csc_render_cities_settings_page() {
        $cities = csc_get_cities();
        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'RU');
        if (empty($timezones)) {
            $timezones = ['Europe/Moscow', 'Europe/Samara', 'Asia/Yekaterinburg', 'Asia/Novosibirsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Yakutsk', 'Asia/Vladivostok', 'Asia/Magadan', 'Asia/Kamchatka'];
        }
        ?>
        <div class="wrap">
            <h1>🌍 Города присутствия и зоны обслуживания</h1>
            
            <div style="background:#fff3cd; border:1px solid #ffc107; padding:12px 20px; border-radius:6px; margin:20px 0;">
                <strong>ℹ️ Важно:</strong> Города определяют, в каких регионах работает сервис. 
                Зоны обслуживания внутри города позволяют мастерам указывать, где именно они работают, 
                а системе — корректно распределять заказы по географии.
            </div>
            
            <div style="margin:20px 0; display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="button button-primary" id="csc-add-city-btn">+ Добавить город</button>
                <button type="button" class="button" id="csc-export-cities-btn">📥 Экспорт городов</button>
                <button type="button" class="button" id="csc-import-cities-btn">📤 Импорт городов</button>
                <input type="file" id="csc-import-file-input" accept=".json" style="display:none;">
            </div>
            
            <table class="wp-list-table widefat fixed striped" id="csc-cities-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Поддомен</th>
                        <th>Часовой пояс</th>
                        <th>Координаты</th>
                        <th>Зоны</th>
                        <th>Лимит зоны</th>
                        <th>Статус</th>
                        <th style="width:200px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="csc-cities-tbody">
                    <?php foreach ($cities as $key => $city): 
                        $zones_count = isset($city['zones']) ? count($city['zones']) : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($city['name']); ?></strong></td>
                            <td><code><?php echo esc_html($city['subdomain']); ?></code></td>
                            <td><?php echo esc_html($city['timezone']); ?></td>
                            <td>
                                <?php 
                                $lat = $city['lat'] ?? null;
                                $lng = $city['lng'] ?? null;
                                if ($lat !== null && $lng !== null) {
                                    echo '<small style="font-family:monospace; color:#64748b;" title="Широта: ' . esc_attr($lat) . ', Долгота: ' . esc_attr($lng) . '">' 
                                         . esc_html(number_format($lat, 4, '.', '')) . ', ' 
                                         . esc_html(number_format($lng, 4, '.', '')) . '</small>';
                                } else {
                                    echo '<span style="color:#cbd5e1;">—</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small csc-manage-zones-btn" 
                                    data-city-key="<?php echo esc_attr($key); ?>"
                                    data-city-name="<?php echo esc_attr($city['name']); ?>">
                                    📍 Зоны (<?php echo $zones_count; ?>)
                                </button>
                            </td>
                            <td>
                                <?php 
                                $zl = isset($city['zone_limit']) ? intval($city['zone_limit']) : 0;
                                if ($zl > 0) {
                                    echo '<strong style="color:#6366f1;">' . $zl . ' мастеров</strong>';
                                } else {
                                    echo '<span style="color:#94a3b8;">общий</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($city['active'])): ?>
                                    <span style="color:#10b981; font-weight:600;">🟢 Активен</span>
                                <?php else: ?>
                                    <span style="color:#6b7280;">⚪ Неактивен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small csc-edit-city-btn" 
                                    data-city-key="<?php echo esc_attr($key); ?>"
                                    data-name="<?php echo esc_attr($city['name']); ?>"
                                    data-subdomain="<?php echo esc_attr($city['subdomain']); ?>"
                                    data-timezone="<?php echo esc_attr($city['timezone']); ?>"
                                    data-active="<?php echo !empty($city['active']) ? '1' : '0'; ?>"
                                    data-zone-limit="<?php echo esc_attr(isset($city['zone_limit']) ? intval($city['zone_limit']) : 0); ?>"
                                    data-lat="<?php echo esc_attr($city['lat'] ?? ''); ?>"
                                    data-lng="<?php echo esc_attr($city['lng'] ?? ''); ?>">✏️ Изменить</button>
                                <button type="button" class="button button-small csc-delete-city-btn" data-city-key="<?php echo esc_attr($key); ?>" data-city-name="<?php echo esc_attr($city['name']); ?>" style="color:#dc3232;">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Модальное окно города -->
        <div id="csc-city-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:99998; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:12px; width:90%; max-width:500px; padding:24px; box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                <h2 id="csc-city-modal-title" style="margin:0 0 16px 0;">Добавить город</h2>
                <form id="csc-city-form">
                    <input type="hidden" id="csc-city-form-key" value="">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Ключ (латиницей) *</label>
                        <input type="text" id="csc-city-key" required style="width:100%;" placeholder="moskva, spb, kazan...">
                        <small style="color:#64748b;">Используется в URL и БД</small>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Название *</label>
                        <input type="text" id="csc-city-name" required style="width:100%;" placeholder="Москва">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Поддомен *</label>
                        <input type="text" id="csc-city-subdomain" required style="width:100%;" placeholder="moskva">
                        <small style="color:#64748b;">Будет: <code id="csc-city-subdomain-preview">moskva.site.ru</code></small>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Часовой пояс *</label>
                        <select id="csc-city-timezone" style="width:100%;">
                            <?php foreach ($timezones as $tz): ?>
                                <option value="<?php echo esc_attr($tz); ?>"><?php echo esc_html($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:4px;">🗺️ Широта (lat)</label>
                            <input type="text" id="csc-city-lat" style="width:100%;" placeholder="55.7558" step="any" inputmode="decimal">
                            <small style="color:#64748b;">от -90 до 90</small>
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:4px;">🗺️ Долгота (lng)</label>
                            <input type="text" id="csc-city-lng" style="width:100%;" placeholder="37.6173" step="any" inputmode="decimal">
                            <small style="color:#64748b;">от -180 до 180</small>
                        </div>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label><input type="checkbox" id="csc-city-active" value="1" checked> Активен (город доступен для регистрации)</label>
                    </div>
                    <div style="margin-bottom:16px; padding-top:16px; border-top:1px solid #e2e8f0;">
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Персональный лимит мастеров в зоне</label>
                        <input type="number" id="csc-city-zone-limit" value="0" min="0" max="100" style="width:140px; padding:6px 10px; border:2px solid #e2e8f0; border-radius:6px;">
                        <small style="display:block; margin-top:6px; color:#64748b;">
                            Максимум мастеров в ячейке зоны для этого города.<br>
                            <strong>0</strong> — использовать общий лимит из «Регистрация мастеров». 
                            Значение больше 0 перекрывает общий лимит.
                        </small>
                    </div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button type="button" class="button" id="csc-city-cancel">Отмена</button>
                        <button type="submit" class="button button-primary" id="csc-city-submit">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Модальное окно зон -->
        <div id="csc-zones-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:99998; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; border-radius:12px; width:90%; max-width:800px; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="margin:0;">📍 Зоны: <span id="csc-zones-city-name" style="color:#2563eb;"></span></h2>
                    <button type="button" class="button" id="csc-zones-close">✕</button>
                </div>
                
                <div style="background:#e0f2fe; border:1px solid #0ea5e9; padding:10px 16px; border-radius:6px; margin-bottom:16px;">
                    <strong>💡 Подсказка:</strong> Перетаскивайте зоны за иконку ≡ для изменения порядка отображения.
                </div>
                
                <input type="hidden" id="csc-zones-city-key" value="">
                
                <div style="background:#f8fafc; border-radius:8px; padding:16px; margin-bottom:16px;">
                    <h3 style="margin:0 0 12px 0;">Добавить зону</h3>
                    <div style="display:grid; grid-template-columns:1fr 120px 100px auto; gap:8px; align-items:end; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:12px; margin-bottom:4px;">Название зоны</label>
                            <input type="text" id="csc-new-zone-name" placeholder="Центр, Северный район..." style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; margin-bottom:4px;">Множитель цены</label>
                            <input type="number" id="csc-new-zone-mult" value="1.0" step="0.1" min="0.5" max="3" style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; margin-bottom:4px;">Активна</label>
                            <label style="display:block;"><input type="checkbox" id="csc-new-zone-active" checked> Да</label>
                        </div>
                        <button type="button" class="button button-primary" id="csc-add-zone-btn">+ Добавить</button>
                    </div>
                    
                    <!-- НОВОЕ: Поля координат -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; padding-top:12px; border-top:1px solid #e2e8f0;">
                        <div>
                            <label style="display:block; font-size:12px; margin-bottom:4px; color:#64748b;">🗺️ Широта зоны (lat)</label>
                            <input type="text" id="csc-new-zone-lat" placeholder="56.113451" step="any" inputmode="decimal" style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; margin-bottom:4px; color:#64748b;">🗺️ Долгота зоны (lng)</label>
                            <input type="text" id="csc-new-zone-lng" placeholder="40.292621" step="any" inputmode="decimal" style="width:100%;">
                        </div>
                    </div>
                    <small style="display:block; margin-top:8px; color:#94a3b8;">
                        💡 Координаты центра зоны для автоматического определения района по адресу клиента
                    </small>
                </div>
                
                <table class="wp-list-table widefat fixed striped" id="csc-zones-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="csc-zones-select-all" title="Выбрать все"></th>
                            <th style="width:40px;">≡</th>
                            <th>Название зоны</th>
                            <th style="width:80px;">Множитель</th>
                            <th style="width:140px;">Координаты</th>
                            <th style="width:80px;">Статус</th>
                            <th style="width:150px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="csc-zones-tbody"></tbody>
                </table>
                
                <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e2e8f0; display:flex; gap:12px; align-items:center;">
                    <button type="button" class="button" id="csc-bulk-delete-zones" style="color:#dc3232;" disabled>🗑️ Удалить выбранные (0)</button>
                    <span style="color:#64748b; font-size:13px;" id="csc-zones-counter">Выбрано: 0</span>
                </div>
            </div>
        </div>
        
        <!-- Модальное окно импорта -->
        <div id="csc-import-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:99998; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:12px; width:90%; max-width:500px; padding:24px; box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                <h2 style="margin:0 0 16px 0;">📤 Импорт городов</h2>
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-weight:600; margin-bottom:8px;">Режим импорта:</label>
                    <label style="display:block; margin-bottom:8px;">
                        <input type="radio" name="import-mode" value="merge" checked> 
                        <strong>Объединить</strong> — добавить новые города к существующим
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="import-mode" value="replace"> 
                        <strong>Заменить всё</strong> — удалить все существующие города и импортировать новые
                    </label>
                </div>
                <div style="background:#fef3c7; border:1px solid #f59e0b; padding:12px; border-radius:6px; margin-bottom:16px;">
                    <strong>⚠️ Внимание:</strong> Режим "Заменить всё" удалит все текущие города и зоны. Создайте бэкап перед использованием.
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="button" id="csc-import-cancel">Отмена</button>
                    <button type="button" class="button button-primary" id="csc-import-confirm">Импортировать</button>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            const ajaxUrl = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
            const nonce = '<?php echo wp_create_nonce("csc_cities_nonce"); ?>';
            const siteHost = '<?php echo esc_js(parse_url(home_url(), PHP_URL_HOST)); ?>';
            
            const cityModal = document.getElementById('csc-city-modal');
            const zonesModal = document.getElementById('csc-zones-modal');
            const importModal = document.getElementById('csc-import-modal');
            
            // === УТИЛИТЫ ===
            function setButtonLoading(btn, loading, originalText = null) {
                if (!btn) return;
                if (loading) {
                    btn.dataset.originalText = btn.textContent;
                    btn.textContent = '⏳ Сохранение...';
                    btn.disabled = true;
                    btn.style.opacity = '0.7';
                } else {
                    btn.textContent = originalText || btn.dataset.originalText || 'Сохранить';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
            }
            
            function updateBulkDeleteCounter() {
                const checked = document.querySelectorAll('.csc-zone-checkbox:checked');
                const bulkBtn = document.getElementById('csc-bulk-delete-zones');
                const counter = document.getElementById('csc-zones-counter');
                const count = checked.length;
                if (bulkBtn) {
                    bulkBtn.textContent = '🗑️ Удалить выбранные (' + count + ')';
                    bulkBtn.disabled = count === 0;
                }
                if (counter) {
                    counter.textContent = 'Выбрано: ' + count;
                }
            }
            
            // === ГОРОДА ===
            document.getElementById('csc-add-city-btn').addEventListener('click', () => openCityModal());
            document.getElementById('csc-city-cancel').addEventListener('click', () => cityModal.style.display = 'none');
            cityModal.addEventListener('click', e => { if (e.target === cityModal) cityModal.style.display = 'none'; });
            
            document.getElementById('csc-city-subdomain').addEventListener('input', function() {
                document.getElementById('csc-city-subdomain-preview').textContent = this.value + '.' + siteHost;
            });
            
            function openCityModal(data = null) {
                document.getElementById('csc-city-modal-title').textContent = data ? 'Изменить город' : 'Добавить город';
                document.getElementById('csc-city-form-key').value = data ? data.key : '';
                document.getElementById('csc-city-key').value = data ? data.key : '';
                document.getElementById('csc-city-key').disabled = !!data;
                document.getElementById('csc-city-name').value = data ? data.name : '';
                document.getElementById('csc-city-subdomain').value = data ? data.subdomain : '';
                document.getElementById('csc-city-timezone').value = data ? data.timezone : 'Europe/Moscow';
                document.getElementById('csc-city-active').checked = data ? data.active : true;
                document.getElementById('csc-city-zone-limit').value = data ? (data.zoneLimit || 0) : 0;
                document.getElementById('csc-city-lat').value = data ? (data.lat ?? '') : '';
                document.getElementById('csc-city-lng').value = data ? (data.lng ?? '') : '';
                document.getElementById('csc-city-subdomain-preview').textContent = (data ? data.subdomain : 'moskva') + '.' + siteHost;
                cityModal.style.display = 'flex';
            }
            
            document.getElementById('csc-city-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = document.getElementById('csc-city-submit');
                setButtonLoading(submitBtn, true);
                
                const fd = new FormData();
                fd.append('action', 'csc_save_city');
                fd.append('_wpnonce', nonce);
                fd.append('city_key', document.getElementById('csc-city-key').value);
                fd.append('name', document.getElementById('csc-city-name').value);
                fd.append('subdomain', document.getElementById('csc-city-subdomain').value);
                fd.append('timezone', document.getElementById('csc-city-timezone').value);
                fd.append('active', document.getElementById('csc-city-active').checked ? '1' : '0');
                fd.append('zone_limit', document.getElementById('csc-city-zone-limit').value || '0');
                fd.append('lat', document.getElementById('csc-city-lat').value);
                fd.append('lng', document.getElementById('csc-city-lng').value);
                
                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else {
                        alert('Ошибка: ' + (res.data?.message || ''));
                        setButtonLoading(submitBtn, false);
                    }
                })
                .catch(err => {
                    alert('Ошибка сети: ' + err.message);
                    setButtonLoading(submitBtn, false);
                });
            });
            
            document.body.addEventListener('click', function(e) {
                const editBtn = e.target.closest('.csc-edit-city-btn');
                if (editBtn) {
                    openCityModal({
                        key: editBtn.dataset.cityKey,
                        name: editBtn.dataset.name,
                        subdomain: editBtn.dataset.subdomain,
                        timezone: editBtn.dataset.timezone,
                        active: editBtn.dataset.active === '1',
                        zoneLimit: parseInt(editBtn.dataset.zoneLimit || '0', 10),
                        lat: editBtn.dataset.lat ? parseFloat(editBtn.dataset.lat) : null,
                        lng: editBtn.dataset.lng ? parseFloat(editBtn.dataset.lng) : null,
                    });
                    return;
                }
                
                const delBtn = e.target.closest('.csc-delete-city-btn');
                if (delBtn) {
                    if (!confirm('Удалить город "' + delBtn.dataset.cityName + '"? Все зоны и мастера этого города останутся, но потеряют привязку.')) return;
                    setButtonLoading(delBtn, true, '🗑️');
                    const fd = new FormData();
                    fd.append('action', 'csc_delete_city');
                    fd.append('_wpnonce', nonce);
                    fd.append('city_key', delBtn.dataset.cityKey);
                    fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                        if (res.success) location.reload(); 
                        else {
                            alert(res.data?.message || 'Ошибка');
                            setButtonLoading(delBtn, false, '🗑️');
                        }
                    });
                    return;
                }
                
                const zonesBtn = e.target.closest('.csc-manage-zones-btn');
                if (zonesBtn) {
                    openZonesModal(zonesBtn.dataset.cityKey, zonesBtn.dataset.cityName);
                    return;
                }
            });
            
            // === ЭКСПОРТ/ИМПОРТ ===
            document.getElementById('csc-export-cities-btn').addEventListener('click', function() {
                const exportBtn = this;
                setButtonLoading(exportBtn, true, '📥 Экспорт городов');
                
                fetch(ajaxUrl + '?action=csc_export_cities&_wpnonce=' + encodeURIComponent(nonce))
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const dataStr = JSON.stringify(res.data.data, null, 2);
                        const dataBlob = new Blob([dataStr], { type: 'application/json' });
                        const url = URL.createObjectURL(dataBlob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = res.data.filename;
                        link.click();
                        URL.revokeObjectURL(url);
                        setButtonLoading(exportBtn, false, '📥 Экспорт городов');
                    } else {
                        alert('Ошибка экспорта: ' + (res.data?.message || ''));
                        setButtonLoading(exportBtn, false, '📥 Экспорт городов');
                    }
                })
                .catch(err => {
                    alert('Ошибка сети: ' + err.message);
                    setButtonLoading(exportBtn, false, '📥 Экспорт городов');
                });
            });
            
            document.getElementById('csc-import-cities-btn').addEventListener('click', function() {
                document.getElementById('csc-import-file-input').click();
            });
            
            document.getElementById('csc-import-file-input').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                const reader = new FileReader();
                reader.onload = function(event) {
                    window.importJsonData = event.target.result;
                    importModal.style.display = 'flex';
                };
                reader.readAsText(file);
                this.value = ''; // Сброс для повторного выбора того же файла
            });
            
            document.getElementById('csc-import-cancel').addEventListener('click', () => {
                importModal.style.display = 'none';
                window.importJsonData = null;
            });
            
            importModal.addEventListener('click', e => {
                if (e.target === importModal) {
                    importModal.style.display = 'none';
                    window.importJsonData = null;
                }
            });
            
            document.getElementById('csc-import-confirm').addEventListener('click', function() {
                if (!window.importJsonData) {
                    alert('Нет данных для импорта');
                    return;
                }
                
                const importBtn = this;
                setButtonLoading(importBtn, true, 'Импортировать');
                
                const importMode = document.querySelector('input[name="import-mode"]:checked').value;
                
                const fd = new FormData();
                fd.append('action', 'csc_import_cities');
                fd.append('_wpnonce', nonce);
                fd.append('json_data', window.importJsonData);
                fd.append('import_mode', importMode);
                
                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert('Успешно! ' + res.data.message);
                        importModal.style.display = 'none';
                        window.importJsonData = null;
                        setButtonLoading(importBtn, false, 'Импортировать');
                        location.reload();
                    } else {
                        alert('Ошибка импорта: ' + (res.data?.message || ''));
                        setButtonLoading(importBtn, false, 'Импортировать');
                    }
                })
                .catch(err => {
                    alert('Ошибка сети: ' + err.message);
                    setButtonLoading(importBtn, false, 'Импортировать');
                });
            });
            
            // === ЗОНЫ ===
            document.getElementById('csc-zones-close').addEventListener('click', () => zonesModal.style.display = 'none');
            zonesModal.addEventListener('click', e => { if (e.target === zonesModal) zonesModal.style.display = 'none'; });
            
            let currentZones = {};
            let draggedZoneId = null;
            
            function openZonesModal(cityKey, cityName) {
                document.getElementById('csc-zones-city-key').value = cityKey;
                document.getElementById('csc-zones-city-name').textContent = cityName;
                zonesModal.style.display = 'flex';
                
                fetch(ajaxUrl + '?action=csc_get_cities_ajax&city_key=' + encodeURIComponent(cityKey) + '&_wpnonce=' + encodeURIComponent(nonce))
                .then(r => r.json())
                .then(res => {
                    currentZones = (res.success && res.data && res.data.zones) ? res.data.zones : {};
                    renderZonesTable();
                    updateBulkDeleteCounter();
                });
            }
            
            function renderZonesTable() {
                const tbody = document.getElementById('csc-zones-tbody');
                
                // Сортировка зон по sort_order
                const sortedKeys = Object.keys(currentZones).sort((a, b) => {
                    const orderA = currentZones[a].sort_order || 0;
                    const orderB = currentZones[b].sort_order || 0;
                    return orderA - orderB;
                });
                
                if (sortedKeys.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">Зоны не добавлены</td></tr>';
                    return;
                }
                
                tbody.innerHTML = sortedKeys.map(k => {
                    const z = currentZones[k];
                    const coordsDisplay = (z.lat && z.lng) 
                        ? `<small style="font-family:monospace; color:#64748b; font-size:11px;">${parseFloat(z.lat).toFixed(4)}, ${parseFloat(z.lng).toFixed(4)}</small>`
                        : '<span style="color:#cbd5e1; font-size:11px;">—</span>';
                    
                    return `<tr data-zone-id="${k}" draggable="true" class="csc-zone-row" style="cursor:move;">
                        <td><input type="checkbox" class="csc-zone-checkbox" value="${k}"></td>
                        <td class="csc-drag-handle" style="cursor:grab; user-select:none; color:#64748b; font-size:20px;" title="Перетащите для сортировки">≡</td>
                        <td><strong>${escapeHtml(z.name)}</strong></td>
                        <td>×${z.price_multiplier || 1.0}</td>
                        <td>${coordsDisplay}</td>
                        <td>${z.active ? '<span style="color:#10b981;">🟢</span>' : '<span style="color:#6b7280;">⚪</span>'}</td>
                        <td>
                            <button type="button" class="button button-small csc-delete-zone-btn" data-zone-id="${k}" style="color:#dc3232;">🗑️</button>
                        </td>
                    </tr>`;
                }).join('');
                
                initDragAndDrop();
            }
            
            function initDragAndDrop() {
                const rows = document.querySelectorAll('.csc-zone-row');
                
                rows.forEach(row => {
                    row.addEventListener('dragstart', function(e) {
                        draggedZoneId = this.dataset.zoneId;
                        this.style.opacity = '0.5';
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    
                    row.addEventListener('dragend', function(e) {
                        this.style.opacity = '1';
                        rows.forEach(r => r.style.background = '');
                        draggedZoneId = null;
                    });
                    
                    row.addEventListener('dragover', function(e) {
                        if (e.preventDefault) e.preventDefault();
                        e.dataTransfer.dropType = 'move';
                        
                        // Визуальная индикация
                        rows.forEach(r => r.style.background = '');
                        if (this.dataset.zoneId !== draggedZoneId) {
                            this.style.background = '#dbeafe';
                        }
                        return false;
                    });
                    
                    row.addEventListener('dragenter', function(e) {
                        if (this.dataset.zoneId !== draggedZoneId) {
                            this.style.background = '#dbeafe';
                        }
                    });
                    
                    row.addEventListener('dragleave', function(e) {
                        this.style.background = '';
                    });
                    
                    row.addEventListener('drop', function(e) {
                        if (e.stopPropagation) e.stopPropagation();
                        
                        const targetZoneId = this.dataset.zoneId;
                        if (draggedZoneId !== targetZoneId) {
                            // Пересчёт порядка
                            const currentKeys = Object.keys(currentZones).sort((a, b) => {
                                const orderA = currentZones[a].sort_order || 0;
                                const orderB = currentZones[b].sort_order || 0;
                                return orderA - orderB;
                            });
                            
                            const draggedIndex = currentKeys.indexOf(draggedZoneId);
                            const targetIndex = currentKeys.indexOf(targetZoneId);
                            
                            // Удалить перетаскиваемый элемент
                            currentKeys.splice(draggedIndex, 1);
                            // Вставить на новую позицию
                            currentKeys.splice(targetIndex, 0, draggedZoneId);
                            
                            // Обновить sort_order
                            currentKeys.forEach((key, index) => {
                                currentZones[key].sort_order = index;
                            });
                            
                            renderZonesTable();
                            saveZonesOrder();
                        }
                        
                        return false;
                    });
                });
            }
            
            function saveZonesOrder() {
                const cityKey = document.getElementById('csc-zones-city-key').value;
                const zoneOrder = Object.keys(currentZones).sort((a, b) => {
                    const orderA = currentZones[a].sort_order || 0;
                    const orderB = currentZones[b].sort_order || 0;
                    return orderA - orderB;
                });
                
                const fd = new FormData();
                fd.append('action', 'csc_sort_zones');
                fd.append('_wpnonce', nonce);
                fd.append('city_key', cityKey);
                zoneOrder.forEach(zoneId => fd.append('zone_order[]', zoneId));
                
                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        alert('Ошибка сохранения порядка: ' + (res.data?.message || ''));
                    }
                })
                .catch(err => {
                    alert('Ошибка сети: ' + err.message);
                });
            }
            
            document.getElementById('csc-add-zone-btn').addEventListener('click', function() {
                const name = document.getElementById('csc-new-zone-name').value.trim();
                if (!name) { alert('Укажите название зоны'); return; }
                
                const addBtn = this;
                setButtonLoading(addBtn, true, '+ Добавить');
                
                const fd = new FormData();
                fd.append('action', 'csc_save_zone');
                fd.append('_wpnonce', nonce);
                fd.append('city_key', document.getElementById('csc-zones-city-key').value);
                fd.append('zone_id', '');
                fd.append('zone_name', name);
                fd.append('zone_price_multiplier', document.getElementById('csc-new-zone-mult').value);
                fd.append('zone_active', document.getElementById('csc-new-zone-active').checked ? '1' : '0');
                // НОВОЕ: Добавляем координаты
                fd.append('lat', document.getElementById('csc-new-zone-lat').value);
                fd.append('lng', document.getElementById('csc-new-zone-lng').value);
                
                fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                    if (res.success) {
                        currentZones = res.data.zones || {};
                        renderZonesTable();
                        document.getElementById('csc-new-zone-name').value = '';
                        document.getElementById('csc-new-zone-lat').value = '';
                        document.getElementById('csc-new-zone-lng').value = '';
                        updateBulkDeleteCounter();
                        setButtonLoading(addBtn, false, '+ Добавить');
                        
                        // Обновляем счётчик зон на основной странице
                        const cityKey = document.getElementById('csc-zones-city-key').value;
                        const manageBtn = document.querySelector(`.csc-manage-zones-btn[data-city-key="${cityKey}"]`);
                        if (manageBtn) {
                            const newCount = Object.keys(currentZones).length;
                            manageBtn.innerHTML = '📍 Зоны (' + newCount + ')';
                        }
                    } else {
                        alert(res.data?.message || 'Ошибка');
                        setButtonLoading(addBtn, false, '+ Добавить');
                    }
                }).catch(err => {
                    alert('Ошибка сети: ' + err.message);
                    setButtonLoading(addBtn, false, '+ Добавить');
                });
            });
            
            document.body.addEventListener('click', function(e) {
                const delZoneBtn = e.target.closest('.csc-delete-zone-btn');
                if (delZoneBtn) {
                    if (!confirm('Удалить эту зону?')) return;
                    setButtonLoading(delZoneBtn, true, '🗑️ Удалить');
                    const fd = new FormData();
                    fd.append('action', 'csc_delete_zone');
                    fd.append('_wpnonce', nonce);
                    fd.append('city_key', document.getElementById('csc-zones-city-key').value);
                    fd.append('zone_id', delZoneBtn.dataset.zoneId);
                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                currentZones = res.data.zones || {};
                                renderZonesTable();
                                updateBulkDeleteCounter();
                                // ИСПРАВЛЕНО: убираем location.reload() — таблица обновится без перезагрузки
                                // Обновляем счётчик зон в кнопке "📍 Зоны (N)" на основной странице
                                const cityKey = document.getElementById('csc-zones-city-key').value;
                                const manageBtn = document.querySelector(`.csc-manage-zones-btn[data-city-key="${cityKey}"]`);
                                if (manageBtn) {
                                    const newCount = Object.keys(currentZones).length;
                                    manageBtn.innerHTML = '📍 Зоны (' + newCount + ')';
                                }
                                setButtonLoading(delZoneBtn, false, '🗑️ Удалить');
                            } else {
                                alert(res.data?.message || 'Ошибка');
                                setButtonLoading(delZoneBtn, false, '🗑️ Удалить');
                            }
                        })
                        .catch(err => {
                            alert('Ошибка сети: ' + err.message);
                            setButtonLoading(delZoneBtn, false, '🗑️ Удалить');
                        });
                }
            });
            
            // === ВЫБОР ВСЕХ ЧЕКБОКСОВ ===
            document.getElementById('csc-zones-select-all').addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('.csc-zone-checkbox');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                updateBulkDeleteCounter();
            });
            
            document.body.addEventListener('change', function(e) {
                if (e.target.classList.contains('csc-zone-checkbox')) {
                    updateBulkDeleteCounter();
                }
            });
            
            // === МАССОВОЕ УДАЛЕНИЕ ===
            document.getElementById('csc-bulk-delete-zones').addEventListener('click', function() {
                const checked = document.querySelectorAll('.csc-zone-checkbox:checked');
                if (checked.length === 0) {
                    alert('Не выбрано ни одной зоны');
                    return;
                }
                if (!confirm('Удалить выбранные зоны (' + checked.length + ')?')) return;
                
                const bulkBtn = this;
                setButtonLoading(bulkBtn, true, '🗑️ Удалить выбранные');
                
                const fd = new FormData();
                fd.append('action', 'csc_bulk_delete_zones');
                fd.append('_wpnonce', nonce);
                fd.append('city_key', document.getElementById('csc-zones-city-key').value);
                checked.forEach(cb => fd.append('zone_ids[]', cb.value));
                
                fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                    if (res.success) {
                        currentZones = res.data.zones;
                        renderZonesTable();
                        updateBulkDeleteCounter();
                        document.getElementById('csc-zones-select-all').checked = false;
                        setButtonLoading(bulkBtn, false, '🗑️ Удалить выбранные (0)');
                        location.reload();
                    } else {
                        alert(res.data?.message || 'Ошибка');
                        setButtonLoading(bulkBtn, false, '🗑️ Удалить выбранные');
                    }
                });
            });
            
            function escapeHtml(str) {
                if (str === null || str === undefined) return '';
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
        })();
        </script>
        <?php
    }
}

// ============================================================
// 6. AJAX: Получение зон города (для модалки)
// ============================================================
add_action('wp_ajax_csc_get_cities_ajax', 'csc_get_cities_ajax');
if (!function_exists('csc_get_cities_ajax')) {
    function csc_get_cities_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        csc_verify_ajax_nonce('csc_cities_nonce');
        $city_key = sanitize_title($_GET['city_key'] ?? '');
        $cities = csc_get_cities();
        if (!isset($cities[$city_key])) wp_send_json_error(['message' => 'Город не найден']);
        wp_send_json_success(['zones' => $cities[$city_key]['zones'] ?? []]);
    }
}

// ============================================================
// МИГРАЦИЯ: приведение ключей зон к нижнему регистру
// Исправляет ошибку "Зона не найдена" для зон, созданных до фикса
// ============================================================
if (!function_exists('csc_migrate_zone_keys_lowercase')) {
    function csc_migrate_zone_keys_lowercase() {
        if (get_option('csc_zone_keys_lowercase_migrated')) return;
        
        $cities = get_option('csc_cities', []);
        if (empty($cities) || !is_array($cities)) {
            update_option('csc_zone_keys_lowercase_migrated', true);
            return;
        }
        
        $changed = false;
        foreach ($cities as $city_key => &$city) {
            if (!isset($city['zones']) || !is_array($city['zones'])) continue;
            
            $new_zones = [];
            foreach ($city['zones'] as $zone_id => $zone) {
                $new_zone_id = strtolower($zone_id);
                if ($new_zone_id !== $zone_id) {
                    $changed = true;
                }
                $zone['id'] = $new_zone_id;
                $new_zones[$new_zone_id] = $zone;
            }
            $city['zones'] = $new_zones;
        }
        unset($city);
        
        if ($changed) {
            update_option('csc_cities', $cities, false);
        }
        update_option('csc_zone_keys_lowercase_migrated', true);
    }
}
add_action('admin_init', 'csc_migrate_zone_keys_lowercase', 13);