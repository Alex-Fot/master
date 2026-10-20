<?php
/**
 * Файл: csc-reminders.php
 * Описание: Модуль автоматических напоминаний клиенту и мастеру.
 * УЛУЧШЕНИЕ №2: Напоминания за N часов до заказа.
 * Версия: 1.0
 *
 * Крон: запускается каждые 30 минут через 'csc_reminders_cron_hook'.
 * Настройки в админке (внутри csc_main_settings):
 *   - reminder_enabled (bool) — включить/выключить (по умолчанию: 1)
 *   - reminder_hours (int) — за сколько часов напоминать (1-72, по умолчанию: 10)
 *   - reminder_client_enabled (bool) — напоминать клиенту (по умолчанию: 1)
 *   - reminder_master_enabled (bool) — напоминать мастеру (по умолчанию: 1)
 *
 * Каналы:
 *   - Клиент: логирование + Web Push
 *   - Мастер: Telegram + Web Push + внутрикабинетное уведомление
 *
 * Идемпотентность: поле reminder_sent в заказе предотвращает повторные напоминания.
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================

/** Окно поиска заказов для напоминания (±30 минут от целевого времени) */
if (!defined('CSC_REMINDER_WINDOW_MINUTES')) {
    define('CSC_REMINDER_WINDOW_MINUTES', 30);
}

/** Максимальное время выполнения обработчика напоминаний (сек) */
if (!defined('CSC_REMINDER_MAX_EXECUTION_TIME')) {
    define('CSC_REMINDER_MAX_EXECUTION_TIME', 120);
}

// ============================================================
// 2. МИГРАЦИЯ: КОЛОНКА reminder_sent В ТАБЛИЦЕ ЗАКАЗОВ
// ============================================================
/**
 * Гарантированно добавляет колонку reminder_sent в таблицу csc_orders.
 *
 * Используется:
 * - на init для обычных запросов;
 * - принудительно перед cron-запросом напоминаний.
 *
 * Новый флаг csc_reminders_column_migrated_v2 нужен, чтобы не зависеть
 * от старого csc_reminders_column_migrated, который мог быть установлен
 * ошибочно или до создания таблицы.
 */
if (!function_exists('csc_reminders_ensure_column')) {
    function csc_reminders_ensure_column($force = false) {
        static $checked = false;
        static $forced = false;

        if ($force) {
            if ($forced) {
                return;
            }
        } else {
            if ($checked) {
                return;
            }

            // Обычный режим: если миграция уже подтверждена, не делаем SHOW COLUMNS каждый раз.
            if (get_option('csc_reminders_column_migrated_v2', false)) {
                $checked = true;
                return;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';

        // Если таблицы ещё нет — выходим, не ломая выполнение.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $column_exists = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'reminder_sent')
        );

        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN reminder_sent TINYINT(1) NOT NULL DEFAULT 0"
            );

            error_log('[CSC_REMINDERS] Колонка reminder_sent добавлена в таблицу заказов.');
        }

        update_option('csc_reminders_column_migrated_v2', true, false);

        if ($force) {
            $forced = true;
        } else {
            $checked = true;
        }
    }
}

/**
 * Миграция должна отрабатывать не только в админке.
 * init подходит и для обычных запросов, и для wp-cron.
 */
add_action('init', 'csc_reminders_migrate_column', 1);

if (!function_exists('csc_reminders_migrate_column')) {
    function csc_reminders_migrate_column() {
        csc_reminders_ensure_column(false);
    }
}

// ============================================================
// 3. ПОЛУЧЕНИЕ НАСТРОЕК НАПОМИНАНИЙ
// ============================================================

/**
 * Получить текущие настройки напоминаний.
 * Возвращает массив с параметрами по умолчанию, если не заданы.
 *
 * @return array [
 *   'enabled'         => bool,
 *   'hours'           => int (1-72),
 *   'client_enabled'  => bool,
 *   'master_enabled'  => bool,
 * ]
 */
