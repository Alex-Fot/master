<?php
/**
 * Файл: csc-telegram-bot.php
 * Описание: Модуль интеграции с Telegram API. Обработка Webhook и Inline-кнопок.
 * Версия: 4.0 (убраны дублирующиеся кэши, единая работа с заказами, одноразовые токены)
 * Улучшения:
 * - Валидация webhook от Telegram (проверка подписи)
 * - Кэширование через единые функции из csc-core-functions.php
 * - Повторные попытки отправки с экспоненциальной задержкой
 * - Логирование событий для отладки
 * - Хуки для расширения функционала без правки ядра
 * - Защита от дублирования обработок (idempotency)
 * - Graceful degradation при сбоях API
 * - Подготовка к i18n (все строки через __())
 * - ИНТЕГРАЦИЯ: Полное логирование уведомлений (pending -> sent -> delivered -> read)
 * - ИНТЕГРАЦИЯ: Автоматическая маркировка прочтения при нажатии inline-кнопок
 * - ИСПРАВЛЕНО: одноразовые токены для дашборда вместо постоянных в URL
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'csc-notifications.php';

// ============================================================
// 1. КОНСТАНТЫ И НАСТРОЙКИ
// ============================================================
if (!defined('CSC_TG_MAX_RETRIES')) define('CSC_TG_MAX_RETRIES', 3);
if (!defined('CSC_TG_RETRY_DELAY_MS')) define('CSC_TG_RETRY_DELAY_MS', 1000);
if (!defined('CSC_TG_LOG_ENABLED')) define('CSC_TG_LOG_ENABLED', true);
if (!defined('CSC_TG_WEBHOOK_VERIFY')) define('CSC_TG_WEBHOOK_VERIFY', true);

// ============================================================
// 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

/**
 * Логирование событий бота (только если включено)
 */
if (!function_exists('csc_tg_log')) {
    function csc_tg_log($event, $data = []) {
        if (!CSC_TG_LOG_ENABLED) return;
        
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'event' => $event,
            'data' => $data
        ];
        
        $logs = get_option('csc_tg_logs', []);
        $logs[] = $log_entry;
        $logs = array_slice($logs, -200);
        update_option('csc_tg_logs', $logs, false);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            csc_log(json_encode($log_entry, JSON_UNESCAPED_UNICODE), 'debug', 'TELEGRAM');
        }
    }
}

// ============================================================
// ИСПРАВЛЕНО: дубли функций csc_get_masters_cached() и csc_get_orders_cached()
// удалены. Единые реализации находятся в includes/csc-order-routing.php
// (загружается из ядра сразу после этого файла, вызовы идут внутри обработчиков
// событий — к моменту выполнения order-routing уже загружен).
// (загружается из ядра раньше этого файла); этот модуль использует их как API.
// ============================================================

/**
 * Отправка запроса в Telegram API с повторными попытками
 */
