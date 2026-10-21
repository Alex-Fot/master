<?php
/**
 * Файл: csc-order-history.php
 * Описание: История переходов статусов заявок и финансовых операций.
 * УЛУЧШЕНИЕ №3: Полная трассировка изменений статусов и финансов для аудита и защиты от споров.
 * Версия: 1.1
 *
 * Таблица: wp_csc_order_history (создаётся в custom-service-calculator.php)
 *
 * Интеграция:
 *   - Вызывать do_action('csc_order_status_changed', $order_number, $from, $to, $who, $who_id, $reason)
 *     при каждой смене статуса для автоматического логирования.
 *   - Вызывать csc_log_financial_event() при любых финансовых операциях.
 *
 * Совместимость:
 *   - Работает и с таблицей заказов, и с wp_options (фолбэк)
 *   - Не дублирует записи если статус не изменился
 *   - Кэширование истории на 60 секунд для снижения нагрузки
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================

/** Максимум записей истории при выборке */
if (!defined('CSC_HISTORY_MAX_PER_ORDER')) {
    define('CSC_HISTORY_MAX_PER_ORDER', 200);
}

/** TTL кэша истории в секундах */
if (!defined('CSC_HISTORY_CACHE_TTL')) {
    define('CSC_HISTORY_CACHE_TTL', 60);
}

/** Возраст записей (в днях) для автоматической очистки */
if (!defined('CSC_HISTORY_RETENTION_DAYS')) {
    define('CSC_HISTORY_RETENTION_DAYS', 90);
}

// ============================================================
// 2. ОСНОВНАЯ ФУНКЦИЯ ЛОГИРОВАНИЯ ПЕРЕХОДА СТАТУСА
// ============================================================
/**
 * Запись перехода статуса в таблицу истории.
 * Защита от дублирования: если $from_status === $to_status — запись не создаётся.
 *
 * @param string $order_number  Номер заказа
 * @param string $from_status   Предыдущий статус (пустая строка для создания)
 * @param string $to_status     Новый статус
 * @param string $changed_by    Роль инициатора: 'system'|'admin'|'master'|'customer'|'routing'|'cron'|'payment_gateway'
 * @param string $changed_by_id Идентификатор инициатора (ID мастера, телефон клиента и т.д.)
 * @param string $reason        Причина или комментарий (необязательно)
 * @return int|false ID записи или false при ошибке/дубле
 */