if (!function_exists('csc_reminders_get_settings')) {
    function csc_reminders_get_settings() {
        $s = get_option('csc_main_settings', []);
        return [
            'enabled'        => isset($s['reminder_enabled']) ? (bool)$s['reminder_enabled'] : true,
            'hours'          => max(1, min(72, intval($s['reminder_hours'] ?? 10))),
            'client_enabled' => isset($s['reminder_client_enabled']) ? (bool)$s['reminder_client_enabled'] : true,
            'master_enabled' => isset($s['reminder_master_enabled']) ? (bool)$s['reminder_master_enabled'] : true,
        ];
    }
}

// ============================================================
// 4. ОБРАБОТЧИК КРОНА
// ============================================================

/**
 * Основной обработчик крона напоминаний.
 * Вызывается через 'csc_reminders_cron_hook' каждые 30 минут.
 */
add_action('csc_reminders_cron_hook', 'csc_process_reminders');

if (!function_exists('csc_process_reminders')) {
    function csc_process_reminders() {
        $settings = csc_reminders_get_settings();

        if (!$settings['enabled']) {
            return;
        }

        // Защита от параллельного выполнения
        $lock_key = 'csc_reminders_lock';
        if (get_transient($lock_key)) {
            error_log('[CSC_REMINDERS] Напоминания уже обрабатываются, пропускаем.');
            return;
        }
        set_transient($lock_key, 1, CSC_REMINDER_MAX_EXECUTION_TIME);

        @set_time_limit(CSC_REMINDER_MAX_EXECUTION_TIME);

        try {
            $sent_count = csc_send_due_reminders($settings);
            if ($sent_count > 0) {
                error_log("[CSC_REMINDERS] Отправлено напоминаний: {$sent_count}");
            }
        } catch (Exception $e) {
            error_log('[CSC_REMINDERS] Ошибка обработки напоминаний: ' . $e->getMessage());
        } finally {
            delete_transient($lock_key);
        }
    }
}

// ============================================================
// 5. ОСНОВНАЯ ЛОГИКА: ПОИСК ЗАКАЗОВ И ОТПРАВКА НАПОМИНАНИЙ
// ============================================================

/**
 * Найти заказы, для которых пришло время отправить напоминание,
 * и отправить уведомления клиенту и мастеру.
 *
 * Логика окна:
 *   - Целевое время напоминания = время заказа - reminder_hours
 *   - Отправляем, если текущее время попадает в окно
 *     [целевое_время - 30мин, целевое_время + 30мин]
 *   - Эквивалентно: время заказа попадает в окно
 *     [сейчас + reminder_hours - 30мин, сейчас + reminder_hours + 30мин]
 *
 * @param array $settings Настройки напоминаний
 * @return int Количество отправленных напоминаний
 */