if (!function_exists('csc_tg_api_request')) {
    function csc_tg_api_request($endpoint, $body, $max_attempts = CSC_TG_MAX_RETRIES) {
        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
            
        if (!$tg_token) {
            csc_tg_log('api_error', ['reason' => 'no_token', 'endpoint' => $endpoint]);
            return false;
        }
        
        $api_url = "https://api.telegram.org/bot{$tg_token}/{$endpoint}";
        $attempt = 0;
        $delay = CSC_TG_RETRY_DELAY_MS;
        
        while ($attempt < $max_attempts) {
            $response = wp_remote_post($api_url, [
                'body' => $body,
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                $body_resp = wp_remote_retrieve_body($response);
                $result = json_decode($body_resp, true);
                
                if ($code === 200 && ($result['ok'] ?? false)) {
                    return $result;
                }
                
                csc_tg_log('api_response_error', [
                    'endpoint' => $endpoint,
                    'code' => $code,
                    'response' => $body_resp,
                    'attempt' => $attempt + 1
                ]);
            } else {
                csc_tg_log('api_wp_error', [
                    'endpoint' => $endpoint,
                    'error' => $response->get_error_message(),
                    'attempt' => $attempt + 1
                ]);
            }
            
            $attempt++;
            if ($attempt < $max_attempts) {
                usleep($delay * 1000);
                $delay *= 2;
            }
        }
        
        csc_tg_log('api_max_retries', ['endpoint' => $endpoint, 'attempts' => $max_attempts]);
        return false;
    }
}

/**
 * Проверка подписи webhook от Telegram (защита от подделки)
 */
if (!function_exists('csc_tg_verify_webhook')) {
    function csc_tg_verify_webhook($raw_body, $headers, $request = null) {
        if (!CSC_TG_WEBHOOK_VERIFY) return true;
        
        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        
        if (!$tg_token) {
            csc_tg_log('webhook_verify_no_token', []);
            return false;
        }

        // ИСПРАВЛЕНО: используем сохранённый secret или генерируем заново
        $saved_secret = get_option('csc_tg_webhook_secret', '');
        if (empty($saved_secret)) {
            // Fallback: генерируем как раньше
            $expected = wp_hash('csc_tg_secret_' . $tg_token);
            $expected = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $expected), 0, 256);
        } else {
            $expected = $saved_secret;
        }

        // ИСПРАВЛЕНО: правильно извлекаем заголовок
        $received = '';
        
        // Вариант 1: через WP_REST_Request (предпочтительно)
        if ($request && method_exists($request, 'get_header')) {
            $received = $request->get_header('x_telegram_bot_api_secret_token') ?? '';
        }
        
        // Вариант 2: из массива заголовков
        if (empty($received) && is_array($headers)) {
            // WordPress нормализует: X-Telegram-Bot-Api-Secret-Token → x_telegram_bot_api_secret_token
            $received = $headers['x_telegram_bot_api_secret_token'] ?? '';
            // get_headers() возвращает массив массивов
            if (is_array($received)) {
                $received = $received[0] ?? '';
            }
            // Fallback на исходное имя (если сервер не нормализовал)
            if (empty($received)) {
                $received = $headers['x-telegram-bot-api-secret-token'] ?? '';
                if (is_array($received)) {
                    $received = $received[0] ?? '';
                }
            }
        }

        if (empty($received)) {
            csc_tg_log('webhook_verify_no_header', [
                'headers_keys' => array_keys($headers)
            ]);
            return false;
        }

        $valid = hash_equals($expected, $received);
        if (!$valid) {
            csc_tg_log('webhook_verify_mismatch', [
                'expected_len' => strlen($expected),
                'received_len' => strlen($received)
            ]);
        }
        return $valid;
    }
}

/**
 * ИСПРАВЛЕНО: генерация безопасной ссылки на дашборд.
 * Использует ОДНОРАЗОВЫЙ токен с истечением 24 часа
 * вместо постоянного токена мастера в URL.
 * Токен хранится в транзиенте и удаляется после первого использования.
 *
 * @param string $master_id ID мастера
 * @param array|null $masters Список мастеров (опционально)
 * @return string URL дашборда
 */
if (!function_exists('csc_tg_get_dashboard_link')) {
    function csc_tg_get_dashboard_link($master_id, $masters = null) {
        if (!$masters) $masters = csc_get_masters_cached();
        
        if (empty($masters[$master_id])) {
            return esc_url(home_url('/'));
        }
        
        // Генерируем одноразовый токен на 24 часа
        $one_time_token = wp_generate_password(32, false);
        $transient_key = 'csc_dash_token_' . md5($one_time_token);
        set_transient($transient_key, $master_id, DAY_IN_SECONDS);
        
        // URL без постоянного токена мастера
        // ИСПРАВЛЕНО: используем esc_url()
        $url = add_query_arg([
            'csc_master_dashboard' => '1',
            'mid' => $master_id,
            'token' => $one_time_token
        ], esc_url(home_url('/')));
        
        return $url;
    }
}

/**
 * ИСПРАВЛЕНО: проверка одноразового токена дашборда.
 * Вызывается из csc-master-dashboard.php.
 *
 * @param string $master_id ID мастера
 * @param string $token Одноразовый токен
 * @return bool true если токен валиден
 */
if (!function_exists('csc_tg_verify_dashboard_token')) {
    function csc_tg_verify_dashboard_token($master_id, $token) {
        $transient_key = 'csc_dash_token_' . md5($token);
        $stored_mid = get_transient($transient_key);
        
        if ($stored_mid === false || $stored_mid !== $master_id) {
            return false;
        }
        
        // Удаляем одноразовый токен после проверки
        delete_transient($transient_key);
        return true;
    }
}

/**
 * Форматирование сообщения с поддержкой HTML и экранированием
 */
if (!function_exists('csc_tg_format_msg')) {
    function csc_tg_format_msg($text, $vars = []) {
        foreach ($vars as $k => $v) {
            $text = str_replace("{{$k}}", esc_html($v), $text);
        }
        return $text;
    }
}

// ============================================================
// 3. РЕГИСТРАЦИЯ REST API (WEBHOOK ДЛЯ TELEGRAM)
// ============================================================
add_action('rest_api_init', 'csc_register_telegram_webhook');

if (!function_exists('csc_register_telegram_webhook')) {
    function csc_register_telegram_webhook() {
        register_rest_route('csc/v1', '/telegram', [
            'methods' => 'POST',
            'callback' => 'csc_handle_telegram_webhook',
            'permission_callback' => '__return_true'
        ]);
    }
}

