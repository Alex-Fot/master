<?php
/**
 * Файл: csc-payments.php
 * Описание: Ядро платёжного модуля. Создание платежей, обработка статусов,
 *           возвраты, поллинг, проверка зависших платежей, уведомления.
 * Версия: 1.0
 *
 * Зависимости:
 *   - Таблица {prefix}csc_transactions (из csc-payments-db.php)
 *   - Таблица {prefix}csc_financial_ledger (из csc-payments-db.php)
 *   - Колонки в {prefix}csc_orders (из csc-payments-db.php)
 *   - Модуль уведомлений (из csc-notifications.php)
 *   - Модуль шифрования (из csc-crypto.php)
 *
 * Архитектура:
 *   Ядро НЕ зависит от конкретного провайдера.
 *   Каждый провайдер реализует интерфейс и регистрируется через реестр.
 *   Ядро вызывает методы провайдера через реестр.
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================

/** Максимальное время ожидания оплаты (в секундах) — 24 часа */
if (!defined('CSC_PAYMENT_PENDING_TIMEOUT')) {
    define('CSC_PAYMENT_PENDING_TIMEOUT', 86400);
}

/** Максимальное время обработки запроса к провайдеру (в секундах) */
if (!defined('CSC_PAYMENT_REQUEST_TIMEOUT')) {
    define('CSC_PAYMENT_REQUEST_TIMEOUT', 30);
}

/** Интервал поллинга статуса клиентом (в секундах) */
if (!defined('CSC_PAYMENT_POLL_INTERVAL')) {
    define('CSC_PAYMENT_POLL_INTERVAL', 5);
}

/** Максимальное количество попыток запроса к провайдеру */
if (!defined('CSC_PAYMENT_MAX_RETRIES')) {
    define('CSC_PAYMENT_MAX_RETRIES', 3);
}

// ============================================================
// 2. ПОЛУЧЕНИЕ НАСТРОЕК ПЛАТЕЖЕЙ
// ============================================================

/**
 * Получить настройки платёжного модуля.
 * Читает опцию csc_payment_settings.
 *
 * @return array Настройки
 */
if (!function_exists('csc_payments_get_settings')) {
    function csc_payments_get_settings() {
        $defaults = [
            'payment_enabled'           => true,
            'allow_cash'                => true,
            'allow_cashless'            => true,
            'cash_only_mode'            => false,
            'cashless_payment_mode'     => 'prepay', // prepay | postpay | partial
            'partial_prepay_percent'    => 50,
            'start_routing_after_prepay' => true,
            'require_full_payment_before_execution' => false,
            'enabled_methods'           => ['cash', 'card', 'sbp'],
            'active_provider'           => 'yoo',
            'providers'                 => [],
            'platform_commission'       => ['type' => 'percent', 'value' => 20],
            'gateway_fee_payer'         => 'platform', // platform | master
            'tax'                       => ['enabled' => true, 'type' => 'percent', 'value' => 6],
            'invoices'                  => ['enabled' => true, 'retention_months' => 39, 'show_download_after_order' => true],
            'master_notification_channels' => ['telegram', 'web_push'],
        ];
        $settings = get_option('csc_payment_settings', []);
        return wp_parse_args($settings, $defaults);
    }
}

/**
 * Сохранить настройки платёжного модуля.
 *
 * @param array $settings Новые настройки
 * @return bool Успех сохранения
 */
if (!function_exists('csc_payments_save_settings')) {
    function csc_payments_save_settings($settings) {
        return update_option('csc_payment_settings', $settings, false);
    }
}

/**
 * Проверить, включены ли платежи.
 *
 * @return bool
 */
if (!function_exists('csc_payments_enabled')) {
    function csc_payments_enabled() {
        $settings = csc_payments_get_settings();
        return !empty($settings['payment_enabled']);
    }
}

/**
 * Проверить, доступен ли конкретный способ оплаты.
 *
 * @param string $method Способ оплаты (cash, card, sbp, installment)
 * @return bool
 */
if (!function_exists('csc_payment_method_available')) {
    function csc_payment_method_available($method) {
        $settings = csc_payments_get_settings();

        // Глобальное отключение
        if (!$settings['payment_enabled']) {
            return false;
        }

        // Режим "только наличные"
        if ($settings['cash_only_mode']) {
            return $method === 'cash';
        }

        // Наличные
        if ($method === 'cash') {
            return !empty($settings['allow_cash']);
        }

        // Безналичные
        if (!$settings['allow_cashless']) {
            return false;
        }

        return in_array($method, $settings['enabled_methods'], true);
    }
}

/**
 * Получить список доступных способов оплаты для отображения клиенту.
 *
 * @return array Массив способов оплаты
 */
if (!function_exists('csc_get_available_payment_methods')) {
    function csc_get_available_payment_methods() {
        $all_methods = [
            'cash'       => ['label' => 'Наличные / Перевод', 'icon' => '💵'],
            'card'       => ['label' => 'Банковская карта', 'icon' => '💳'],
            'sbp'        => ['label' => 'СБП (Быстрая оплата)', 'icon' => '⚡'],
            'installment'=> ['label' => 'Рассрочка', 'icon' => '📅'],
        ];

        $available = [];
        foreach ($all_methods as $method => $info) {
            if (csc_payment_method_available($method)) {
                $available[$method] = $info;
            }
        }
        return $available;
    }
}

// ============================================================
// 3. ПОЛУЧЕНИЕ ПРОВАЙДЕРА (ЧЕРЕЗ РЕЕСТР)
// ============================================================

/**
 * Получить экземпляр активного провайдера.
 * Использует реестр провайдеров (из csc-payment-providers.php).
 *
 * @return object|null Экземпляр провайдера или null
 */
