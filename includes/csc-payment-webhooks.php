<?php
/**
 * Файл: csc-payment-webhooks.php
 * Описание: Обработка вебхуков от платёжных провайдеров.
 * Версия: 1.0
 *
 * Зависимости:
 *   - csc-payment-provider-interface.php (интерфейс провайдера)
 *   - csc-payment-providers.php (реестр провайдеров)
 *   - csc-payments.php (ядро платёжного модуля)
 *   - csc-notifications.php (логирование уведомлений)
 *
 * Эндпоинты:
 *   - POST /wp-json/csc/v1/payment/webhook/yoo
 *   - POST /wp-json/csc/v1/payment/webhook/tinkoff
 *   - POST /wp-json/csc/v1/payment/webhook/sber
 *   - POST /wp-json/csc/v1/payment/webhook/alfa
 *   - POST /wp-json/csc/v1/payment/webhook/sbp
 *   - POST /wp-json/csc/v1/payment/webhook/{provider} (универсальный)
 *
 * Безопасность:
 *   - Проверка подписи вебхука через провайдера
 *   - Идемпотентность: повторные события не обрабатываются повторно
 *   - Логирование всех входящих запросов
 *   - Защита от DDoS через ограничение частоты запросов
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================

/** Максимальное количество вебхуков в минуту с одного IP */
if (!defined('CSC_WEBHOOK_RATE_LIMIT')) {
    define('CSC_WEBHOOK_RATE_LIMIT', 60);
}

/** TTL для блокировки повторной обработки */
if (!defined('CSC_WEBHOOK_IDEMPOTENCY_TTL')) {
    define('CSC_WEBHOOK_IDEMPOTENCY_TTL', 86400); // 24 часа
}

/** Максимальный размер тела вебхука (в байтах) */
if (!defined('CSC_WEBHOOK_MAX_BODY_SIZE')) {
    define('CSC_WEBHOOK_MAX_BODY_SIZE', 1024 * 1024); // 1 МБ
}

/** Включить логирование всех вебхуков */
if (!defined('CSC_WEBHOOK_LOG_ENABLED')) {
    define('CSC_WEBHOOK_LOG_ENABLED', true);
}

// ============================================================
// 2. РЕГИСТРАЦИЯ REST API ЭНДПОИНТОВ
// ============================================================

add_action('rest_api_init', 'csc_register_payment_webhook_routes');

if (!function_exists('csc_register_payment_webhook_routes')) {
    function csc_register_payment_webhook_routes() {
        // Универсальный эндпоинт с параметром провайдера
        register_rest_route('csc/v1', '/payment/webhook/(?P<provider>[a-z]+)', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_payment_webhook',
            'permission_callback' => '__return_true', // Проверка подписи внутри
            'args'                => [
                'provider' => [
                    'description' => 'ID платёжного провайдера',
                    'type'        => 'string',
                    'required'    => true,
                    'pattern'     => '^[a-z]+$',
                ],
            ],
        ]);

        // Отдельный эндпоинт для ЮKassa (для удобства настройки в личном кабинете)
        register_rest_route('csc/v1', '/payment/webhook/yoo', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_payment_webhook_yoo',
            'permission_callback' => '__return_true',
        ]);

        // Отдельный эндпоинт для Тинькофф
        register_rest_route('csc/v1', '/payment/webhook/tinkoff', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_payment_webhook_tinkoff',
            'permission_callback' => '__return_true',
        ]);

        // Отдельный эндпоинт для Сбербанк
        register_rest_route('csc/v1', '/payment/webhook/sber', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_payment_webhook_sber',
            'permission_callback' => '__return_true',
        ]);

        // Отдельный эндпоинт для Альфа-Банк
        register_rest_route('csc/v1', '/payment/webhook/alfa', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_payment_webhook_alfa',
            'permission_callback' => '__return_true',
        ]);

        // Отдельный эндпоинт для СБП
        register_rest_route('csc/v1', '/payment/webhook/sbp', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_payment_webhook_sbp',
            'permission_callback' => '__return_true',
        ]);
    }
}

