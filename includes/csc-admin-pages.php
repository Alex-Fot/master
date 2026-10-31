<?php
/**
* Файл управления административными страницами плагина Custom Service Calculator
* Полная версия со всеми правками, модулем сотрудников, календарем, аналитикой, минимальной суммой заказа.
* Глобальная комиссия удалена - используется только индивидуальная в csc-masters.php.
* Версия: 3.1
*
* ИНТЕГРИРОВАННЫЕ УЛУЧШЕНИЯ:
* - №1: Шифрование токена бота (csc_prepare_token_for_storage / csc_get_telegram_token)
* - №2: Настройки напоминаний (за 10 часов до заказа)
* - №3: История переходов статусов в модальном окне логов
* - №4: Пагинация в админке заявок (25 записей на страницу)
* - №9: Настройки автобэкапа + ручной бэкап
* - НОВОЕ: Массовое изменение цен на услуги (процентная корректировка)
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. ПОДКЛЮЧЕНИЕ ЗАВИСИМЫХ МОДУЛЕЙ
// ============================================================
$plugin_path = plugin_dir_path(__FILE__);

// Модуль импорта/экспорта
if (file_exists($plugin_path . 'csc-import-export.php')) {
    require_once $plugin_path . 'csc-import-export.php';
}

// Модуль аналитики
if (file_exists($plugin_path . 'csc-analytics.php')) {
    require_once $plugin_path . 'csc-analytics.php';
}

// Модуль промокодов и скидок
if (file_exists($plugin_path . 'csc-promo.php')) {
    require_once $plugin_path . 'csc-promo.php';
}

// Модуль управления мастерами (сотрудниками)
if (file_exists($plugin_path . 'csc-masters.php')) {
    require_once $plugin_path . 'csc-masters.php';
}

// ============================================================
// AJAX ОБРАБОТЧИК ДЛЯ ПОЛУЧЕНИЯ ЛОГОВ ЗАКАЗА
// ИСПРАВЛЕНО: добавлена история переходов статусов (УЛУЧШЕНИЕ №3)
// ============================================================
add_action('wp_ajax_csc_get_order_routing_logs', 'csc_get_order_routing_logs_handler');
if (!function_exists('csc_get_order_routing_logs_handler')) {
    function csc_get_order_routing_logs_handler() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
        if (!check_ajax_referer('csc_order_logs_nonce', '_ajax_nonce', false)) wp_send_json_error(['message' => 'Ошибка безопасности']);
        
        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        if (!$order_number) wp_send_json_error(['message' => 'Не указан номер заказа']);
        
        // ИСПРАВЛЕНО: array_values() для сброса ключей — иначе json_encode вернет объект, а не массив,
        // и forEach в JS упадет с TypeError
        $routing_history = function_exists('csc_get_routing_history') ? array_values(csc_get_routing_history($order_number)) : [];
        $tg_logs = function_exists('csc_tg_get_order_log') ? csc_tg_get_order_log($order_number) : [];
        
        // УЛУЧШЕНИЕ №3: История переходов статусов
        $order_history = function_exists('csc_format_order_history') ? csc_format_order_history($order_number) : [];
        $order_history_html = function_exists('csc_render_order_history_html') ? csc_render_order_history_html($order_number) : '';
        
        // === ФОТО ИЗ ОТЗЫВА ===
        $review_photos_html = '';
        $order_data = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if ($order_data && !empty($order_data['review']['photos'])) {
            $photos = $order_data['review']['photos'];
            $review_photos_html .= '<div class="log-section-title">📷 Фото из отзыва клиента</div>';
            $review_photos_html .= '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;">';
            foreach ($photos as $idx => $photo) {
                $purl = is_array($photo) ? ($photo['url'] ?? '') : $photo;
                if (empty($purl)) continue;
                $review_photos_html .= '<a href="' . esc_url($purl) . '" target="_blank" '
                       . 'style="display:block;width:90px;height:90px;border-radius:8px;overflow:hidden;border:2px solid #e2e8f0;transition:border-color 0.2s;" '
                       . 'onmouseover="this.style.borderColor=\'#3b82f6\'" '
                       . 'onmouseout="this.style.borderColor=\'#e2e8f0\'">'
                       . '<img src="' . esc_url($purl) . '" alt="Фото ' . ($idx + 1) . '" '
                       . 'style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy">'
                       . '</a>';
            }
            $review_photos_html .= '</div>';
        }
        // === КОНЕЦ ФОТО ===
        
        wp_send_json_success([
            'routing' => $routing_history,
            'tg_logs' => $tg_logs,
            'history' => $order_history,
            'history_html' => $order_history_html,
            'review_photos_html' => $review_photos_html
        ]);
    }
}

// ============================================================
// AJAX ОБРАБОТЧИК РУЧНОГО БЭКАПА (УЛУЧШЕНИЕ №9)
// ============================================================
add_action('wp_ajax_csc_admin_manual_backup', 'csc_admin_manual_backup_ajax');
if (!function_exists('csc_admin_manual_backup_ajax')) {
    function csc_admin_manual_backup_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }
        if (!function_exists('csc_admin_manual_backup')) {
            wp_send_json_error(['message' => 'Модуль бэкапа не подключён']);
        }
        $result = csc_admin_manual_backup();
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}

// ============================================================
// AJAX ОБРАБОТЧИК РУЧНОГО НАПОМИНАНИЯ (УЛУЧШЕНИЕ №2)
// ============================================================
add_action('wp_ajax_csc_admin_send_reminder', 'csc_admin_send_reminder_ajax');
if (!function_exists('csc_admin_send_reminder_ajax')) {
    function csc_admin_send_reminder_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }
        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        if (!$order_number) {
            wp_send_json_error(['message' => 'Не указан номер заказа']);
        }
        if (!function_exists('csc_admin_send_reminder')) {
            wp_send_json_error(['message' => 'Модуль напоминаний не подключён']);
        }
        $result = csc_admin_send_reminder($order_number);
        if ($result) {
            wp_send_json_success(['message' => 'Напоминание отправлено для заказа ' . $order_number]);
        } else {
            wp_send_json_error(['message' => 'Не удалось отправить напоминание']);
        }
    }
}

// ============================================================
// AJAX ОБРАБОТЧИК МАССОВОГО ИЗМЕНЕНИЯ ЦЕН (ИНФЛЯЦИЯ/ДЕФЛЯЦИЯ)
// ============================================================
add_action('wp_ajax_csc_bulk_update_prices', 'csc_bulk_update_prices_ajax');

if (!function_exists('csc_bulk_update_prices_ajax')) {
    function csc_bulk_update_prices_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_save_items_action', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $percent = floatval($_POST['percent'] ?? 0);
        $scope = sanitize_text_field($_POST['scope'] ?? 'all'); // all, section, selected
        $section_id = sanitize_text_field($_POST['section_id'] ?? '');
        $selected_ids = isset($_POST['selected_ids']) ? array_map('sanitize_text_field', (array)$_POST['selected_ids']) : [];

        if ($percent == 0) {
            wp_send_json_error(['message' => 'Процент изменения не может быть равен нулю']);
        }

        if ($percent < -99 || $percent > 1000) {
            wp_send_json_error(['message' => 'Процент должен быть от -99% до +1000%']);
        }

        $items = function_exists('csc_get_items') ? csc_get_items() : get_option('csc_service_items', []);

        if (empty($items)) {
            wp_send_json_error(['message' => 'Нет услуг для обновления']);
        }

        $updated_count = 0;
        $updated_items = [];

        foreach ($items as $id => $item) {
            // Фильтрация по области применения
            if ($scope === 'section' && !empty($section_id)) {
                if (($item['section_id'] ?? '') !== $section_id) continue;
            } elseif ($scope === 'selected' && !empty($selected_ids)) {
                if (!in_array($id, $selected_ids)) continue;
            }

            // Пересчёт цены
            $old_price = floatval($item['price'] ?? 0);
            if ($old_price <= 0) continue;

            $new_price = $old_price * (1 + $percent / 100);
            $new_price = round($new_price, 2);

            // Защита от отрицательных цен
            if ($new_price < 0) $new_price = 0;

            $items[$id]['price'] = $new_price;
            $updated_count++;
            $updated_items[] = [
                'id' => $id,
                'name' => $item['name'] ?? '',
                'old_price' => $old_price,
                'new_price' => $new_price
            ];
        }

        if ($updated_count === 0) {
            wp_send_json_error(['message' => 'Не найдено услуг для обновления']);
        }

        // Принудительная запись в обход кэша
        update_option('csc_service_items', $items, false);

        // Жёсткий сброс ВСЕХ кэшей услуг
        wp_cache_delete('csc_service_items', 'options');
        wp_cache_delete('csc_items_all');
        wp_cache_delete('csc_items_cache');
        delete_transient('csc_items_cache');
        delete_transient('csc_get_items_cache');
        delete_transient('csc_service_items_cache');
        if (function_exists('csc_cache_delete_items')) {
            csc_cache_delete_items();
        }

        echo '<div class="notice notice-success is-dismissible"><p>Услуги сохранены. Записей: ' . count($items) . '. Кэш сброшен.</p></div>';

        // Логирование
        if (function_exists('csc_log_notification')) {
            $direction = $percent > 0 ? 'повышены' : 'снижены';
            csc_log_notification(
                'system',
                'admin',
                get_current_user_id() ? (string)get_current_user_id() : 'admin',
                'web',
                '💰 Массовое изменение цен',
                "Цены {$updated_count} услуг {$direction} на " . abs($percent) . "%",
                ['percent' => $percent, 'count' => $updated_count, 'scope' => $scope]
            );
        }

        wp_send_json_success([
            'message' => "Обновлено цен: {$updated_count}",
            'count' => $updated_count,
            'percent' => $percent,
            'items' => $updated_items
        ]);
    }
}

// ============================================================
// 2. РЕГИСТРАЦИЯ МЕНЮ В АДМИН-ПАНЕЛИ
// ============================================================
add_action('admin_menu', 'csc_add_admin_menu');
function csc_add_admin_menu() {
    // Главная страница настроек
    add_options_page(
        'Калькулятор услуг',
        'Калькулятор услуг',
        'manage_options',
        'custom-service-calculator',
        'csc_main_settings_page'
    );

    // Подменю: Разделы
    add_submenu_page(
        'options-general.php',
        'Разделы (CSC)',
        'Разделы (CSC)',
        'manage_options',
        'csc_sections_admin',
        'csc_sections_admin_page'
    );

    // Подменю: Услуги
    add_submenu_page(
        'options-general.php',
        'Услуги (CSC)',
        'Услуги (CSC)',
        'manage_options',
        'csc_items_admin',
        'csc_items_admin_page'
    );

    // Подменю: Аналитика (CSC)
    if (function_exists('csc_render_analytics_page')) {
        add_submenu_page(
            'options-general.php',
            'Аналитика (CSC)',
            '📊 Аналитика',
            'manage_options',
            'csc_analytics_page',
            'csc_render_analytics_page'
        );
    }

    // Подменю: Сотрудники (Мастера)
    if (function_exists('csc_masters_admin_page')) {
        add_submenu_page(
            'options-general.php',
            'Сотрудники (CSC)',
            'Сотрудники (CSC)',
            'manage_options',
            'csc_masters_admin',
            'csc_masters_admin_page'
        );
    }
	
	// Подменю: Отзывы
    if (function_exists('csc_render_reviews_admin_page')) {
        add_submenu_page(
            'options-general.php',
            'Отзывы (CSC)',
            '⭐ Отзывы (CSC)',
            'manage_options',
            'csc_reviews_admin',
            'csc_render_reviews_admin_page'
        );
    }

    // Подменю: Заявки (Календарь и список)
    add_submenu_page(
        'options-general.php',
        'Заявки (CSC)',
        'Заявки (CSC)',
        'manage_options',
        'csc_orders_admin',
        'csc_orders_admin_page'
    );

    // Подменю: Скидки
    if (function_exists('csc_promo_admin_page')) {
        add_submenu_page(
            'options-general.php',
            'Скидки (CSC)',
            'Скидки (CSC)',
            'manage_options',
            'csc_promo_admin',
            'csc_promo_admin_page'
        );
    }

    // Подменю: Импорт/Экспорт
    if (function_exists('csc_import_export_page')) {
        add_submenu_page(
            'options-general.php',
            'Импорт/Экспорт (CSC)',
            'Импорт (CSC)',
            'manage_options',
            'csc_import_export',
            'csc_import_export_page'
        );
    }
}

// ============================================================
// 3. СТРАНИЦА ГЛАВНЫХ НАСТРОЕК
// ============================================================

/**
 * Разбор токена, присланного из формы админки (модуль csc-crypto.php).
 * - Пустая строка или значение-маска (содержит '*') => оставить ранее сохранённый токен.
 * - Иначе => зашифровать новый токен перед записью в БД.
 *
 * @param string $submitted Сырое значение из $_POST['telegram_token']
 * @return string Значение для записи в опцию (зашифрованное либо открытое при недоступности OpenSSL)
 */