if (!function_exists('csc_get_active_payment_provider')) {
    function csc_get_active_payment_provider() {
        // Если реестр не загружен, возвращаем null
        if (!function_exists('csc_payment_providers')) {
            error_log('[CSC_PAYMENTS] Реестр провайдеров не загружен');
            return null;
        }

        $settings = csc_payments_get_settings();
        $active_id = $settings['active_provider'] ?? '';

        if (!empty($active_id)) {
            $provider = csc_payment_providers()->get_provider($active_id);
            if ($provider !== null && $provider->is_enabled()) {
                return $provider;
            }
        }

        // Фолбэк: первый включённый провайдер
        $enabled = csc_payment_providers()->get_enabled_providers();
        return !empty($enabled) ? reset($enabled) : null;
    }
}

/**
 * Получить провайдера по способу оплаты.
 *
 * @param string $method Способ оплаты
 * @return object|null Экземпляр провайдера или null
 */
if (!function_exists('csc_get_payment_provider_by_method')) {
    function csc_get_payment_provider_by_method($method) {
        if (!function_exists('csc_payment_providers')) {
            return null;
        }
        return csc_payment_providers()->get_provider_by_method($method);
    }
}

// ============================================================
// 4. СОЗДАНИЕ ПЛАТЕЖА
// ============================================================

/**
 * Создать платёж для заказа.
 * Вызывается после создания заказа при безналичной оплате.
 *
 * @param string $order_number Номер заказа
 * @param array  $params       Дополнительные параметры (amount, method)
 * @return array Результат: ['success' => bool, 'payment_id' => string, 'confirmation_url' => string, 'qr_code' => string, 'error' => string]
 */
if (!function_exists('csc_create_payment')) {
    function csc_create_payment($order_number, $params = []) {
        // Проверка что платежи включены
        if (!csc_payments_enabled()) {
            return ['success' => false, 'error' => 'Платежи отключены'];
        }

        // Получение заказа
        $order = csc_get_order($order_number);
        if (!$order) {
            return ['success' => false, 'error' => 'Заказ не найден'];
        }

        // Проверка что заказ не отменён и не оплачен
        $status = $order['status'] ?? '';
        $payment_status = $order['payment_status'] ?? 'no_payment_required';
        if (in_array($status, [CSC_STATUS_CANCELLED, CSC_STATUS_COMPLETED], true)) {
            return ['success' => false, 'error' => 'Заказ в статусе ' . $status];
        }
        if ($payment_status === 'paid') {
            return ['success' => false, 'error' => 'Заказ уже оплачен'];
        }

        // Сумма платежа
        $amount = isset($params['amount']) ? floatval($params['amount']) : floatval($order['total'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Некорректная сумма платежа'];
        }

        // Способ оплаты
        $method = $params['method'] ?? ($order['payment_method'] ?? 'card');

        // Получение провайдера
        $provider = csc_get_payment_provider_by_method($method);
        if (!$provider) {
            $provider = csc_get_active_payment_provider();
        }
        if (!$provider) {
            return ['success' => false, 'error' => 'Платёжный провайдер не настроен'];
        }

        // Проверка что провайдер поддерживает способ оплаты
        if (!in_array($method, $provider->get_supported_methods(), true)) {
            return ['success' => false, 'error' => 'Провайдер не поддерживает способ оплаты: ' . $method];
        }

        // Настройки
        $settings = csc_payments_get_settings();
        $cashless_mode = $settings['cashless_payment_mode'] ?? 'prepay';

        // Частичная оплата
        if ($cashless_mode === 'partial' && empty($params['is_remaining'])) {
            $partial_percent = floatval($settings['partial_prepay_percent'] ?? 50);
            $amount = round($amount * $partial_percent / 100, 2);
        }

        // Формирование параметров для провайдера
        $payment_params = [
            'order_number'   => $order_number,
            'amount'         => $amount,
            'currency'       => 'RUB',
            'description'    => 'Оплата заказа #' . $order_number,
            'customer_email' => $order['email'] ?? '',
            'customer_phone' => $order['phone'] ?? '',
            'return_url'     => add_query_arg(['csc_payment_return' => $order_number], home_url('/')),
            'cancel_url'     => add_query_arg(['csc_payment_cancel' => $order_number], home_url('/')),
            'metadata'       => [
                'order_number' => $order_number,
                'payment_mode' => $cashless_mode,
            ],
            'receipt'        => csc_build_receipt_data($order),
        ];

        // Создание платежа у провайдера
        try {
            $result = $provider->create_payment($payment_params);
        } catch (Exception $e) {
            error_log('[CSC_PAYMENTS] Ошибка создания платежа: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Ошибка создания платежа: ' . $e->getMessage()];
        }

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error_message'] ?? 'Ошибка провайдера'];
        }

        $payment_id = $result['payment_id'] ?? '';
        $confirmation_url = $result['confirmation_url'] ?? '';
        $qr_code = $result['qr_code'] ?? '';

        // Создание транзакции в БД
        global $wpdb;
        $table_transactions = $wpdb->prefix . 'csc_transactions';
        $wpdb->insert($table_transactions, [
            'order_number'    => $order_number,
            'payment_id'      => $payment_id,
            'provider'        => $provider->get_id(),
            'method'          => $method,
            'payment_mode'    => $cashless_mode,
            'type'            => 'payment',
            'status'          => 'pending',
            'amount'          => $amount,
            'currency'        => 'RUB',
            'description'     => 'Оплата заказа #' . $order_number,
            'provider_response' => wp_json_encode($result['raw_response'] ?? []),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);
        $transaction_id = $wpdb->insert_id;

        // Обновление заказа
        $new_payment_status = 'pending';
        $order['payment_status'] = $new_payment_status;
        $order['payment_provider'] = $provider->get_id();
        $order['payment_mode'] = $cashless_mode;
        $order['payment_transaction_id'] = $transaction_id;
        $order['paid_amount'] = 0;
        $order['remaining_amount'] = floatval($order['total'] ?? 0);
        $order['status'] = CSC_STATUS_AWAITING_PAYMENT;

        csc_update_order($order_number, $order);

        // Логирование истории статусов
        if (function_exists('csc_log_status_change')) {
            csc_log_status_change(
                $order_number,
                $status,
                CSC_STATUS_AWAITING_PAYMENT,
                'system',
                'payment',
                'Создан платёж ' . $payment_id . ' на сумму ' . number_format($amount, 2, '.', ' ') . ' ₽'
            );
        }

        // Логирование уведомления
        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                $order_number,
                'customer',
                $order['phone'] ?? '',
                'web',
                '💳 Платёж создан',
                'Платёж на сумму ' . number_format($amount, 0, '.', ' ') . ' ₽ ожидает оплаты.',
                ['payment_id' => $payment_id, 'transaction_id' => $transaction_id]
            );
        }

        return [
            'success'          => true,
            'payment_id'       => $payment_id,
            'transaction_id'   => $transaction_id,
            'confirmation_url' => $confirmation_url,
            'qr_code'          => $qr_code,
            'amount'           => $amount,
        ];
    }
}