// ============================================================
// 3. ОБЩИЙ ОБРАБОТЧИК ВЕБХУКОВ
// ============================================================

/**
 * Универсальный обработчик вебхука для любого провайдера.
 * Вызывается через /csc/v1/payment/webhook/{provider}
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
if (!function_exists('csc_handle_payment_webhook')) {
    function csc_handle_payment_webhook(WP_REST_Request $request) {
        $provider_id = sanitize_key($request->get_param('provider'));
        return csc_process_webhook_for_provider($provider_id, $request);
    }
}

/**
 * Обработчик вебхука ЮKassa
 */
if (!function_exists('csc_handle_payment_webhook_yoo')) {
    function csc_handle_payment_webhook_yoo(WP_REST_Request $request) {
        return csc_process_webhook_for_provider('yoo', $request);
    }
}

/**
 * Обработчик вебхука Тинькофф
 */
if (!function_exists('csc_handle_payment_webhook_tinkoff')) {
    function csc_handle_payment_webhook_tinkoff(WP_REST_Request $request) {
        return csc_process_webhook_for_provider('tinkoff', $request);
    }
}

/**
 * Обработчик вебхука Сбербанк
 */
if (!function_exists('csc_handle_payment_webhook_sber')) {
    function csc_handle_payment_webhook_sber(WP_REST_Request $request) {
        return csc_process_webhook_for_provider('sber', $request);
    }
}

/**
 * Обработчик вебхука Альфа-Банк
 */
if (!function_exists('csc_handle_payment_webhook_alfa')) {
    function csc_handle_payment_webhook_alfa(WP_REST_Request $request) {
        return csc_process_webhook_for_provider('alfa', $request);
    }
}

/**
 * Обработчик вебхука СБП
 */
if (!function_exists('csc_handle_payment_webhook_sbp')) {
    function csc_handle_payment_webhook_sbp(WP_REST_Request $request) {
        return csc_process_webhook_for_provider('sbp', $request);
    }
}

// ============================================================
// 4. ОСНОВНАЯ ЛОГИКА ОБРАБОТКИ ВЕБХУКА
// ============================================================

