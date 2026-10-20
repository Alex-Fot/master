<?php
/**
 * Файл: csc-notification-triggers.php
 * Версия: 1.0
 *
 * Централизованный модуль триггеров уведомлений.
 * Слушает события плагина и автоматически:
 *   1. Пишет в лог csc_notifications (для истории)
 *   2. Пишет в внутрикабинетное хранилище (для модалки мастера/клиента)
 *   3. Отправляет Web Push (если есть подписка)
 *   4. Отправляет Telegram мастеру (если настроено)
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// ВСПОМОГАТЕЛЬНАЯ: Запись уведомления в внутрикабинетное хранилище
// Это то, что видит мастер/клиент в модалке на сайте.
// ============================================================
if (!function_exists('csc_push_to_in_cabinet')) {
    function csc_push_to_in_cabinet($recipient_type, $recipient_id, $payload) {
        if (empty($recipient_type) || empty($recipient_id)) return false;
        
        $option_key = 'csc_in_cabinet_' . $recipient_type . '_' . $recipient_id;
        $notifications = get_option($option_key, []);
        if (!is_array($notifications)) $notifications = [];
        
        // Добавляем в начало
        array_unshift($notifications, [
            'id'         => 'n_' . wp_generate_password(10, false),
            'type'       => $payload['type'] ?? 'info',
            'title'      => $payload['title'] ?? '',
            'message'    => $payload['message'] ?? '',
            'order'      => $payload['order_number'] ?? '',
            'icon'       => $payload['icon'] ?? '📩',
            'data'       => $payload['data'] ?? [],
            'read'       => false,
            'created_at' => current_time('mysql'),
        ]);
        
        // Храним максимум 100 штук
        $notifications = array_slice($notifications, 0, 100);
        update_option($option_key, $notifications, false);
        
        // Web Push (если браузер не открыт)
        if (function_exists('csc_send_web_push_notification')) {
            $subs_key = 'csc_push_subs_' . $recipient_type . '_' . $recipient_id;
            $subs = get_option($subs_key, []);
            if (is_array($subs) && !empty($subs)) {
                foreach ($subs as $sub) {
                    csc_send_web_push_notification(
                        is_array($sub) ? wp_json_encode($sub) : $sub,
                        $payload['title'] ?? 'Уведомление',
                        $payload['message'] ?? '',
                        $payload['order_number'] ?? '',
                        $recipient_type,
                        $recipient_id
                    );
                }
            }
        }
        
        return true;
    }
}

// ============================================================
// TELEGRAM-ОТПРАВКИ (реализация функций, вызываемых триггерами)
// ============================================================

if (!function_exists('csc_send_telegram_to_master')) {
    /**
     * Отправка простого HTML-сообщения в Telegram мастеру.
     * Интегрировано с логированием уведомлений csc_send_tg_notification().
     *
     * @param string $chat_id Chat ID мастера (поле tg_chat_id у мастера)
     * @param string $text    Текст сообщения (HTML)
     * @return bool
     */
    function csc_send_telegram_to_master($chat_id, $text, $order_number = '') {
        if (empty($chat_id) || empty($text)) return false;

        // Логируем через единый конвейер, если доступна функция и известен заказ
        if (function_exists('csc_send_tg_notification') && !empty($order_number)) {
            $parts = explode("\n", $text, 2);
            $title = trim(strip_tags($parts[0]));
            $body  = $parts[1] ?? '';
            return csc_send_tg_notification(
                $chat_id, $title, $body, $order_number, 'master', $chat_id, null, 'HTML'
            );
        }

        // Прямая отправка без логирования (фолбэк)
        if (!function_exists('csc_tg_api_request')) return false;
        $result = csc_tg_api_request('sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
        return !empty($result['ok']);
    }
}