// ============================================================
// 5. ФОРМИРОВАНИЕ ДАННЫХ ЧЕКА (ДЛЯ ФИСКАЛИЗАЦИИ)
// ============================================================

/**
 * Формирование данных чека для провайдера.
 * Используется при создании платежа для отправки в онлайн-кассу.
 *
 * @param array $order Данные заказа
 * @return array Данные чека
 */
if (!function_exists('csc_build_receipt_data')) {
    function csc_build_receipt_data($order) {
        $items = [];
        $services = $order['services'] ?? [];

        // Парсинг услуг из формата "Название ×2 (1000 ₽, 60 мин)"
        foreach ($services as $service_str) {
            $name = $service_str;
            $qty = 1;
            $price = 0;

            if (preg_match('/^(.*?)\s+×(\d+)/u', $service_str, $matches)) {
                $name = trim($matches[1]);
                $qty = intval($matches[2]);
            }

            // Извлечение цены из строки
            if (preg_match('/\(([\d\s]+)\s*₽/u', $service_str, $matches)) {
                $price = floatval(str_replace(' ', '', $matches[1]));
            }

            if ($price > 0) {
                $items[] = [
                    'description' => mb_substr($name, 0, 128),
                    'quantity'    => $qty,
                    'amount'      => ['value' => number_format($price / $qty, 2, '.', ''), 'currency' => 'RUB'],
                    'vat_code'    => 1, // Без НДС
                ];
            }
        }

        // Если не удалось распарсить, добавляем одну позицию
        if (empty($items)) {
            $items[] = [
                'description' => 'Оплата услуг по заказу #' . ($order['order_number'] ?? ''),
                'quantity'    => 1,
                'amount'      => ['value' => number_format(floatval($order['total'] ?? 0), 2, '.', ''), 'currency' => 'RUB'],
                'vat_code'    => 1,
            ];
        }

        $receipt = [
            'customer' => [
                'email' => $order['email'] ?? '',
                'phone' => $order['phone'] ?? '',
            ],
            'items' => $items,
        ];

        // Скидка
        $discount = floatval($order['discount'] ?? 0);
        if ($discount > 0) {
            $receipt['discount'] = $discount;
        }

        return $receipt;
    }
}

// ============================================================
// 6. ОБРАБОТКА ВЕБХУКА (ОБНОВЛЕНИЕ СТАТУСА ПЛАТЕЖА)
// ============================================================

/**
 * Обработка вебхука от провайдера.
 * Вызывается из csc-payment-webhooks.php.
 *
 * @param string $provider_id ID провайдера
 * @param array  $headers     HTTP-заголовки запроса
 * @param string $body        Тело запроса
 * @return array Результат обработки
 */
if (!function_exists('csc_process_payment_webhook')) {
    function csc_process_payment_webhook($provider_id, $headers, $body) {
        // Получение провайдера
        if (!function_exists('csc_get_payment_provider')) {
            return ['success' => false, 'error' => 'Реестр провайдеров не загружен'];
        }

        $provider = csc_get_payment_provider($provider_id);
        if (!$provider) {
            return ['success' => false, 'error' => 'Провайдер не найден: ' . $provider_id];
        }

        // Проверка подписи вебхука
        if (!$provider->verify_webhook($headers, $body)) {
            error_log('[CSC_PAYMENTS] Неверная подпись вебхука от провайдера: ' . $provider_id);
            return ['success' => false, 'error' => 'Invalid webhook signature'];
        }

        // Парсинг вебхука
        $webhook_data = $provider->parse_webhook($body);
        if (empty($webhook_data['payment_id'])) {
            return ['success' => false, 'error' => 'Missing payment_id in webhook'];
        }

        $payment_id = $webhook_data['payment_id'];
        $new_status = $webhook_data['status'] ?? '';
        $order_number = $webhook_data['order_number'] ?? '';

        // Поиск транзакции
        global $wpdb;
        $table_transactions = $wpdb->prefix . 'csc_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_transactions} WHERE payment_id = %s AND provider = %s ORDER BY id DESC LIMIT 1",
            $payment_id,
            $provider_id
        ));

        if (!$transaction) {
            error_log('[CSC_PAYMENTS] Транзакция не найдена: ' . $payment_id);
            return ['success' => false, 'error' => 'Transaction not found'];
        }

        // Идемпотентность: если статус уже обработан, не обрабатываем повторно
        $current_status = $transaction->status;
        if ($current_status === $new_status) {
            return ['success' => true, 'message' => 'Already processed'];
        }

        // Обновление транзакции
        $update_data = [
            'status'          => $new_status,
            'provider_response' => $body,
            'webhook_received_at' => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ];

        if ($new_status === 'succeeded') {
            $update_data['paid_at'] = current_time('mysql');
        } elseif ($new_status === 'refunded' || $new_status === 'partially_refunded') {
            $update_data['refunded_at'] = current_time('mysql');
        }

        $wpdb->update($table_transactions, $update_data, ['id' => $transaction->id]);

        // Обновление заказа
        if (!empty($transaction->order_number)) {
            $order_number = $transaction->order_number;
            csc_update_order_payment_status($order_number, $new_status, $transaction);
        }

        return ['success' => true, 'payment_id' => $payment_id, 'status' => $new_status];
    }
}