/**
 * Основная логика обработки вебхука для конкретного провайдера.
 *
 * @param string          $provider_id ID провайдера
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
if (!function_exists('csc_process_webhook_for_provider')) {
    function csc_process_webhook_for_provider($provider_id, WP_REST_Request $request) {
        $start_time = microtime(true);
        $ip = csc_webhook_get_client_ip();
        $raw_body = $request->get_body();
        $headers = $request->get_headers();

        // Логирование входящего запроса
        csc_webhook_log('incoming', [
            'provider'  => $provider_id,
            'ip'        => $ip,
            'body_size' => strlen($raw_body),
        ]);

        // === Проверка 1: Ограничение размера тела ===
        if (strlen($raw_body) > CSC_WEBHOOK_MAX_BODY_SIZE) {
            csc_webhook_log('rejected', [
                'provider' => $provider_id,
                'ip'       => $ip,
                'reason'   => 'body_too_large',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Request body too large'],
                413
            );
        }

        // === Проверка 2: Ограничение частоты запросов (защита от DDoS) ===
        if (!csc_webhook_check_rate_limit($provider_id, $ip)) {
            csc_webhook_log('rejected', [
                'provider' => $provider_id,
                'ip'       => $ip,
                'reason'   => 'rate_limit_exceeded',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Too many requests'],
                429
            );
        }

        // === Проверка 3: Проверка что провайдер существует ===
        if (!function_exists('csc_payment_providers')) {
            csc_webhook_log('error', [
                'provider' => $provider_id,
                'reason'   => 'providers_registry_not_loaded',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Payment system not initialized'],
                500
            );
        }

        $registry = csc_payment_providers();
        if (!$registry->has_provider($provider_id)) {
            csc_webhook_log('rejected', [
                'provider' => $provider_id,
                'ip'       => $ip,
                'reason'   => 'unknown_provider',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Unknown provider'],
                404
            );
        }

        // === Проверка 4: Получение экземпляра провайдера ===
        $provider = $registry->get_provider($provider_id);
        if (!$provider) {
            csc_webhook_log('error', [
                'provider' => $provider_id,
                'reason'   => 'provider_instance_failed',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Provider initialization failed'],
                500
            );
        }

        // === Проверка 5: Проверка что провайдер включён ===
        if (!$provider->is_enabled()) {
            csc_webhook_log('rejected', [
                'provider' => $provider_id,
                'ip'       => $ip,
                'reason'   => 'provider_disabled',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Provider is disabled'],
                403
            );
        }

        // === Проверка 6: Проверка подписи вебхука ===
        if (!$provider->verify_webhook($headers, $raw_body)) {
            csc_webhook_log('rejected', [
                'provider' => $provider_id,
                'ip'       => $ip,
                'reason'   => 'invalid_signature',
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid webhook signature'],
                403
            );
        }

        // === Проверка 7: Идемпотентность (защита от повторной обработки) ===
        $idempotency_key = csc_webhook_get_idempotency_key($provider_id, $raw_body);
        if (csc_webhook_is_processed($idempotency_key)) {
            csc_webhook_log('duplicate', [
                'provider'        => $provider_id,
                'ip'              => $ip,
                'idempotency_key' => $idempotency_key,
            ]);
            return new WP_REST_Response(
                ['success' => true, 'message' => 'Already processed'],
                200
            );
        }

        // === Обработка вебхука ===
        try {
            // Парсинг вебхука
            $webhook_data = $provider->parse_webhook($raw_body);

            if (empty($webhook_data['payment_id'])) {
                csc_webhook_log('error', [
                    'provider' => $provider_id,
                    'reason'   => 'missing_payment_id',
                    'body'     => substr($raw_body, 0, 500),
                ]);
                return new WP_REST_Response(
                    ['success' => false, 'message' => 'Missing payment_id'],
                    400
                );
            }

            // Обработка события
            $result = csc_process_webhook_event($provider_id, $webhook_data);

            // Помечаем как обработанный
            csc_webhook_mark_processed($idempotency_key);

            $elapsed = round((microtime(true) - $start_time) * 1000, 2);
            csc_webhook_log('processed', [
                'provider'    => $provider_id,
                'payment_id'  => $webhook_data['payment_id'],
                'event_type'  => $webhook_data['event_type'] ?? 'unknown',
                'status'      => $webhook_data['status'] ?? 'unknown',
                'order'       => $webhook_data['order_number'] ?? '',
                'elapsed_ms'  => $elapsed,
            ]);

            return new WP_REST_Response(
                ['success' => true, 'message' => 'Webhook processed'],
                200
            );

        } catch (Exception $e) {
            csc_webhook_log('exception', [
                'provider' => $provider_id,
                'error'    => $e->getMessage(),
                'trace'    => substr($e->getTraceAsString(), 0, 500),
            ]);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Internal error: ' . $e->getMessage()],
                500
            );
        }
    }
}

// ============================================================
// 5. ОБРАБОТКА СОБЫТИЯ ВЕБХУКА
// ============================================================

/**
 * Обработка конкретного события вебхука.
 * Обновляет транзакцию и заказ, отправляет уведомления.
 *
 * @param string $provider_id ID провайдера
 * @param array  $webhook_data Данные вебхука от провайдера
 * @return array Результат обработки
 */