if (!function_exists('csc_resolve_submitted_token')) {
    function csc_resolve_submitted_token($submitted) {
        $old = get_option('csc_main_settings', []);
        $old_token = is_array($old) ? ($old['telegram_token'] ?? '') : '';
        // Поле не тронуто (пусто) или прислана маска — оставляем хранимый токен как есть
        if ($submitted === '' || strpos($submitted, '*') !== false) {
            return $old_token;
        }
        return function_exists('csc_prepare_token_for_storage')
            ? csc_prepare_token_for_storage($submitted)
            : $submitted;
    }
}

function csc_main_settings_page() {
    if ($_POST && wp_verify_nonce($_POST['_wpnonce'], 'csc_main_settings_action')) {
        $settings = [
            // УЛУЧШЕНИЕ №1: Шифрование токена при сохранении
            'telegram_token'    => csc_resolve_submitted_token(sanitize_text_field($_POST['telegram_token'] ?? '')),
            'telegram_chat_id'  => sanitize_text_field($_POST['telegram_chat_id'] ?? ''),
            'work_hours_start'  => intval($_POST['work_hours_start']),
            'work_hours_end'    => intval($_POST['work_hours_end']),
            'interval_minutes'  => intval($_POST['interval_minutes']),
            'travel_time'       => intval($_POST['travel_time']),
            'work_days'         => array_map('sanitize_text_field', $_POST['work_days'] ?? []),
            'holidays'          => array_filter(array_map('trim', explode("\n", $_POST['holidays']))),
            'enable_sbp'        => !empty($_POST['enable_sbp']),
            'sbp_base_url'      => sanitize_text_field($_POST['sbp_base_url']),
            'sbp_redirect_url'  => sanitize_text_field($_POST['sbp_redirect_url']),
            'min_order_amount'  => intval($_POST['min_order_amount'] ?? 0),
            'routing_timeout' => intval($_POST['csc_settings']['routing_timeout'] ?? 180),
            'routing_max_attempts' => intval($_POST['csc_settings']['routing_max_attempts'] ?? 3),
            'routing_log_enabled' => !empty($_POST['csc_settings']['routing_log_enabled']) ? 1 : 0,
            // === УЛУЧШЕНИЕ №2: Настройки напоминаний ===
            'reminder_enabled'        => !empty($_POST['reminder_enabled']) ? 1 : 0,
            'reminder_hours'          => max(1, min(72, intval($_POST['reminder_hours'] ?? 10))),
            'reminder_client_enabled' => !empty($_POST['reminder_client_enabled']) ? 1 : 0,
            'reminder_master_enabled' => !empty($_POST['reminder_master_enabled']) ? 1 : 0,
            // === УЛУЧШЕНИЕ №9: Настройки автобэкапа ===
            'backup_enabled'          => !empty($_POST['backup_enabled']) ? 1 : 0,
            'backup_retention_days'   => max(1, min(30, intval($_POST['backup_retention_days'] ?? 7))),
            // === Сохранение шаблонов уведомлений ===
            'templates'         => [
                'new_order'     => sanitize_textarea_field($_POST['tpl_new_order'] ?? ''),
                'order_assigned' => sanitize_textarea_field($_POST['tpl_order_assigned'] ?? ''),
                'order_completed' => sanitize_textarea_field($_POST['tpl_order_completed'] ?? ''),
                'order_cancelled' => sanitize_textarea_field($_POST['tpl_order_cancelled'] ?? ''),
                'no_masters'    => sanitize_textarea_field($_POST['tpl_no_masters'] ?? ''),
            ]
        ];
        update_option('csc_main_settings', $settings, false);
        echo '<div class="notice notice-success is-dismissible"><p>Настройки успешно сохранены.</p></div>';
    }

    $s = get_option('csc_main_settings', []);
    $templates = isset($s['templates']) ? $s['templates'] : [];

    // УЛУЧШЕНИЕ №1: в HTML админки показываем ТОЛЬКО маскированный токен,
    // полный токен больше не светится в source-code страницы.
    // Пустое поле при сохранении = «не менять текущий токен» (см. csc_resolve_submitted_token).
    $raw_stored   = $s['telegram_token'] ?? '';
    $display_token = '';
    if (!empty($raw_stored)) {
        $plain_for_mask = function_exists('csc_decrypt_token') ? csc_decrypt_token($raw_stored) : $raw_stored;
        $display_token = function_exists('csc_mask_token') ? csc_mask_token($plain_for_mask) : $plain_for_mask;
    }
    ?>
    <div class="wrap">
    <h1>Основные настройки калькулятора</h1>
    <form method="post" action="">
        <?php wp_nonce_field('csc_main_settings_action'); ?>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Настройки уведомлений Telegram</h2>
            <table class="form-table">
                <tr>
                    <th>Бот Токен:</th>
                    <td>
                        <input type="text" name="telegram_token" value="" placeholder="<?php echo esc_attr($display_token ? ('Текущий: ' . $display_token) : '1234567890:AAH...'); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Токен шифруется перед сохранением в БД (AES-256-GCM).<?php if ($display_token): ?> Текущий: <code><?php echo esc_html($display_token); ?></code>. Оставьте поле пустым, чтобы не менять токен.<?php endif; ?></p>
                    </td>
                </tr>
                <tr>
                    <th>ID Чата Админа:</th>
                    <td><input type="text" name="telegram_chat_id" value="<?php echo esc_attr($s['telegram_chat_id'] ?? ''); ?>" class="regular-text"></td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Настройки стоимости заказов</h2>
            <table class="form-table">
                <tr>
                    <th>Минимальная сумма заявки (₽):</th>
                    <td>
                        <input type="number" name="min_order_amount" value="<?php echo intval($s['min_order_amount'] ?? 0); ?>" style="width:120px" min="0">
                        <p class="description">Если итоговая сумма меньше указанной, клиент не сможет отправить заявку. Оставьте 0, чтобы отключить ограничение.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>График работы и Логистика</h2>
            <table class="form-table">
                <tr>
                    <th>Часы работы:</th>
                    <td>
                        с <input type="number" name="work_hours_start" value="<?php echo intval($s['work_hours_start'] ?? 9); ?>" style="width:60px">
                        до <input type="number" name="work_hours_end" value="<?php echo intval($s['work_hours_end'] ?? 18); ?>" style="width:60px">
                    </td>
                </tr>
                <tr>
                    <th>Интервал слотов (мин):</th>
                    <td><input type="number" name="interval_minutes" value="<?php echo intval($s['interval_minutes'] ?? 30); ?>" style="width:80px"></td>
                </tr>
                <tr>
                    <th>Время на дорогу (мин):</th>
                    <td>
                        <input type="number" name="travel_time" value="<?php echo intval($s['travel_time'] ?? 0); ?>" style="width:80px">
                        <p class="description">Это время добавляется к занятости мастера (клиент его не видит в чеке).</p>
                    </td>
                </tr>
                <!-- === НАСТРОЙКИ МАРШРУТИЗАЦИИ === -->
                <tr>
                    <th scope="row">Таймаут ожидания ответа мастера (сек)</th>
                    <td>
                        <input type="number" name="csc_settings[routing_timeout]" value="<?php echo esc_attr($s['routing_timeout'] ?? 300); ?>" min="60" max="3600" />
                        <p class="description">Время, в течение которого мастер может принять/отклонить заявку. Минимум 60 сек, максимум 3600.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Макс. попыток отправки уведомления</th>
                    <td>
                        <input type="number" name="csc_settings[routing_max_attempts]" value="<?php echo esc_attr($s['routing_max_attempts'] ?? 3); ?>" min="1" max="10" />
                        <p class="description">Сколько раз система будет пытаться отправить уведомление в Telegram, если первый раз не удался. Минимум 1, максимум 10.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Включить логирование маршрутизации</th>
                    <td>
                        <label>
                            <input type="checkbox" name="csc_settings[routing_log_enabled]" value="1" <?php checked(1, $s['routing_log_enabled'] ?? 0); ?> />
                            Да
                        </label>
                        <p class="description">Записывать события маршрутизации (назначение, таймауты, отказы) в журнал. Может замедлить работу при большом количестве заявок.</p>
                    </td>
                </tr>
                <tr>
                    <th>Рабочие дни недели:</th>
                    <td>
                        <?php
                        $days = ['1' => 'Пн', '2' => 'Вт', '3' => 'Ср', '4' => 'Чт', '5' => 'Пт', '6' => 'Сб', '7' => 'Вс'];
                        $selected_days = (array)($s['work_days'] ?? ['1','2','3','4','5']);
                        foreach ($days as $num => $label) {
                            $checked = in_array($num, $selected_days) ? 'checked' : '';
                            echo "<label style='margin-right:15px;'><input type='checkbox' name='work_days[]' value='$num' $checked> $label</label>";
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Праздники / Исключения:</th>
                    <td>
                        <textarea name="holidays" rows="4" class="large-text" placeholder="2024-12-31&#10;2025-01-01"><?php echo esc_textarea(implode("\n", (array)($s['holidays'] ?? []))); ?></textarea>
                        <p class="description">Каждая дата с новой строки в формате ГГГГ-ММ-ДД</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ============================================================
        УЛУЧШЕНИЕ №2: НАСТРОЙКИ НАПОМИНАНИЙ
        ============================================================ -->
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #8b5cf6;">
            <h2>🔔 Автоматические напоминания</h2>
            <p class="description">Клиент и мастер получают напоминание за указанное количество часов до запланированного времени выполнения работ.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Включить напоминания</th>
                    <td>
                        <label>
                            <input type="checkbox" name="reminder_enabled" value="1" <?php checked(1, $s['reminder_enabled'] ?? 1); ?> />
                            Да
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">За сколько часов напоминать</th>
                    <td>
                        <input type="number" name="reminder_hours" value="<?php echo esc_attr($s['reminder_hours'] ?? 10); ?>" min="1" max="72" style="width:80px" />
                        <p class="description">От 1 до 72 часов. По умолчанию: 10 часов.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Напоминать клиенту</th>
                    <td>
                        <label>
                            <input type="checkbox" name="reminder_client_enabled" value="1" <?php checked(1, $s['reminder_client_enabled'] ?? 1); ?> />
                            Да (логирование + Web Push)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Напоминать мастеру</th>
                    <td>
                        <label>
                            <input type="checkbox" name="reminder_master_enabled" value="1" <?php checked(1, $s['reminder_master_enabled'] ?? 1); ?> />
                            Да (сообщение в Telegram)
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ============================================================
        УЛУЧШЕНИЕ №9: НАСТРОЙКИ АВТОБЭКАПА
        ============================================================ -->
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #06b6d4;">
            <h2>💾 Автоматический бэкап</h2>
            <p class="description">Ежедневное резервное копирование таблиц заказов, истории и уведомлений в CSV. Файлы хранятся в <code>wp-content/uploads/csc-backups/</code> (защищено от прямого доступа).</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Включить автобэкап</th>
                    <td>
                        <label>
                            <input type="checkbox" name="backup_enabled" value="1" <?php checked(1, $s['backup_enabled'] ?? 1); ?> />
                            Да (ежедневно)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Сколько дней хранить бэкапы</th>
                    <td>
                        <input type="number" name="backup_retention_days" value="<?php echo esc_attr($s['backup_retention_days'] ?? 7); ?>" min="1" max="30" style="width:80px" />
                        <p class="description">От 1 до 30 дней. По умолчанию: 7 дней. Старые файлы удаляются автоматически.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ручной бэкап</th>
                    <td>
                        <button type="button" class="button button-secondary" id="csc-manual-backup-btn">Создать бэкап сейчас</button>
                        <span id="csc-backup-status" style="margin-left: 10px; font-size: 13px; color: #64748b;"></span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Оплата через СБП</h2>
            <table class="form-table">
                <tr>
                    <th>Включить СБП:</th>
                    <td><input type="checkbox" name="enable_sbp" value="1" <?php checked(!empty($s['enable_sbp'])); ?>></td>
                </tr>
                <tr>
                    <th>Базовый URL оплаты:</th>
                    <td><input type="text" name="sbp_base_url" value="<?php echo esc_attr($s['sbp_base_url'] ?? ''); ?>" class="large-text" placeholder="https://pay.bank.ru/..."></td>
                </tr>
                <tr>
                    <th>Redirect URL:</th>
                    <td><input type="text" name="sbp_redirect_url" value="<?php echo esc_attr($s['sbp_redirect_url'] ?? ''); ?>" class="large-text" placeholder="Страница после оплаты"></td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Интеграция бота (Команда "coin" и авто-отчеты)</h2>
            <p>Чтобы мастера могли проверять свой баланс, отправив боту сообщение <b>coin</b>, необходимо привязать вашего бота к сайту. Для этого скопируйте ссылку ниже, вставьте ее в новую вкладку браузера и нажмите Enter (должно появиться сообщение {"ok":true,...}).</p>
            <?php
            $webhook_url = get_rest_url(null, 'csc/v1/telegram');
            $display_token_for_url = !empty($display_token) ? $display_token : 'ВАШ_ТОКЕН_БОТА';
            
            // ИСПРАВЛЕНО: добавлен параметр secret_token.
            // csc_tg_verify_webhook() в csc-telegram-bot.php проверяет заголовок
            // X-Telegram-Bot-Api-Secret-Token. Без передачи этого параметра в
            // setWebhook Telegram не отправляет заголовок, и ВСЕ вебхуки
            // отклоняются с кодом 403.
            $webhook_secret_token = ($display_token_for_url !== 'ВАШ_ТОКЕН_БОТА')
                ? wp_hash('csc_tg_secret_' . $display_token_for_url)
                : 'ВАШ_SECRET_TOKEN';
            
            $example_url = "https://api.telegram.org/bot" . $display_token_for_url . "/setWebhook?url=" . urlencode($webhook_url) . "&secret_token=" . urlencode($webhook_secret_token);
            ?>
            <input type="text" class="large-text" readonly value="<?php echo esc_attr($example_url); ?>" onclick="this.select();" style="background:#f0f0f1;">
            <p class="description">Система также будет автоматически присылать мастерам итог за день в конце смены (согласно часам работы).</p>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #f59e0b;">
            <h2>📝 Шаблоны уведомлений</h2>
            <p class="description">Настройте текст сообщений для участников. Используйте плейсхолдеры: {order_number}, {name}, {date}, {time}, {total}, {status}. Оставьте пустым для использования стандартных текстов.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Новая заявка (Админу)</th>
                    <td>
                        <textarea name="tpl_new_order" rows="3" class="large-text" placeholder="🆕 <b>Новая заявка #{order_number}</b>..."><?php echo esc_textarea($templates['new_order'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Заказ назначен (Мастеру)</th>
                    <td>
                        <textarea name="tpl_order_assigned" rows="3" class="large-text" placeholder="✅ Мастер принял заявку..."><?php echo esc_textarea($templates['order_assigned'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Заказ выполнен (Админу/Мастеру)</th>
                    <td>
                        <textarea name="tpl_order_completed" rows="3" class="large-text" placeholder="🏁 Заказ выполнен..."><?php echo esc_textarea($templates['order_completed'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Заказ отменен (Всем)</th>
                    <td>
                        <textarea name="tpl_order_cancelled" rows="3" class="large-text" placeholder="❌ Заявка отменена..."><?php echo esc_textarea($templates['order_cancelled'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Нет доступных мастеров (Админу)</th>
                    <td>
                        <textarea name="tpl_no_masters" rows="3" class="large-text" placeholder="⚠️ Нет мастеров на заявку..."><?php echo esc_textarea($templates['no_masters'] ?? ''); ?></textarea>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #3b82f6;">
            <h2>🔔 Push-уведомления администратору</h2>
            <p class="description">Включите браузерные уведомления о новых заявках и проблемах с маршрутизацией (повторный поиск, отсутствие мастеров). Клик по уведомлению открывает карточку заявки в разделе «Заявки».</p>
            <p>
                <button type="button" class="button button-primary" id="csc-admin-push-subscribe">🔔 Включить push-уведомления мне</button>
                <span id="csc-admin-push-status" style="margin-left: 10px; font-size: 13px; color: #64748b;"></span>
            </p>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #10b981;">
            <h2>🧹 Управление Web Push подписками</h2>
            <p class="description">Если клиенты сменили устройства или браузеры, старые подписки могут накапливаться. Нажмите кнопку ниже для полной очистки базы Push-подписок.</p>
            <p>
                <button type="button" class="button button-secondary" id="csc-clear-push-subs" style="margin-bottom: 10px;">Очистить все Push-подписки</button>
                <span id="csc-push-clear-status" style="margin-left: 10px; font-size: 13px; color: #64748b;"></span>
            </p>
        </div>

        <?php submit_button('Сохранить все настройки'); ?>
    </form>
    </div>
    <script>
    jQuery(document).ready(function($){
        // 🔔 Подписка администратора на браузерные push-уведомления о заявках.
        var adminPushBtn = $('#csc-admin-push-subscribe');
        if (adminPushBtn.length) {
            $.post(ajaxurl, { action: 'csc_push_status', user_type: 'admin', user_id: 'admin' }, function(res){
                if (res && res.success && res.data.subscribed) {
                    $('#csc-admin-push-status').text('✅ Подписка активна').css('color', '#10b981');
                    adminPushBtn.text('🔔 Обновить подписку');
                }
            });
            adminPushBtn.on('click', async function(){
                var st = $('#csc-admin-push-status');
                if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                    st.text('❌ Требуется HTTPS').css('color', '#ef4444'); return;
                }
                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    st.text('❌ Браузер не поддерживает push').css('color', '#ef4444'); return;
                }
                var vapidKey = '<?php echo esc_js(get_option("csc_vapid_public_key", "")); ?>';
                if (!vapidKey) { st.text('❌ VAPID-ключи не сгенерированы (см. блок статуса Web Push ниже)').css('color', '#ef4444'); return; }
                st.text('Подключение...').css('color', '#64748b');
                try {
                    var perm = await Notification.requestPermission();
                    if (perm !== 'granted') throw new Error('Разрешите уведомления в настройках браузера');
                    var reg = await navigator.serviceWorker.getRegistration('/');
                    if (!reg || !reg.active) {
                        reg = await navigator.serviceWorker.register('/csc-sw.js', { scope: '/' });
                        await navigator.serviceWorker.ready;
                    }
                    function b64u(buf){ var s=btoa(String.fromCharCode.apply(null,new Uint8Array(buf))); return s.replace(/=/g,'').replace(/\+/g,'-').replace(/\//g,'_'); }
                    var sub = await reg.pushManager.getSubscription();
                    if (!sub || sub.expirationTime) {
                        var bin = atob(vapidKey.replace(/-/g,'+').replace(/_/g,'/'));
                        var arr = Uint8Array.from(bin, function(c){ return c.charCodeAt(0); });
                        sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: arr.buffer });
                    }
                    await fetch(ajaxurl, { method: 'POST', body: new URLSearchParams({
                        action: 'csc_push_subscribe', user_type: 'admin', user_id: 'admin',
                        subscription: JSON.stringify(sub.toJSON ? sub.toJSON() : sub)
                    })});
                    st.text('✅ Готово — вы будете получать уведомления о заявках').css('color', '#10b981');
                    adminPushBtn.text('🔔 Обновить подписку');
                } catch (e) {
                    st.text('❌ ' + (e.message || 'Ошибка подписки')).css('color', '#ef4444');
                }
            });
        }

        $('#csc-clear-push-subs').on('click', function(){
            if(!confirm('Вы уверены? Все сохраненные браузерные подписки будут удалены. Клиентам потребуется разрешить уведомления заново.')) return;
            $('#csc-push-clear-status').text('Очистка...').css('color', '#64748b');
            $.post(ajaxurl, {
                action: 'csc_clear_notification_subs',
                nonce: '<?php echo wp_create_nonce("csc_admin_nonce"); ?>'
            }, function(res){
                if(res.success){
                    $('#csc-push-clear-status').text('Успешно очищено: ' + res.data.cleared + ' записей.').css('color', '#10b981');
                } else {
                    $('#csc-push-clear-status').text('Ошибка: ' + res.data).css('color', '#ef4444');
                }
            });
        });

        // УЛУЧШЕНИЕ №9: Ручной бэкап
        $('#csc-manual-backup-btn').on('click', function(){
            if(!confirm('Создать резервную копию всех данных сейчас? Это может занять несколько секунд при большом объёме заказов.')) return;
            $(this).prop('disabled', true).text('Создание...');
            $('#csc-backup-status').text('Выполняется...').css('color', '#64748b');
            $.post(ajaxurl, {
                action: 'csc_admin_manual_backup',
                nonce: '<?php echo wp_create_nonce("csc_admin_nonce"); ?>'
            }, function(res){
                if(res.success){
                    $('#csc-backup-status').text('✅ ' + res.data.message).css('color', '#10b981');
                } else {
                    $('#csc-backup-status').text('❌ ' + (res.data.message || 'Ошибка')).css('color', '#ef4444');
                }
                $('#csc-manual-backup-btn').prop('disabled', false).text('Создать бэкап сейчас');
            }).fail(function(){
                $('#csc-backup-status').text('❌ Ошибка соединения').css('color', '#ef4444');
                $('#csc-manual-backup-btn').prop('disabled', false).text('Создать бэкап сейчас');
            });
        });
    });
    </script>
    <?php
}

// ============================================================
// 4. СТРАНИЦА УПРАВЛЕНИЯ РАЗДЕЛАМИ
// ============================================================
function csc_sections_admin_page() {
    if ($_POST && isset($_POST['save_sections']) && check_admin_referer('csc_save_sections_action')) {
        $sections = [];
        if (!empty($_POST['sections'])) {
            foreach ($_POST['sections'] as $data) {
                if (empty($data['name'])) continue;
                $id = !empty($data['id']) ? sanitize_text_field($data['id']) : uniqid('sec_');
                $sections[$id] = ['name' => sanitize_text_field($data['name'])];
            }
        }
        if (function_exists('csc_save_sections')) {
            csc_save_sections($sections);
            echo '<div class="notice notice-success"><p>Разделы обновлены.</p></div>';
        } else {
            update_option('csc_service_sections', $sections);
            echo '<div class="notice notice-warning"><p>Сохранено (прямой записью).</p></div>';
        }
    }

    $sections = function_exists('csc_get_sections') ? csc_get_sections() : get_option('csc_service_sections', []);
    ?>
    <div class="wrap">
    <h1>Разделы услуг</h1>
    <form method="post">
        <?php wp_nonce_field('csc_save_sections_action'); ?>
        <table class="widefat fixed striped" style="max-width: 700px;" id="csc-sections-table">
            <thead>
            <tr>
                <th style="width: 70%;">Название раздела</th>
                <th>Действие</th>
            </tr>
            </thead>
            <tbody id="csc-sections-body">
            <?php if (!empty($sections)): foreach ($sections as $id => $sec): ?>
                <tr>
                    <td>
                        <input type="hidden" name="sections[<?php echo esc_attr($id); ?>][id]" value="<?php echo esc_attr($id); ?>">
                        <input type="text" name="sections[<?php echo esc_attr($id); ?>][name]" value="<?php echo esc_attr($sec['name']); ?>" style="width: 95%;">
                    </td>
                    <td><button type="button" class="button csc-remove-row">Удалить</button></td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td><input type="text" name="sections[new_0][name]" style="width: 95%;"></td>
                    <td><button type="button" class="button csc-remove-row">Удалить</button></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top: 15px;">
            <button type="button" class="button" id="csc-add-section">+ Добавить раздел</button>
            <?php submit_button('Сохранить изменения', 'primary', 'save_sections', false); ?>
        </div>
    </form>
    </div>
    <script>
    jQuery(document).ready(function($){
        $('#csc-add-section').click(function(){
            var id = 'new_' + Date.now();
            $('#csc-sections-body').append('<tr><td><input type="text" name="sections['+id+'][name]" style="width: 95%;"></td><td><button type="button" class="button csc-remove-row">Удалить</button></td></tr>');
        });
        $(document).on('click', '.csc-remove-row', function(){
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}

// ============================================================
// 5. СТРАНИЦА УПРАВЛЕНИЯ УСЛУГАМИ
// УЛУЧШЕНИЕ: Фильтр по разделам + пагинация (30 записей на страницу)
// НОВОЕ: Массовое изменение цен (процентная корректировка)
// НОВОЕ: Интеллектуальное округление цен (шаг 50, порог 10/11)
// ============================================================
function csc_items_admin_page() {
    // === Обработка сохранения ===
    if ($_POST && isset($_POST['save_items']) && check_admin_referer('csc_save_items_action')) {
        $items = get_option('csc_service_items', []);
        if (!is_array($items)) $items = [];
        
        $editing_section = sanitize_text_field($_POST['editing_section'] ?? '');
        
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $it) {
                if (empty($it['name'])) continue;
                $id = !empty($it['id']) ? sanitize_text_field($it['id']) : uniqid('it_');
                $items[$id] = [
                    'section_id'   => sanitize_text_field($it['section_id'] ?? ''),
                    'name'         => sanitize_text_field($it['name']),
                    'price'        => floatval($it['price'] ?? 0),
                    'duration'     => intval($it['duration'] ?? 0),
                    'is_available' => !empty($it['is_available'])
                ];
            }
        }
        
        if (!empty($_POST['delete_items']) && is_array($_POST['delete_items'])) {
            foreach ($_POST['delete_items'] as $del_id) {
                $del_id = sanitize_text_field($del_id);
                if (isset($items[$del_id])) {
                    unset($items[$del_id]);
                }
            }
        }
        
        if (function_exists('csc_save_items')) {
            csc_save_items($items);
            echo '<div class="notice notice-success"><p>Услуги успешно сохранены.</p></div>';
        } else {
            update_option('csc_service_items', $items);
            echo '<div class="notice notice-warning"><p>Сохранено (прямой записью).</p></div>';
        }
    }
    
    // === Получение данных ===
    $items = function_exists('csc_get_items') ? csc_get_items() : get_option('csc_service_items', []);
    $sections = function_exists('csc_get_sections') ? csc_get_sections() : get_option('csc_service_sections', []);
    
    if (!is_array($items)) $items = [];
    if (!is_array($sections)) $sections = [];
    
    // === Фильтр по разделу ===
    $section_filter = sanitize_text_field($_GET['section_filter'] ?? 'all');
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 30;
    
    $section_counts = ['all' => count($items)];
    foreach ($sections as $sid => $sec) {
        $section_counts[$sid] = 0;
    }
    foreach ($items as $item) {
        $sid = $item['section_id'] ?? '';
        if (isset($section_counts[$sid])) {
            $section_counts[$sid]++;
        }
    }
    
    $filtered_items = $items;
    if ($section_filter !== 'all' && isset($sections[$section_filter])) {
        $filtered_items = array_filter($items, function($item) use ($section_filter) {
            return ($item['section_id'] ?? '') === $section_filter;
        });
    }
    
    $total_items = count($filtered_items);
    $total_pages = max(1, ceil($total_items / $per_page));
    if ($paged > $total_pages) $paged = $total_pages;
    $offset = ($paged - 1) * $per_page;
    $items_on_page = array_slice($filtered_items, $offset, $per_page, true);
    
    $base_url = esc_url(add_query_arg(['page' => 'csc_items_admin'], admin_url('options-general.php')));
    ?>
    <div class="wrap">
        <h1>Услуги <span class="title-count" style="color:#eff1d0; font-size:14px; font-weight:normal;">(<?php echo esc_html($total_items); ?> <?php echo $section_filter !== 'all' ? 'в разделе' : 'всего'; ?>)</span></h1>
        
        <!-- === ФИЛЬТР ПО РАЗДЕЛАМ === -->
        <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e2e8f0;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <label style="font-weight: 600; color: #1e293b;">📂 Раздел:</label>
                <select id="csc-section-filter" style="min-width: 250px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;">
                    <option value="all" <?php selected($section_filter, 'all'); ?>>
                        📋 Все разделы (<?php echo $section_counts['all']; ?>)
                    </option>
                    <?php foreach ($sections as $sid => $sec): 
                        $sec_name = is_array($sec) ? ($sec['name'] ?? $sid) : $sec;
                    ?>
                        <option value="<?php echo esc_attr($sid); ?>" <?php selected($section_filter, $sid); ?>>
                            <?php echo esc_html($sec_name); ?> (<?php echo $section_counts[$sid] ?? 0; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($section_filter !== 'all'): ?>
                    <a href="<?php echo $base_url; ?>" class="button" style="margin-left: 5px;">✕ Сбросить фильтр</a>
                <?php endif; ?>
                <span style="margin-left: auto; color: #64748b; font-size: 13px;">
                    Показано: <?php echo count($items_on_page); ?> из <?php echo $total_items; ?>
                </span>
            </div>
        </div>
        
        <!-- ============================================================
        БЛОК МАССОВОГО ИЗМЕНЕНИЯ ЦЕН (ИНФЛЯЦИЯ/ДЕФЛЯЦИЯ + ОКРУГЛЕНИЕ)
        ============================================================ -->
        <div style="margin: 20px 0; padding: 16px; background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 6px;">
            <h3 style="margin: 0 0 12px 0; font-size: 15px; color: #1e40af;">💰 Массовое изменение и округление цен</h3>
            <p style="margin: 0 0 12px 0; color: #475569; font-size: 13px;">
                Применить процентную корректировку или округлить цены до кратных 50. 
                <strong>Логика округления:</strong> отклонение ≤ 10 — вниз (в пользу клиента), ≥ 11 — вверх (в пользу сервиса). <em>(Пример: 110 ₽ → 100 ₽, 111 ₽ → 150 ₽, 149 ₽ → 150 ₽)</em>
            </p>
            <div style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #374151;">Процент (%)</label>
                    <input type="number" id="csc-price-percent" step="0.1" min="-99" max="1000" value="0" style="width: 100px; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #374151;">Область применения</label>
                    <select id="csc-price-scope" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <option value="all">Все услуги</option>
                        <option value="section">Только выбранный раздел</option>
                        <option value="selected">Только выбранные услуги</option>
                    </select>
                </div>
                <div id="csc-price-section-wrapper" style="display: none;">
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #374151;">Раздел</label>
                    <select id="csc-price-section" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <?php foreach ($sections as $sid => $s): 
                            $s_name = is_array($s) ? ($s['name'] ?? $sid) : $s;
                        ?>
                            <option value="<?php echo esc_attr($sid); ?>"><?php echo esc_html($s_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="button button-primary" id="csc-apply-price-change" style="padding: 6px 16px;">Применить %</button>
                <button type="button" class="button" id="csc-apply-round-prices" style="padding: 6px 16px; background: #f59e0b; color: white; border-color: #f59e0b; font-weight: 600;" title="Округлить выбранные цены до кратных 50">
                    🎯 Округлить до 50
                </button>
                <span id="csc-price-change-status" style="margin-left: 10px; font-size: 13px; color: #64748b;"></span>
            </div>
            <div id="csc-price-change-preview" style="margin-top: 12px; display: none; padding: 10px; background: #fff; border: 1px solid #e5e7eb; border-radius: 4px; max-height: 200px; overflow-y: auto;">
            </div>
        </div>
        
        <!-- === ИНФО-БЛОК ПАГИНАЦИИ === -->
        <?php if ($total_pages > 1): ?>
        <div style="margin: 10px 0; padding: 8px 12px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 3px;">
            <strong>Страница <?php echo esc_html($paged); ?> из <?php echo esc_html($total_pages); ?></strong>
        </div>
        <?php endif; ?>
        
        <form method="post">
            <?php wp_nonce_field('csc_save_items_action'); ?>
            <input type="hidden" name="editing_section" value="<?php echo esc_attr($section_filter); ?>">
            
            <table class="wp-list-table widefat fixed striped" id="csc-items-table">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;">
                            <input type="checkbox" id="csc-select-all-items" title="Выбрать все для удаления">
                        </th>
                        <th style="width: 18%;">Раздел</th>
                        <th style="width: 32%;">Название услуги</th>
                        <th style="width: 12%;">Цена (₽)</th>
                        <th style="width: 12%;">Время (мин)</th>
                        <th style="width: 8%;">Вкл.</th>
                        <th style="width: 8%;">🗑️</th>
                    </tr>
                </thead>
                <tbody id="csc-items-body">
                    <?php if (!empty($items_on_page)): ?>
                        <?php foreach ($items_on_page as $id => $it): ?>
                        <tr class="csc-item-row" data-section="<?php echo esc_attr($it['section_id'] ?? ''); ?>">
                            <td style="text-align: center;">
                                <input type="checkbox" name="delete_items[]" value="<?php echo esc_attr($id); ?>" class="csc-item-delete-checkbox">
                            </td>
                            <td>
                                <select name="items[<?php echo esc_attr($id); ?>][section_id]" style="width: 100%;">
                                    <?php foreach ($sections as $sid => $s): 
                                        $s_name = is_array($s) ? ($s['name'] ?? $sid) : $s;
                                    ?>
                                        <option value="<?php echo esc_attr($sid); ?>" <?php selected($it['section_id'] ?? '', $sid); ?>>
                                            <?php echo esc_html($s_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="hidden" name="items[<?php echo esc_attr($id); ?>][id]" value="<?php echo esc_attr($id); ?>">
                                <input type="text" name="items[<?php echo esc_attr($id); ?>][name]" value="<?php echo esc_attr($it['name'] ?? ''); ?>" style="width: 100%;">
                            </td>
                            <td>
                                <input type="number" name="items[<?php echo esc_attr($id); ?>][price]" value="<?php echo esc_attr($it['price'] ?? 0); ?>" style="width: 100%;" min="0" step="0.01">
                            </td>
                            <td>
                                <input type="number" name="items[<?php echo esc_attr($id); ?>][duration]" value="<?php echo esc_attr($it['duration'] ?? 0); ?>" style="width: 100%;" min="0">
                            </td>
                            <td>
                                <input type="checkbox" name="items[<?php echo esc_attr($id); ?>][is_available]" value="1" <?php checked(!empty($it['is_available'])); ?>>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" class="button csc-remove-row" style="color: #dc3232; padding: 2px 8px;" title="Удалить (отметить для удаления)">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #64748b;">
                                <?php if ($section_filter !== 'all'): ?>
                                    В этом разделе пока нет услуг. 
                                    <button type="button" class="button" id="csc-add-item-empty">+ Добавить первую услугу</button>
                                <?php else: ?>
                                    Услуги не найдены. Добавьте первую.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- === ПАНЕЛЬ ДЕЙСТВИЙ === -->
            <div style="margin-top: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <button type="button" class="button" id="csc-add-item">+ Добавить услугу</button>
                <button type="button" class="button" id="csc-delete-selected" style="color: #dc3232;" disabled>
                    🗑️ Удалить выбранные (<span id="csc-delete-count">0</span>)
                </button>
                <div style="margin-left: auto;">
                    <?php submit_button('💾 Сохранить изменения', 'primary', 'save_items', false); ?>
                </div>
            </div>
        </form>
        
        <!-- === НАВИГАЦИЯ ПАГИНАЦИИ === -->
        <?php if ($total_pages > 1): ?>
        <div class="tablenav" style="margin-top: 20px;">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_items); ?> элемент(ов)</span>
                <?php
                $pagination_args = [
                    'base'      => add_query_arg('paged', '%#%', add_query_arg(['page' => 'csc_items_admin', 'section_filter' => $section_filter], admin_url('options-general.php'))),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '« Назад',
                    'next_text' => 'Вперёд »',
                    'type'      => 'plain',
                ];
                echo paginate_links($pagination_args);
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    jQuery(document).ready(function($){
        // === Фильтр по разделам (авто-переход) ===
        $('#csc-section-filter').on('change', function(){
            var sectionId = $(this).val();
            var baseUrl = <?php echo wp_json_encode($base_url); ?>;
            if (sectionId === 'all') {
                window.location.href = baseUrl;
            } else {
                window.location.href = baseUrl + '&section_filter=' + encodeURIComponent(sectionId);
            }
        });
        
        // === Подсчёт выбранных для удаления ===
        function updateDeleteCount() {
            var count = $('.csc-item-delete-checkbox:checked').length;
            $('#csc-delete-count').text(count);
            $('#csc-delete-selected').prop('disabled', count === 0);
        }
        
        // === Выбор всех чекбоксов ===
        $('#csc-select-all-items').on('change', function(){
            $('.csc-item-delete-checkbox').prop('checked', this.checked);
            updateDeleteCount();
        });
        
        $(document).on('change', '.csc-item-delete-checkbox', updateDeleteCount);
        
        // === Массовое удаление (отметить чекбоксы) ===
        $('#csc-delete-selected').on('click', function(){
            var count = $('.csc-item-delete-checkbox:checked').length;
            if (count === 0) return;
            if (!confirm('Удалить выбранные услуги (' + count + ')? Это действие необратимо после сохранения.')) {
                return;
            }
            $('.csc-item-delete-checkbox:checked').closest('tr').fadeOut(300, function(){
                $(this).remove();
                updateDeleteCount();
            });
        });
        
        // === Удаление одной строки (кнопка ✕) ===
        $(document).on('click', '.csc-remove-row', function(){
            var row = $(this).closest('tr');
            var checkbox = row.find('.csc-item-delete-checkbox');
            if (checkbox.length) {
                checkbox.prop('checked', true);
                updateDeleteCount();
            }
            row.fadeOut(300, function(){
                $(this).remove();
            });
        });
        
        // === Добавление новой услуги ===
        var sectionsOpts = <?php echo wp_json_encode(array_map(function($s){ return is_array($s) ? ($s['name'] ?? '') : ''; }, $sections)); ?>;
        var sectionsIds = <?php echo wp_json_encode(array_keys($sections)); ?>;
        var currentSectionFilter = <?php echo wp_json_encode($section_filter); ?>;
        
        function buildSectionOptions(selectedId) {
            var html = '';
            for (var i = 0; i < sectionsIds.length; i++) {
                var sid = sectionsIds[i];
                var sname = sectionsOpts[sid] || sid;
                var sel = (sid === selectedId) ? ' selected' : '';
                html += '<option value="' + sid + '"' + sel + '>' + sname + '</option>';
            }
            return html;
        }
        
        function addNewItemRow() {
            var timestamp = Date.now();
            var id = 'new_' + timestamp;
            var defaultSection = (currentSectionFilter !== 'all') ? currentSectionFilter : (sectionsIds[0] || '');
            
            var row = '<tr class="csc-item-row" data-section="' + defaultSection + '" style="background: #fffbeb;">' +
                '<td style="text-align: center;">' +
                    '<input type="checkbox" class="csc-item-delete-checkbox" disabled title="Новая услуга">' +
                '</td>' +
                '<td>' +
                    '<select name="items[' + id + '][section_id]" style="width: 100%;">' +
                        buildSectionOptions(defaultSection) +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<input type="hidden" name="items[' + id + '][id]" value="' + id + '">' +
                    '<input type="text" name="items[' + id + '][name]" style="width: 100%;" placeholder="Название услуги" autofocus>' +
                '</td>' +
                '<td><input type="number" name="items[' + id + '][price]" style="width: 100%;" min="0" step="0.01" placeholder="0"></td>' +
                '<td><input type="number" name="items[' + id + '][duration]" style="width: 100%;" min="0" placeholder="60"></td>' +
                '<td><input type="checkbox" name="items[' + id + '][is_available]" value="1" checked></td>' +
                '<td style="text-align: center;">' +
                    '<button type="button" class="button csc-remove-row" style="color: #dc3232; padding: 2px 8px;">✕</button>' +
                '</td>' +
            '</tr>';
            
            $('#csc-items-body').append(row);
            $('#csc-items-body tr:last input[name*="[name]"]').focus();
        }
        
        $('#csc-add-item, #csc-add-item-empty').on('click', addNewItemRow);
        
        $(document).on('keydown', function(e){
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                addNewItemRow();
            }
        });

        // === МАССОВОЕ ИЗМЕНЕНИЕ ЦЕН ===
        $('#csc-price-scope').on('change', function(){
            if ($(this).val() === 'section') {
                $('#csc-price-section-wrapper').show();
            } else {
                $('#csc-price-section-wrapper').hide();
            }
        });

        // === ФУНКЦИЯ ОКРУГЛЕНИЯ (КЛИЕНТСКАЯ) ===
        // Логика: Кратное 50. Отклонение <= 10 -> вниз, >= 11 -> вверх.
        function cscRoundPrice(price) {
            if (price <= 0) return price;
            var lower = Math.floor(price / 50) * 50;
            var deviation = price - lower;
            if (deviation <= 10) {
                return lower; // Округление вниз (в пользу клиента)
            } else {
                return lower + 50; // Округление вверх (в пользу сервиса)
            }
        }

        // === КНОПКА ОКРУГЛЕНИЯ ===
        $('#csc-apply-round-prices').on('click', function(){
            var scope = $('#csc-price-scope').val();
            var sectionId = $('#csc-price-section').val();
            
            var $rows = $('#csc-items-body tr.csc-item-row');
            
            if (scope === 'section') {
                $rows = $rows.filter('[data-section="' + sectionId + '"]');
            } else if (scope === 'selected') {
                var selectedIds = [];
                $('input.csc-item-delete-checkbox:checked').each(function(){
                    var val = $(this).val();
                    if (val) selectedIds.push(val);
                });
                if (selectedIds.length === 0) {
                    alert('Выберите хотя бы одну услугу для округления (поставьте галочки слева)');
                    return;
                }
                $rows = $rows.filter(function(){
                    var id = $(this).find('input[name*="[id]"]').val();
                    return selectedIds.indexOf(id) !== -1;
                });
            }
            
            var changedCount = 0;
            $rows.each(function(){
                var $priceInput = $(this).find('input[name*="[price]"]');
                var currentPrice = parseFloat($priceInput.val()) || 0;
                if (currentPrice <= 0) return;
                
                var newPrice = cscRoundPrice(currentPrice);
                
                if (newPrice !== currentPrice) {
                    $priceInput.val(newPrice);
                    $priceInput.css({
                        'background-color': '#fef3c7', 
                        'border-color': '#f59e0b', 
                        'font-weight': 'bold',
                        'transition': 'all 0.3s ease'
                    });
                    changedCount++;
                }
            });
            
            if (changedCount > 0) {
                $('#csc-price-change-status').text('🎯 Округлено ' + changedCount + ' услуг. Не забудьте нажать "💾 Сохранить изменения" внизу!').css('color', '#d97706');
                $('html, body').animate({scrollTop: $('#csc-items-table').offset().top - 100}, 500);
            } else {
                $('#csc-price-change-status').text('✅ Все цены в выбранном диапазоне уже круглые или не требуют изменений.').css('color', '#10b981');
            }
        });

        $('#csc-apply-price-change').on('click', function(){
            var percent = parseFloat($('#csc-price-percent').val()) || 0;
            var scope = $('#csc-price-scope').val();
            var sectionId = $('#csc-price-section').val();
            var $btn = $(this);

            if (percent === 0) {
                alert('Введите процент изменения (отличный от нуля)');
                return;
            }

            if (percent < -99 || percent > 1000) {
                alert('Процент должен быть от -99% до +1000%');
                return;
            }

            var selectedIds = [];
            if (scope === 'selected') {
                $('input.csc-item-delete-checkbox:checked').each(function(){
                    var val = $(this).val();
                    if (val) selectedIds.push(val);
                });
                if (selectedIds.length === 0) {
                    alert('Выберите хотя бы одну услугу для обновления');
                    return;
                }
            }

            var direction = percent > 0 ? 'повысить' : 'снизить';
            var confirmMsg = 'Вы уверены, что хотите ' + direction + ' цены на ' + Math.abs(percent) + '%?\n\n';
            if (scope === 'all') {
                confirmMsg += 'Будут изменены ВСЕ услуги.';
            } else if (scope === 'section') {
                confirmMsg += 'Будут изменены услуги в выбранном разделе.';
            } else {
                confirmMsg += 'Будут изменены ' + selectedIds.length + ' выбранных услуг.';
            }

            if (!confirm(confirmMsg)) return;

            $btn.prop('disabled', true).text('Применение...');
            $('#csc-price-change-status').text('Обработка...').css('color', '#64748b');

            var data = {
                action: 'csc_bulk_update_prices',
                _wpnonce: '<?php echo wp_create_nonce("csc_save_items_action"); ?>',
                percent: percent,
                scope: scope,
                section_id: sectionId,
                selected_ids: selectedIds
            };

            $.post(ajaxurl, data, function(res){
                if (res.success) {
                    $('#csc-price-change-status').text('✅ ' + res.data.message).css('color', '#10b981');

                    var previewHtml = '<strong>Изменённые услуги:</strong><br>';
                    res.data.items.slice(0, 20).forEach(function(item){
                        var oldFormatted = item.old_price.toLocaleString('ru-RU', {maximumFractionDigits: 2});
                        var newFormatted = item.new_price.toLocaleString('ru-RU', {maximumFractionDigits: 2});
                        var changeClass = item.new_price > item.old_price ? 'color:#dc3232' : 'color:#10b981';
                        previewHtml += '<div style="font-size:12px; padding:2px 0;">' +
                            item.name + ': <s>' + oldFormatted + ' ₽</s> → <span style="' + changeClass + '; font-weight:600;">' + newFormatted + ' ₽</span>' +
                            '</div>';
                    });
                    if (res.data.items.length > 20) {
                        previewHtml += '<div style="font-size:11px; color:#64748b; margin-top:4px;">... и ещё ' + (res.data.items.length - 20) + ' услуг</div>';
                    }
                    $('#csc-price-change-preview').html(previewHtml).show();

                    setTimeout(function(){ location.reload(); }, 2000);
                } else {
                    $('#csc-price-change-status').text('❌ ' + (res.data.message || 'Ошибка')).css('color', '#ef4444');
                    $btn.prop('disabled', false).text('Применить %');
                }
            }).fail(function(){
                $('#csc-price-change-status').text('❌ Ошибка соединения').css('color', '#ef4444');
                $btn.prop('disabled', false).text('Применить %');
            });
        });
    });
    </script>
    <?php
}
// ============================================================
// 6. СТРАНИЦА ЗАЯВОК (СПИСОК И КАЛЕНДАРЬ)
// УЛУЧШЕНИЕ №4: Пагинация (25 записей на страницу)
// УЛУЧШЕНИЕ №3: Логирование переходов статусов через do_action
// ============================================================
function csc_orders_admin_page() {
    // Обработка единичного изменения статуса
    if (isset($_POST['csc_update_status'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        $ord_num = sanitize_text_field($_POST['order_number'] ?? '');
        $new_status = sanitize_text_field($_POST['new_status'] ?? '');
        if (!$ord_num || !$new_status || !check_admin_referer('csc_update_status_' . $ord_num, '_csc_status_nonce')) {
            wp_die('Security check failed');
        }
        $all_orders = csc_get_all_orders();
        if (isset($all_orders[$ord_num])) {
            $old_status = $all_orders[$ord_num]['status'] ?? '';
            // Защита от гонки с вебхуком оплаты: если заказ уже оплачен, не переводим его из awaiting_payment «в никуда».
            if ($old_status === CSC_STATUS_AWAITING_PAYMENT && ($all_orders[$ord_num]['payment_status'] ?? '') === 'paid') {
                echo '<div class="notice notice-warning is-dismissible"><p>Заказ ' . esc_html($ord_num) . ' уже оплачен — статус изменён быть не может. Сначала оформите возврат в модуле платежей.</p></div>';
            } else {
            $all_orders[$ord_num]['status'] = $new_status;
            if ($new_status === CSC_STATUS_CANCELLED) {
                $all_orders[$ord_num]['routing_status'] = 'cancelled';
                $all_orders[$ord_num]['cancelled_at'] = current_time('mysql');
                // Зависшие заказы «Ожидает оплаты»: при ручной отмене помечаем платёж как
                // неоплаченный, чтобы крон-проверка зависших платежей больше не трогала заказ.
                if ($old_status === CSC_STATUS_AWAITING_PAYMENT && in_array($all_orders[$ord_num]['payment_status'] ?? '', ['pending', ''], true)) {
                    $all_orders[$ord_num]['payment_status'] = 'unpaid';
                    $all_orders[$ord_num]['cancel_reason'] = 'Отменено администратором (зависший заказ в ожидании оплаты)';
                }
            }
            if (in_array($new_status, [CSC_STATUS_PROCESSING, CSC_STATUS_COMPLETED]) && ($all_orders[$ord_num]['routing_status'] ?? '') === 'searching') {
                $all_orders[$ord_num]['routing_status'] = 'manual_override';
            }
            csc_update_order($ord_num, $all_orders[$ord_num]);
            csc_cache_delete_orders();

            // УЛУЧШЕНИЕ №3: Логирование перехода статуса в историю и вызов общего хука
            if ($old_status !== $new_status) {
                if (function_exists('csc_log_status_change')) {
                    csc_log_status_change(
                        $ord_num,
                        $old_status,
                        $new_status,
                        'admin',
                        get_current_user_id() ? (string)get_current_user_id() : 'admin',
                        'Ручное изменение статуса администратором'
                    );
                }
                
                // Хук срабатывает при ЛЮБОМ изменении статуса админом
                do_action('csc_order_status_changed', $ord_num, $old_status, $new_status, 'admin', (string)get_current_user_id(), 'Ручное изменение администратором');
            }

            echo '<div class="notice notice-success is-dismissible"><p>Статус заказа ' . esc_html($ord_num) . ' обновлен.</p></div>';
            if (function_exists('csc_log_notification')) {
                $phone = $all_orders[$ord_num]['phone'] ?? '';
                $master_id = $all_orders[$ord_num]['master_id'] ?? '';
                csc_log_notification($ord_num, 'customer', $phone, 'web', "📌 Статус изменен вручную", "Статус заявки #$ord_num изменен администратором на \"$new_status\".", ['action' => 'manual_status_change']);
                if ($master_id) {
                    csc_log_notification($ord_num, 'master', $master_id, 'web', "📦 Статус заказа изменен", "Администратор установил статус \"$new_status\" для заказа #$ord_num.", ['action' => 'manual_status_change']);
                }
            }
            } // else (защита от изменения уже оплаченного заказа)
        }
    }

    $all_orders = csc_get_all_orders();
    uasort($all_orders, function($a, $b) {
        return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
    });
    $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
    $view = sanitize_key($_GET['view'] ?? 'list');

    // Фильтр по статусу (в т.ч. быстрый доступ к зависшим заказам «Ожидает оплаты»)
    $status_filter = sanitize_text_field(wp_unslash($_GET['status_filter'] ?? ''));
    if ($status_filter !== '' && strpos($status_filter, ':') !== false) {
        list($status_filter, $payment_status_filter) = explode(':', $status_filter, 2);
    } else {
        $payment_status_filter = '';
    }
    if ($status_filter !== '') {
        $all_orders = array_filter($all_orders, function ($o) use ($status_filter, $payment_status_filter) {
            if (($o['status'] ?? '') !== $status_filter) return false;
            if ($payment_status_filter !== '' && ($o['payment_status'] ?? '') !== $payment_status_filter) return false;
            return true;
        });
    }

    // ИСПРАВЛЕНО (UX админки): фильтр по мастеру (чипы «Мастер: ...»)
    $master_filter = sanitize_text_field(wp_unslash($_GET['master_filter'] ?? ''));
    if ($master_filter !== '') {
        $all_orders = array_filter($all_orders, function ($o) use ($master_filter) {
            return ($o['master_id'] ?? '') === $master_filter;
        });
    }

    // ОПТИМИЗАЦИЯ (Кэш заказов): счётчики чипов через SQL GROUP BY
    // (csc_get_order_status_counts) вместо перечитывания всего списка;
    // повторный запрос к кэшу/БД здесь больше не нужен.
    $status_counts = function_exists('csc_get_order_status_counts')
        ? csc_get_order_status_counts()
        : [];
    if ($payment_status_filter !== '') {
        // Для комбинированного чипа «статус:платёж» считаем по отфильтрованному списку
        $awaiting_with_payment = 0;
        foreach ($all_orders as $o) {
            if (($o['status'] ?? '') === CSC_STATUS_AWAITING_PAYMENT
                && ($o['payment_status'] ?? '') === 'pending') {
                $awaiting_with_payment++;
            }
        }
        $chip_key = CSC_STATUS_AWAITING_PAYMENT . ':pending';
        $status_counts[$chip_key] = $awaiting_with_payment;
    }
    if (!isset($status_counts[CSC_STATUS_AWAITING_PAYMENT])) {
        $status_counts[CSC_STATUS_AWAITING_PAYMENT] = 0;
    }

    // ============================================================
    // УЛУЧШЕНИЕ №4: ПАГИНАЦИЯ
    // ============================================================
    $per_page = 25;
    $current_page = max(1, intval($_GET['paged'] ?? 1));
    $total_orders = count($all_orders);
    $total_pages = max(1, ceil($total_orders / $per_page));

    // Безопасная корректировка текущей страницы
    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }

    // Выборка заказов для текущей страницы
    $offset = ($current_page - 1) * $per_page;
    $orders_on_page = array_slice($all_orders, $offset, $per_page, true);

    // Базовые URL для навигации (сохраняем все GET-параметры кроме paged)
    $base_admin_url = esc_url(add_query_arg(['page' => 'csc_orders_admin'], admin_url('options-general.php')));
    // Сохраняем активный фильтр в ссылках навигации (view/list, пагинация)
    $admin_url_list = esc_url(add_query_arg(array_filter([
        'page' => 'csc_orders_admin',
        'view' => 'list',
        'status_filter' => ($status_filter !== '' ? $status_filter . ($payment_status_filter !== '' ? ':' . $payment_status_filter : '') : null),
    ]), admin_url('options-general.php')));
    $admin_url_calendar = esc_url(add_query_arg(['page' => 'csc_orders_admin', 'view' => 'calendar'], admin_url('options-general.php')));

    // Чипы быстрой фильтрации по статусу (включая зависшие «Ожидает оплаты»)
    $filter_chips = [
        ''                                  => 'Все',
        CSC_STATUS_PENDING                   => 'Ожидает',
        CSC_STATUS_SEARCHING                 => 'Поиск мастера',
        CSC_STATUS_AWAITING_PAYMENT          => '💳 Ожидает оплаты (зависшие)',
        CSC_STATUS_AWAITING_PAYMENT . ':pending' => 'Ожидает оплаты: платёж в процессе',
        CSC_STATUS_PROCESSING                => 'В работе',
        CSC_STATUS_COMPLETED                 => 'Выполнено',
        CSC_STATUS_CANCELLED                 => 'Отменено',
    ];
    ?>
    <div class="wrap">
    <h1>Управление заявками <span class="title-count" style="color:#64748b; font-size:14px; font-weight:normal;">(<?php echo esc_html($total_orders); ?> всего)</span></h1>

    <h2 class="nav-tab-wrapper">
        <a href="<?php echo $admin_url_list; ?>" class="nav-tab <?php echo $view == 'list' ? 'nav-tab-active' : ''; ?>">Список заявок</a>
        <a href="<?php echo $admin_url_calendar; ?>" class="nav-tab <?php echo $view == 'calendar' ? 'nav-tab-active' : ''; ?>">Календарь записей</a>
    </h2>

    <?php if ($view == 'list'): ?>

    <!-- ============================================================
    ИСПРАВЛЕНО (UX админки): фильтры были одной строкой «простынёй» —
    теперь сгруппированы по смыслу с подписями групп.
    ============================================================ -->
    <style>
        .csc-filter-bar { margin: 12px 0; padding: 12px 14px; background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 8px; }
        .csc-filter-group { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .csc-filter-group:last-child { margin-bottom: 0; }
        .csc-filter-label { font-weight: 600; color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .3px; min-width: 92px; }
        .csc-chip { display: inline-block; padding: 4px 10px; border: 1px solid #cbd5e1; border-radius: 999px; background: #fff; color: #334155; text-decoration: none; font-size: 12px; line-height: 1.4; white-space: nowrap; }
        .csc-chip:hover { border-color: #2271b1; color: #2271b1; }
        .csc-chip.current { background: #2271b1; border-color: #2271b1; color: #fff; font-weight: 600; }
        /* ИСПРАВЛЕНО (UX админки): ячейка «Статус» больше не инлайн-флекс — форма перенесена в блок с flex-wrap, колонки имеют min-width, кнопки «Статус» и «Действия» не накладываются друг на друга */
        .wp-list-table .csc-col-status { min-width: 190px; }
        .wp-list-table .csc-col-actions { min-width: 210px; }
        .wp-list-table td.csc-col-status form { display: block; }
        .wp-list-table td.csc-col-status .csc-status-form-row { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; margin-top: 4px; }
    </style>
    <div class="csc-filter-bar">
        <div class="csc-filter-group">
            <span class="csc-filter-label">По статусу:</span>
            <?php foreach ($filter_chips as $chip_key => $chip_label):
                $chip_count = $chip_key === '' ? array_sum($status_counts) : ($status_counts[$chip_key] ?? 0);
                $chip_url = esc_url(add_query_arg(array_filter([
                    'page' => 'csc_orders_admin',
                    'view' => 'list',
                    'status_filter' => $chip_key !== '' ? $chip_key : null,
                ]), admin_url('options-general.php')));
                $is_active = ($status_filter . ($payment_status_filter !== '' ? ':' . $payment_status_filter : '')) === $chip_key;
                ?>
                <a class="csc-chip<?php echo $is_active ? ' current' : ''; ?>" href="<?php echo $chip_url; ?>"><?php echo esc_html($chip_label); ?> (<?php echo esc_html($chip_count); ?>)</a>
            <?php endforeach; ?>
        </div>
        <div class="csc-filter-group">
            <span class="csc-filter-label">Мастер:</span>
            <?php
            $master_filter = sanitize_text_field(wp_unslash($_GET['master_filter'] ?? ''));
            $mf_base = add_query_arg(array_filter([
                'page' => 'csc_orders_admin',
                'view' => 'list',
                'status_filter' => ($status_filter !== '' ? $status_filter . ($payment_status_filter !== '' ? ':' . $payment_status_filter : '') : null),
            ]), admin_url('options-general.php'));
            ?>
            <a class="csc-chip<?php echo $master_filter === '' ? ' current' : ''; ?>" href="<?php echo esc_url($mf_base); ?>">Все</a>
            <?php foreach ($masters as $mf_id => $mf):
                if (($mf['status'] ?? 'active') === 'archived') continue;
                $mf_url = esc_url(add_query_arg('master_filter', rawurlencode((string) $mf_id), $mf_base));
                ?>
                <a class="csc-chip<?php echo $master_filter === (string) $mf_id ? ' current' : ''; ?>" href="<?php echo $mf_url; ?>"><?php echo esc_html($mf['name'] ?? $mf_id); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================
    УЛУЧШЕНИЕ №4: ИНФО-БЛОК ПАГИНАЦИИ
    ============================================================ -->
    <?php if ($total_pages > 1): ?>
    <div style="margin: 10px 0; padding: 8px 12px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 3px;">
        <strong>Страница <?php echo esc_html($current_page); ?> из <?php echo esc_html($total_pages); ?></strong>
        <span style="color:#64748b; margin-left:10px;">Показано <?php echo esc_html(count($orders_on_page)); ?> из <?php echo esc_html($total_orders); ?> заказов</span>
    </div>
    <?php endif; ?>

    <!-- БЛОК МАССОВЫХ ДЕЙСТВИЙ (работает только с выбранными на текущей странице) -->
    <?php if (!empty($orders_on_page)): ?>
    <div style="margin: 15px 0; padding: 12px; background: #f8f9fa; border-radius: 6px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border: 1px solid #e2e8f0;">
        <label style="font-weight: 500;">Выбрать:</label>
        <button type="button" class="button" id="csc-bulk-select-all">Все на странице</button>
        <button type="button" class="button" id="csc-bulk-select-none">Снять</button>
        <div style="width: 1px; height: 24px; background: #ddd; margin: 0 5px;"></div>
        <select id="csc-bulk-status" style="height: 30px; padding: 0 8px;">
            <option value="">— Изменить статус на —</option>
            <option value="pending">Ожидает</option>
            <option value="searching">Поиск мастера</option>
            <option value="awaiting_payment">Ожидает оплаты</option>
            <option value="processing">В работе</option>
            <option value="completed">Выполнено</option>
            <option value="cancelled">Отменено</option>
        </select>
        <button type="button" class="button button-primary" id="csc-bulk-apply">Применить</button>
        <span id="csc-bulk-result" style="margin-left: 10px; font-weight: 500; color: #666;"></span>
    </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
        <thead>
        <tr>
            <th style="width: 30px; text-align: center;"><input type="checkbox" id="csc-select-all-header"></th>
            <th style="width: 120px;">Заказ / Дата</th>
            <th>Клиент</th>
            <th>Мастер</th>
            <th>Услуги</th>
            <th style="width: 100px;">Итого</th>
            <th class="csc-col-status">Статус</th>
            <th class="csc-col-actions">Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!empty($orders_on_page)): foreach ($orders_on_page as $o):
            $mid = $o['master_id'] ?? 'master_default';
            $m_name = isset($masters[$mid]) ? $masters[$mid]['name'] : 'Не назначен';
            $m_color = isset($masters[$mid]) ? $masters[$mid]['color'] : '#e2e8f0';
            $order_date_str = date('d.m.Y H:i', strtotime(($o['date'] ?? '') . ' ' . ($o['time'] ?? '')));
            $reminder_sent = !empty($o['reminder_sent']);
            ?>
            <tr>
                <td style="text-align: center;">
                    <input type="checkbox" class="csc-order-checkbox" value="<?php echo esc_attr($o['order_number']); ?>">
                </td>
                <td>
                    <strong>#<?php echo esc_html($o['order_number']); ?></strong><br>
                    <small><?php echo esc_html($order_date_str); ?></small>
                </td>
                <td>
                    <?php echo esc_html($o['name'] ?? ''); ?><br>
                    <small><?php echo esc_html($o['phone'] ?? ''); ?></small>
                </td>
                <td>
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?php echo esc_attr($m_color); ?>; margin-right:5px;"></span>
                    <?php echo esc_html($m_name); ?>
                </td>
                <td>
                    <div style="max-height: 60px; overflow-y: auto; font-size: 0.9em;">
                        <?php echo esc_html(implode(' | ', $o['services'] ?? [])); ?>
                    </div>
                    <?php if(!empty($o['address'])): ?><br><em>Адрес: <?php echo esc_html($o['address']); ?></em><?php endif; ?>
                </td>
                <td><strong><?php echo esc_html(number_format(floatval($o['total'] ?? 0), 0, '.', ' ')); ?> ₽</strong></td>
                <?php
                // ИСПРАВЛЕНО (UX админки): статусная форма — не inline-flex (кнопки «Статус»/«Действия»
                // больше не накладываются); добавлена ЯВНАЯ кнопка перезапуска поиска мастера.
                $o_status_lower = mb_strtolower($o['status'] ?? '');
                $can_restart    = in_array($o_status_lower, ['pending', 'searching', 'no_masters', 'routing_timeout', 'timeout_global'], true);
                ?>
                <td class="csc-col-status">
                    <form method="post" class="csc-single-status-form">
                        <?php wp_nonce_field('csc_update_status_' . $o['order_number'], '_csc_status_nonce'); ?>
                        <input type="hidden" name="order_number" value="<?php echo esc_attr($o['order_number']); ?>">
                        <?php if (($o['status'] ?? '') === CSC_STATUS_AWAITING_PAYMENT): ?>
                            <!-- Быстрая ручная отмена зависшего заказа «Ожидает оплаты» -->
                            <button type="submit" name="csc_update_status" value="1" class="button button-small" style="color:#d63638;" data-confirm="Отменить зависший заказ #<?php echo esc_attr($o['order_number']); ?> (ожидает оплаты)?">❌ Отменить</button>
                            <input type="hidden" name="new_status" value="cancelled">
                        <?php endif; ?>
                        <div class="csc-status-form-row">
                            <select name="new_status" style="font-size: 12px; height: 28px;">
                                <option value="pending" <?php selected($o['status'] ?? '', CSC_STATUS_PENDING); ?>>Ожидает</option>
                                <option value="searching" <?php selected($o['status'] ?? '', CSC_STATUS_SEARCHING); ?>>Поиск мастера</option>
                                <option value="awaiting_payment" <?php selected($o['status'] ?? '', CSC_STATUS_AWAITING_PAYMENT); ?>>Ожидает оплаты</option>
                                <option value="processing" <?php selected($o['status'] ?? '', CSC_STATUS_PROCESSING); ?>>В работе</option>
                                <option value="completed" <?php selected($o['status'] ?? '', CSC_STATUS_COMPLETED); ?>>Выполнено</option>
                                <option value="cancelled" <?php selected($o['status'] ?? '', CSC_STATUS_CANCELLED); ?>>Отменено</option>
                            </select>
                            <button type="submit" name="csc_update_status" class="button button-small">OK</button>
                        </div>
                    </form>
                </td>
                <td class="csc-col-actions">
                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                        <button type="button" class="button button-small" onclick="openOrderLogModal('<?php echo esc_js($o['order_number']); ?>')">📜 Логи</button>
                        <?php if ($can_restart): ?>
                        <!-- ИСПРАВЛЕНО (UX админки): явная кнопка перезапуска поиска мастера для зависших статусов -->
                        <button type="button"
                            class="button button-small csc-send-reminder-btn csc-restart-routing-btn"
                            data-order="<?php echo esc_attr($o['order_number']); ?>"
                            data-status="<?php echo esc_attr($o_status_lower); ?>"
                            title="Заявка зависла без мастера — запустить поиск по новому кругу">🔄 Перезапустить поиск</button>
                        <?php else: ?>
                        <!-- УЛУЧШЕНИЕ №2: Кнопка отправки напоминания вручную -->
                        <button type="button"
                            class="button button-small csc-send-reminder-btn"
                            data-order="<?php echo esc_attr($o['order_number']); ?>"
                            data-status="<?php echo esc_attr($o_status_lower); ?>"
                            <?php if ($reminder_sent) echo 'style="color:#10b981;" title="Напоминание уже отправлено"'; ?>>
                            🔔 <?php echo $reminder_sent ? '✓' : 'Напомнить'; ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="8">Заявок пока нет.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- ============================================================
    УЛУЧШЕНИЕ №4: НАВИГАЦИЯ ПАГИНАЦИИ
    ============================================================ -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav" style="margin-top: 20px;">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html($total_orders); ?> элемент(ов)</span>
            <?php
            echo paginate_links([
                'base'      => add_query_arg('paged', '%#%', $admin_url_list),
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '« Назад',
                'next_text' => 'Вперёд »',
                'type'      => 'plain',
            ]);
            ?>
        </div>
    </div>
    <?php endif; ?>

    <script>
    // ИСПРАВЛЕНО: все nonce и URL через esc_js / esc_url
    const CSC_BULK_NONCE = '<?php echo esc_js(wp_create_nonce("csc_bulk_nonce")); ?>';
    const CSC_REMINDER_NONCE = '<?php echo esc_js(wp_create_nonce("csc_admin_nonce")); ?>';
    const CSC_AJAX_URL = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
    jQuery(document).ready(function($){
        // Подтверждение быстрой отмены зависшего заказа «Ожидает оплаты»
        $(document).on('click', '.csc-single-status-form button[data-confirm]', function(e){
            if (!confirm($(this).data('confirm'))) {
                e.preventDefault();
                return false;
            }
        });

        $('#csc-select-all-header').on('change', function(){
            $('.csc-order-checkbox').prop('checked', this.checked);
        });

        $('#csc-bulk-select-all').on('click', function(){
            $('.csc-order-checkbox').prop('checked', true);
            $('#csc-select-all-header').prop('checked', true);
        });

        $('#csc-bulk-select-none').on('click', function(){
            $('.csc-order-checkbox').prop('checked', false);
            $('#csc-select-all-header').prop('checked', false);
        });

        // Массовое изменение статусов
        $('#csc-bulk-apply').on('click', function(){
            var selected = [];
            $('.csc-order-checkbox:checked').each(function(){ selected.push($(this).val()); });
            if (selected.length === 0) { alert('Выберите хотя бы один заказ'); return; }
            var newStatus = $('#csc-bulk-status').val();
            if (!newStatus) { alert('Выберите новый статус'); return; }
            if (!confirm('Изменить статус у ' + selected.length + ' заказ(ов) на "' + newStatus + '"?')) return;
            $('#csc-bulk-result').text('Обработка...').css('color', '#666');
            $.post(ajaxurl, {
                action: 'csc_bulk_update_status',
                order_numbers: selected,
                new_status: newStatus,
                _ajax_nonce: CSC_BULK_NONCE
            }, function(response){
                if (response.success) {
                    $('#csc-bulk-result').text(response.data.message).css('color', '#2271b1');
                    // ИСПРАВЛЕНО 6.2: сохраняем текущую страницу пагинации при перезагрузке
                    var currentUrl = window.location.href;
                    setTimeout(function(){ window.location.href = currentUrl; }, 800);
                } else {
                    $('#csc-bulk-result').text(response.data.message || 'Ошибка').css('color', '#d63638');
                }
            }).fail(function(){ $('#csc-bulk-result').text('Ошибка соединения').css('color', '#d63638'); });
        });

        // ИСПРАВЛЕНО 6.2: блокировка кнопки после отправки + обработка уже отправленных
        // ДОБАВЛЕНО: для зависших статусов кнопка перезапускает маршрутизацию, а не отправляет напоминание
        $('.csc-send-reminder-btn').on('click', function(){
            var $btn = $(this);
            var orderNum = $btn.data('order');
            var orderStatus = ($btn.data('status') || '').toLowerCase();

            // Для зависших статусов — перезапуск маршрутизации, а не напоминание.
            // ИСПРАВЛЕНО (UX админки): PHP-константы (CSC_STATUS_*) в JS недоступны —
            // раньше это кидало ReferenceError и ломало обработчик; теперь литеральные строки.
            var restartStatuses = [
                'no_masters',
                'searching',
                'routing_timeout',
                'timeout_global',
                'pending'
            ];
            var isRestart = restartStatuses.indexOf(orderStatus) !== -1;

            // Если уже отправлено — не даём нажать повторно, кроме случаев перезапуска маршрутизации
            if ($btn.data('sent') && !isRestart) return;

            var confirmText = isRestart
                ? 'Перезапустить поиск мастера по заказу #' + orderNum + '? Заявка пойдёт по новому кругу рассылки.'
                : 'Отправить напоминание по заказу #' + orderNum + ' сейчас?';

            if (!confirm(confirmText)) return;

            $btn.prop('disabled', true).text(isRestart ? 'Перезапуск...' : 'Отправка...');

            $.post(ajaxurl, {
                action: 'csc_admin_send_reminder',
                order_number: orderNum,
                nonce: CSC_REMINDER_NONCE
            }, function(res){
                if (res.success) {
                    if (isRestart) {
                        $btn.text('🔄 Перезапущено').css('color', '#f59e0b');
                        // Не ставим data('sent'), чтобы можно было перезапустить повторно.
                        // Обновляем страницу, чтобы получить актуальный статус заказа.
                        setTimeout(function(){
                            location.reload();
                        }, 1500);
                    } else {
                        $btn.text('✓ Отправлено').css('color', '#10b981');
                        $btn.data('sent', true);
                        // Не разблокируем — напоминание уже отправлено
                    }
                } else {
                    var msg = res.data && res.data.message
                        ? res.data.message
                        : 'не удалось выполнить действие';

                    $btn.prop('disabled', false)
                        .text(isRestart ? '🔄 Перезапустить' : '🔔 Напомнить')
                        .css('color', '#ef4444');

                    alert('❌ ' + msg);

                    setTimeout(function(){
                        $btn.prop('disabled', false)
                            .text(isRestart ? '🔄 Перезапустить' : '🔔 Напомнить')
                            .css('color', '');
                    }, 3000);
                }
            }).fail(function(){
                $btn.prop('disabled', false)
                    .text(isRestart ? '🔄 Перезапустить' : '🔔 Напомнить')
                    .css('color', '');

                alert('Ошибка соединения с сервером');
            });
        });
    </script>

    <?php else: // РЕЖИМ КАЛЕНДАРЯ ?>
    <script src='<?php echo esc_url(CSC_PLUGIN_URL . 'assets/vendor/fullcalendar/index.global.min.js'); ?>'></script>
    <div id='csc-calendar' style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px;"></div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('csc-calendar');
        var events = [
            <?php
            foreach ($all_orders as $o) {
                if (($o['status'] ?? '') === CSC_STATUS_CANCELLED) continue;
                $mid = $o['master_id'] ?? 'master_default';
                $color = isset($masters[$mid]) ? $masters[$mid]['color'] : '#3788d8';
                $start = ($o['date'] ?? '') . 'T' . ($o['time'] ?? '');
                $duration = intval($o['duration'] ?? 60);
                $end = date('Y-m-d\TH:i:s', strtotime("$start +$duration minutes"));
                echo "{
                    title: '" . esc_js(($o['order_number'] ?? '') . " - " . ($o['name'] ?? '')) . "',
                    start: '" . esc_js($start) . "',
                    end: '" . esc_js($end) . "',
                    color: '" . esc_js($color) . "',
                    extendedProps: {
                        phone: '" . esc_js($o['phone'] ?? '') . "',
                        status: '" . esc_js($o['status'] ?? '') . "'
                    }
                },";
            }
            ?>
        ];
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            locale: 'ru',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            slotMinTime: '07:00:00',
            slotMaxTime: '23:00:00',
            allDaySlot: false,
            events: events,
            eventClick: function(info) {
                alert('Заявка: ' + info.event.title + '\nТел: ' + info.event.extendedProps.phone + '\nСтатус: ' + info.event.extendedProps.status);
            }
        });
        calendar.render();
    });
    </script>
    <?php endif; ?>
    </div>

    <!-- ============================================================
    МОДАЛЬНОЕ ОКНО ИСТОРИИ ЗАКАЗА (РАСШИРЕННАЯ ИНТЕГРАЦИЯ)
    УЛУЧШЕНИЕ №3: добавлена вкладка "История статусов"
    ============================================================ -->
    <div id="csc-order-log-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.45); z-index:99999; align-items:center; justify-content:center; backdrop-filter:blur(2px);">
        <div style="background:#ffffff; border-radius:14px; width:92%; max-width:850px; max-height:88vh; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,0.15); display:flex; flex-direction:column; font-family:system-ui,-apple-system,sans-serif;">
            <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
                <h3 style="margin:0; font-size:17px; font-weight:600; color:#0f172a;">📜 Логи и Уведомления <span id="modal-order-id" style="color:#3b82f6; margin-left:6px;"></span></h3>
                <button onclick="document.getElementById('csc-order-log-modal').style.display='none'" style="background:#e2e8f0; border:none; width:28px; height:28px; border-radius:6px; cursor:pointer; color:#475569; font-size:16px;">&times;</button>
            </div>
            <!-- УЛУЧШЕНИЕ №3: Вкладки для разделения типов логов -->
            <div style="padding:10px 20px 0; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; gap:4px;">
                <button type="button" class="csc-log-tab-btn active" data-tab="history" style="padding:8px 16px; border:none; background:#fff; border-bottom:2px solid #3b82f6; color:#3b82f6; font-weight:600; cursor:pointer; border-radius:6px 6px 0 0;">📋 История статусов</button>
                <button type="button" class="csc-log-tab-btn" data-tab="routing" style="padding:8px 16px; border:none; background:transparent; color:#64748b; cursor:pointer; border-radius:6px 6px 0 0;">🔀 Маршрутизация</button>
                <button type="button" class="csc-log-tab-btn" data-tab="notifications" style="padding:8px 16px; border:none; background:transparent; color:#64748b; cursor:pointer; border-radius:6px 6px 0 0;">📡 Уведомления</button>
            </div>
            <div id="modal-content" style="padding:20px; overflow-y:auto; flex:1; font-size:13px; color:#334155; background:#fff;"></div>
            <div style="padding:12px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; text-align:right;">
                <!-- УЛУЧШЕНИЕ №3: Кнопка экспорта истории в CSV -->
                <button type="button" id="csc-export-history-btn" class="button button-secondary" style="margin-right:10px;">📥 Экспорт истории</button>
                <button onclick="document.getElementById('csc-order-log-modal').style.display='none'" class="button button-secondary">Закрыть</button>
            </div>
        </div>
    </div>

    <style>
    @keyframes csc-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .log-section-title { font-size:14px; font-weight:700; color:#0f172a; margin:15px 0 10px; padding-bottom:6px; border-bottom:1px solid #f1f5f9; }
    .log-table { width:100%; border-collapse:collapse; margin-bottom:16px; }
    .log-table th { text-align:left; padding:8px 10px; color:#64748b; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; font-weight:600; }
    .log-table td { padding:10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .log-time { white-space:nowrap; color:#475569; font-family:monospace; font-size:12px; }
    .log-badge { display:inline-block; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:600; }
    .badge-success { background:#d1fae5; color:#065f46; }
    .badge-warn { background:#fef3c7; color:#92400e; }
    .badge-error { background:#fee2e2; color:#991b1b; }
    .badge-info { background:#e0f2fe; color:#075985; }
    .badge-read { background:#f3e8ff; color:#6b21a8; }
    .log-empty { background:#f8fafc; padding:12px; border-radius:8px; color:#64748b; font-style:italic; text-align:center; margin-bottom:12px; border:1px dashed #e2e8f0; }
    </style>

    <script>
    const CSC_MODAL_AJAX_URL = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
    const CSC_ORDER_LOGS_NONCE = '<?php echo esc_js(wp_create_nonce("csc_order_logs_nonce")); ?>';
    const CSC_ORDER_NOTIF_NONCE = '<?php echo esc_js(wp_create_nonce("csc_order_logs_nonce")); ?>';
    const CSC_EXPORT_NONCE = '<?php echo esc_js(wp_create_nonce("csc_order_logs_nonce")); ?>';

    // Глобальные переменные для вкладок
    let currentModalOrder = '';
    let cachedModalData = null;
    let currentLogTab = 'history';

    // 🔗 Deep-link из push-уведомления администратора: ?page=csc_orders_admin&csc_open_order=NNN
    (function(){
        var m = /[?&]csc_open_order=([0-9A-Za-z\-]+)/.exec(location.search);
        if (!m) return;
        history.replaceState(null, '', location.href.replace(/[?&]csc_open_order=[0-9A-Za-z\-]+/, ''));
        jQuery(function(){ setTimeout(function(){ openOrderLogModal(m[1]); }, 300); });
    })();

    function openOrderLogModal(orderNum) {
        const modal = document.getElementById('csc-order-log-modal');
        const content = document.getElementById('modal-content');
        const titleSpan = document.getElementById('modal-order-id');
        modal.style.display = 'flex';
        titleSpan.textContent = '#' + orderNum;
        currentModalOrder = orderNum;
        currentLogTab = 'history';
        cachedModalData = null;

        // Сброс вкладок
        document.querySelectorAll('.csc-log-tab-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.style.background = 'transparent';
            btn.style.color = '#64748b';
            btn.style.borderBottom = 'none';
        });
        const historyTab = document.querySelector('.csc-log-tab-btn[data-tab="history"]');
        if (historyTab) {
            historyTab.classList.add('active');
            historyTab.style.background = '#fff';
            historyTab.style.color = '#3b82f6';
            historyTab.style.borderBottom = '2px solid #3b82f6';
        }

        content.innerHTML = '<div style="text-align:center; padding:40px 20px; color:#64748b;"><div style="width:24px; height:24px; border:3px solid #e2e8f0; border-top-color:#3b82f6; border-radius:50%; animation:csc-spin 1s linear infinite; margin:0 auto 10px;"></div>Загрузка данных...</div>';

        // Параллельная загрузка двух типов логов
        const fd1 = new FormData();
        fd1.append('action', 'csc_get_order_routing_logs');
        fd1.append('order_number', orderNum);
        fd1.append('_ajax_nonce', CSC_ORDER_LOGS_NONCE);

        const fd2 = new FormData();
        fd2.append('action', 'csc_get_order_notifications');
        fd2.append('order_number', orderNum);
        fd2.append('_wpnonce', CSC_ORDER_NOTIF_NONCE);

        Promise.all([
            fetch(CSC_MODAL_AJAX_URL, { method: 'POST', body: fd1 }).then(r => r.json()),
            fetch(CSC_MODAL_AJAX_URL, { method: 'POST', body: fd2 }).then(r => r.json())
        ]).then(([resRoute, resNotif]) => {
            cachedModalData = { routing: resRoute, notif: resNotif };
            renderCurrentLogTab();
        }).catch(function(err) {
            content.innerHTML = '<div class="log-empty" style="color:#ef4444;">❌ Ошибка сети</div>';
            console.error('Modal load error:', err);
        });
    }

    function renderCurrentLogTab() {
        const content = document.getElementById('modal-content');
        if (!cachedModalData) return;
        let html = '';
        const resRoute = cachedModalData.routing;
        const resNotif = cachedModalData.notif;

        // === ВКЛАДКА: ИСТОРИЯ СТАТУСОВ (УЛУЧШЕНИЕ №3) ===
        if (currentLogTab === 'history') {
            if (resRoute.success && resRoute.data.history_html) {
                html += resRoute.data.history_html;
            } else {
                html += '<div class="log-empty">📋 История переходов статусов отсутствует</div>';
            }
            // Фото из отзыва клиента
            if (resRoute.success && resRoute.data.review_photos_html) {
                html += resRoute.data.review_photos_html;
            }
        }
        // === ВКЛАДКА: МАРШРУТИЗАЦИЯ ===
        else if (currentLogTab === 'routing') {
            if (resRoute.success && resRoute.data.routing && resRoute.data.routing.length > 0) {
                html += '<div class="log-section-title">🔀 Логика диспетчеризации</div><table class="log-table"><tr><th>Время</th><th>Событие</th><th>Параметры</th></tr>';
                resRoute.data.routing.forEach(function(log) {
                    const eventData = log.data ? JSON.stringify(log.data).replace(/["{}]/g, '').replace(/,/g, ', ') : '-';
                    html += '<tr><td class="log-time">' + (log.timestamp || '') + '</td><td><span class="log-badge badge-info">' + (log.event || '').replace(/_/g, ' ') + '</span></td><td style="word-break:break-all; color:#475569;">' + eventData + '</td></tr>';
                });
                html += '</table>';
            } else {
                html += '<div class="log-empty">⏳ История маршрутизации отсутствует</div>';
            }
        }
        // === ВКЛАДКА: УВЕДОМЛЕНИЯ ===
        else if (currentLogTab === 'notifications') {
            if (resNotif.success && resNotif.data.notifications && resNotif.data.notifications.length > 0) {
                html += '<div class="log-section-title">📡 Лог Уведомлений (TG/Web)</div><table class="log-table"><tr><th>Время</th><th>Кому</th><th>Статус</th><th>Прочитано</th></tr>';
                resNotif.data.notifications.forEach(function(log) {
                    let statusClass = 'badge-info';
                    if (log.status === 'delivered') statusClass = 'badge-success';
                    else if (log.status === 'read') statusClass = 'badge-read';
                    else if (log.status === 'failed') statusClass = 'badge-error';
                    else if (log.status === 'pending') statusClass = 'badge-warn';
                    html += '<tr><td class="log-time">' + (log.created_at || '') + '</td><td>' + (log.recipient_type || '') + ' (' + (log.recipient_id || '') + ')</td><td><span class="log-badge ' + statusClass + '">' + (log.status || '') + '</span></td><td class="log-time">' + (log.read_at || '-') + '</td></tr>';
                });
                html += '</table>';
            } else {
                html += '<div class="log-empty">📡 Логи уведомлений отсутствуют</div>';
            }
        }
        content.innerHTML = html;
    }

    // Переключение вкладок
    document.querySelectorAll('.csc-log-tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.csc-log-tab-btn').forEach(b => {
                b.classList.remove('active');
                b.style.background = 'transparent';
                b.style.color = '#64748b';
                b.style.borderBottom = 'none';
            });
            this.classList.add('active');
            this.style.background = '#fff';
            this.style.color = '#3b82f6';
            this.style.borderBottom = '2px solid #3b82f6';
            currentLogTab = this.getAttribute('data-tab');
            renderCurrentLogTab();
        });
    });

    // Экспорт истории в CSV
    document.getElementById('csc-export-history-btn').addEventListener('click', function() {
        if (!currentModalOrder) return;
        const url = CSC_MODAL_AJAX_URL + '?action=csc_export_order_history_csv&order_number=' + encodeURIComponent(currentModalOrder) + '&_wpnonce=' + encodeURIComponent(CSC_EXPORT_NONCE);
        window.location.href = url;
    });

    // Закрытие по клику на фон и Esc
    document.getElementById('csc-order-log-modal').addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') document.getElementById('csc-order-log-modal').style.display = 'none'; });
    </script>
    <?php } 
	// ============================================================
// ГЛОБАЛЬНЫЙ LIGHTBOX ДЛЯ ФОТОГРАФИЙ (АДМИНКА)
// ============================================================
add_action('admin_footer', 'csc_inject_global_lightbox');
if (!function_exists('csc_inject_global_lightbox')) {
    function csc_inject_global_lightbox() {
        ?>
        <style>
        .csc-lightbox-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 999999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
        .csc-lightbox-overlay.active { display: flex; opacity: 1; }
        .csc-lightbox-content { position: relative; max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .csc-lightbox-img { max-width: 90vw; max-height: 80vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); transition: transform 0.2s; }
        .csc-lightbox-close, .csc-lightbox-prev, .csc-lightbox-next { position: absolute; background: rgba(255,255,255,0.2); border: none; color: white; font-size: 24px; width: 48px; height: 48px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; backdrop-filter: blur(4px); }
        .csc-lightbox-close:hover, .csc-lightbox-prev:hover, .csc-lightbox-next:hover { background: rgba(255,255,255,0.4); }
        .csc-lightbox-close { top: 20px; right: 20px; font-size: 32px; }
        .csc-lightbox-prev { left: 20px; top: 50%; transform: translateY(-50%); }
        .csc-lightbox-next { right: 20px; top: 50%; transform: translateY(-50%); }
        .csc-lightbox-counter { color: white; margin-top: 15px; font-size: 14px; font-weight: 600; background: rgba(0,0,0,0.5); padding: 4px 12px; border-radius: 12px; }
        .csc-review-photo-thumb { width: 64px; height: 64px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 2px solid #e2e8f0; transition: transform 0.2s, border-color 0.2s; margin-right: 6px; margin-bottom: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .csc-review-photo-thumb:hover { transform: scale(1.05); border-color: #3b82f6; }
        </style>
        <div id="csc-lightbox" class="csc-lightbox-overlay">
            <div class="csc-lightbox-backdrop" style="position:absolute;top:0;left:0;width:100%;height:100%;"></div>
            <div class="csc-lightbox-content">
                <button class="csc-lightbox-close">&times;</button>
                <button class="csc-lightbox-prev">&#10094;</button>
                <button class="csc-lightbox-next">&#10095;</button>
                <img src="" class="csc-lightbox-img" alt="Review photo">
                <div class="csc-lightbox-counter"></div>
            </div>
        </div>
        <script>
        window.CSC_Lightbox = {
            images: [], currentIndex: 0, overlay: null, img: null, counter: null,
            init: function() {
                if (this.overlay) return;
                this.overlay = document.getElementById('csc-lightbox');
                this.img = this.overlay.querySelector('.csc-lightbox-img');
                this.counter = this.overlay.querySelector('.csc-lightbox-counter');
                this.overlay.querySelector('.csc-lightbox-close').addEventListener('click', () => this.close());
                this.overlay.querySelector('.csc-lightbox-backdrop').addEventListener('click', () => this.close());
                this.overlay.querySelector('.csc-lightbox-prev').addEventListener('click', (e) => { e.stopPropagation(); this.prev(); });
                this.overlay.querySelector('.csc-lightbox-next').addEventListener('click', (e) => { e.stopPropagation(); this.next(); });
                document.addEventListener('keydown', (e) => {
                    if (!this.overlay.classList.contains('active')) return;
                    if (e.key === 'Escape') this.close();
                    if (e.key === 'ArrowLeft') this.prev();
                    if (e.key === 'ArrowRight') this.next();
                });
            },
            open: function(images, startIndex = 0) {
                this.init();
                this.images = images;
                this.currentIndex = startIndex;
                this.updateView();
                this.overlay.style.display = 'flex';
                setTimeout(() => this.overlay.classList.add('active'), 10);
            },
            close: function() {
                this.overlay.classList.remove('active');
                setTimeout(() => { this.overlay.style.display = 'none'; }, 300);
            },
            prev: function() {
                this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
                this.updateView();
            },
            next: function() {
                this.currentIndex = (this.currentIndex + 1) % this.images.length;
                this.updateView();
            },
            updateView: function() {
                this.img.src = this.images[this.currentIndex];
                this.counter.textContent = (this.currentIndex + 1) + ' / ' + this.images.length;
                this.overlay.querySelector('.csc-lightbox-prev').style.display = this.images.length > 1 ? 'flex' : 'none';
                this.overlay.querySelector('.csc-lightbox-next').style.display = this.images.length > 1 ? 'flex' : 'none';
            }
        };
        </script>
        <?php
    }
}
	?>