/**
 * Обновление статуса оплаты заказа на основе статуса транзакции.
 *
 * @param string $order_number Номер заказа
 * @param string $payment_status Новый статус платежа (из провайдера)
 * @param object $transaction Данные транзакции
 */
if (!function_exists('csc_update_order_payment_status')) {
    function csc_update_order_payment_status($order_number, $payment_status, $transaction) {
        $order = csc_get_order($order_number);
        if (!$order) return;

        $old_status = $order['status'] ?? '';
        $old_payment_status = $order['payment_status'] ?? 'no_payment_required';
        $total = floatval($order['total'] ?? 0);

        switch ($payment_status) {
            case 'succeeded':
                $order['payment_status'] = 'paid';
                $order['paid_at'] = current_time('mysql');
                $order['paid_amount'] = floatval($transaction->amount);
                $order['remaining_amount'] = max(0, $total - floatval($transaction->amount));

                // Если полная оплата — меняем статус заказа и запускаем маршрутизацию
                if ($order['remaining_amount'] <= 0) {
                    $order['payment_status'] = 'paid';
                    if ($old_status === CSC_STATUS_AWAITING_PAYMENT) {
                        $order['status'] = CSC_STATUS_SEARCHING;

                        // Логирование перехода статуса
                        if (function_exists('csc_log_status_change')) {
                            csc_log_status_change(
                                $order_number,
                                CSC_STATUS_AWAITING_PAYMENT,
                                CSC_STATUS_SEARCHING,
                                'system',
                                'payment',
                                'Оплата получена. Запуск маршрутизации.'
                            );
                        }

                        // Запуск маршрутизации
                        if (function_exists('csc_start_order_routing')) {
                            csc_start_order_routing($order_number);
                        }
                    }
                } else {
                    $order['payment_status'] = 'partially_paid';
                }

                // Уведомления клиенту
                if (function_exists('csc_log_notification')) {
                    csc_log_notification(
                        $order_number,
                        'customer',
                        $order['phone'] ?? '',
                        'web',
                        '✅ Оплата получена',
                        'Платёж на сумму ' . number_format(floatval($transaction->amount), 0, '.', ' ') . ' ₽ успешно обработан.',
                        ['payment_status' => 'paid']
                    );
                }

                // Push клиенту
                if (function_exists('csc_send_web_push_notification')) {
                    $phone = csc_normalize_phone($order['phone'] ?? '');
                    if ($phone) {
                        $subs = get_option("csc_push_subs_customer_{$phone}", []);
                        foreach ($subs as $sub_json) {
                            csc_send_web_push_notification(
                                $sub_json,
                                '✅ Оплата получена',
                                'Заказ #' . $order_number . ' оплачен.',
                                $order_number,
                                'customer',
                                $phone
                            );
                        }
                    }
                }

                // Уведомления мастеру (если назначен)
                $master_id = $order['master_id'] ?? '';
                if ($master_id) {
                    csc_notify_master_payment($master_id, $order_number, 'paid', floatval($transaction->amount));
                }

                break;

            case 'failed':
            case 'cancelled':
                $order['payment_status'] = 'unpaid';

                if (function_exists('csc_log_notification')) {
                    csc_log_notification(
                        $order_number,
                        'customer',
                        $order['phone'] ?? '',
                        'web',
                        '❌ Оплата не прошла',
                        'Платёж не был обработан. Попробуйте ещё раз.',
                        ['payment_status' => $payment_status]
                    );
                }
                break;

            case 'refunded':
            case 'partially_refunded':
                $order['payment_status'] = $payment_status;
                $order['refunded_amount'] = floatval($order['refunded_amount'] ?? 0) + floatval($transaction->amount);

                if (function_exists('csc_log_notification')) {
                    csc_log_notification(
                        $order_number,
                        'customer',
                        $order['phone'] ?? '',
                        'web',
                        '↩️ Возврат оформлен',
                        'Возврат на сумму ' . number_format(floatval($transaction->amount), 0, '.', ' ') . ' ₽ оформлен.',
                        ['payment_status' => $payment_status]
                    );
                }
                break;
        }

        csc_update_order($order_number, $order);
    }
}

// ============================================================
// 7. ВОЗВРАТ ПЛАТЕЖА
// ============================================================

/**
 * Оформить возврат платежа (полный или частичный).
 *
 * @param string $order_number Номер заказа
 * @param float  $amount       Сумма возврата (0 = полный возврат)
 * @param string $reason       Причина возврата
 * @return array Результат: ['success' => bool, 'refund_id' => string, 'error' => string]
 */