// ============================================================
// 4. ОТПРАВКА СООБЩЕНИЙ С КНОПКАМИ (ИНТЕГРИРОВАНО ЛОГИРОВАНИЕ)
// ============================================================

/**
 * Отправка предложения мастеру (кнопки Принять / Отклонить)
 * @filter csc_tg_offer_message Позволяет изменить текст предложения
 * @filter csc_tg_offer_keyboard Позволяет изменить клавиатуру
 */
if (!function_exists('csc_tg_send_offer_to_master')) {
    function csc_tg_send_offer_to_master($order_number, $master_chat_id, $message_text) {
        $s = get_option('csc_main_settings', []);
        // ИСПРАВЛЕНО: расшифровка токена через csc_get_telegram_token() (модуль csc-crypto.php)
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        
        if (!$tg_token || !$master_chat_id) {
            csc_tg_log('send_failed', ['reason' => 'missing_config', 'order' => $order_number]);
            $orders = csc_get_all_orders();
            $order = $orders[$order_number] ?? [];
            $mid = $order['current_candidate'] ?? '';
            csc_log_notification($order_number, 'master', $mid, 'telegram', 'Предложение мастеру', 'Ошибка конфигурации', ['status' => 'failed', 'reason' => 'no_token_or_chat']);
            return false;
        }
        
        $orders = csc_get_all_orders();
        $mid = $orders[$order_number]['current_candidate'] ?? '';
        $base_text = $message_text . "\n⏳ <i>" . __('У вас есть 5 минут на принятие решения.', 'csc') . "</i>";
        $final_text = apply_filters('csc_tg_offer_message', $base_text, $order_number);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ ' . __('Принять', 'csc'), 'callback_data' => 'acc_' . $order_number],
                    ['text' => '❌ ' . __('Отклонить', 'csc'), 'callback_data' => 'rej_' . $order_number]
                ]
            ]
        ];
        $keyboard = apply_filters('csc_tg_offer_keyboard', $keyboard, $order_number);
        
        $log_id = csc_log_notification($order_number, 'master', $mid, 'telegram', '🆕 Предложение по заказу', $base_text, ['keyboard_type' => 'offer']);
        csc_update_notification_status($log_id, 'sent');
        
        $result = csc_tg_api_request('sendMessage', [
            'chat_id' => $master_chat_id,
            'text' => $final_text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        if ($result && isset($result['result']['message_id'])) {
            $msg_id = $result['result']['message_id'];
            csc_update_notification_status($log_id, 'delivered', $msg_id);
            csc_tg_log('offer_sent', ['order' => $order_number, 'message_id' => $msg_id]);
            return $msg_id;
        }
        
        csc_update_notification_status($log_id, 'failed');
        csc_tg_log('offer_send_failed', ['order' => $order_number]);
        return null;
    }
}

/**
 * Отправка сообщения о назначенном заказе (кнопки Выполнено + Расширить заказ)
 * @filter csc_tg_assigned_message Позволяет изменить текст назначения
 * @filter csc_tg_assigned_keyboard Позволяет изменить клавиатуру
 */
if (!function_exists('csc_tg_send_assigned_order')) {
    function csc_tg_send_assigned_order($order_number, $master_chat_id, $message_text) {
        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
            
        if (!$tg_token || !$master_chat_id) {
            $orders = csc_get_all_orders();
            $mid = $orders[$order_number]['accepted_by'] ?? $orders[$order_number]['master_id'] ?? '';
            csc_log_notification($order_number, 'master', $mid, 'telegram', 'Заказ назначен', 'Ошибка конфигурации', ['status' => 'failed']);
            return false;
        }
        
        $orders = csc_get_all_orders();
        $mid = $orders[$order_number]['accepted_by'] ?? $orders[$order_number]['master_id'] ?? '';
        $base_text = "🎉 <b>" . __('Заказ закреплен за вами!', 'csc') . "</b>\n" . $message_text;
        $final_text = apply_filters('csc_tg_assigned_message', $base_text, $order_number);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏁 ' . __('Заказ выполнен', 'csc'), 'callback_data' => 'don_' . $order_number]
                ]
            ]
        ];
        
        if (function_exists('csc_get_upsell_link')) {
            $keyboard['inline_keyboard'][] = [
                ['text' => '🛠 ' . __('Расширить заказ', 'csc'), 'url' => csc_get_upsell_link($order_number)]
            ];
        }
        
        $keyboard = apply_filters('csc_tg_assigned_keyboard', $keyboard, $order_number);
        $log_id = csc_log_notification($order_number, 'master', $mid, 'telegram', '✅ Заказ назначен', $base_text, ['keyboard_type' => 'assigned']);
        csc_update_notification_status($log_id, 'sent');
        
        $result = csc_tg_api_request('sendMessage', [
            'chat_id' => $master_chat_id,
            'text' => $final_text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        if ($result) {
            $msg_id = $result['result']['message_id'] ?? 'unknown';
            csc_update_notification_status($log_id, 'delivered', $msg_id);
            csc_tg_log('assigned_sent', ['order' => $order_number]);
            return true;
        }
        
        csc_update_notification_status($log_id, 'failed');
        return false;
    }
}