if (!function_exists('csc_send_due_reminders')) {
    function csc_send_due_reminders($settings) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);

        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $reminder_hours = $settings['hours'];
        $window_minutes = CSC_REMINDER_WINDOW_MINUTES;

        // Вычисляем окно: время заказа должно быть в диапазоне
        // [сейчас + N часов - окно, сейчас + N часов + окно]
        $window_start = (clone $now)
            ->modify("+{$reminder_hours} hours")
            ->modify("-{$window_minutes} minutes");
        $window_end = (clone $now)
            ->modify("+{$reminder_hours} hours")
            ->modify("+{$window_minutes} minutes");

        $window_start_str = $window_start->format('Y-m-d H:i:00');
        $window_end_str = $window_end->format('Y-m-d H:i:00');

        $sent_count = 0;

        if ($table_exists) {
            // ============================================================
            // РЕЖИМ ТАБЛИЦЫ: прямой SQL-запрос
            // ============================================================
            csc_reminders_ensure_column(true);

            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status NOT IN ('отменено', 'выполнено')
                 AND (reminder_sent IS NULL OR reminder_sent = 0)
                 AND date != ''
                 AND time != ''
                 AND STR_TO_DATE(CONCAT(date, ' ', time, ':00'), '%%Y-%%m-%%d %%H:%%i:%%s') >= %s
                 AND STR_TO_DATE(CONCAT(date, ' ', time, ':00'), '%%Y-%%m-%%d %%H:%%i:%%s') <= %s
                 LIMIT 50",
                $window_start_str,
                $window_end_str
            ), ARRAY_A);

            if (empty($results)) {
                return 0;
            }

            foreach ($results as $row) {
                $order = csc_db_row_to_order_array($row);
                $order_number = $order['order_number'] ?? '';

                if (empty($order_number)) continue;

                try {
                    csc_send_reminder_for_order($order, $settings);

                    // Помечаем как отправленное
                    $wpdb->update(
                        $table,
                        ['reminder_sent' => 1],
                        ['order_number' => $order_number],
                        ['%d'],
                        ['%s']
                    );

                    $sent_count++;
                } catch (Exception $e) {
                    error_log("[CSC_REMINDERS] Ошибка напоминания для #{$order_number}: " . $e->getMessage());
                }
            }

            // Сброс кэша заказов
            if (function_exists('csc_cache_delete_orders')) {
                csc_cache_delete_orders();
            }
        } else {
            // ============================================================
            // РЕЖИМ WP_OPTIONS: фолбэк для немигрированных данных
            // ============================================================
            $all_orders = csc_get_all_orders();
            $updated = false;

            foreach ($all_orders as $order_number => $order) {
                $status = $order['status'] ?? '';
                if (in_array($status, ['отменено', 'выполнено'])) continue;
                if (!empty($order['reminder_sent'])) continue;

                $order_date = $order['date'] ?? '';
                $order_time = $order['time'] ?? '';
                if (empty($order_date) || empty($order_time)) continue;

                try {
                    $order_dt = new DateTime("{$order_date} {$order_time}:00", $tz);
                } catch (Exception $e) {
                    continue;
                }

                // Время напоминания = время заказа - N часов
                $reminder_dt = (clone $order_dt)->modify("-{$reminder_hours} hours");
                $reminder_ts = $reminder_dt->getTimestamp();

                // Проверяем попадание в окно ±30 минут от текущего момента
                $window_start_ts = $now->getTimestamp() - ($window_minutes * 60);
                $window_end_ts = $now->getTimestamp() + ($window_minutes * 60);

                if ($reminder_ts >= $window_start_ts && $reminder_ts <= $window_end_ts) {
                    try {
                        csc_send_reminder_for_order($order, $settings);
                        $all_orders[$order_number]['reminder_sent'] = 1;
                        $updated = true;
                        $sent_count++;
                    } catch (Exception $e) {
                        error_log("[CSC_REMINDERS] Ошибка напоминания для #{$order_number}: " . $e->getMessage());
                    }
                }
            }

            if ($updated) {
                update_option('csc_all_orders', $all_orders, false);
                if (function_exists('csc_cache_delete_orders')) {
                    csc_cache_delete_orders();
                }
            }
        }

        return $sent_count;
    }
}

// ============================================================
// 6. ОТПРАВКА НАПОМИНАНИЯ ДЛЯ ОДНОГО ЗАКАЗА
// ============================================================

/**
 * Отправить напоминание клиенту и мастеру по конкретному заказу.
 *
 * Клиент: логирование + Web Push
 * Мастер: Telegram + Web Push + внутрикабинетное уведомление
 *
 * @param array $order    Данные заказа
 * @param array $settings Настройки напоминаний
 */