if (!function_exists('csc_refund_payment')) {
    function csc_refund_payment($order_number, $amount = 0, $reason = '') {
        // Проверка прав
        if (!current_user_can('manage_options')) {
            return ['success' => false, 'error' => 'Доступ запрещён'];
        }

        $order = csc_get_order($order_number);
        if (!$order) {
            return ['success' => false, 'error' => 'Заказ не найден'];
        }

        $payment_status = $order['payment_status'] ?? '';
        if (!in_array($payment_status, ['paid', 'partially_paid'], true)) {
            return ['success' => false, 'error' => 'Заказ не оплачен'];
        }

        // Поиск успешной транзакции
        global $wpdb;
        $table_transactions = $wpdb->prefix . 'csc_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_transactions} WHERE order_number = %s AND type = 'payment' AND status = 'succeeded' ORDER BY id DESC LIMIT 1",
            $order_number
        ));

        if (!$transaction) {
            return ['success' => false, 'error' => 'Успешная транзакция не найдена'];
        }

        // Сумма возврата
        $refund_amount = floatval($amount);
        if ($refund_amount <= 0) {
            $refund_amount = floatval($transaction->amount); // Полный возврат
        }

        // Проверка что сумма возврата не превышает сумму платежа
        $already_refunded = floatval($order['refunded_amount'] ?? 0);
        $max_refundable = floatval($transaction->amount) - $already_refunded;
        if ($refund_amount > $max_refundable) {
            return ['success' => false, 'error' => 'Сумма возврата превышает доступную: ' . number_format($max_refundable, 2, '.', ' ') . ' ₽'];
        }

        // Получение провайдера
        $provider = csc_get_payment_provider($transaction->provider);
        if (!$provider) {
            return ['success' => false, 'error' => 'Провайдер не найден'];
        }

        // Проверка поддержки частичного возврата
        if ($refund_amount < floatval($transaction->amount) && !$provider->supports_partial_refund()) {
            return ['success' => false, 'error' => 'Провайдер не поддерживает частичный возврат'];
        }

        // Оформление возврата у провайдера
        try {
            $result = $provider->refund_payment($transaction->payment_id, $refund_amount, $reason);
        } catch (Exception $e) {
            error_log('[CSC_PAYMENTS] Ошибка возврата: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Ошибка возврата: ' . $e->getMessage()];
        }

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error_message'] ?? 'Ошибка провайдера'];
        }

        $refund_id = $result['refund_id'] ?? '';
        $refund_status = $result['status'] ?? 'succeeded';

        // Создание транзакции возврата
        $wpdb->insert($table_transactions, [
            'order_number'    => $order_number,
            'payment_id'      => $transaction->payment_id,
            'provider'        => $transaction->provider,
            'method'          => $transaction->method,
            'payment_mode'    => $transaction->payment_mode,
            'type'            => 'refund',
            'status'          => $refund_status,
            'amount'          => $refund_amount,
            'currency'        => 'RUB',
            'description'     => 'Возврат по заказу #' . $order_number . ($reason ? ': ' . $reason : ''),
            'provider_response' => wp_json_encode($result['raw_response'] ?? []),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        // Обновление заказа
        $order['refunded_amount'] = $already_refunded + $refund_amount;
        $order['payment_status'] = ($order['refunded_amount'] >= floatval($transaction->amount)) ? 'refunded' : 'partially_refunded';
        csc_update_order($order_number, $order);

        // Логирование
        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                $order_number,
                'admin',
                get_current_user_id(),
                'web',
                '↩️ Возврат оформлен',
                'Возврат ' . number_format($refund_amount, 0, '.', ' ') . ' ₽ по заказу #' . $order_number,
                ['refund_id' => $refund_id, 'amount' => $refund_amount, 'reason' => $reason]
            );
        }

        return ['success' => true, 'refund_id' => $refund_id, 'amount' => $refund_amount];
    }
}

// ============================================================
// 8. ПОЛУЧЕНИЕ СТАТУСА ПЛАТЕЖА
// ============================================================

/**
 * Получить текущий статус оплаты заказа.
 *
 * @param string $order_number Номер заказа
 * @return array Статус оплаты
 */
if (!function_exists('csc_get_payment_status')) {
    function csc_get_payment_status($order_number) {
        $order = csc_get_order($order_number);
        if (!$order) {
            return ['payment_status' => 'not_found'];
        }

        return [
            'payment_status'   => $order['payment_status'] ?? 'no_payment_required',
            'payment_method'   => $order['payment_method'] ?? 'cash',
            'payment_provider' => $order['payment_provider'] ?? '',
            'payment_mode'     => $order['payment_mode'] ?? '',
            'total'            => floatval($order['total'] ?? 0),
            'paid_amount'      => floatval($order['paid_amount'] ?? 0),
            'remaining_amount' => floatval($order['remaining_amount'] ?? 0),
            'paid_at'          => $order['paid_at'] ?? null,
            'refunded_amount'  => floatval($order['refunded_amount'] ?? 0),
        ];
    }
}

// ============================================================
// 9. ПОЛЛИНГ СТАТУСА ПЛАТЕЖА (ДЛЯ КЛИЕНТА)
// ============================================================

/**
 * AJAX-обработчик поллинга статуса платежа клиентом.
 * Вызывается каждые 5 секунд из калькулятора после создания платежа.
 */
add_action('wp_ajax_csc_poll_payment_status', 'csc_poll_payment_status_handler');
add_action('wp_ajax_nopriv_csc_poll_payment_status', 'csc_poll_payment_status_handler');

