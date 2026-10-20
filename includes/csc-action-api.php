<?php
/**
 * Файл: csc-action-api.php
 * Описание: П.1.6 — публичное событийное API плагина.
 *
 * Все внутренние do_action('csc_*') события являются частью API плагина:
 * сторонние разработчики и дочерние модули могут подписываться на них через
 * add_action(). Этот файл:
 *   1) документирует полный список событий (см. таблицу ниже);
 *   2) регистрирует встроенных слушателей, которые ранее отсутствовали
 *      (инвалидация кэша, логирование в history, перепланирование напоминаний).
 *
 * ============================ СОБЫТИЯ API =============================
 * | Событие                              | Аргументы                                                        | Источник                    |
 * |--------------------------------------|------------------------------------------------------------------|-----------------------------|
 * | csc_order_created                    | ($order_number, $order_data)                                     | ajax-handlers               |
 * | csc_order_status_changed             | ($order_number, $old_status, $new_status, $context)              | core/routing/dashboard      |
 * | csc_order_datetime_changed           | ($order_number, $old_dt, $new_dt)                                | ajax/reminders              |
 * | csc_master_completed_order           | ($order_number, $master_id)                                      | dashboard                   |
 * | csc_order_completed_finance          | ($order_number, $order)                                          | finance                     |
 * | csc_routing_offer_sent               | ($order_number, $master_id, $tg_message_id)                      | order-routing               |
 * | csc_routing_no_masters               | ($order_number, $order, $rejected_master_ids)                    | order-routing               |
 * | csc_tg_order_accepted                | ($order_number, $master_id, $chat_id)                            | telegram-bot                |
 * | csc_tg_order_rejected                | ($order_number, $master_id, $chat_id)                            | telegram-bot                |
 * | csc_tg_order_completed               | ($order_number, $master_id)                                      | telegram-bot                |
 * | csc_review_submitted                 | ($review_id, $order_number, $rating)                             | reviews                     |
 * | csc_status_change_logged             | ($order_number, $from, $to, $changed_by, $history_id)            | order-history               |
 * | csc_financial_event_logged           | ($order_number, $event_type, $data, $initiator, $history_id)     | order-history               |
 * | csc_cache_invalidated                | ($group) 'items'|'masters'|'orders'                              | core-functions              |
 * | csc_invalidate_ratings_cache         | ($master_id)                                                     | master-ratings              |
 * | csc_log_notification                 | ($recipient_type, $recipient_id, $title, $message, $order_number)| notification-triggers       |
 * | csc_reminder_sent                    | ($order_number, $target, $type)                                  | reminders                   |
 * | csc_backup_completed                 | ($file_path, $size)                                              | backup                      |
 * | csc_backup_rotated                   | ($deleted_files)                                                 | backup                      |
 * | csc_finance_payout / _refund / _penalty | ($transaction_id, $amount, $order_number ...)                 | finance                     |
 * | csc_finance_transaction_recorded     | ($transaction_id, $type, $amount, $order_number)                 | finance                     |
 * | csc_finance_entry_recorded           | ($debit_acc, $credit_acc, $amount, $order_number, $ref_id)       | finance                     |
 * | csc_finance_after_invoice_created    | ($invoice_id, $order_number)                                     | finance                     |
 * | csc_finance_statement_exported       | ($period, $summary)                                              | finance                     |
 * | csc_finance_daily_cron_completed     | ()                                                               | finance                     |
 * | csc_finance_fiscal_receipt_created   | ($receipt_id, $order_number, $type, $amount)                     | finance                     |
 * | csc_finance_fiscal_receipt_registered| ($receipt_id, $fiscal_doc_number)                                | finance                     |
 * | csc_finance_fiscal_receipt_failed    | ($receipt_id, $error_message)                                    | finance                     |
 * | csc_secret_option_encrypted          | ($option_name)                                                   | crypto                      |
 * | csc_token_encrypted                  | ($encrypted, $original)                                          | crypto                      |
 * | csc_db_installed                     | ()                                                               | main                        |
 * | csc_db_upgraded                      | ($from_version, $to_version)                                     | main                        |
 * | csc_plugin_activate                  | ()                                                               | main                        |
 * | csc_plugin_deactivate                | ()                                                               | main                        |
 * ======================================================================
 *
 * Зависимости: подключать ПОСЛЕДНИМ (после core-functions, order-history),
 * все вызовы защищены function_exists().
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. Инвалидация кэша заказов/мастеров при смене статуса заказа
//    Раньше события «вещались в пустоту» — подписчик добавлен здесь.
// ============================================================
add_action('csc_order_status_changed', function ($order_number, $old_status = '', $new_status = '', $context = '') {
    if (function_exists('csc_cache_delete_orders')) {
        csc_cache_delete_orders();
    }
    // Мастер-виджетам нужен актуальный список — инвалидируем и его
    if (function_exists('csc_cache_delete_masters')) {
        csc_cache_delete_masters();
    }
}, 10, 4);

add_action('csc_order_created', function ($order_number, $order_data = array()) {
    if (function_exists('csc_cache_delete_orders')) {
        csc_cache_delete_orders();
    }
}, 10, 2);

// ============================================================
// 2. Дублирующая страховка: любое событие маршрутизации также
//    инвалидирует кэш (список «свободных мастеров» меняется).
// ============================================================
$csc_routing_cache_listener = function () {
    if (function_exists('csc_cache_delete_masters')) {
        csc_cache_delete_masters();
    }
};
add_action('csc_routing_offer_sent', $csc_routing_cache_listener, 10, 3);
add_action('csc_routing_no_masters', $csc_routing_cache_listener, 10, 3);
add_action('csc_tg_order_accepted', $csc_routing_cache_listener, 10, 3);
add_action('csc_tg_order_rejected', $csc_routing_cache_listener, 10, 3);

// ============================================================
// 3. Telegram-принятие/отклонение -> запись в историю статусов.
//    Раньше эти события не логировались в csc_order_history.
// ============================================================
add_action('csc_tg_order_accepted', function ($order_number, $master_id, $chat_id = '') {
    if (function_exists('csc_log_status_change')) {
        csc_log_status_change($order_number, 'ожидает', 'в работе', 'telegram', 'master:' . $master_id, 'Принят мастером через Telegram');
    }
}, 10, 3);

add_action('csc_tg_order_rejected', function ($order_number, $master_id, $chat_id = '') {
    if (function_exists('csc_log_status_change')) {
        csc_log_status_change($order_number, 'ожидает', 'ожидает', 'telegram', 'master:' . $master_id, 'Отклонён мастером через Telegram');
    }
}, 10, 3);

// ============================================================
// 4. Сброс флага напоминания при переносе даты/времени заказа.
//    (csc_reset_reminder_flag — существующая функция модуля reminders;
//     отдельной функции «перепланирования» в проекте нет, напоминания
//     обрабатываются общим кроном csc_process_reminders.)
// ============================================================
add_action('csc_order_datetime_changed', function ($order_number, $old_dt = '', $new_dt = '') {
    if (function_exists('csc_reset_reminder_flag')) {
        csc_reset_reminder_flag($order_number);
    }
}, 10, 3);

// ============================================================
// 5. Уведомление администратору, если мастер не найден.
//    Без слушателя заказ мог «зависнуть» незамеченным.
// ============================================================
add_action('csc_routing_no_masters', function ($order_number, $order = array(), $rejected = array()) {
    if (!function_exists('csc_send_admin_telegram')) {
        return;
    }
    $phone = is_array($order) ? ($order['phone'] ?? '') : '';
    csc_send_admin_telegram(sprintf(
        "⚠️ Заказ %s (%s): ни один мастер не принял заявку.\nТребуется ручное распределение.",
        $order_number,
        $phone ? esc_html($phone) : 'без телефона'
    ));
}, 10, 3);

// ============================================================
// 6. Отправка push-уведомления клиенту при создании заказа
//    (если у клиента есть сохранённая web-push подписка).
// ============================================================
add_action('csc_order_created', function ($order_number, $order_data = array()) {
    if (!is_array($order_data)) {
        return;
    }
    $sub = $order_data['push_subscription'] ?? '';
    if (empty($sub) || !function_exists('csc_send_web_push_notification')) {
        return;
    }
    csc_send_web_push_notification(
        $sub,
        'Заявка принята',
        'Заказ ' . $order_number . ' зарегистрирован. Статус придёт в Telegram/e-mail.',
        $order_number,
        'client',
        $order_data['phone'] ?? ''
    );
}, 20, 2);

// ============================================================
// 7. Сброс object-cache WordPress при явной инвалидации.
// ============================================================
add_action('csc_cache_invalidated', function ($group = '') {
    wp_cache_set('csc_' . sanitize_key((string) $group) . '_version', microtime(true));
}, 10, 1);