if (!function_exists('csc_process_webhook_event')) {
    function csc_process_webhook_event($provider_id, $webhook_data) {
        global $wpdb;

        $payment_id = $webhook_data['payment_id'];
        $event_type = $webhook_data['event_type'] ?? 'unknown';
        $status = $webhook_data['status'] ?? 'unknown';
        $order_number = $webhook_data['order_number'] ?? '';
        $amount = floatval($webhook_data['amount'] ?? 0);

        // === Поиск транзакции в БД ===
        $table_transactions = $wpdb->prefix . 'csc_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_transactions} WHERE payment_id = %s AND provider = %s ORDER BY id DESC LIMIT 1",
            $payment_id,
            $provider_id
        ));

        if (!$transaction) {
            csc_webhook_log('warning', [
                'provider'   => $provider_id,
                'payment_id' => $payment_id,
                'reason'     => 'transaction_not_found',
            ]);

            // Если заказ указан, но транзакция не найдена — создаём её
            if (!empty($order_number)) {
                $transaction_id = csc_create_transaction_from_webhook($provider_id, $webhook_data);
                if ($transaction_id) {
                    $transaction = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table_transactions} WHERE id = %d",
                        $transaction_id
                    ));
                }
            }

            if (!$transaction) {
                return ['success' => false, 'message' => 'Transaction not found'];
            }
        }

        $order_number = $transaction->order_number;
        $current_status = $transaction->status;

        // === Идемпотентность по статусу транзакции ===
        if ($current_status === $status) {
            return ['success' => true, 'message' => 'Status already set'];
        }

        // === Обновление транзакции ===
        $update_data = [
            'status'              => $status,
            'provider_response'   => wp_json_encode($webhook_data),
            'webhook_received_at' => current_time('mysql'),
            'updated_at'          => current_time('mysql'),
        ];

        // Дополнительные поля в зависимости от статуса
        if ($status === 'succeeded' || $status === 'paid') {
            $update_data['paid_at'] = current_time('mysql');
        } elseif ($status === 'refunded' || $status === 'partially_refunded') {
            $update_data['refunded_at'] = current_time('mysql');
        }

        $wpdb->update($table_transactions, $update_data, ['id' => $transaction->id]);

        // === Обновление заказа ===
        if (!empty($order_number)) {
            csc_update_order_payment_status($order_number, $status, $transaction);
        }

        // === Логирование в систему уведомлений ===
        if (function_exists('csc_log_notification')) {
            $event_labels = [
                'payment.succeeded'   => '✅ Оплата успешна',
                'payment.waiting'     => '⏳ Ожидание оплаты',
                'payment.canceled'    => '❌ Оплата отменена',
                'payment.failed'      => '❌ Оплата не удалась',
                'refund.succeeded'    => '↩️ Возврат выполнен',
                'refund.pending'      => '⏳ Возврат в обработке',
                'payment.authorized'  => '🔒 Оплата авторизована',
                'payment.captured'    => '✅ Оплата подтверждена',
            ];

            $event_label = $event_labels[$event_type] ?? "📦 Событие: {$event_type}";
            $event_message = "Провайдер: {$provider_id}. Сумма: " . number_format($amount, 2, '.', ' ') . " ₽. Статус: {$status}.";

            csc_log_notification(
                $order_number,
                'system',
                'payment_webhook',
                'web',
                $event_label,
                $event_message,
                [
                    'provider'   => $provider_id,
                    'payment_id' => $payment_id,
                    'event_type' => $event_type,
                    'status'     => $status,
                    'amount'     => $amount,
                ]
            );
        }

        // === Уведомления клиенту и мастеру ===
        csc_webhook_send_notifications($order_number, $status, $event_type, $amount);

        // === Хук для расширения ===
        do_action('csc_payment_webhook_processed', $provider_id, $order_number, $status, $webhook_data);

        return ['success' => true, 'message' => 'Event processed'];
    }
}

// ============================================================
// 6. СОЗДАНИЕ ТРАНЗАКЦИИ ИЗ ВЕБХУКА (ЕСЛИ НЕ НАЙДЕНА)
// ============================================================