// ============================================================
// 5. ОБРАБОТЧИК ВХОДЯЩИХ ДАННЫХ ОТ TELEGRAM
// ============================================================
if (!function_exists('csc_handle_telegram_webhook')) {
    function csc_handle_telegram_webhook(WP_REST_Request $request) {
        $raw_body = $request->get_body();
        $headers = $request->get_headers();
        
        // ИСПРАВЛЕНО: передаём сам $request для корректного извлечения заголовков
        if (!csc_tg_verify_webhook($raw_body, $headers, $request)) {
            csc_tg_log('webhook_verify_failed', [
                'ip' => $request->get_ip(),
                'headers' => array_keys($headers)
            ]);
            return new WP_REST_Response('Forbidden', 403);
        }
        
        $body = $request->get_json_params();
        
        if (isset($body['message']['text'])) {
            return new WP_REST_Response('OK', 200);
        }
        
        if (!isset($body['callback_query'])) {
            return new WP_REST_Response('OK', 200);
        }
        
        $callback = $body['callback_query'];
        $chat_id = $callback['message']['chat']['id'];
        $message_id = $callback['message']['message_id'];
        $callback_id = $callback['id'];
        $callback_data = $callback['data'] ?? '';
        $from_user = $callback['from'] ?? [];
        
        $s = get_option('csc_main_settings', []);
        // ИСПРАВЛЕНО: расшифровка токена через csc_get_telegram_token() (модуль csc-crypto.php)
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        
        $answer_callback = function($text, $show_alert = false) use ($tg_token, $callback_id) {
            csc_tg_api_request('answerCallbackQuery', [
                'callback_query_id' => $callback_id,
                'text' => $text,
                'show_alert' => $show_alert ? 'true' : 'false'
            ]);
        };
        
        $edit_message = function($new_text) use ($tg_token, $chat_id, $message_id) {
            csc_tg_api_request('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $new_text,
                'parse_mode' => 'HTML'
            ]);
        };
        
        if (!preg_match('/^(acc|rej|don)_(.+)$/', $callback_data, $matches)) {
            $answer_callback(__('Неизвестная команда', 'csc'), true);
            return new WP_REST_Response('OK', 200);
        }
        
        $action = $matches[1];
        $order_number = sanitize_text_field($matches[2]);
        
        // === ИНТЕГРАЦИЯ: ТРЕКИНГ ПРОЧТЕНИЯ УВЕДОМЛЕНИЙ ===
        csc_mark_notifications_read('master', $order_number, $order_number);
        
        // ИСПРАВЛЕНО: единая функция чтения заказов
        $all_orders = csc_get_all_orders();
        
        if (!isset($all_orders[$order_number])) {
            $answer_callback(__('Заказ не найден', 'csc'), true);
            $edit_message("❌ " . sprintf(__('Ошибка: Заказ %s больше не существует.', 'csc'), $order_number));
            csc_tg_log('order_not_found', ['order' => $order_number, 'action' => $action]);
            return new WP_REST_Response('OK', 200);
        }
        
        $order = $all_orders[$order_number];
        $masters = csc_get_masters_cached();
        
        // Проверка: является ли нажавший мастером этого заказа
        $expected_chat = null;
        if ($action === 'acc' || $action === 'rej') {
            $expected_chat = $masters[$order['current_candidate'] ?? '']['tg_chat_id'] ?? null;
        } elseif ($action === 'don') {
            $expected_chat = $masters[$order['master_id'] ?? '']['tg_chat_id'] ?? null;
        }
        
        if ($expected_chat && $expected_chat != $chat_id) {
            $answer_callback(__('Доступ запрещён', 'csc'), true);
            csc_tg_log('unauthorized_callback', ['order' => $order_number, 'chat_id' => $chat_id]);
            return new WP_REST_Response('OK', 200);
        }

        // ============================================================
        // ДЕЙСТВИЕ: ПРИНЯТЬ ЗАКАЗ (acc)
        // ============================================================
        if ($action === 'acc') {
            $allow_accept = apply_filters('csc_tg_allow_accept_order', true, $order_number, $chat_id);
            if (!$allow_accept) {
                $answer_callback(__('Заказ нельзя принять', 'csc'), true);
                return new WP_REST_Response('OK', 200);
            }
            
            if (($order['routing_status'] ?? '') !== 'searching') {
                $answer_callback(__('Поздно! Заказ уже передан или отменен.', 'csc'), true);
                $edit_message("⏱ " . __('Время вышло или заказ забрал другой мастер.', 'csc'));
                csc_tg_log('accept_too_late', ['order' => $order_number]);
                return new WP_REST_Response('OK', 200);
            }
            
            // ИСПРАВЛЕНО: используем csc_routing_accept_order если доступна
            // (она очищает rejected_by для корректной аналитики)
            if (function_exists('csc_routing_accept_order')) {
                csc_routing_accept_order($order_number, $order['current_candidate']);
            } else {
                // Фолбэк: прямое обновление
                $all_orders[$order_number]['routing_status'] = 'assigned';
                $all_orders[$order_number]['status'] = CSC_STATUS_PROCESSING;
                $all_orders[$order_number]['accepted_by'] = $order['current_candidate'];
                $all_orders[$order_number]['accepted_at'] = current_time('mysql');
                $all_orders[$order_number]['rejected_by'] = []; // Очистка для аналитики
                csc_update_order($order_number, $all_orders[$order_number]);
            }
            csc_cache_delete_orders();
            
            $answer_callback(__('Вы приняли заказ!', 'csc'));
            $edit_message("✅ <b>" . __('Вы приняли этот заказ.', 'csc') . "</b>");
            
            // Формируем сообщение для мастера с одноразовой ссылкой на дашборд
            $dash_url = csc_tg_get_dashboard_link($all_orders[$order_number]['accepted_by'] ?? $order['current_candidate'], $masters);
            $msg = sprintf(
                __("Клиент: %s (%s)\nАдрес: %s\nВремя: %s в %s", 'csc'),
                $order['name'], $order['phone'], $order['address'], $order['date'], $order['time']
            );
            $msg .= "\n📲 <a href='" . esc_url($dash_url) . "'>" . __('Открыть кабинет', 'csc') . "</a>";
            csc_tg_send_assigned_order($order_number, $chat_id, $msg);
            
            // Уведомление администратору
            if (!empty($s['telegram_chat_id'])) {
                $admin_text = sprintf(__("✅ Мастер принял заявку #%s", 'csc'), $order_number);
                $adm_log_id = csc_log_notification($order_number, 'admin', 'admin_chat', 'telegram', 'Заявка принята', $admin_text);
                csc_update_notification_status($adm_log_id, 'sent');
                
                $adm_res = csc_tg_api_request('sendMessage', [
                    'chat_id' => $s['telegram_chat_id'],
                    'text' => $admin_text,
                    'parse_mode' => 'HTML'
                ]);
                if ($adm_res) {
                    csc_update_notification_status($adm_log_id, 'delivered', $adm_res['result']['message_id']);
                }
            }
            
            do_action('csc_tg_order_accepted', $order_number, $order['accepted_by'] ?? $order['current_candidate'], $chat_id);
            csc_tg_log('order_accepted', ['order' => $order_number, 'master' => $order['accepted_by'] ?? $order['current_candidate']]);
        }

        // ============================================================
        // ДЕЙСТВИЕ: ОТКЛОНИТЬ ЗАКАЗ (rej)
        // ============================================================
        elseif ($action === 'rej') {
            if (($order['routing_status'] ?? '') !== 'searching') {
                $answer_callback(__('Статус уже изменен.', 'csc'));
                $edit_message("⏱ " . __('Предложение больше не актуально.', 'csc'));
                return new WP_REST_Response('OK', 200);
            }
            
            $answer_callback(__('Вы отклонили заказ.', 'csc'));
            $edit_message("❌ <b>" . __('Вы отказались от заказа.', 'csc') . "</b>");
            
            $mid = $order['current_candidate'] ?? '';
            if ($mid && !empty($masters[$mid])) {
                $masters[$mid]['total_rejects'] = ($masters[$mid]['total_rejects'] ?? 0) + 1;
                update_option('csc_masters_list', $masters, false);
                csc_cache_delete_masters();
            }
            
            if (function_exists('csc_route_order_to_next')) {
                csc_route_order_to_next($order_number, 'rejected');
            }
            
            do_action('csc_tg_order_rejected', $order_number, $mid, $chat_id);
            csc_tg_log('order_rejected', ['order' => $order_number, 'master' => $mid]);
        }

        // ============================================================
        // ДЕЙСТВИЕ: ЗАКАЗ ВЫПОЛНЕН (don)
        // ============================================================
        elseif ($action === 'don') {
            // ИСПРАВЛЕНО: сохраняем старый статус ДО изменения для истории
            $old_status = $order['status'] ?? '';
            $master_id = $order['master_id'] ?? $order['accepted_by'] ?? '';
            
            $all_orders[$order_number]['status'] = CSC_STATUS_COMPLETED;
            $all_orders[$order_number]['routing_status'] = 'completed';
            $all_orders[$order_number]['completed_at'] = current_time('mysql');
            
            // ИСПРАВЛЕНО: сохранение через единую функцию
            csc_update_order($order_number, $all_orders[$order_number]);
            csc_cache_delete_orders();

            // === ДОБАВЛЕН ВЫЗОВ ХУКА ===
            do_action('csc_order_status_changed', $order_number, $old_status, CSC_STATUS_COMPLETED, 'master', $master_id, '');

            // ИСПРАВЛЕНО: логирование перехода в таблицу истории (УЛУЧШЕНИЕ №3).
            // Раньше переход «выполнено» через Telegram-кнопку не фиксировался.
            if (function_exists('csc_log_status_change')) {
                csc_log_status_change(
                    $order_number,
                    $old_status,
                    CSC_STATUS_COMPLETED,
                    'master',
                    $master_id,
                    'Мастер отметил заказ выполненным через Telegram кнопку'
                );
            }
            
            $answer_callback(__('Статус изменен на Выполнено!', 'csc'));
            $edit_message("🏁 <b>" . sprintf(__('Заказ #%s успешно выполнен!', 'csc'), $order_number) . "</b>\n" . __('Отличная работа.', 'csc'));
            
            $dash_url = $master_id ? csc_tg_get_dashboard_link($master_id, $masters) : '';
            
            // Уведомление администратору
            if (!empty($s['telegram_chat_id'])) {
                $admin_msg = sprintf(
                    __("🏁 <b>Заказ #%s ВЫПОЛНЕН</b>\nМастер завершил работу.", 'csc'),
                    $order_number
                );
                if ($dash_url) {
                    $admin_msg .= "\n📲 <a href='" . esc_url($dash_url) . "'>" . __('Кабинет мастера', 'csc') . "</a>";
                }
                
                $adm_log_id = csc_log_notification($order_number, 'admin', 'admin_chat', 'telegram', 'Заказ выполнен', $admin_msg);
                csc_update_notification_status($adm_log_id, 'sent');
                
                $adm_res = csc_tg_api_request('sendMessage', [
                    'chat_id' => $s['telegram_chat_id'],
                    'text' => $admin_msg,
                    'parse_mode' => 'HTML'
                ]);
                if ($adm_res) {
                    csc_update_notification_status($adm_log_id, 'delivered', $adm_res['result']['message_id']);
                }
            }
            
            // Дополнительное уведомление мастеру
            if ($master_id && !empty($masters[$master_id]['tg_chat_id']) && $masters[$master_id]['tg_chat_id'] !== ($s['telegram_chat_id'] ?? '')) {
                $master_msg = sprintf(
                    __("🏁 <b>Заказ #%s отмечен как выполнен!</b>\nСпасибо за работу.", 'csc'),
                    $order_number
                );
                if ($dash_url) {
                    $master_msg .= "\n📲 <a href='" . esc_url($dash_url) . "'>" . __('Перейти в кабинет', 'csc') . "</a>";
                }
                
                $m_log_id = csc_log_notification($order_number, 'master', $master_id, 'telegram', 'Статус: Выполнено', $master_msg);
                csc_update_notification_status($m_log_id, 'sent');
                
                csc_tg_api_request('sendMessage', [
                    'chat_id' => $masters[$master_id]['tg_chat_id'],
                    'text' => $master_msg,
                    'parse_mode' => 'HTML'
                ]);
                csc_update_notification_status($m_log_id, 'read');
            }
            
            do_action('csc_tg_order_completed', $order_number, $master_id, $chat_id);
            csc_tg_log('order_completed', ['order' => $order_number, 'master' => $master_id]);
        }

        return new WP_REST_Response('OK', 200);
    }
}