if (!function_exists('csc_poll_payment_status_handler')) {
    function csc_poll_payment_status_handler() {
        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        $auth_token = sanitize_text_field($_POST['auth_token'] ?? '');

        if (empty($order_number)) {
            wp_send_json_error(['message' => 'Не указан номер заказа']);
        }

        // Проверка авторизации через auth_token
        $order = csc_get_order($order_number);
        if (!$order) {
            wp_send_json_error(['message' => 'Заказ не найден']);
        }

        $stored_token = $order['auth_token'] ?? '';
        if (empty($stored_token) || !hash_equals($stored_token, $auth_token)) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }

        // Получение статуса
        $payment_status = csc_get_payment_status($order_number);

        // Если статус ещё "ожидает" — проверяем у провайдера
        if ($payment_status['payment_status'] === 'pending') {
            $provider_id = $payment_status['payment_provider'];
            if ($provider_id && function_exists('csc_get_payment_provider')) {
                $provider = csc_get_payment_provider($provider_id);
                if ($provider) {
                    // Поиск транзакции
                    global $wpdb;
                    $table_transactions = $wpdb->prefix . 'csc_transactions';
                    $transaction = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table_transactions} WHERE order_number = %s AND type = 'payment' ORDER BY id DESC LIMIT 1",
                        $order_number
                    ));

                    if ($transaction && !empty($transaction->payment_id)) {
                        try {
                            $status_result = $provider->get_payment_status($transaction->payment_id);
                            if ($status_result['success'] && $status_result['status'] !== 'pending') {
                                // Обновление транзакции
                                $wpdb->update($table_transactions, [
                                    'status'     => $status_result['status'],
                                    'updated_at' => current_time('mysql'),
                                    'paid_at'    => ($status_result['status'] === 'succeeded') ? current_time('mysql') : $transaction->paid_at,
                                ], ['id' => $transaction->id]);

                                // Обновление заказа
                                csc_update_order_payment_status($order_number, $status_result['status'], $transaction);

                                // Обновление ответа
                                $payment_status = csc_get_payment_status($order_number);
                            }
                        } catch (Exception $e) {
                            error_log('[CSC_PAYMENTS] Ошибка проверки статуса: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        wp_send_json_success($payment_status);
    }
}

// ============================================================
// 10. ПРОВЕРКА ЗАВИСШИХ ПЛАТЕЖЕЙ (КРОН)
// ============================================================

/**
 * Крон-задача: проверка зависших платежей.
 * Платежи в статусе "pending" старше 24 часов проверяются у провайдера.
 * Если оплата прошла — обновляем статус. Если нет — отменяем.
 */
add_action('csc_payment_check_cron', 'csc_check_pending_payments');

if (!function_exists('csc_check_pending_payments')) {
    function csc_check_pending_payments() {
        global $wpdb;
        $table_transactions = $wpdb->prefix . 'csc_transactions';

        // Поиск транзакций в статусе "pending" старше 24 часов
        $cutoff = date('Y-m-d H:i:s', time() - CSC_PAYMENT_PENDING_TIMEOUT);
        $pending_transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_transactions} WHERE status = 'pending' AND type = 'payment' AND created_at < %s ORDER BY id ASC LIMIT 50",
            $cutoff
        ));

        if (empty($pending_transactions)) {
            return;
        }

        foreach ($pending_transactions as $transaction) {
            $provider_id = $transaction->provider;
            $payment_id = $transaction->payment_id;
            $order_number = $transaction->order_number;

            if (empty($provider_id) || empty($payment_id)) {
                continue;
            }

            // Получение провайдера
            if (!function_exists('csc_get_payment_provider')) {
                continue;
            }
            $provider = csc_get_payment_provider($provider_id);
            if (!$provider) {
                continue;
            }

            // Проверка статуса у провайдера
            try {
                $status_result = $provider->get_payment_status($payment_id);
            } catch (Exception $e) {
                error_log('[CSC_PAYMENTS] Ошибка проверки зависшего платежа ' . $payment_id . ': ' . $e->getMessage());
                continue;
            }

            if (!$status_result['success']) {
                continue;
            }

            $new_status = $status_result['status'];

            if ($new_status === 'succeeded') {
                // Оплата прошла — обновляем
                $wpdb->update($table_transactions, [
                    'status'     => 'succeeded',
                    'paid_at'    => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $transaction->id]);

                csc_update_order_payment_status($order_number, 'succeeded', $transaction);
                error_log('[CSC_PAYMENTS] Зависший платёж ' . $payment_id . ' оказался оплаченным');
            } elseif (in_array($new_status, ['failed', 'cancelled', 'expired'], true)) {
                // Оплата не прошла — отменяем
                $wpdb->update($table_transactions, [
                    'status'     => 'failed',
                    'updated_at' => current_time('mysql'),
                ], ['id' => $transaction->id]);

                // Обновление заказа
                $order = csc_get_order($order_number);
                if ($order && ($order['payment_status'] ?? '') === 'pending') {
                    $order['payment_status'] = 'unpaid';
                    $order['status'] = CSC_STATUS_CANCELLED;
                    $order['cancelled_at'] = current_time('mysql');
                    $order['cancel_reason'] = 'Оплата не была завершена в течение 24 часов';
                    csc_update_order($order_number, $order);

                    if (function_exists('csc_log_status_change')) {
                        csc_log_status_change(
                            $order_number,
                            CSC_STATUS_AWAITING_PAYMENT,
                            CSC_STATUS_CANCELLED,
                            'system',
                            'payment_cron',
                            'Платёж не был завершён в течение 24 часов'
                        );
                    }
                }
                error_log('[CSC_PAYMENTS] Зависший платёж ' . $payment_id . ' отменён');
            }
            // Если статус ещё "pending" — оставляем как есть
        }
    }
}

// ============================================================
// 11. УВЕДОМЛЕНИЕ О ЗАВИСШИХ ПЛАТЕЖАХ (КРОН)
// ============================================================

/**
 * Крон-задача: уведомление о зависших платежах.
 * Уведомляет админа и мастера о платежах старше 24 часов.
 */
add_action('csc_payment_stuck_notify_cron', 'csc_notify_stuck_payments');