/**
 * Создание транзакции из данных вебхука, если она не была найдена.
 * Это может произойти, если платёж был создан вне системы или данные потеряны.
 *
 * @param string $provider_id ID провайдера
 * @param array  $webhook_data Данные вебхука
 * @return int|false ID созданной транзакции или false
 */
if (!function_exists('csc_create_transaction_from_webhook')) {
    function csc_create_transaction_from_webhook($provider_id, $webhook_data) {
        global $wpdb;

        $order_number = $webhook_data['order_number'] ?? '';
        if (empty($order_number)) {
            return false;
        }

        // Проверяем существование заказа
        $order = csc_get_order($order_number);
        if (!$order) {
            csc_webhook_log('warning', [
                'provider'   => $provider_id,
                'order'      => $order_number,
                'reason'     => 'order_not_found_for_transaction_creation',
            ]);
            return false;
        }

        $table_transactions = $wpdb->prefix . 'csc_transactions';
        $result = $wpdb->insert($table_transactions, [
            'order_number'    => $order_number,
            'payment_id'      => $webhook_data['payment_id'],
            'provider'        => $provider_id,
            'method'          => $webhook_data['method'] ?? 'card',
            'payment_mode'    => $order['payment_mode'] ?? '',
            'type'            => 'payment',
            'status'          => $webhook_data['status'] ?? 'pending',
            'amount'          => floatval($webhook_data['amount'] ?? 0),
            'currency'        => 'RUB',
            'description'     => 'Создано из вебхука (транзакция не найдена)',
            'provider_response' => wp_json_encode($webhook_data),
            'webhook_received_at' => current_time('mysql'),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        if ($result === false) {
            csc_webhook_log('error', [
                'provider' => $provider_id,
                'order'    => $order_number,
                'reason'   => 'failed_to_create_transaction_from_webhook',
            ]);
            return false;
        }

        csc_webhook_log('info', [
            'provider'   => $provider_id,
            'order'      => $order_number,
            'payment_id' => $webhook_data['payment_id'],
            'reason'     => 'transaction_created_from_webhook',
        ]);

        return $wpdb->insert_id;
    }
}

// ============================================================
// 7. УВЕДОМЛЕНИЯ ПРИ ОБРАБОТКЕ ВЕБХУКА
// ============================================================

/**
 * Отправка уведомлений клиенту и мастеру при обработке вебхука.
 *
 * @param string $order_number Номер заказа
 * @param string $status Статус платежа
 * @param string $event_type Тип события
 * @param float  $amount Сумма
 */
if (!function_exists('csc_webhook_send_notifications')) {
    function csc_webhook_send_notifications($order_number, $status, $event_type, $amount) {
        $order = csc_get_order($order_number);
        if (!$order) return;

        $client_phone = csc_normalize_phone($order['phone'] ?? '');
        $master_id = $order['master_id'] ?? '';

        // === Уведомления клиенту ===
        if ($status === 'succeeded' || $status === 'paid') {
            // Web Push клиенту
            if ($client_phone && function_exists('csc_send_web_push_notification')) {
                $subs = get_option("csc_push_subs_customer_{$client_phone}", []);
                foreach ($subs as $sub) {
                    csc_send_web_push_notification(
                        $sub,
                        "✅ Оплата получена",
                        "Заказ #{$order_number} оплачен. Сумма: " . number_format($amount, 0, '.', ' ') . " ₽.",
                        $order_number,
                        'customer',
                        $client_phone
                    );
                }
            }
        } elseif ($status === 'failed' || $status === 'canceled') {
            // Web Push клиенту об ошибке
            if ($client_phone && function_exists('csc_send_web_push_notification')) {
                $subs = get_option("csc_push_subs_customer_{$client_phone}", []);
                foreach ($subs as $sub) {
                    csc_send_web_push_notification(
                        $sub,
                        "❌ Оплата не прошла",
                        "Оплата по заказу #{$order_number} не была завершена. Попробуйте ещё раз.",
                        $order_number,
                        'customer',
                        $client_phone
                    );
                }
            }
        }

        // === Уведомления мастеру ===
        if ($master_id) {
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
            $master = $masters[$master_id] ?? null;

            if ($master) {
                // Telegram мастеру
                if (!empty($master['tg_chat_id']) && function_exists('csc_send_tg_notification')) {
                    $tg_messages = [
                        'succeeded' => "💳 Заказ #{$order_number} оплачен. Сумма: " . number_format($amount, 0, '.', ' ') . " ₽.",
                        'paid'      => "💳 Заказ #{$order_number} оплачен. Сумма: " . number_format($amount, 0, '.', ' ') . " ₽.",
                        'failed'    => "⚠️ Оплата по заказу #{$order_number} не прошла.",
                        'canceled'  => "⚠️ Оплата по заказу #{$order_number} была отменена.",
                        'refunded'  => "↩️ По заказу #{$order_number} выполнен возврат. Сумма: " . number_format($amount, 0, '.', ' ') . " ₽.",
                    ];

                    $tg_message = $tg_messages[$status] ?? "📦 Статус оплаты заказа #{$order_number}: {$status}";
                    $tg_title = $status === 'succeeded' || $status === 'paid'
                        ? "💳 Оплата получена #{$order_number}"
                        : "📦 Статус оплаты #{$order_number}";

                    csc_send_tg_notification(
                        $master['tg_chat_id'],
                        $tg_title,
                        $tg_message,
                        $order_number,
                        'master',
                        $master_id,
                        null
                    );
                }

                // Web Push мастеру
                if (function_exists('csc_send_web_push_notification')) {
                    $master_subs = get_option("csc_push_subs_master_{$master_id}", []);
                    foreach ($master_subs as $sub) {
                        $push_message = $status === 'succeeded' || $status === 'paid'
                            ? "Заказ #{$order_number} оплачен. Сумма: " . number_format($amount, 0, '.', ' ') . " ₽."
                            : "Статус оплаты заказа #{$order_number}: {$status}";

                        csc_send_web_push_notification(
                            $sub,
                            "📦 Оплата #{$order_number}",
                            $push_message,
                            $order_number,
                            'master',
                            $master_id
                        );
                    }
                }
            }
        }
    }
}

// ============================================================
// 8. ЛОГИРОВАНИЕ ВЕБХУКОВ
// ============================================================

/**
 * Логирование событий вебхуков.
 *
 * @param string $event_type Тип события (incoming, processed, rejected, error)
 * @param array  $data Данные для логирования
 */
if (!function_exists('csc_webhook_log')) {
    function csc_webhook_log($event_type, $data = []) {
        if (!CSC_WEBHOOK_LOG_ENABLED) return;

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'event'     => $event_type,
            'data'      => $data,
        ];

        // Логирование в error_log для продакшена
        if (defined('WP_DEBUG') && WP_DEBUG) {
            csc_log(json_encode($log_entry, JSON_UNESCAPED_UNICODE), 'debug', 'WEBHOOK');
        }

        // Сохранение в опцию для отладки в админке (последние 200 записей)
        $logs = get_option('csc_payment_webhook_logs', []);
        $logs[] = $log_entry;
        $logs = array_slice($logs, -200);
        update_option('csc_payment_webhook_logs', $logs, false);
    }
}

/**
 * Получение лога вебхуков для отладки.
 *
 * @param string|null $provider_id Фильтр по провайдеру
 * @param int         $limit Максимум записей
 * @return array
 */
if (!function_exists('csc_webhook_get_logs')) {
    function csc_webhook_get_logs($provider_id = null, $limit = 50) {
        $logs = get_option('csc_payment_webhook_logs', []);

        if ($provider_id) {
            $logs = array_filter($logs, function($l) use ($provider_id) {
                return ($l['data']['provider'] ?? '') === $provider_id;
            });
        }

        return array_slice(array_reverse($logs), 0, $limit);
    }
}

// ============================================================
// 9. ИДЕМПОТЕНТНОСТЬ (ЗАЩИТА ОТ ПОВТОРНОЙ ОБРАБОТКИ)
// ============================================================

/**
 * Генерация ключа идемпотентности для вебхука.
 *
 * @param string $provider_id ID провайдера
 * @param string $raw_body Тело запроса
 * @return string Уникальный ключ
 */
if (!function_exists('csc_webhook_get_idempotency_key')) {
    function csc_webhook_get_idempotency_key($provider_id, $raw_body) {
        // Используем хеш тела запроса + провайдера как уникальный ключ
        return 'csc_webhook_' . $provider_id . '_' . md5($raw_body);
    }
}

/**
 * Проверка, был ли вебхук уже обработан.
 *
 * @param string $key Ключ идемпотентности
 * @return bool true если уже обработан
 */
if (!function_exists('csc_webhook_is_processed')) {
    function csc_webhook_is_processed($key) {
        return get_transient($key) !== false;
    }
}

/**
 * Пометить вебхук как обработанный.
 *
 * @param string $key Ключ идемпотентности
 */
if (!function_exists('csc_webhook_mark_processed')) {
    function csc_webhook_mark_processed($key) {
        set_transient($key, time(), CSC_WEBHOOK_IDEMPOTENCY_TTL);
    }
}

// ============================================================
// 10. ЗАЩИТА ОТ DDoS (ОГРАНИЧЕНИЕ ЧАСТОТЫ ЗАПРОСОВ)
// ============================================================

/**
 * Проверка ограничения частоты запросов.
 *
 * @param string $provider_id ID провайдера
 * @param string $ip IP-адрес клиента
 * @return bool true если запрос разрешён
 */
if (!function_exists('csc_webhook_check_rate_limit')) {
    function csc_webhook_check_rate_limit($provider_id, $ip) {
        $key = 'csc_webhook_rate_' . $provider_id . '_' . md5($ip);
        $data = get_transient($key);

        if (!$data) {
            set_transient($key, ['count' => 1, 'window_start' => time()], 60);
            return true;
        }

        $count = $data['count'] ?? 0;
        $window_start = $data['window_start'] ?? time();

        // Если окно прошло — сбрасываем
        if (time() - $window_start > 60) {
            set_transient($key, ['count' => 1, 'window_start' => time()], 60);
            return true;
        }

        // Проверяем лимит
        if ($count >= CSC_WEBHOOK_RATE_LIMIT) {
            return false;
        }

        // Увеличиваем счётчик
        $data['count'] = $count + 1;
        set_transient($key, $data, 60 - (time() - $window_start));
        return true;
    }
}

/**
 * Получение IP-адреса клиента с учётом прокси.
 *
 * @return string IP-адрес
 */
if (!function_exists('csc_webhook_get_client_ip')) {
    function csc_webhook_get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Проверяем заголовки прокси
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
// 11. ХУКИ ДЛЯ РАСШИРЕНИЯ
// ============================================================

/**
 * Действие: после успешной обработки вебхука.
 * Позволяет другим модулям реагировать на события платежей.
 *
 * @param string $provider_id ID провайдера
 * @param string $order_number Номер заказа
 * @param string $status Статус платежа
 * @param array  $webhook_data Данные вебхука
 */
// Пример:
// add_action('csc_payment_webhook_processed', function($provider_id, $order_number, $status, $data) {
//     if ($status === 'succeeded') {
//         // Отправить бонусные баллы клиенту
//     }
// }, 10, 4);

/**
 * Фильтр: позволяет изменить данные вебхука перед обработкой.
 *
 * @param array  $webhook_data Данные вебхука
 * @param string $provider_id ID провайдера
 * @return array
 */
// Пример:
// add_filter('csc_payment_webhook_data', function($data, $provider_id) {
//     // Дополнительная валидация или трансформация
//     return $data;
// }, 10, 2);

// ============================================================
// 12. AJAX: ПОЛУЧЕНИЕ ЛОГОВ ВЕБХУКОВ (ДЛЯ АДМИНКИ)
// ============================================================

add_action('wp_ajax_csc_get_webhook_logs', 'csc_get_webhook_logs_ajax');

if (!function_exists('csc_get_webhook_logs_ajax')) {
    function csc_get_webhook_logs_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещен']);
        }

        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $provider_id = sanitize_text_field($_POST['provider'] ?? '');
        $limit = intval($_POST['limit'] ?? 50);

        $logs = csc_webhook_get_logs($provider_id ?: null, $limit);

        wp_send_json_success(['logs' => $logs]);
    }
}