if (!function_exists('csc_send_admin_telegram')) {
    /**
     * Отправка HTML-сообщения администратору (чат из общих настроек).
     *
     * @param string $text Текст сообщения (HTML)
     * @return bool
     */
    function csc_send_admin_telegram($text) {
        if (empty($text)) return false;
        $s       = function_exists('csc_get_settings_cached') ? csc_get_settings_cached() : get_option('csc_main_settings', []);
        $chat_id = $s['telegram_chat_id'] ?? '';
        if (empty($chat_id)) return false;

        if (!function_exists('csc_tg_api_request')) return false;
        $result = csc_tg_api_request('sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
        return !empty($result['ok']);
    }
}

// ============================================================
// URL ДАШБОРДА МАСТЕРА (реализация функции, вызываемой из csc-push.php)
// ============================================================
if (!function_exists('csc_get_master_dashboard_url')) {
    /**
     * Ссылка на кабинет мастера с одноразовым токеном (24 ч),
     * как в Telegram-интеграции (csc_tg_get_dashboard_link).
     *
     * @param string $master_id ID мастера
     * @return string URL дашборда (или home_url, если мастер не найден)
     */
    function csc_get_master_dashboard_url($master_id) {
        if (empty($master_id)) return esc_url(home_url('/'));

        // Предпочитаем стандартный генератор одноразовых ссылок
        if (function_exists('csc_tg_get_dashboard_link')) {
            return csc_tg_get_dashboard_link($master_id);
        }

        // Фолбэк: самостоятельная генерация одноразового токена
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        if (empty($masters[$master_id])) {
            return esc_url(home_url('/'));
        }
        $one_time_token = wp_generate_password(32, false);
        set_transient('csc_dash_token_' . md5($one_time_token), $master_id, DAY_IN_SECONDS);

        return add_query_arg([
            'csc_master_dashboard' => '1',
            'mid'   => $master_id,
            'token' => $one_time_token,
        ], home_url('/'));
    }
}

// ============================================================
// ВСПОМОГАТЕЛЬНАЯ: Уведомление мастеру (кабинет + Telegram + push)
// ============================================================
if (!function_exists('csc_notify_master')) {
    function csc_notify_master($master_id, $title, $message, $order_number = '', $type = 'info', $icon = '📩') {
        if (empty($master_id)) return;
        
        // 1. Внутрикабинетное + push
        csc_push_to_in_cabinet('master', $master_id, [
            'type'         => $type,
            'title'        => $title,
            'message'      => $message,
            'order_number' => $order_number,
            'icon'         => $icon,
        ]);
        
        // 2. Telegram (если настроен и не выключен)
        // ИСПРАВЛЕНО: у мастеров chat ID хранится в поле tg_chat_id
        // (ранее читалось несуществующее telegram_chat_id — пуши молча не отправлялись)
        if (function_exists('csc_send_telegram_to_master')) {
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
            $master  = $masters[$master_id] ?? [];
            $chat_id = $master['tg_chat_id'] ?? ($master['telegram_chat_id'] ?? '');
            if (!empty($chat_id)) {
                $text = "📩 <b>{$title}</b>\n\n{$message}";
                if ($order_number) $text .= "\n\nЗаказ: <code>{$order_number}</code>";
                csc_send_telegram_to_master($chat_id, $text, $order_number);
            }
        }
    }
}

// ============================================================
// ВСПОМОГАТЕЛЬНАЯ: Уведомление клиенту (кабинет + push)
// ============================================================
if (!function_exists('csc_notify_customer')) {
    function csc_notify_customer($phone, $title, $message, $order_number = '', $type = 'info', $icon = '📩') {
        if (empty($phone)) return;
        
        $phone_norm = function_exists('csc_normalize_phone') ? csc_normalize_phone($phone) : preg_replace('/\D/', '', $phone);
        if (empty($phone_norm)) return;
        
        csc_push_to_in_cabinet('customer', $phone_norm, [
            'type'         => $type,
            'title'        => $title,
            'message'      => $message,
            'order_number' => $order_number,
            'icon'         => $icon,
        ]);
    }
}

// ============================================================
// ТРИГГЕР 1: Создана новая заявка
// ============================================================
add_action('csc_order_created', 'csc_trigger_order_created', 10, 2);
if (!function_exists('csc_trigger_order_created')) {
    function csc_trigger_order_created($order_number, $order) {
        if (!$order) return;
        $name  = $order['name'] ?? 'Клиент';
        $total = floatval($order['total'] ?? 0);
        $addr  = $order['address'] ?? '';
        $date  = ($order['date'] ?? '') . ' ' . ($order['time'] ?? '');
        $phone = $order['phone'] ?? '';
        
        // 1. Админу (через Telegram админа — он уже работает)
        if (function_exists('csc_send_admin_telegram')) {
            $text = "🆕 <b>Новая заявка #{$order_number}</b>\n\n";
            $text .= "👤 {$name} ({$phone})\n";
            $text .= "📅 {$date}\n";
            if ($addr) $text .= "📍 {$addr}\n";
            $text .= "💰 " . number_format($total, 0, '.', ' ') . " ₽";
            csc_send_admin_telegram($text);
        }
        
        // 2. Уведомляем всех активных мастеров о новой заявке
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        foreach ($masters as $mid => $m) {
            if (($m['status'] ?? '') !== 'active') continue;
            csc_notify_master(
                $mid,
                'Новая заявка',
                "Поступил заказ #{$order_number} от {$name} на сумму " . number_format($total, 0, '.', ' ') . " ₽. Дата: {$date}",
                $order_number,
                'new_order',
                '🆕'
            );
        }
    }
}

// ============================================================
// ТРИГГЕР 2: Смена статуса заявки
// ============================================================
add_action('csc_order_status_changed', 'csc_trigger_status_changed', 10, 6);
if (!function_exists('csc_trigger_status_changed')) {
    function csc_trigger_status_changed($order_number, $from, $to, $changed_by, $changed_by_id, $reason) {
        $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if (!$order) return;
        
        $phone       = $order['phone'] ?? '';
        $client_name = $order['name'] ?? 'Клиент';
        $master_id   = $order['master_id'] ?? '';
        
        switch ($to) {
            case 'в работе':
                csc_notify_customer($phone,
                    'Заказ в работе',
                    "Ваш заказ #{$order_number} принят в работу. Мастер выехал к вам.",
                    $order_number, 'status_change', '🔧'
                );
                if ($master_id) {
                    csc_notify_master($master_id,
                        'Заказ в работе',
                        "Вы взяли заказ #{$order_number} ({$client_name}) в работу.",
                        $order_number, 'status_change', '🔧'
                    );
                }
                break;
                
            case 'выполнено':
            case 'завершено':
                $total = floatval($order['total'] ?? 0);
                csc_notify_customer($phone,
                    'Заказ выполнен',
                    "Ваш заказ #{$order_number} выполнен на сумму " . number_format($total, 0, '.', ' ') . " ₽. Будем рады вашему отзыву!",
                    $order_number, 'completed', '✅'
                );
                if ($master_id) {
                    $income = floatval($order['master_income'] ?? max(0, $total - floatval($order['commission'] ?? 0)));
                    csc_notify_master($master_id,
                        'Заказ выполнен',
                        "Заказ #{$order_number} выполнен. Ваш доход: " . number_format($income, 0, '.', ' ') . " ₽.",
                        $order_number, 'completed', '✅'
                    );
                }
                // Финансовый триггер
                do_action('csc_order_completed_finance', $order_number, $order);
                break;
                
            case 'отменено':
                $cancel_reason = $reason ?: 'причина не указана';
                csc_notify_customer($phone,
                    'Заказ отменён',
                    "Ваш заказ #{$order_number} отменён. Причина: {$cancel_reason}",
                    $order_number, 'cancelled', '❌'
                );
                if ($master_id) {
                    csc_notify_master($master_id,
                        'Заказ отменён',
                        "Заказ #{$order_number} ({$client_name}) отменён. Причина: {$cancel_reason}",
                        $order_number, 'cancelled', '❌'
                    );
                }
                break;
                
            case 'назначен':
                if ($master_id) {
                    $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
                    $mname = $masters[$master_id]['name'] ?? 'Мастер';
                    csc_notify_customer($phone,
                        'Мастер назначен',
                        "На заказ #{$order_number} назначен мастер {$mname}.",
                        $order_number, 'assigned', '👷'
                    );
                    csc_notify_master($master_id,
                        'Вам назначен заказ',
                        "Вам назначен заказ #{$order_number} от клиента {$client_name}.",
                        $order_number, 'assigned', '👷'
                    );
                }
                break;
        }
    }
}

// ============================================================
// ТРИГГЕР 3: Отзыв от клиента
// ============================================================
add_action('csc_review_submitted', 'csc_trigger_review_submitted', 10, 3);
if (!function_exists('csc_trigger_review_submitted')) {
    function csc_trigger_review_submitted($order_number, $review, $order = null) {
        if (!$order) {
            $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        }
        if (!$order) return;
        
        $master_id = $order['master_id'] ?? '';
        if (empty($master_id)) return;
        
        $rating = intval($review['rating'] ?? 0);
        $stars  = str_repeat('⭐', $rating);
        $comment = $review['comment'] ?? '';
        $photos_count = count($review['photos'] ?? []);
        
        $msg = "Оценка: {$stars} ({$rating}/5)";
        if ($comment) $msg .= "\nКомментарий: {$comment}";
        if ($photos_count > 0) $msg .= "\n📷 Прикреплено фото: {$photos_count}";
        
        csc_notify_master($master_id,
            'Новый отзыв',
            $msg,
            $order_number,
            'review',
            '⭐'
        );
    }
}

// ============================================================
// ТРИГГЕР 4: Штраф мастеру
// ============================================================
add_action('csc_finance_penalty', 'csc_trigger_penalty', 10, 4);
if (!function_exists('csc_trigger_penalty')) {
    function csc_trigger_penalty($master_id, $amount, $reason, $order_number) {
        csc_notify_master(
            $master_id,
            'Штраф начислен',
            "Вам начислен штраф " . number_format($amount, 0, '.', ' ') . " ₽" . ($reason ? ": {$reason}" : ''),
            $order_number,
            'penalty',
            '⚠️'
        );
    }
}

// ============================================================
// ТРИГГЕР 5: Выплата мастеру
// ============================================================
add_action('csc_finance_payout', 'csc_trigger_payout', 10, 4);
if (!function_exists('csc_trigger_payout')) {
    function csc_trigger_payout($master_id, $amount, $method, $description) {
        $methods = ['cash'=>'Наличные','transfer'=>'Перевод','sbp'=>'СБП','card'=>'На карту'];
        $m_label = $methods[$method] ?? $method;
        
        csc_notify_master(
            $master_id,
            'Выплата отправлена',
            "Вам отправлена выплата " . number_format($amount, 0, '.', ' ') . " ₽ способом: {$m_label}",
            '',
            'payout',
            '💸'
        );
    }
}

// ============================================================
// ТРИГГЕР 6: Уведомление админу (дублирование в Telegram админа)
// ============================================================
add_action('csc_log_notification', 'csc_trigger_admin_duplication', 10, 7);
if (!function_exists('csc_trigger_admin_duplication')) {
    function csc_trigger_admin_duplication($order, $type, $recipient_id, $channel, $title, $message, $data) {
        // Не дублируем то, что уже отправлено админу (чтобы не спамить)
        if ($recipient_id === 'admin' || $channel === 'admin_tg') return;
        
        // Можно расширить: например, только при важных событиях
    }
}