if (!function_exists('csc_send_reminder_for_order')) {
    function csc_send_reminder_for_order($order, $settings) {
        $order_number = $order['order_number'] ?? '';
        $order_date = $order['date'] ?? '';
        $order_time = $order['time'] ?? '';
        $client_name = $order['name'] ?? 'Клиент';
        $client_phone_raw = $order['phone'] ?? '';
        $client_phone = function_exists('csc_normalize_phone')
            ? csc_normalize_phone($client_phone_raw)
            : preg_replace('/[^0-9]/', '', $client_phone_raw);
        $master_id = $order['master_id'] ?? '';
        $total = floatval($order['total'] ?? 0);
        $address = $order['address'] ?? 'Адрес не указан';
        $services = $order['services_for_master'] ?? $order['services'] ?? [];
        $services_text = is_array($services) ? implode(', ', array_slice($services, 0, 3)) : $services;

        $reminder_time_str = "{$order_date} в {$order_time}";
        $total_formatted = number_format($total, 0, '.', ' ') . ' ₽';

        // ============================================================
        // 6.1 НАПОМИНАНИЕ КЛИЕНТУ (логирование + Web Push)
        // ============================================================
        if ($settings['client_enabled'] && !empty($client_phone)) {
            $client_title = "🔔 Напоминание о заказе #{$order_number}";
            $client_message = "{$client_name}, напоминаем: {$reminder_time_str}. Адрес: {$address}. Сумма: {$total_formatted}.";

            // Логирование
            if (function_exists('csc_log_notification')) {
                csc_log_notification(
                    $order_number,
                    'customer',
                    $client_phone,
                    'web',
                    $client_title,
                    $client_message,
                    ['type' => 'reminder', 'reminder_hours' => $settings['hours']]
                );
            }

            // Web Push клиенту
            if (function_exists('csc_send_web_push_notification')) {
                $subs = get_option("csc_push_subs_customer_{$client_phone}", []);
                if (is_array($subs)) {
                    foreach ($subs as $sub_json) {
                        csc_send_web_push_notification(
                            $sub_json,
                            $client_title,
                            $client_message,
                            $order_number,
                            'customer',
                            $client_phone
                        );
                    }
                }
            }
        }

        // ============================================================
        // 6.2 НАПОМИНАНИЕ МАСТЕРУ (Telegram + Web Push + Internal)
        // ============================================================
        if ($settings['master_enabled'] && !empty($master_id)) {
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
            $master_data = $masters[$master_id] ?? null;

            if ($master_data) {
                $master_name = $master_data['name'] ?? 'Мастер';
                $master_tg_chat = $master_data['tg_chat_id'] ?? '';

                $master_title = "🔔 Напоминание: заказ #{$order_number}";

                // Формируем сообщение для Telegram (HTML)
                $master_tg_message = "{$master_name}, напоминаем о предстоящем заказе.

";
                $master_tg_message .= "📅 <b>{$reminder_time_str}</b>
";
                $master_tg_message .= "📍 " . esc_html($address) . "
";
                if (!empty($services_text)) {
                    $master_tg_message .= "🔧 " . esc_html($services_text) . "
";
                }
                $master_tg_message .= "💰 {$total_formatted}
";
                $master_tg_message .= "👤 " . esc_html($client_name) . " (+" . esc_html($client_phone_raw) . ")";

                // Ссылка на кабинет мастера (одноразовый токен)
                if (function_exists('csc_tg_get_dashboard_link')) {
                    $dash_url = csc_tg_get_dashboard_link($master_id, $masters);
                    $master_tg_message .= "
📲 <a href=\"" . esc_url($dash_url) . "\">Открыть кабинет</a>";
                }

                // Telegram-уведомление мастеру
                if (!empty($master_tg_chat) && function_exists('csc_send_tg_notification')) {
                    csc_send_tg_notification(
                        $master_tg_chat,
                        $master_title,
                        $master_tg_message,
                        $order_number,
                        'master',
                        $master_id,
                        null,
                        'HTML'
                    );
                }

                // Web Push мастеру
                if (function_exists('csc_send_web_push_notification')) {
                    $master_subs = get_option("csc_push_subs_master_{$master_id}", []);
                    if (is_array($master_subs)) {
                        $push_message = "Заказ #{$order_number}: {$reminder_time_str}. {$address}";
                        foreach ($master_subs as $sub_json) {
                            csc_send_web_push_notification(
                                $sub_json,
                                $master_title,
                                $push_message,
                                $order_number,
                                'master',
                                $master_id
                            );
                        }
                    }
                }

                // Внутрикабинетное уведомление мастера (для виджета в дашборде)
                if (function_exists('csc_add_master_notification')) {
                    csc_add_master_notification(
                        $master_id,
                        'reminder',
                        $master_title,
                        "Заказ #{$order_number}: {$reminder_time_str}. Адрес: {$address}.",
                        ['order_number' => $order_number, 'type' => 'reminder']
                    );
                }
            }
        }

        // ============================================================
        // 6.3 СИСТЕМНОЕ ЛОГИРОВАНИЕ
        // ============================================================
        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                $order_number,
                'system',
                'reminder',
                'web',
                '🔔 Напоминания отправлены',
                "Автоматические напоминания по заказу #{$order_number} отправлены за {$settings['hours']} ч до выполнения.",
                ['type' => 'reminder_sent', 'reminder_hours' => $settings['hours']]
            );
        }

        error_log("[CSC_REMINDERS] Напоминания отправлены для заказа #{$order_number} (за {$settings['hours']} ч)");
    }
}