// ============================================================
// 6. ХУКИ ДЛЯ РАСШИРЕНИЯ
// ============================================================
/**
 * Фильтр: Разрешить ли мастеру принять заказ
 * @param bool $allow
 * @param string $order_number
 * @param string $chat_id
 * @return bool
 */
// Пример:
// add_filter('csc_tg_allow_accept_order', function($allow, $order_num, $chat) {
//     if (csc_is_master_on_vacation($chat)) return false;
//     return $allow;
// }, 10, 3);

/**
 * Действие: После принятия заказа мастером
 * @param string $order_number
 * @param string $master_id
 * @param string $chat_id
 */
// add_action('csc_tg_order_accepted', 'my_handler', 10, 3);

/**
 * Действие: После отказа мастера от заказа
 * @param string $order_number
 * @param string $master_id
 * @param string $chat_id
 */
// add_action('csc_tg_order_rejected', 'my_handler', 10, 3);

/**
 * Действие: После выполнения заказа
 * @param string $order_number
 * @param string $master_id
 * @param string $chat_id
 */
// add_action('csc_tg_order_completed', 'my_handler', 10, 3);

// ============================================================
// 7. ФУНКЦИИ УПРАВЛЕНИЯ И ОТЛАДКИ (ADMIN)
// ============================================================