if (!function_exists('csc_log_status_change')) {
    function csc_log_status_change($order_number, $from_status, $to_status, $changed_by = 'system', $changed_by_id = '', $reason = '') {
        if (empty($order_number)) {
            return false;
        }
        
        $from_status = sanitize_text_field($from_status);
        $to_status = sanitize_text_field($to_status);
        
        // Защита от дублирования: статус не изменился
        if ($from_status === $to_status && $from_status !== '') {
            return false;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'csc_order_history';
        
        // Валидация роли инициатора (расширено для платежных шлюзов)
        $valid_roles = ['system', 'admin', 'master', 'customer', 'routing', 'cron', 'upsell', 'reminder', 'payment_gateway', 'finance'];
        $changed_by = sanitize_key($changed_by);
        if (!in_array($changed_by, $valid_roles, true)) {
            $changed_by = 'system';
        }
        
        $result = $wpdb->insert(
            $table,
            [
                'order_number'  => sanitize_text_field($order_number),
                'from_status'   => $from_status,
                'to_status'     => $to_status,
                'changed_by'    => $changed_by,
                'changed_by_id' => sanitize_text_field($changed_by_id),
                'reason'        => sanitize_textarea_field($reason),
                'created_at'    => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            error_log('[CSC_HISTORY] Ошибка записи истории: ' . $wpdb->last_error);
            return false;
        }
        
        $history_id = $wpdb->insert_id;
        
        // Инвалидация кэша для этого заказа
        wp_cache_delete('csc_history_' . $order_number);
        
        // Хук после записи истории (для расширений)
        do_action('csc_status_change_logged', $order_number, $from_status, $to_status, $changed_by, $history_id);
        
        return $history_id;
    }
}

// ============================================================
// 2.5. ЛОГИРОВАНИЕ ФИНАНСОВЫХ ОПЕРАЦИЙ (НОВОЕ В 1.1)
// ============================================================
/**
 * Логирование финансовой операции в историю заказа.
 * Используется для трекинга: создание платежа, оплата, возврат, штраф, выплата.
 *
 * @param string $order_number Номер заказа
 * @param string $event_type   Тип события (payment_created, payment_paid, payment_refunded, penalty, payout)
 * @param array  $data         Дополнительные данные (amount, provider, transaction_id и т.д.)
 * @param string $initiator    Инициатор (system, admin, payment_gateway)
 * @return int|false ID записи или false
 */
if (!function_exists('csc_log_financial_event')) {
    function csc_log_financial_event($order_number, $event_type, $data = [], $initiator = 'system') {
        if (empty($order_number)) return false;

        // Валидация типа события
        $valid_events = [
            'payment_created', 'payment_paid', 'payment_failed', 
            'payment_refunded', 'payment_partial_refund', 'penalty_charged', 
            'payout_created', 'payout_completed', 'commission_calculated', 
            'manual_adjustment', 'balance_hold', 'balance_released'
        ];

        if (!in_array($event_type, $valid_events, true)) {
            error_log("[CSC_HISTORY] Некорректный тип финансового события: {$event_type}");
            return false;
        }

        $financial_statuses = [
            'payment_created'       => 'Платёж создан',
            'payment_paid'          => 'Оплачено',
            'payment_failed'        => 'Ошибка оплаты',
            'payment_refunded'      => 'Возврат средств',
            'payment_partial_refund'=> 'Частичный возврат',
            'penalty_charged'       => 'Штраф начислен',
            'payout_created'        => 'Выплата мастеру создана',
            'payout_completed'      => 'Выплата мастеру завершена',
            'commission_calculated' => 'Комиссия рассчитана',
            'manual_adjustment'     => 'Ручная корректировка баланса',
            'balance_hold'          => 'Средства заморожены',
            'balance_released'      => 'Средства разморожены',
        ];

        $status_text = $financial_statuses[$event_type] ?? $event_type;
        $amount = floatval($data['amount'] ?? 0);
        
        // Формирование читаемого описания (reason)
        $description = $status_text;
        if ($amount > 0) {
            $description .= ' • ' . number_format($amount, 2, '.', ' ') . ' ₽';
        }
        if (!empty($data['provider'])) {
            $description .= ' • ' . esc_html($data['provider']);
        }
        if (!empty($data['transaction_id'])) {
            $description .= ' [TXN: ' . esc_html($data['transaction_id']) . ']';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'csc_order_history';

        // Адаптация под реальную структуру таблицы csc_order_history
        $result = $wpdb->insert(
            $table,
            [
                'order_number'  => sanitize_text_field($order_number),
                'from_status'   => '', // Для финансов нет "предыдущего" статуса заказа
                'to_status'     => 'financial:' . $event_type, // Специальный маркер
                'changed_by'    => sanitize_key($initiator),
                'changed_by_id' => sanitize_text_field($data['transaction_id'] ?? $data['provider'] ?? ''),
                'reason'        => $description,
                'created_at'    => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            error_log('[CSC_HISTORY] Ошибка записи фин. события: ' . $wpdb->last_error);
            return false;
        }

        $history_id = $wpdb->insert_id;

        // Инвалидация кэша (без группы, как в основном коде)
        wp_cache_delete('csc_history_' . $order_number);

        // Хук для расширения
        do_action('csc_financial_event_logged', $order_number, $event_type, $data, $initiator, $history_id);

        return $history_id;
    }
}

// ============================================================
// 3. АВТОМАТИЧЕСКОЕ ЛОГИРОВАНИЕ ЧЕРЕЗ ХУК
// ============================================================
add_action('csc_order_status_changed', 'csc_auto_log_status_change', 10, 6);

if (!function_exists('csc_auto_log_status_change')) {
    function csc_auto_log_status_change($order_number, $from_status, $to_status, $changed_by = 'system', $changed_by_id = '', $reason = '') {
        csc_log_status_change($order_number, $from_status, $to_status, $changed_by, $changed_by_id, $reason);
    }
}

// ============================================================
// 4. ПОЛУЧЕНИЕ ИСТОРИИ ПО ЗАКАЗУ (С КЭШИРОВАНИЕМ)
// ============================================================
if (!function_exists('csc_get_order_history')) {
    function csc_get_order_history($order_number, $limit = CSC_HISTORY_MAX_PER_ORDER) {
        if (empty($order_number)) return [];
        $limit = max(1, min((int)$limit, CSC_HISTORY_MAX_PER_ORDER));

        $cache_key = 'csc_history_' . $order_number;
        $cached = wp_cache_get($cache_key);
        if ($cached !== false && is_array($cached)) {
            return array_slice($cached, 0, $limit);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'csc_order_history';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_number = %s ORDER BY created_at DESC, id DESC LIMIT %d",
                $order_number,
                CSC_HISTORY_MAX_PER_ORDER
            ),
            ARRAY_A
        );
        
        if ($results === null) {
            $results = [];
            error_log('[CSC_HISTORY] Ошибка выборки истории: ' . $wpdb->last_error);
        }

        wp_cache_set($cache_key, $results, '', CSC_HISTORY_CACHE_TTL);
        return array_slice($results, 0, $limit);
    }
}

if (!function_exists('csc_get_last_status_change')) {
    function csc_get_last_status_change($order_number) {
        $history = csc_get_order_history($order_number, 1);
        return !empty($history) ? $history[0] : null;
    }
}

if (!function_exists('csc_count_status_changes')) {
    function csc_count_status_changes($order_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_order_history';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_number = %s",
            $order_number
        ));
        return (int)$count;
    }
}

// ============================================================
// 5. ФОРМАТИРОВАНИЕ ИСТОРИИ ДЛЯ ОТОБРАЖЕНИЯ (С ПОДДЕРЖКОЙ ФИНАНСОВ)
// ============================================================
if (!function_exists('csc_format_order_history')) {
    function csc_format_order_history($order_number) {
        $raw_history = csc_get_order_history($order_number);
        $formatted = [];

        $role_labels = [
            'system'          => '🤖 Система',
            'admin'           => '👤 Администратор',
            'master'          => '🔧 Мастер',
            'customer'        => '📱 Клиент',
            'routing'         => '🔀 Маршрутизация',
            'cron'            => '⏰ Крон-задача',
            'upsell'          => '🛠 Расширение чека',
            'reminder'        => '🔔 Напоминание',
            'payment_gateway' => '💳 Платёжный шлюз',
            'finance'         => '💰 Фин. модуль',
        ];

        $status_labels = [
            ''                  => '(создание)',
            CSC_STATUS_PENDING           => 'Ожидает',
            CSC_STATUS_SEARCHING => 'Поиск мастера',
            CSC_STATUS_AWAITING_PAYMENT => 'Ожидает оплаты',
            CSC_STATUS_PROCESSING => 'В работе',
            CSC_STATUS_COMPLETED         => 'Выполнено',
            CSC_STATUS_CANCELLED          => 'Отменено',
            CSC_STATUS_NO_MASTERS => 'Нет мастеров',
            CSC_STATUS_ROUTING_TIMEOUT => 'Ошибка: время поиска истекло',
        ];
        
        $financial_statuses = [
            'payment_created'       => 'Платёж создан',
            'payment_paid'          => 'Оплачено',
            'payment_failed'        => 'Ошибка оплаты',
            'payment_refunded'      => 'Возврат средств',
            'payment_partial_refund'=> 'Частичный возврат',
            'penalty_charged'       => 'Штраф начислен',
            'payout_created'        => 'Выплата создана',
            'payout_completed'      => 'Выплата завершена',
            'commission_calculated' => 'Комиссия рассчитана',
            'manual_adjustment'     => 'Ручная корректировка',
            'balance_hold'          => 'Средства заморожены',
            'balance_released'      => 'Средства разморожены',
        ];

        foreach ($raw_history as $entry) {
            $from = $entry['from_status'] ?? '';
            $to = $entry['to_status'] ?? '';
            // 3.5: нормализация старых кириллических статусов в истории к латинским ключам
            if (function_exists('csc_status_normalize')) {
                if ($from !== '' && strpos($from, 'financial:') !== 0) $from = csc_status_normalize($from);
                if ($to !== ''   && strpos($to, 'financial:')   !== 0) $to   = csc_status_normalize($to);
            }
            $role = $entry['changed_by'] ?? 'system';
            
            $role_label = $role_labels[$role] ?? $role;
            $is_financial = strpos($to, 'financial:') === 0;
            
            if ($is_financial) {
                $event_key = str_replace('financial:', '', $to);
                $from_label = '';
                $to_label = '💰 ' . ($financial_statuses[$event_key] ?? $event_key);
            } else {
                $from_label = $status_labels[$from] ?? ($from !== '' ? $from : '(создание)');
                $to_label = $status_labels[$to] ?? $to;
            }

            $formatted[] = [
                'id'             => $entry['id'],
                'timestamp'      => $entry['created_at'],
                'time_formatted' => date('d.m.Y H:i:s', strtotime($entry['created_at'])),
                'from_status'    => $from,
                'to_status'      => $to,
                'from_label'     => $from_label,
                'to_label'       => $to_label,
                'changed_by'     => $role,
                'role_label'     => $role_label,
                'changed_by_id'  => $entry['changed_by_id'] ?? '',
                'reason'         => $entry['reason'] ?? '',
                'is_financial'   => $is_financial,
            ];
        }
        return $formatted;
    }
}

if (!function_exists('csc_render_order_history_html')) {
    function csc_render_order_history_html($order_number) {
        $history = csc_format_order_history($order_number);
        if (empty($history)) {
            return '<div class="log-empty">📋 История переходов статусов отсутствует</div>';
        }

        $html = '<div class="log-section-title">📋 История переходов статусов и финансов</div>';
        $html .= '<table class="log-table">';
        $html .= '<tr><th>Время</th><th>Событие</th><th>Кто</th><th>Детали / Причина</th></tr>';
        
        foreach ($history as $entry) {
            $time_escaped = esc_html($entry['time_formatted']);
            $from_escaped = esc_html($entry['from_label']);
            $to_escaped = esc_html($entry['to_label']);
            $role_escaped = esc_html($entry['role_label']);
            $by_id_escaped = esc_html($entry['changed_by_id']);
            $reason_escaped = esc_html($entry['reason']);
            
            // Визуальное разделение финансовых и статусных событий
            $row_class = $entry['is_financial'] ? 'log-row-financial' : '';
            $row_style = $entry['is_financial'] ? 'background-color: #f0fdf4; border-left: 3px solid #22c55e;' : '';
            
            if ($entry['is_financial']) {
                $transition = "<strong style='color:#166534;'>{$to_escaped}</strong>";
            } else {
                $transition = "<strong>{$from_escaped}</strong> → <strong>{$to_escaped}</strong>";
            }

            $who = $role_escaped;
            if (!empty($by_id_escaped)) {
                $who .= " <small style='color:#64748b;'>({$by_id_escaped})</small>";
            }

            $reason = !empty($reason_escaped) ? $reason_escaped : '—';
            
            $html .= "<tr class='{$row_class}' style='{$row_style}'>";
            $html .= "<td class='log-time' style='white-space:nowrap;'>{$time_escaped}</td>";
            $html .= "<td>{$transition}</td>";
            $html .= "<td>{$who}</td>";
            $html .= "<td style='word-break:break-all; color:#475569;'>{$reason}</td>";
            $html .= "</tr>";
        }
        $html .= '</table>';
        return $html;
    }
}

// ============================================================
// 6. AJAX-ОБРАБОТЧИК: ПОЛУЧЕНИЕ ИСТОРИИ ЗАКАЗА
// ============================================================
add_action('wp_ajax_csc_get_order_history', 'csc_get_order_history_ajax_handler');

if (!function_exists('csc_get_order_history_ajax_handler')) {
    function csc_get_order_history_ajax_handler() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещен']);
        }
        if (!check_ajax_referer('csc_order_logs_nonce', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        if (empty($order_number)) {
            wp_send_json_error(['message' => 'Не указан номер заказа']);
        }

        $history = csc_format_order_history($order_number);
        $html = csc_render_order_history_html($order_number);

        wp_send_json_success([
            'order_number' => $order_number,
            'entries'      => $history,
            'html'         => $html,
            'count'        => count($history),
        ]);
    }
}

// ============================================================
// 7. ИНТЕГРАЦИЯ С МОДАЛЬНЫМ ОКНОМ ЛОГОВ АДМИНКИ
// ============================================================
// Для интеграции в csc-admin-pages.php добавить в csc_get_order_routing_logs_handler:
// $order_history = function_exists('csc_get_order_history') ? array_values(csc_get_order_history($order_number)) : [];
// wp_send_json_success(['routing' => $routing_history, 'tg_logs' => $tg_logs, 'history' => $order_history]);

// ============================================================
// 8. ОЧИСТКА СТАРЫХ ЗАПИСЕЙ (КРОН)
// ============================================================
add_action('csc_history_cleanup_cron', 'csc_cleanup_order_history');

if (!function_exists('csc_cleanup_order_history')) {
    function csc_cleanup_order_history() {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_order_history';
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . CSC_HISTORY_RETENTION_DAYS . ' days'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT 1000",
            $cutoff_date
        ));

        if ($deleted > 0) {
            error_log("[CSC_HISTORY] Очищено записей истории: {$deleted}");
        }
    }
}