// ============================================================
// 7. РУЧНАЯ ОТПРАВКА НАПОМИНАНИЯ (АДМИН-ФУНКЦИЯ)
// ============================================================

/**
 * Ручная отправка напоминания администратором.
 * Вызывается из AJAX-обработчика в csc-admin-pages.php.
 *
 * @param string $order_number Номер заказа
 * @return bool true если напоминание отправлено, иначе false
 */
if (!function_exists('csc_admin_send_reminder')) {
    function csc_admin_send_reminder($order_number) {
        if (!current_user_can('manage_options')) {
            return false;
        }

        if (empty($order_number)) {
            return false;
        }

        $order = csc_get_order($order_number);
        if (!$order) {
            error_log("[CSC_REMINDERS] Заказ #{$order_number} не найден для ручной отправки.");
            return false;
        }

        $status = $order['status'] ?? '';

        // ============================================================
        // ИСПРАВЛЕНО: для зависших статусов — перезапуск маршрутизации,
        // а НЕ отправка напоминания о времени (мастера ведь нет).
        // ============================================================
        $restart_statuses = ['нет мастеров', 'поиск мастера', 'ошибка: время поиска истекло', 'timeout_global'];

        if (in_array($status, $restart_statuses, true)) {
            if (!function_exists('csc_admin_restart_routing')) {
                error_log("[CSC_REMINDERS] csc_admin_restart_routing() не найдена. Проверьте подключение csc-order-routing.php.");
                return false;
            }

            $result = csc_admin_restart_routing($order_number);

            if ($result) {
                // Сбрасываем флаг напоминания, чтобы кнопка стала активной снова
                if (get_option('csc_orders_migrated', false)) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'csc_orders',
                        ['reminder_sent' => 0],
                        ['order_number' => $order_number],
                        ['%d'],
                        ['%s']
                    );
                } else {
                    $all_orders = csc_get_all_orders();
                    if (isset($all_orders[$order_number])) {
                        $all_orders[$order_number]['reminder_sent'] = 0;
                        update_option('csc_all_orders', $all_orders, false);
                    }
                }

                if (function_exists('csc_cache_delete_orders')) {
                    csc_cache_delete_orders();
                }

                if (function_exists('csc_log_notification')) {
                    csc_log_notification(
                        $order_number,
                        'admin',
                        (string)get_current_user_id(),
                        'web',
                        '🔄 Перезапуск маршрутизации',
                        "Администратор вручную перезапустил поиск мастера по заказу #{$order_number}.",
                        ['type' => 'routing_restart', 'previous_status' => $status]
                    );
                }

                error_log("[CSC_REMINDERS] Маршрутизация перезапущена для заказа #{$order_number} (был статус: {$status})");
                return true;
            }

            error_log("[CSC_REMINDERS] Не удалось перезапустить маршрутизацию для #{$order_number}");
            return false;
        }
        // ============================================================
        // КОНЕЦ ВЕТКИ ПЕРЕЗАПУСКА
        // ============================================================

        if (in_array($status, ['отменено', 'выполнено'])) {
            error_log("[CSC_REMINDERS] Заказ #{$order_number} в статусе '{$status}', напоминание не отправляется.");
            return false;
        }

        $settings = csc_reminders_get_settings();

        // Принудительно включаем оба канала при ручной отправке
        $manual_settings = $settings;
        $manual_settings['client_enabled'] = true;
        $manual_settings['master_enabled'] = true;

        try {
            csc_send_reminder_for_order($order, $manual_settings);
        } catch (Exception $e) {
            error_log("[CSC_REMINDERS] Ошибка ручной отправки для #{$order_number}: " . $e->getMessage());
            return false;
        }

        // Помечаем как отправленное
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);

        if ($table_exists) {
            $wpdb->update(
                $table,
                ['reminder_sent' => 1],
                ['order_number' => $order_number],
                ['%d'],
                ['%s']
            );
        } else {
            $all_orders = csc_get_all_orders();
            if (isset($all_orders[$order_number])) {
                $all_orders[$order_number]['reminder_sent'] = 1;
                update_option('csc_all_orders', $all_orders, false);
            }
        }

        if (function_exists('csc_cache_delete_orders')) {
            csc_cache_delete_orders();
        }

        // Логируем ручную отправку
        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                $order_number,
                'admin',
                (string)get_current_user_id(),
                'web',
                '🔔 Ручное напоминание',
                "Администратор вручную отправил напоминание по заказу #{$order_number}.",
                ['type' => 'manual_reminder']
            );
        }

        error_log("[CSC_REMINDERS] Ручное напоминание отправлено для заказа #{$order_number}");
        return true;
    }
}