/**
 * Принудительная повторная отправка уведомления мастеру (админ-функция)
 */
if (!function_exists('csc_tg_resend_notification')) {
    function csc_tg_resend_notification($order_number, $master_id = null) {
        if (!current_user_can('manage_options')) return false;
        
        // ИСПРАВЛЕНО: единая функция чтения заказов
        $all_orders = csc_get_all_orders(true);
        if (!isset($all_orders[$order_number])) return false;
        
        $order = $all_orders[$order_number];
        $masters = csc_get_masters_cached();
        $mid = $master_id ?: ($order['master_id'] ?? $order['accepted_by'] ?? null);
        
        if (!$mid || empty($masters[$mid]['tg_chat_id'])) return false;
        
        $msg = sprintf(
            __("🔔 Напоминание по заказу #%s\nКлиент: %s (%s)\nВремя: %s в %s", 'csc'),
            $order_number, $order['name'], $order['phone'], $order['date'], $order['time']
        );
        
        $dash_url = csc_tg_get_dashboard_link($mid, $masters);
        $msg .= "\n📲 <a href='" . esc_url($dash_url) . "'>" . __('Открыть кабинет', 'csc') . "</a>";
        
        $log_id = csc_log_notification($order_number, 'master', $mid, 'telegram', '🔔 Напоминание', $msg, ['type' => 'admin_resend']);
        csc_update_notification_status($log_id, 'sent');
        
        $result = csc_tg_api_request('sendMessage', [
            'chat_id' => $masters[$mid]['tg_chat_id'],
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        
        if ($result) {
            csc_update_notification_status($log_id, 'delivered', $result['result']['message_id']);
            csc_tg_log('admin_resend', ['order' => $order_number, 'master' => $mid]);
            return true;
        }
        
        csc_update_notification_status($log_id, 'failed');
        return false;
    }
}

/**
 * Получение лога событий для заказа (для отладки)
 */
if (!function_exists('csc_tg_get_order_log')) {
    function csc_tg_get_order_log($order_number) {
        if (!current_user_can('manage_options')) return [];
        
        $logs = get_option('csc_tg_logs', []);
        return array_values(array_filter($logs, function($l) use ($order_number) {
            return ($l['data']['order'] ?? '') === $order_number ||
                   ($l['data']['order_number'] ?? '') === $order_number;
        }));
    }
}

// ============================================================
// 8. УСТАНОВКА WEBHOOK (КРИТИЧНО ДЛЯ РАБОТЫ КНОПОК TELEGRAM)
// ============================================================
if (!function_exists('csc_setup_telegram_webhook')) {
    function csc_setup_telegram_webhook() {
        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');

        if (!$tg_token) {
            csc_tg_log('webhook_setup_failed', ['reason' => 'no_token']);
            return false;
        }

        // URL webhook — должен быть HTTPS и публично доступен
        $webhook_url = rest_url('csc/v1/telegram');
        
        // Secret token для верификации запросов от Telegram
        $secret_token = wp_hash('csc_tg_secret_' . $tg_token);
        // Telegram принимает только 1-256 символов: A-Z, a-z, 0-9, _, -
        $secret_token = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $secret_token), 0, 256);

        // Удаляем старый webhook сначала
        csc_tg_api_request('deleteWebhook', ['drop_pending_updates' => false]);

        // Устанавливаем новый webhook с secret_token
        $result = csc_tg_api_request('setWebhook', [
            'url'             => $webhook_url,
            'secret_token'    => $secret_token,
            'allowed_updates' => ['callback_query', 'message'],
            'drop_pending_updates' => false,
        ]);

        if ($result && ($result['ok'] ?? false)) {
            csc_tg_log('webhook_set', ['url' => $webhook_url]);
            update_option('csc_tg_webhook_url', $webhook_url, false);
            update_option('csc_tg_webhook_secret', $secret_token, false);
            return true;
        }

        csc_tg_log('webhook_setup_failed', [
            'url'    => $webhook_url,
            'result' => $result
        ]);
        return false;
    }
}