if (!function_exists('csc_schedule_history_cleanup')) {
    function csc_schedule_history_cleanup() {
        if (!wp_next_scheduled('csc_history_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'csc_history_cleanup_cron');
        }
    }
}
add_action('admin_init', 'csc_schedule_history_cleanup', 50);

// ============================================================
// 9. УТИЛИТА: ЭКСПОРТ ИСТОРИИ В CSV (ДЛЯ АУДИТА)
// ============================================================
if (!function_exists('csc_export_order_history_csv')) {
    function csc_export_order_history_csv($order_number) {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещен');
        }

        $history = csc_get_order_history($order_number, 1000);
        if (empty($history)) {
            wp_die('История не найдена для заказа: ' . esc_html($order_number));
        }

        $filename = 'csc-history-' . sanitize_file_name($order_number) . '-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM для Excel
        
        fputcsv($out, ['ID', 'Номер заказа', 'Из статуса', 'В статус / Событие', 'Кто', 'ID инициатора', 'Детали / Причина', 'Дата/Время'], ';');
        
        foreach ($history as $entry) {
            fputcsv($out, [
                $entry['id'],
                $entry['order_number'],
                $entry['from_status'],
                $entry['to_status'],
                $entry['changed_by'],
                $entry['changed_by_id'],
                $entry['reason'],
                $entry['created_at'],
            ], ';');
        }
        fclose($out);
        exit;
    }
}

add_action('wp_ajax_csc_export_order_history_csv', 'csc_export_order_history_csv_ajax');

if (!function_exists('csc_export_order_history_csv_ajax')) {
    function csc_export_order_history_csv_ajax() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещен');
        }
        if (!check_ajax_referer('csc_order_logs_nonce', '_wpnonce', false)) {
            wp_die('Ошибка безопасности');
        }

        $order_number = sanitize_text_field($_GET['order_number'] ?? '');
        if (empty($order_number)) {
            wp_die('Не указан номер заказа');
        }
        
        csc_export_order_history_csv($order_number);
    }
}