// ============================================================
// 8. УТИЛИТА: ПРОВЕРКА, ОТПРАВЛЕНО ЛИ НАПОМИНАНИЕ
// ============================================================

/**
 * Проверить, было ли уже отправлено напоминание по заказу.
 *
 * @param string $order_number Номер заказа
 * @return bool true если напоминание уже отправлено
 */
if (!function_exists('csc_is_reminder_sent')) {
    function csc_is_reminder_sent($order_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);

        if ($table_exists) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT reminder_sent FROM {$table} WHERE order_number = %s",
                $order_number
            ));
            return !empty($result);
        }

        // Фолбэк: читаем из массива заказов
        $order = csc_get_order($order_number);
        return !empty($order['reminder_sent']);
    }
}

// ============================================================
// 9. УТИЛИТА: СБРОС ФЛАГА НАПОМИНАНИЯ (для повторной отправки)
// ============================================================

/**
 * Сбросить флаг напоминания для заказа.
 * Используется при изменении даты/времени заказа.
 *
 * @param string $order_number Номер заказа
 */
if (!function_exists('csc_reset_reminder_flag')) {
    function csc_reset_reminder_flag($order_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';
        $table_exists = get_option('csc_orders_migrated', false);

        if ($table_exists) {
            $wpdb->update(
                $table,
                ['reminder_sent' => 0],
                ['order_number' => $order_number],
                ['%d'],
                ['%s']
            );
        } else {
            $all_orders = csc_get_all_orders();
            if (isset($all_orders[$order_number])) {
                $all_orders[$order_number]['reminder_sent'] = 0;
                update_option('csc_all_orders', $all_orders, false);
            }
        }

        if (function_exists('csc_cache_delete_orders')) {
            csc_cache_delete_orders();
        }
    }
}

// ============================================================
// 10. ХУК: СБРОС ФЛАГА ПРИ ИЗМЕНЕНИИ ДАТЫ/ВРЕМЕНИ ЗАКАЗА
// ============================================================

/**
 * Если администратор или мастер меняет дату/время заказа,
 * напоминание должно быть отправлено заново.
 * Вызывается через do_action('csc_order_datetime_changed', $order_number).
 */
add_action('csc_order_datetime_changed', 'csc_reset_reminder_flag', 10, 1);

// ============================================================
// 11. ХУКИ ДЛЯ ИНТЕГРАЦИИ С ДРУГИМИ МОДУЛЯМИ
// ============================================================

/**
 * Действие: после отправки напоминания по заказу.
 * Позволяет другим модулям реагировать.
 *
 * @param string $order_number Номер заказа
 * @param array  $settings     Настройки напоминаний
 */
// Пример использования:
// add_action('csc_reminder_sent', function($order_number, $settings) {
//     // Дополнительная логика после отправки напоминания
// }, 10, 2);

/**
 * Фильтр: позволяет изменить текст напоминания клиенту.
 *
 * @param string $message      Текст сообщения
 * @param array  $order        Данные заказа
 * @param array  $settings     Настройки
 * @return string
 */
// Пример:
// add_filter('csc_reminder_client_message', function($message, $order, $settings) {
//     return $message . ' Не забудьте подготовить помещение!';
// }, 10, 3);