if (!function_exists('csc_notify_stuck_payments')) {
    function csc_notify_stuck_payments() {
        global $wpdb;
        $table_transactions = $wpdb->prefix . 'csc_transactions';

        $cutoff = date('Y-m-d H:i:s', time() - CSC_PAYMENT_PENDING_TIMEOUT);
        $stuck_transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_transactions} WHERE status = 'pending' AND type = 'payment' AND created_at < %s ORDER BY id ASC LIMIT 20",
            $cutoff
        ));

        if (empty($stuck_transactions)) {
            return;
        }

        $settings = csc_payments_get_settings();
        $channels = $settings['master_notification_channels'] ?? ['telegram', 'web_push'];

        foreach ($stuck_transactions as $transaction) {
            $order_number = $transaction->order_number;
            $amount = floatval($transaction->amount);

            // Уведомление админу
            if (function_exists('csc_log_notification')) {
                csc_log_notification(
                    $order_number,
                    'admin',
                    'admin',
                    'web',
                    '⚠️ Зависший платёж',
                    'Платёж по заказу #' . $order_number . ' на сумму ' . number_format($amount, 0, '.', ' ') . ' ₽ не завершён более 24 часов.',
                    ['type' => 'stuck_payment', 'transaction_id' => $transaction->id]
                );
            }

            // Уведомление мастеру (если назначен)
            $order = csc_get_order($order_number);
            if ($order) {
                $master_id = $order['master_id'] ?? '';
                if ($master_id) {
                    csc_notify_master_payment($master_id, $order_number, 'stuck', $amount);
                }
            }
        }
    }
}

// ============================================================
// 12. УВЕДОМЛЕНИЯ МАСТЕРУ ОБ ОПЛАТЕ
// ============================================================

/**
 * Отправка уведомления мастеру о событии оплаты.
 * Каналы: Telegram + Web Push (согласно настройкам).
 *
 * @param string $master_id    ID мастера
 * @param string $order_number Номер заказа
 * @param string $event_type   Тип события: paid | refunded | stuck
 * @param float  $amount       Сумма
 */
if (!function_exists('csc_notify_master_payment')) {
    function csc_notify_master_payment($master_id, $order_number, $event_type, $amount) {
        $settings = csc_payments_get_settings();
        $channels = $settings['master_notification_channels'] ?? ['telegram', 'web_push'];

        // Формирование сообщения
        $messages = [
            'paid'     => '✅ Новая оплата по заказу #' . $order_number . ', сумма: ' . number_format($amount, 0, '.', ' ') . ' ₽',
            'refunded' => '↩️ Возврат по заказу #' . $order_number . ', сумма: ' . number_format($amount, 0, '.', ' ') . ' ₽',
            'stuck'    => '⚠️ Зависший платёж по заказу #' . $order_number . ' (более 24ч), сумма: ' . number_format($amount, 0, '.', ' ') . ' ₽',
        ];

        $titles = [
            'paid'     => '💳 Новая оплата #' . $order_number,
            'refunded' => '↩️ Возврат #' . $order_number,
            'stuck'    => '⚠️ Зависший платёж #' . $order_number,
        ];

        $message = $messages[$event_type] ?? $messages['paid'];
        $title = $titles[$event_type] ?? $titles['paid'];

        // Логирование
        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                $order_number,
                'master',
                $master_id,
                'web',
                $title,
                $message,
                ['type' => $event_type, 'amount' => $amount]
            );
        }

        // Telegram
        if (in_array('telegram', $channels, true)) {
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
            $master = $masters[$master_id] ?? null;
            $tg_chat_id = $master['tg_chat_id'] ?? '';

            if ($tg_chat_id && function_exists('csc_send_tg_notification')) {
                csc_send_tg_notification(
                    $tg_chat_id,
                    $title,
                    $message,
                    $order_number,
                    'master',
                    $master_id,
                    null
                );
            }
        }

        // Web Push
        if (in_array('web_push', $channels, true)) {
            if (function_exists('csc_send_web_push_notification')) {
                $subs = get_option("csc_push_subs_master_{$master_id}", []);
                foreach ($subs as $sub_json) {
                    csc_send_web_push_notification(
                        $sub_json,
                        $title,
                        $message,
                        $order_number,
                        'master',
                        $master_id
                    );
                }
            }
        }
    }
}

// ============================================================
// 13. AJAX: СОЗДАНИЕ ПЛАТЕЖА (ДЛЯ КАЛЬКУЛЯТОРА)
// ============================================================

add_action('wp_ajax_csc_create_payment', 'csc_create_payment_ajax');
add_action('wp_ajax_nopriv_csc_create_payment', 'csc_create_payment_ajax');

if (!function_exists('csc_create_payment_ajax')) {
    function csc_create_payment_ajax() {
        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        $auth_token = sanitize_text_field($_POST['auth_token'] ?? '');
        $method = sanitize_text_field($_POST['payment_method'] ?? 'card');

        if (empty($order_number)) {
            wp_send_json_error(['message' => 'Не указан номер заказа']);
        }

        // Проверка авторизации
        $order = csc_get_order($order_number);
        if (!$order) {
            wp_send_json_error(['message' => 'Заказ не найден']);
        }

        $stored_token = $order['auth_token'] ?? '';
        if (empty($stored_token) || !hash_equals($stored_token, $auth_token)) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }

        // Проверка способа оплаты
        if (!csc_payment_method_available($method)) {
            wp_send_json_error(['message' => 'Способ оплаты недоступен']);
        }

        // Создание платежа
        $result = csc_create_payment($order_number, ['method' => $method]);

        if ($result['success']) {
            wp_send_json_success([
                'payment_id'       => $result['payment_id'],
                'confirmation_url' => $result['confirmation_url'],
                'qr_code'          => $result['qr_code'],
                'amount'           => $result['amount'],
                'poll_interval'    => CSC_PAYMENT_POLL_INTERVAL,
            ]);
        } else {
            wp_send_json_error(['message' => $result['error']]);
        }
    }
}