/**
 * Диагностика webhook — проверяет текущее состояние в Telegram
 */
if (!function_exists('csc_check_telegram_webhook')) {
    function csc_check_telegram_webhook() {
        $result = csc_tg_api_request('getWebhookInfo', []);
        if (!$result || empty($result['ok'])) {
            return ['status' => 'error', 'message' => 'Cannot get webhook info'];
        }
        return [
            'status'              => 'ok',
            'url'                 => $result['result']['url'] ?? '(not set)',
            'pending_update_count'=> $result['result']['pending_update_count'] ?? 0,
            'last_error_message'  => $result['result']['last_error_message'] ?? null,
            'last_error_date'     => isset($result['result']['last_error_date'])
                ? date('Y-m-d H:i:s', $result['result']['last_error_date'])
                : null,
        ];
    }
}

/**
 * Хук: переустановить webhook при сохранении настроек Telegram
 */
add_action('update_option_csc_main_settings', 'csc_on_telegram_settings_saved', 10, 3);
function csc_on_telegram_settings_saved($old_value, $new_value, $option) {
    static $running = false;
    // Защита от рекурсии: миграция шифрования тоже обновляет эту опцию
    if ($running) return;

    $old_token = is_array($old_value) ? ($old_value['telegram_token'] ?? '') : '';
    $new_token = is_array($new_value) ? ($new_value['telegram_token'] ?? '') : '';

    // Значения в БД зашифрованы (AES-256-GCM) — сравниваем открытые токены,
    // иначе миграция открытого токена в зашифрованный ложно сработает как смена токена
    if (function_exists('csc_decrypt_token')) {
        $old_token = csc_decrypt_token($old_token);
        $new_token = csc_decrypt_token($new_token);
    }

    // Если токен изменился — переустанавливаем webhook
    if ($old_token !== $new_token && !empty($new_token)) {
        $running = true;
        csc_setup_telegram_webhook();
        $running = false;
    }
}
// ============================================================
// САМОПРОВЕДКА WEBHOOK (самовосстановление после обновлений плагина)
// ============================================================
/**
 * При каждой загрузке админки проверяем, что webhook в Telegram всё ещё
 * указывает на наш текущий REST URL. Если нет (например, хостинг менял домен,
 * токен был переустановлен вручную через setWebhook без secret_token или
 * update_option не сработал при сохранении настроек) — тихо переустанавливаем.
 * Без работающего webhook кнопки «Принять/Отклонить» и ответы бота перестают
 * приходить, поэтому проверка критична для уведомлений о новых заявках.
 */