// ============================================================
// 13. КРОН: ОЧИСТКА СТАРЫХ ТРАНЗИЕНТОВ ИДЕМПОТЕНТНОСТИ
// ============================================================

/**
 * Очистка старых транзиентов идемпотентности.
 * Вызывается по крону раз в сутки.
 */
add_action('csc_webhook_cleanup_cron', 'csc_cleanup_webhook_transients');

if (!function_exists('csc_cleanup_webhook_transients')) {
    function csc_cleanup_webhook_transients() {
        global $wpdb;

        // Удаляем транзиенты вебхуков старше 24 часов
        $cutoff = time() - CSC_WEBHOOK_IDEMPOTENCY_TTL;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            '_transient_timeout_csc_webhook_%',
            $cutoff
        ));

        // Также удаляем соответствующие значения
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_csc_webhook_%' AND option_name NOT IN (
                SELECT CONCAT('_transient_', SUBSTRING(option_name, 20)) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_csc_webhook_%'
            )"
        );
    }
}

// Регистрация крона очистки
add_action('init', 'csc_ensure_webhook_cleanup_scheduled');

if (!function_exists('csc_ensure_webhook_cleanup_scheduled')) {
    function csc_ensure_webhook_cleanup_scheduled() {
        if (!wp_next_scheduled('csc_webhook_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'csc_webhook_cleanup_cron');
        }
    }
}

// Очистка крона при деактивации плагина
add_action('csc_plugin_deactivate', 'csc_deactivate_webhook_cleanup');

if (!function_exists('csc_deactivate_webhook_cleanup')) {
    function csc_deactivate_webhook_cleanup() {
        wp_clear_scheduled_hook('csc_webhook_cleanup_cron');
    }
}

// ============================================================
// 14. ФУНКЦИЯ ГЕНЕРАЦИИ СЕКРЕТНОГО КЛЮЧА ДЛЯ ВЕБХУКОВ
// ============================================================

/**
 * Генерация секретного ключа для вебхуков провайдера.
 * Используется при настройке вебхука в личном кабинете провайдера.
 *
 * @param string $provider_id ID провайдера
 * @return string Секретный ключ
 */
if (!function_exists('csc_get_webhook_secret')) {
    function csc_get_webhook_secret($provider_id) {
        $settings = get_option('csc_payment_settings', []);
        $provider_settings = $settings['providers'][$provider_id] ?? [];

        if (!empty($provider_settings['webhook_secret'])) {
            return $provider_settings['webhook_secret'];
        }

        // Генерируем новый секретный ключ
        $secret = wp_generate_password(64, false);
        $provider_settings['webhook_secret'] = $secret;
        $settings['providers'][$provider_id] = $provider_settings;
        update_option('csc_payment_settings', $settings, false);

        return $secret;
    }
}

/**
 * Получение URL вебхука для провайдера.
 * Используется при настройке вебхука в личном кабинете провайдера.
 *
 * @param string $provider_id ID провайдера
 * @return string URL вебхука
 */
if (!function_exists('csc_get_webhook_url')) {
    function csc_get_webhook_url($provider_id) {
        return rest_url('csc/v1/payment/webhook/' . $provider_id);
    }
}