// ============================================================
// 14. AJAX: ДОПЛАТА (ДЛЯ ЧАСТИЧНОЙ ОПЛАТЫ)
// ============================================================

add_action('wp_ajax_csc_pay_remaining', 'csc_pay_remaining_ajax');
add_action('wp_ajax_nopriv_csc_pay_remaining', 'csc_pay_remaining_ajax');

if (!function_exists('csc_pay_remaining_ajax')) {
    function csc_pay_remaining_ajax() {
        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        $auth_token = sanitize_text_field($_POST['auth_token'] ?? '');

        if (empty($order_number)) {
            wp_send_json_error(['message' => 'Не указан номер заказа']);
        }

        $order = csc_get_order($order_number);
        if (!$order) {
            wp_send_json_error(['message' => 'Заказ не найден']);
        }

        $stored_token = $order['auth_token'] ?? '';
        if (empty($stored_token) || !hash_equals($stored_token, $auth_token)) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }

        $remaining = floatval($order['remaining_amount'] ?? 0);
        if ($remaining <= 0) {
            wp_send_json_error(['message' => 'Заказ уже полностью оплачен']);
        }

        // Создание платежа на остаток
        $result = csc_create_payment($order_number, [
            'amount'       => $remaining,
            'is_remaining' => true,
        ]);

        if ($result['success']) {
            wp_send_json_success([
                'payment_id'       => $result['payment_id'],
                'confirmation_url' => $result['confirmation_url'],
                'qr_code'          => $result['qr_code'],
                'amount'           => $result['amount'],
            ]);
        } else {
            wp_send_json_error(['message' => $result['error']]);
        }
    }
}

// ============================================================
// 15. AJAX: ВОЗВРАТ (ДЛЯ АДМИНКИ)
// ============================================================

add_action('wp_ajax_csc_admin_refund_payment', 'csc_admin_refund_payment_ajax');

if (!function_exists('csc_admin_refund_payment_ajax')) {
    function csc_admin_refund_payment_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }

        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? '');

        $result = csc_refund_payment($order_number, $amount, $reason);

        if ($result['success']) {
            wp_send_json_success([
                'message'   => 'Возврат оформлен на сумму ' . number_format($result['amount'], 0, '.', ' ') . ' ₽',
                'refund_id' => $result['refund_id'],
            ]);
        } else {
            wp_send_json_error(['message' => $result['error']]);
        }
    }
}

// ============================================================
// 16. ХУКИ ДЛЯ ИНТЕГРАЦИИ
// ============================================================

/**
 * Действие: после создания платежа.
 * Позволяет другим модулям реагировать.
 *
 * @param string $order_number Номер заказа
 * @param string $payment_id   ID платежа у провайдера
 * @param float  $amount       Сумма платежа
 * @param string $method       Способ оплаты
 */
// Пример:
// add_action('csc_payment_created', function($order_number, $payment_id, $amount, $method) {
//     // Дополнительная логика
// }, 10, 4);

/**
 * Действие: после успешной оплаты.
 *
 * @param string $order_number Номер заказа
 * @param float  $amount       Сумма оплаты
 */
// Пример:
// add_action('csc_payment_succeeded', function($order_number, $amount) {
//     // Дополнительная логика
// }, 10, 2);

/**
 * Действие: после оформления возврата.
 *
 * @param string $order_number Номер заказа
 * @param float  $amount       Сумма возврата
 * @param string $reason       Причина
 */
// Пример:
// add_action('csc_payment_refunded', function($order_number, $amount, $reason) {
//     // Дополнительная логика
// }, 10, 3);

// ============================================================
// 17. РЕГИСТРАЦИЯ КРОН-ЗАДАЧ
// ============================================================

/**
 * Регистрация крон-задач при активации плагина.
 * Вызывается из custom-service-calculator.php.
 */
if (!function_exists('csc_register_payment_cron_hooks')) {
    function csc_register_payment_cron_hooks() {
        // Проверка зависших платежей — каждые 5 минут
        if (!wp_next_scheduled('csc_payment_check_cron')) {
            wp_schedule_event(time(), 'csc_every_5_minutes', 'csc_payment_check_cron');
        }

        // Уведомление о зависших платежах — каждый час
        if (!wp_next_scheduled('csc_payment_stuck_notify_cron')) {
            wp_schedule_event(time(), 'hourly', 'csc_payment_stuck_notify_cron');
        }
    }
}

// Регистрация кастомного интервала для крона
add_filter('cron_schedules', 'csc_add_payment_cron_intervals');
if (!function_exists('csc_add_payment_cron_intervals')) {
    function csc_add_payment_cron_intervals($schedules) {
        if (!isset($schedules['csc_every_5_minutes'])) {
            $schedules['csc_every_5_minutes'] = [
                'interval' => 300,
                'display'  => 'Каждые 5 минут (платежи CSC)',
            ];
        }
        return $schedules;
    }
}

// Регистрация крон-задач при загрузке модуля
add_action('init', 'csc_register_payment_cron_hooks');

// Очистка крон-задач при деактивации плагина
add_action('csc_plugin_deactivate', 'csc_deactivate_payment_cron');
if (!function_exists('csc_deactivate_payment_cron')) {
    function csc_deactivate_payment_cron() {
        wp_clear_scheduled_hook('csc_payment_check_cron');
        wp_clear_scheduled_hook('csc_payment_stuck_notify_cron');
    }
}