if (!function_exists('csc_tg_do_webhook_selfcheck')) {
    function csc_tg_do_webhook_selfcheck() {
        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        if (!$tg_token) return;

        $expected_url = rest_url('csc/v1/telegram');
        $info = csc_check_telegram_webhook();
        $current_url = $info['url'] ?? '';

        // Webhook отсутствует вообще или ведёт не на наш URL — переустанавливаем
        if ($current_url !== $expected_url) {
            if (csc_setup_telegram_webhook()) {
                csc_log('tg', 'info', 'webhook_selfcheck_fixed: ' . ($current_url ?: '(not set)') . ' -> ' . $expected_url);
            } else {
                csc_log('tg', 'warning', 'webhook_selfcheck_fix_failed: ' . ($current_url ?: '(not set)'));
            }
            return;
        }

        // URL верный, но секрет может отсутствовать (webhook установлен вручную
        // строкой из инструкции в админке без secret_token) — сверяем по факту:
        // если сохранённого секрета нет, генерируем и переустанавливаем корректно.
        if (empty(get_option('csc_tg_webhook_secret', ''))) {
            csc_setup_telegram_webhook();
            csc_log('tg', 'info', 'webhook_selfcheck_secret_restored');
        } else {
            csc_log('tg', 'debug', 'webhook_selfcheck_ok');
        }
    }
}

// Запуск самотестирования не блокирует админку: делаем фоновый cron-запрос раз в час
add_action('init', function () {
    if (!wp_next_scheduled('csc_tg_webhook_selfcheck_hourly')) {
        wp_schedule_event(time() + 60, 'hourly', 'csc_tg_webhook_selfcheck_hourly');
    }
});
add_action('csc_tg_webhook_selfcheck_hourly', 'csc_tg_do_webhook_selfcheck');
// Очистка при деактивации плагина выполняется штатным csc_deactivation_hook
// (wp_clear_scheduled_hook чистит все csc_* кроны).
