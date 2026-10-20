<?php
/**
 * Файл: csc-finance.php
 * Описание: Полноценная внутренняя бухгалтерия плагина.
 * Версия: 1.0
 *
 * Архитектура:
 * - Двойная запись (каждая операция = 2 проводки: дебет + кредит)
 * - Аккаунты: platform_cash, platform_commission, master_{id}, penalties, client_prepay
 * - Триггеры на все финансовые события плагина
 * - Админ-панель: баланс, транзакции, выплаты, отчёты
 *
 * Таблицы:
 * - {prefix}csc_transactions — платёжные транзакции
 * - {prefix}csc_financial_ledger — журнал проводок (двойная запись)
 * - {prefix}csc_invoices — квитанции для клиентов
 * - {prefix}csc_fiscal_receipts — фискальные чеки (задел под 54-ФЗ)
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================
if (!defined('CSC_FINANCE_VERSION')) {
    define('CSC_FINANCE_VERSION', '1.0');
}
if (!defined('CSC_FINANCE_CACHE_TTL')) {
    define('CSC_FINANCE_CACHE_TTL', 300); // 5 минут
}
if (!defined('CSC_FINANCE_ITEMS_PER_PAGE')) {
    define('CSC_FINANCE_ITEMS_PER_PAGE', 25);
}

// ============================================================
// 2. ИНИЦИАЛИЗАЦИЯ ТАБЛИЦ
// ============================================================

/**
 * Создание финансовых таблиц.
 * Вызывается при активации плагина и при проверке версии.
 */
if (!function_exists('csc_finance_create_tables')) {
    function csc_finance_create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // --- Таблица транзакций ---
        $table_transactions = $wpdb->prefix . 'csc_transactions';
        $sql_transactions = "CREATE TABLE IF NOT EXISTS $table_transactions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            payment_id VARCHAR(100) NOT NULL DEFAULT '',
            provider VARCHAR(50) NOT NULL DEFAULT 'cash',
            method VARCHAR(50) NOT NULL DEFAULT 'cash',
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'full',
            type ENUM('income','expense','refund','penalty','payout','commission') NOT NULL DEFAULT 'income',
            status ENUM('pending','processing','completed','failed','refunded') NOT NULL DEFAULT 'pending',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'RUB',
            description TEXT,
            master_id VARCHAR(100) NOT NULL DEFAULT '',
            client_phone VARCHAR(30) NOT NULL DEFAULT '',
            provider_response TEXT,
            webhook_received_at DATETIME DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            refunded_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_payment_id (payment_id),
            KEY idx_order (order_number),
            KEY idx_master (master_id),
            KEY idx_status (status),
            KEY idx_type (type),
            KEY idx_created (created_at),
            KEY idx_client_phone (client_phone)
        ) $charset_collate;";

        // --- Таблица журнала проводок (двойная запись) ---
        $table_ledger = $wpdb->prefix . 'csc_financial_ledger';
        $sql_ledger = "CREATE TABLE IF NOT EXISTS $table_ledger (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(30) NOT NULL DEFAULT 'platform',
            entity_id VARCHAR(100) NOT NULL DEFAULT '',
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
            type ENUM('debit','credit') NOT NULL,
            account VARCHAR(50) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            balance_before DECIMAL(14,2) NOT NULL DEFAULT 0,
            balance_after DECIMAL(14,2) NOT NULL DEFAULT 0,
            description TEXT,
            created_by VARCHAR(50) NOT NULL DEFAULT 'system',
            created_by_id VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_order (order_number),
            KEY idx_account (account),
            KEY idx_transaction (transaction_id),
            KEY idx_created (created_at)
        ) $charset_collate;";

        // --- Таблица квитанций ---
        $table_invoices = $wpdb->prefix . 'csc_invoices';
        $sql_invoices = "CREATE TABLE IF NOT EXISTS $table_invoices (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            invoice_number VARCHAR(50) NOT NULL DEFAULT '',
            client_phone VARCHAR(30) NOT NULL DEFAULT '',
            client_name VARCHAR(255) NOT NULL DEFAULT '',
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            commission DECIMAL(12,2) NOT NULL DEFAULT 0,
            master_income DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('draft','issued','paid','refunded','cancelled') NOT NULL DEFAULT 'draft',
            services TEXT,
            notes TEXT,
            issued_at DATETIME DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_order (order_number),
            KEY idx_invoice_number (invoice_number),
            KEY idx_client_phone (client_phone),
            KEY idx_status (status)
        ) $charset_collate;";

        // --- Таблица фискальных чеков (задел под 54-ФЗ) ---
        $table_fiscal = $wpdb->prefix . 'csc_fiscal_receipts';
        $sql_fiscal = "CREATE TABLE IF NOT EXISTS $table_fiscal (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT '',
            receipt_type ENUM('sale','refund') NOT NULL DEFAULT 'sale',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending','registered','failed','cancelled') NOT NULL DEFAULT 'pending',
            fiscal_document_number VARCHAR(50) DEFAULT NULL,
            fiscal_sign VARCHAR(100) DEFAULT NULL,
            provider_response TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            registered_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_order (order_number),
            KEY idx_transaction (transaction_id),
            KEY idx_status (status)
        ) $charset_collate;";

        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }

        dbDelta($sql_transactions);
        dbDelta($sql_ledger);
        dbDelta($sql_invoices);
        dbDelta($sql_fiscal);

        update_option('csc_finance_db_version', CSC_FINANCE_VERSION, false);
    }
}

// Проверка версии при загрузке админки
add_action('admin_init', 'csc_finance_check_version', 5);
if (!function_exists('csc_finance_check_version')) {
    function csc_finance_check_version() {
        $installed = get_option('csc_finance_db_version', '0');
        if ($installed !== CSC_FINANCE_VERSION) {
            csc_finance_create_tables();
        }
    }
}

// ============================================================
// 3. КОНСТАНТЫ АККАУНТОВ (ПЛАНЫ СЧЕТОВ)
// ============================================================

/**
 * Получить список всех аккаунтов системы.
 * Формат: 'тип:идентификатор'
 */
if (!function_exists('csc_finance_get_accounts')) {
    function csc_finance_get_accounts() {
        $accounts = [
            'platform_cash'        => 'Касса платформы',
            'platform_commission'  => 'Комиссия платформы',
            'client_prepay'        => 'Предоплата клиентов',
            'penalties'            => 'Штрафы',
            'platform_reserve'     => 'Резерв платформы',
        ];

        // Динамические аккаунты мастеров
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        foreach ($masters as $mid => $m) {
            $accounts['master_' . $mid] = 'Баланс: ' . ($m['name'] ?? $mid);
        }

        return $accounts;
    }
}

// ============================================================
// 4. ЯДРО: ЗАПИСЬ ПРОВОДКИ (ДВОЙНАЯ ЗАПИСЬ)
// ============================================================

/**
 * Получить текущий баланс аккаунта.
 *
 * @param string $account Имя аккаунта
 * @return float Текущий баланс
 */
if (!function_exists('csc_finance_get_balance')) {
    function csc_finance_get_balance($account) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_financial_ledger';

        // Кэш
        $cache_key = 'csc_fin_bal_' . md5($account);
        $cached = wp_cache_get($cache_key);
        if ($cached !== false) {
            return (float)$cached;
        }

        // Баланс = сумма всех дебетов - сумма всех кредитов для аккаунта
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
            FROM {$table}
            WHERE account = %s",
            $account
        ));

        $balance = floatval($result->total_debit) - floatval($result->total_credit);

        wp_cache_set($cache_key, $balance, '', CSC_FINANCE_CACHE_TTL);

        return $balance;
    }
}

/**
 * Создание пары проводок (дебет + кредит) — атомарная операция.
 *
 * @param array $params [
 *   'account_debit'   => string,  // Аккаунт по дебету
 *   'account_credit'  => string,  // Аккаунт по кредиту
 *   'amount'          => float,   // Сумма (> 0)
 *   'entity_type'     => string,  // 'platform'|'master'|'client'
 *   'entity_id'       => string,
 *   'order_number'    => string,
 *   'transaction_id'  => int|null,
 *   'description'     => string,
 *   'created_by'      => string,  // 'system'|'admin'|'master'|'payment_gateway'
 *   'created_by_id'   => string,
 * ]
 * @return array ['success' => bool, 'debit_id' => int, 'credit_id' => int, 'error' => string]
 */
if (!function_exists('csc_finance_record_entry')) {
    function csc_finance_record_entry($params) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_financial_ledger';

        $account_debit  = sanitize_text_field($params['account_debit'] ?? '');
        $account_credit = sanitize_text_field($params['account_credit'] ?? '');
        $amount         = abs(floatval($params['amount'] ?? 0));
        $entity_type    = sanitize_key($params['entity_type'] ?? 'platform');
        $entity_id      = sanitize_text_field($params['entity_id'] ?? '');
        $order_number   = sanitize_text_field($params['order_number'] ?? '');
        $transaction_id = !empty($params['transaction_id']) ? intval($params['transaction_id']) : null;
        $description    = sanitize_textarea_field($params['description'] ?? '');
        $created_by     = sanitize_key($params['created_by'] ?? 'system');
        $created_by_id  = sanitize_text_field($params['created_by_id'] ?? '');

        if (empty($account_debit) || empty($account_credit)) {
            return ['success' => false, 'debit_id' => 0, 'credit_id' => 0, 'error' => 'Аккаунты не указаны'];
        }
        if ($amount <= 0) {
            return ['success' => false, 'debit_id' => 0, 'credit_id' => 0, 'error' => 'Сумма должна быть > 0'];
        }
        if ($account_debit === $account_credit) {
            return ['success' => false, 'debit_id' => 0, 'credit_id' => 0, 'error' => 'Дебет и кредит не могут быть одним аккаунтом'];
        }

        $now = current_time('mysql');

        // Балансы ДО операции
        $balance_debit_before  = csc_finance_get_balance($account_debit);
        $balance_credit_before = csc_finance_get_balance($account_credit);

        // Вставка дебетовой проводки
        $wpdb->insert($table, [
            'entity_type'     => $entity_type,
            'entity_id'       => $entity_id,
            'order_number'    => $order_number,
            'transaction_id'  => $transaction_id,
            'type'            => 'debit',
            'account'         => $account_debit,
            'amount'          => $amount,
            'balance_before'  => $balance_debit_before,
            'balance_after'   => $balance_debit_before + $amount,
            'description'     => $description,
            'created_by'      => $created_by,
            'created_by_id'   => $created_by_id,
            'created_at'      => $now,
        ], ['%s','%s','%s','%d','%s','%s','%f','%f','%f','%s','%s','%s','%s']);

        $debit_id = $wpdb->insert_id;

        // Вставка кредитовой проводки
        $wpdb->insert($table, [
            'entity_type'     => $entity_type,
            'entity_id'       => $entity_id,
            'order_number'    => $order_number,
            'transaction_id'  => $transaction_id,
            'type'            => 'credit',
            'account'         => $account_credit,
            'amount'          => $amount,
            'balance_before'  => $balance_credit_before,
            'balance_after'   => $balance_credit_before - $amount,
            'description'     => $description,
            'created_by'      => $created_by,
            'created_by_id'   => $created_by_id,
            'created_at'      => $now,
        ], ['%s','%s','%s','%d','%s','%s','%f','%f','%f','%s','%s','%s','%s']);

        $credit_id = $wpdb->insert_id;

        // Инвалидация кэша балансов
        wp_cache_delete('csc_fin_bal_' . md5($account_debit));
        wp_cache_delete('csc_fin_bal_' . md5($account_credit));

        // Хук после записи проводки
        do_action('csc_finance_entry_recorded', $account_debit, $account_credit, $amount, $order_number, $debit_id);

        return ['success' => true, 'debit_id' => $debit_id, 'credit_id' => $credit_id, 'error' => ''];
    }
}

// ============================================================
// 5. ЯДРО: ЗАПИСЬ ТРАНЗАКЦИИ
// ============================================================

/**
 * Запись платёжной транзакции.
 *
 * @param array $params [
 *   'order_number'   => string,
 *   'type'           => 'income'|'expense'|'refund'|'penalty'|'payout'|'commission',
 *   'amount'         => float,
 *   'method'         => 'cash'|'transfer'|'sbp'|'card',
 *   'provider'       => string (провайдер оплаты),
 *   'payment_mode'   => 'full'|'partial'|'prepay',
 *   'master_id'      => string,
 *   'client_phone'   => string,
 *   'description'    => string,
 *   'status'         => 'pending'|'processing'|'completed'|'failed'|'refunded',
 *   'payment_id'     => string (внешний идентификатор платежа),
 * ]
 * @return array ['success' => bool, 'transaction_id' => int, 'error' => string]
 */
if (!function_exists('csc_finance_record_transaction')) {
    function csc_finance_record_transaction($params) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_transactions';

        $order_number = sanitize_text_field($params['order_number'] ?? '');
        $type         = sanitize_key($params['type'] ?? 'income');
        $amount       = abs(floatval($params['amount'] ?? 0));
        $method       = sanitize_key($params['method'] ?? 'cash');
        $provider     = sanitize_text_field($params['provider'] ?? 'cash');
        $payment_mode = sanitize_key($params['payment_mode'] ?? 'full');
        $master_id    = sanitize_text_field($params['master_id'] ?? '');
        $client_phone = sanitize_text_field($params['client_phone'] ?? '');
        $description  = sanitize_textarea_field($params['description'] ?? '');
        $status       = sanitize_key($params['status'] ?? 'completed');
        $payment_id   = sanitize_text_field($params['payment_id'] ?? '');

        // Валидация типа
        $valid_types = ['income', 'expense', 'refund', 'penalty', 'payout', 'commission'];
        if (!in_array($type, $valid_types, true)) {
            return ['success' => false, 'transaction_id' => 0, 'error' => 'Недопустимый тип транзакции: ' . $type];
        }

        if ($amount <= 0) {
            return ['success' => false, 'transaction_id' => 0, 'error' => 'Сумма должна быть > 0'];
        }

        // Генерация уникального payment_id если не передан
        if (empty($payment_id)) {
            $payment_id = 'TXN-' . date('Ymd') . '-' . wp_generate_password(8, false);
        }

        $valid_statuses = ['pending', 'processing', 'completed', 'failed', 'refunded'];
        if (!in_array($status, $valid_statuses, true)) {
            $status = 'completed';
        }

        $result = $wpdb->insert($table, [
            'order_number'   => $order_number,
            'payment_id'     => $payment_id,
            'provider'       => $provider,
            'method'         => $method,
            'payment_mode'   => $payment_mode,
            'type'           => $type,
            'status'         => $status,
            'amount'         => $amount,
            'currency'       => 'RUB',
            'description'    => $description,
            'master_id'      => $master_id,
            'client_phone'   => $client_phone,
            'paid_at'        => ($status === 'completed') ? current_time('mysql') : null,
            'created_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s','%s','%s']);

        if ($result === false) {
            error_log('[CSC_FINANCE] Ошибка записи транзакции: ' . $wpdb->last_error);
            return ['success' => false, 'transaction_id' => 0, 'error' => $wpdb->last_error];
        }

        $transaction_id = $wpdb->insert_id;

        // Логирование в историю заказа
        if (function_exists('csc_log_financial_event') && !empty($order_number)) {
            csc_log_financial_event($order_number, 'payment_created', [
                'amount'         => $amount,
                'provider'       => $provider,
                'transaction_id' => $payment_id,
                'type'           => $type,
            ], 'system');
        }

        // Хук
        do_action('csc_finance_transaction_recorded', $transaction_id, $type, $amount, $order_number);

        return ['success' => true, 'transaction_id' => $transaction_id, 'error' => ''];
    }
}

// ============================================================
// 6. ТРИГГЕРЫ НА СОБЫТИЯ ПЛАГИНА
// ============================================================

/**
 * Триггер: заказ выполнен → начислить комиссию + доход мастеру.
 * Вызывается через do_action('csc_order_completed_finance', $order_number, $order_data)
 */
add_action('csc_order_completed_finance', 'csc_finance_on_order_completed', 10, 2);
if (!function_exists('csc_finance_on_order_completed')) {
    function csc_finance_on_order_completed($order_number, $order_data = null) {
        if (empty($order_number)) return;

        // Получаем данные заказа если не переданы
        if (!$order_data && function_exists('csc_get_order')) {
            $order_data = csc_get_order($order_number);
        }
        if (!$order_data) return;

        $master_id = $order_data['master_id'] ?? '';
        if (empty($master_id)) return;

        $total = floatval($order_data['total'] ?? 0);
        if ($total <= 0) return;

        // Комиссия из данных мастера
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        $master = $masters[$master_id] ?? [];
        $comm_type = $master['comm_type'] ?? 'percent';
        $comm_val = floatval($master['comm_val'] ?? 0);

        // Расчёт комиссии
        if ($comm_type === 'percent') {
            $commission = round($total * $comm_val / 100, 2);
        } else {
            $commission = min($comm_val, $total); // Фикс не может превышать сумму
        }
        $master_income = $total - $commission;

        // Обновляем поля в заказе
        if (function_exists('csc_update_order')) {
            $order_data['commission'] = $commission;
            $order_data['master_income'] = $master_income;
            csc_update_order($order_number, $order_data);
        }

        // Транзакция: доход
        csc_finance_record_transaction([
            'order_number' => $order_number,
            'type'         => 'income',
            'amount'       => $total,
            'method'       => $order_data['payment_method'] ?? 'cash',
            'provider'     => $order_data['payment_method'] ?? 'cash',
            'master_id'    => $master_id,
            'client_phone' => csc_normalize_phone($order_data['phone'] ?? ''),
            'description'  => "Оплата за заказ #{$order_number}",
            'status'       => 'completed',
        ]);

        // Проводка: Касса ← Предоплата клиента
        csc_finance_record_entry([
            'account_debit'  => 'platform_cash',
            'account_credit' => 'client_prepay',
            'amount'         => $total,
            'entity_type'    => 'client',
            'entity_id'      => csc_normalize_phone($order_data['phone'] ?? ''),
            'order_number'   => $order_number,
            'description'    => "Поступление оплаты за заказ #{$order_number}",
            'created_by'     => 'system',
        ]);

        // Проводка: Комиссия платформы
        if ($commission > 0) {
            csc_finance_record_entry([
                'account_debit'  => 'platform_commission',
                'account_credit' => 'platform_cash',
                'amount'         => $commission,
                'entity_type'    => 'platform',
                'entity_id'      => 'platform',
                'order_number'   => $order_number,
                'description'    => "Комиссия платформы {$comm_val}" . ($comm_type === 'percent' ? '%' : '₽') . " за заказ #{$order_number}",
                'created_by'     => 'system',
            ]);
        }

        // Проводка: Доход мастера
        if ($master_income > 0) {
            csc_finance_record_entry([
                'account_debit'  => 'master_' . $master_id,
                'account_credit' => 'platform_cash',
                'amount'         => $master_income,
                'entity_type'    => 'master',
                'entity_id'      => $master_id,
                'order_number'   => $order_number,
                'description'    => "Доход мастера за заказ #{$order_number}",
                'created_by'     => 'system',
            ]);
        }

        // Обновляем earned_total мастера
        if (isset($masters[$master_id])) {
            $masters[$master_id]['earned_total'] = floatval($masters[$master_id]['earned_total'] ?? 0) + $master_income;
            update_option('csc_masters_list', $masters, false);
            if (function_exists('csc_cache_delete_masters')) {
                csc_cache_delete_masters();
            }
        }

        // Логирование в историю
        if (function_exists('csc_log_financial_event')) {
            csc_log_financial_event($order_number, 'commission_calculated', [
                'amount'        => $commission,
                'master_income' => $master_income,
                'comm_type'     => $comm_type,
                'comm_val'      => $comm_val,
            ], 'system');
        }
    }
}

/**
 * Триггер: штраф мастеру.
 * Вызывается через do_action('csc_finance_penalty', $master_id, $amount, $reason, $order_number)
 */
add_action('csc_finance_penalty', 'csc_finance_on_penalty', 10, 4);
if (!function_exists('csc_finance_on_penalty')) {
    function csc_finance_on_penalty($master_id, $amount, $reason = '', $order_number = '') {
        $amount = abs(floatval($amount));
        if ($amount <= 0 || empty($master_id)) return;

        // Транзакция
        csc_finance_record_transaction([
            'order_number' => $order_number,
            'type'         => 'penalty',
            'amount'       => $amount,
            'master_id'    => $master_id,
            'description'  => $reason ?: 'Штраф мастеру',
            'status'       => 'completed',
        ]);

        // Проводка: Штрафы ← Баланс мастера
        csc_finance_record_entry([
            'account_debit'  => 'penalties',
            'account_credit' => 'master_' . $master_id,
            'amount'         => $amount,
            'entity_type'    => 'master',
            'entity_id'      => $master_id,
            'order_number'   => $order_number,
            'description'    => $reason ?: 'Штраф мастеру',
            'created_by'     => 'system',
        ]);

        // Обновляем penalties мастера
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        if (isset($masters[$master_id])) {
            $masters[$master_id]['penalties_total'] = intval($masters[$master_id]['penalties_total'] ?? 0) + 1;
            $masters[$master_id]['penalties_amount'] = floatval($masters[$master_id]['penalties_amount'] ?? 0) + $amount;
            update_option('csc_masters_list', $masters, false);
            if (function_exists('csc_cache_delete_masters')) {
                csc_cache_delete_masters();
            }
        }
    }
}

/**
 * Триггер: выплата мастеру.
 * Вызывается через do_action('csc_finance_payout', $master_id, $amount, $method, $description)
 */
add_action('csc_finance_payout', 'csc_finance_on_payout', 10, 4);
if (!function_exists('csc_finance_on_payout')) {
    function csc_finance_on_payout($master_id, $amount, $method = 'cash', $description = '') {
        $amount = abs(floatval($amount));
        if ($amount <= 0 || empty($master_id)) return;

        // Транзакция
        $txn = csc_finance_record_transaction([
            'type'        => 'payout',
            'amount'      => $amount,
            'method'      => $method,
            'master_id'   => $master_id,
            'description' => $description ?: "Выплата мастеру",
            'status'      => 'completed',
        ]);

        // Проводка: Касса ← Баланс мастера
        csc_finance_record_entry([
            'account_debit'  => 'platform_cash',
            'account_credit' => 'master_' . $master_id,
            'amount'         => $amount,
            'entity_type'    => 'master',
            'entity_id'      => $master_id,
            'transaction_id' => $txn['transaction_id'] ?? null,
            'description'    => $description ?: "Выплата мастеру",
            'created_by'     => 'admin',
            'created_by_id'  => (string)get_current_user_id(),
        ]);

        // Запись в историю мастера (в массив выплат)
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        if (isset($masters[$master_id])) {
            if (!isset($masters[$master_id]['payouts']) || !is_array($masters[$master_id]['payouts'])) {
                $masters[$master_id]['payouts'] = [];
            }
            $masters[$master_id]['payouts'][] = [
                'amount' => $amount,
                'method' => $method,
                'date'   => current_time('mysql'),
                'status' => 'completed',
            ];
            update_option('csc_masters_list', $masters, false);
            if (function_exists('csc_cache_delete_masters')) {
                csc_cache_delete_masters();
            }
        }
    }
}

/**
 * Триггер: возврат клиенту.
 * Вызывается через do_action('csc_finance_refund', $order_number, $amount, $reason)
 */
add_action('csc_finance_refund', 'csc_finance_on_refund', 10, 3);
if (!function_exists('csc_finance_on_refund')) {
    function csc_finance_on_refund($order_number, $amount, $reason = '') {
        $amount = abs(floatval($amount));
        if ($amount <= 0) return;

        // Транзакция
        csc_finance_record_transaction([
            'order_number' => $order_number,
            'type'         => 'refund',
            'amount'       => $amount,
            'description'  => $reason ?: "Возврат клиенту за заказ #{$order_number}",
            'status'       => 'completed',
        ]);

        // Проводка: Предоплата ← Касса
        csc_finance_record_entry([
            'account_debit'  => 'client_prepay',
            'account_credit' => 'platform_cash',
            'amount'         => $amount,
            'entity_type'    => 'client',
            'entity_id'      => '',
            'order_number'   => $order_number,
            'description'    => $reason ?: "Возврат клиенту за заказ #{$order_number}",
            'created_by'     => 'admin',
            'created_by_id'  => (string)get_current_user_id(),
        ]);

        // Логирование
        if (function_exists('csc_log_financial_event')) {
            csc_log_financial_event($order_number, 'payment_refunded', [
                'amount' => $amount,
                'reason' => $reason,
            ], 'admin');
        }
    }
}

// ============================================================
// 7. API: ПОЛУЧЕНИЕ БАЛАНСОВ И ОТЧЁТОВ
// ============================================================

/**
 * Получить баланс мастера.
 */
if (!function_exists('csc_finance_get_master_balance')) {
    function csc_finance_get_master_balance($master_id) {
        return csc_finance_get_balance('master_' . $master_id);
    }
}

/**
 * Получить баланс платформы (касса).
 */
if (!function_exists('csc_finance_get_platform_balance')) {
    function csc_finance_get_platform_balance() {
        return csc_finance_get_balance('platform_cash');
    }
}

/**
 * Получить общую комиссию платформы.
 */
if (!function_exists('csc_finance_get_total_commission')) {
    function csc_finance_get_total_commission() {
        return csc_finance_get_balance('platform_commission');
    }
}

/**
 * Получить сумму штрафов.
 */
if (!function_exists('csc_finance_get_total_penalties')) {
    function csc_finance_get_total_penalties() {
        return csc_finance_get_balance('penalties');
    }
}

/**
 * Получить транзакции с фильтрацией.
 *
 * @param array $filters [
 *   'type'         => string|array,
 *   'status'       => string,
 *   'master_id'    => string,
 *   'order_number' => string,
 *   'date_from'    => 'Y-m-d',
 *   'date_to'      => 'Y-m-d',
 *   'limit'        => int,
 *   'offset'       => int,
 * ]
 * @return array ['items' => [], 'total' => int]
 */
if (!function_exists('csc_finance_get_transactions')) {
    function csc_finance_get_transactions($filters = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_transactions';

        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $where[] = "type IN ({$placeholders})";
            $params = array_merge($params, $types);
        }
        if (!empty($filters['status'])) {
            $where[] = "status = %s";
            $params[] = $filters['status'];
        }
        if (!empty($filters['master_id'])) {
            $where[] = "master_id = %s";
            $params[] = $filters['master_id'];
        }
        if (!empty($filters['order_number'])) {
            $where[] = "order_number = %s";
            $params[] = $filters['order_number'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(created_at) >= %s";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(created_at) <= %s";
            $params[] = $filters['date_to'];
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = intval($filters['limit'] ?? CSC_FINANCE_ITEMS_PER_PAGE);
        $offset = intval($filters['offset'] ?? 0);

        // Total count
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total = $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        // Items
        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$limit, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        return ['items' => $items ?: [], 'total' => (int)$total];
    }
}

/**
 * Получить журнал проводок для аккаунта.
 */
if (!function_exists('csc_finance_get_ledger')) {
    function csc_finance_get_ledger($account = '', $filters = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_financial_ledger';

        $where = [];
        $params = [];

        if (!empty($account)) {
            $where[] = "account = %s";
            $params[] = $account;
        }
        if (!empty($filters['order_number'])) {
            $where[] = "order_number = %s";
            $params[] = $filters['order_number'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(created_at) >= %s";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(created_at) <= %s";
            $params[] = $filters['date_to'];
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = intval($filters['limit'] ?? 50);

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d";
        $query_params = array_merge($params, [$limit]);
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        return $items ?: [];
    }
}

/**
 * Сводный финансовый отчёт за период.
 */
if (!function_exists('csc_finance_get_report')) {
    function csc_finance_get_report($date_from = '', $date_to = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_transactions';

        if (empty($date_from)) $date_from = date('Y-m-01');
        if (empty($date_to)) $date_to = date('Y-m-d');

        $sql = "SELECT
            COALESCE(SUM(CASE WHEN type = 'income' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_expense,
            COALESCE(SUM(CASE WHEN type = 'commission' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_commission,
            COALESCE(SUM(CASE WHEN type = 'penalty' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_penalties,
            COALESCE(SUM(CASE WHEN type = 'refund' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_refunds,
            COALESCE(SUM(CASE WHEN type = 'payout' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_payouts,
            COUNT(*) as total_transactions
        FROM {$table}
        WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";

        $row = $wpdb->get_row($wpdb->prepare($sql, $date_from, $date_to), ARRAY_A);

        return [
            'period'          => ['from' => $date_from, 'to' => $date_to],
            'total_income'    => floatval($row['total_income'] ?? 0),
            'total_expense'   => floatval($row['total_expense'] ?? 0),
            'total_commission'=> floatval($row['total_commission'] ?? 0),
            'total_penalties' => floatval($row['total_penalties'] ?? 0),
            'total_refunds'   => floatval($row['total_refunds'] ?? 0),
            'total_payouts'   => floatval($row['total_payouts'] ?? 0),
            'net_profit'      => floatval($row['total_income'] ?? 0) - floatval($row['total_payouts'] ?? 0) - floatval($row['total_refunds'] ?? 0),
            'total_transactions' => intval($row['total_transactions'] ?? 0),
            'balances' => [
                'platform_cash'       => csc_finance_get_balance('platform_cash'),
                'platform_commission' => csc_finance_get_balance('platform_commission'),
                'client_prepay'       => csc_finance_get_balance('client_prepay'),
                'penalties'           => csc_finance_get_balance('penalties'),
            ],
        ];
    }
}
// ============================================================
// БЛОК 2: АДМИН-СТРАНИЦЫ ФИНАНСОВОГО МОДУЛЯ
// Продолжение csc-finance.php
// ============================================================

// ============================================================
// 13. РЕГИСТРАЦИЯ АДМИН-СТРАНИЦ
// ============================================================
add_action('admin_menu', 'csc_finance_add_admin_pages', 30);
if (!function_exists('csc_finance_add_admin_pages')) {
function csc_finance_add_admin_pages() {
// Главная страница "Бухгалтерия" (дашборд)
add_submenu_page(
'options-general.php',
'Бухгалтерия (CSC)',
'💰 Бухгалтерия',
'manage_options',
'csc_finance_dashboard',
'csc_render_finance_dashboard'
);
// Страница транзакций
add_submenu_page(
'options-general.php',
'Транзакции (CSC)',
'📋 Транзакции',
'manage_options',
'csc_finance_transactions',
'csc_render_finance_transactions'
);
// Страница выплат мастерам
add_submenu_page(
'options-general.php',
'Выплаты мастерам (CSC)',
'💸 Выплаты',
'manage_options',
'csc_finance_payouts',
'csc_render_finance_payouts'
);
}
}

// ============================================================
// 14. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ АДМИН-СТРАНИЦ
// ============================================================

/**
* Получить транзакции с фильтрацией и пагинацией из БД.
*
* @param array $filters ['type','status','master_id','order_number','date_from','date_to']
* @param int   $page    Текущая страница
* @param int   $per_page Записей на страницу
* @return array ['items' => [], 'total' => int, 'pages' => int]
*/
if (!function_exists('csc_finance_get_transactions_paged')) {
function csc_finance_get_transactions_paged($filters = [], $page = 1, $per_page = 25) {
global $wpdb;
$table = $wpdb->prefix . 'csc_transactions';
$where = ['1=1'];
$params = [];
if (!empty($filters['type'])) {
$where[] = 'type = %s';
$params[] = $filters['type'];
}
if (!empty($filters['status'])) {
$where[] = 'status = %s';
$params[] = $filters['status'];
}
if (!empty($filters['master_id'])) {
$where[] = 'master_id = %s';
$params[] = $filters['master_id'];
}
if (!empty($filters['order_number'])) {
$where[] = 'order_number = %s';
$params[] = $filters['order_number'];
}
if (!empty($filters['date_from'])) {
$where[] = 'DATE(created_at) >= %s';
$params[] = $filters['date_from'];
}
if (!empty($filters['date_to'])) {
$where[] = 'DATE(created_at) <= %s';
$params[] = $filters['date_to'];
}
$where_sql = implode(' AND ', $where);
// Total count
$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
$total = $params ? (int)$wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int)$wpdb->get_var($count_sql);
$pages = max(1, ceil($total / $per_page));
$page = max(1, min($page, $pages));
$offset = ($page - 1) * $per_page;
// Items
$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_params = array_merge($params, [$per_page, $offset]);
$items = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);
return ['items' => $items ?: [], 'total' => $total, 'pages' => $pages, 'page' => $page];
}
}

/**
* Получить сводку (агрегаты) по транзакциям за период.
*
* @param string $date_from
* @param string $date_to
* @return array
*/
if (!function_exists('csc_finance_get_summary')) {
function csc_finance_get_summary($date_from = '', $date_to = '') {
global $wpdb;
$table = $wpdb->prefix . 'csc_transactions';
if (empty($date_from)) $date_from = date('Y-m-01');
if (empty($date_to)) $date_to = date('Y-m-d');
$sql = "SELECT
COALESCE(SUM(CASE WHEN type = 'income' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_income,
COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_expense,
COALESCE(SUM(CASE WHEN type = 'commission' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_commission,
COALESCE(SUM(CASE WHEN type = 'penalty' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_penalties,
COALESCE(SUM(CASE WHEN type = 'refund' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_refunds,
COALESCE(SUM(CASE WHEN type = 'payout' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_payouts,
COALESCE(SUM(CASE WHEN type = 'payout' AND status = 'pending' THEN amount ELSE 0 END), 0) as pending_payouts,
COUNT(*) as total_transactions,
COUNT(DISTINCT order_number) as unique_orders
FROM {$table}
WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";
$row = $wpdb->get_row($wpdb->prepare($sql, $date_from, $date_to), ARRAY_A);
$income = floatval($row['total_income'] ?? 0);
$expense = floatval($row['total_expense'] ?? 0);
$payouts = floatval($row['total_payouts'] ?? 0);
$refunds = floatval($row['total_refunds'] ?? 0);
return [
'total_income'     => $income,
'total_expense'    => $expense,
'total_commission' => floatval($row['total_commission'] ?? 0),
'total_penalties'  => floatval($row['total_penalties'] ?? 0),
'total_refunds'    => $refunds,
'total_payouts'    => $payouts,
'pending_payouts'  => floatval($row['pending_payouts'] ?? 0),
'net_profit'       => $income - $payouts - $refunds - $expense,
'total_transactions' => intval($row['total_transactions'] ?? 0),
'unique_orders'    => intval($row['unique_orders'] ?? 0),
'period'           => ['from' => $date_from, 'to' => $date_to],
];
}
}

/**
* Получить балансы всех мастеров из журнала проводок.
*
* @return array [master_id => ['balance' => float, 'earned' => float, 'paid_out' => float, 'penalties' => float]]
*/
if (!function_exists('csc_finance_get_master_balances')) {
function csc_finance_get_master_balances() {
global $wpdb;
$ledger_table = $wpdb->prefix . 'csc_financial_ledger';
$masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
$balances = [];
foreach ($masters as $mid => $m) {
$account = 'master_' . $mid;
// Баланс = дебет - кредит по аккаунту мастера
$row = $wpdb->get_row($wpdb->prepare(
"SELECT
COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
FROM {$ledger_table} WHERE account = %s",
$account
), ARRAY_A);
$debit = floatval($row['total_debit'] ?? 0);
$credit = floatval($row['total_credit'] ?? 0);
$balances[$mid] = [
'name'      => $m['name'] ?? 'Без имени',
'balance'   => $debit - $credit,
'earned'    => $debit,   // Начислено всего (дебет)
'paid_out'  => $credit,  // Выплачено (кредит)
'penalties' => 0, // Заполняется из данных мастера
];
// Подтягиваем штрафы из данных мастера
if (isset($m['penalties_amount'])) {
$balances[$mid]['penalties'] = floatval($m['penalties_amount']);
}
}
return $balances;
}
}

/**
* Форматирование суммы в рубли.
*/
if (!function_exists('csc_finance_format_money')) {
function csc_finance_format_money($amount) {
return number_format(floatval($amount), 2, ',', ' ') . ' ₽';
}
}

// ============================================================
// 15. СТРАНИЦА: БУХГАЛТЕРИЯ (ДАШБОРД С KPI)
// ============================================================
if (!function_exists('csc_render_finance_dashboard')) {
function csc_render_finance_dashboard() {
// Период
$period = sanitize_text_field($_GET['period'] ?? 'month');
$custom_from = sanitize_text_field($_GET['date_from'] ?? '');
$custom_to = sanitize_text_field($_GET['date_to'] ?? '');
// Определяем даты
$now = new DateTime();
switch ($period) {
case 'today':
$date_from = $now->format('Y-m-d');
$date_to = $now->format('Y-m-d');
break;
case 'week':
$date_from = (clone $now)->modify('monday this week')->format('Y-m-d');
$date_to = $now->format('Y-m-d');
break;
case 'month':
$date_from = $now->format('Y-m-01');
$date_to = $now->format('Y-m-d');
break;
case 'year':
$date_from = $now->format('Y-01-01');
$date_to = $now->format('Y-m-d');
break;
case 'custom':
$date_from = $custom_from ?: $now->format('Y-m-01');
$date_to = $custom_to ?: $now->format('Y-m-d');
break;
default:
$date_from = $now->format('Y-m-01');
$date_to = $now->format('Y-m-d');
}
$summary = csc_finance_get_summary($date_from, $date_to);
$balances = csc_finance_get_master_balances();
$total_master_debt = 0;
foreach ($balances as $b) {
if ($b['balance'] > 0) $total_master_debt += $b['balance'];
}
$base_url = admin_url('options-general.php?page=csc_finance_dashboard');
?>
<div class="wrap">
<h1>💰 Бухгалтерия</h1>
<!-- Фильтр периода -->
<div style="margin:20px 0; padding:16px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
<strong>Период:</strong>
<a href="<?php echo esc_url(add_query_arg('period', 'today', $base_url)); ?>" class="button <?php echo $period === 'today' ? 'button-primary' : ''; ?>">Сегодня</a>
<a href="<?php echo esc_url(add_query_arg('period', 'week', $base_url)); ?>" class="button <?php echo $period === 'week' ? 'button-primary' : ''; ?>">Неделя</a>
<a href="<?php echo esc_url(add_query_arg('period', 'month', $base_url)); ?>" class="button <?php echo $period === 'month' ? 'button-primary' : ''; ?>">Месяц</a>
<a href="<?php echo esc_url(add_query_arg('period', 'year', $base_url)); ?>" class="button <?php echo $period === 'year' ? 'button-primary' : ''; ?>">Год</a>
<a href="<?php echo esc_url(add_query_arg('period', 'custom', $base_url)); ?>" class="button <?php echo $period === 'custom' ? 'button-primary' : ''; ?>">Свой период</a>
<?php if ($period === 'custom'): ?>
<form method="get" style="display:inline-flex; gap:8px; align-items:center;">
<input type="hidden" name="page" value="csc_finance_dashboard">
<input type="hidden" name="period" value="custom">
<input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
<span>—</span>
<input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
<button type="submit" class="button button-primary">Применить</button>
</form>
<?php endif; ?>
<span style="margin-left:auto; color:#64748b; font-size:13px;">
<?php echo esc_html($date_from); ?> — <?php echo esc_html($date_to); ?>
</span>
</div>
<!-- KPI Карточки -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; border-left:4px solid #10b981;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Доход</div>
<div style="font-size:24px; font-weight:700; color:#065f46;"><?php echo csc_finance_format_money($summary['total_income']); ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; border-left:4px solid #ef4444;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Выплаты мастерам</div>
<div style="font-size:24px; font-weight:700; color:#991b1b;"><?php echo csc_finance_format_money($summary['total_payouts']); ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; border-left:4px solid #3b82f6;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Комиссия платформы</div>
<div style="font-size:24px; font-weight:700; color:#1e40af;"><?php echo csc_finance_format_money($summary['total_commission']); ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; border-left:4px solid #f59e0b;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Штрафы</div>
<div style="font-size:24px; font-weight:700; color:#92400e;"><?php echo csc_finance_format_money($summary['total_penalties']); ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; border-left:4px solid #8b5cf6;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Возвраты</div>
<div style="font-size:24px; font-weight:700; color:#6b21a8;"><?php echo csc_finance_format_money($summary['total_refunds']); ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; border-left:4px solid <?php echo $summary['net_profit'] >= 0 ? '#10b981' : '#ef4444'; ?>;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Чистая прибыль</div>
<div style="font-size:24px; font-weight:700; color:<?php echo $summary['net_profit'] >= 0 ? '#065f46' : '#991b1b'; ?>;">
<?php echo csc_finance_format_money($summary['net_profit']); ?>
</div>
</div>
</div>
<!-- Второй ряд KPI -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Транзакций за период</div>
<div style="font-size:22px; font-weight:700;"><?php echo $summary['total_transactions']; ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Уникальных заказов</div>
<div style="font-size:22px; font-weight:700;"><?php echo $summary['unique_orders']; ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Задолженность перед мастерами</div>
<div style="font-size:22px; font-weight:700; color:#d97706;"><?php echo csc_finance_format_money($total_master_debt); ?></div>
</div>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
<div style="font-size:13px; color:#64748b; margin-bottom:4px;">Ожидают выплаты</div>
<div style="font-size:22px; font-weight:700; color:#d97706;"><?php echo csc_finance_format_money($summary['pending_payouts']); ?></div>
</div>
</div>
<!-- Балансы мастеров -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:24px;">
<h2 style="margin-top:0;">👥 Балансы мастеров</h2>
<?php if (empty($balances)): ?>
<p style="color:#64748b;">Мастера не найдены.</p>
<?php else: ?>
<table class="widefat striped" style="margin-top:12px;">
<thead>
<tr>
<th>Мастер</th>
<th style="text-align:right;">Начислено</th>
<th style="text-align:right;">Выплачено</th>
<th style="text-align:right;">Штрафы</th>
<th style="text-align:right;">Баланс (к выплате)</th>
<th style="text-align:center;">Действия</th>
</tr>
</thead>
<tbody>
<?php foreach ($balances as $mid => $b): ?>
<tr>
<td><strong><?php echo esc_html($b['name']); ?></strong></td>
<td style="text-align:right;"><?php echo csc_finance_format_money($b['earned']); ?></td>
<td style="text-align:right;"><?php echo csc_finance_format_money($b['paid_out']); ?></td>
<td style="text-align:right; color:#dc3232;"><?php echo csc_finance_format_money($b['penalties']); ?></td>
<td style="text-align:right; font-weight:700; color:<?php echo $b['balance'] > 0 ? '#065f46' : '#64748b'; ?>;">
<?php echo csc_finance_format_money($b['balance']); ?>
</td>
<td style="text-align:center;">
<?php if ($b['balance'] > 0): ?>
<a href="<?php echo esc_url(admin_url('options-general.php?page=csc_finance_payouts&master_id=' . urlencode($mid))); ?>" class="button button-small button-primary">💸 Выплатить</a>
<?php else: ?>
<span style="color:#94a3b8;">—</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<!-- Быстрые ссылки -->
<div style="display:flex; gap:12px; flex-wrap:wrap;">
<a href="<?php echo esc_url(admin_url('options-general.php?page=csc_finance_transactions')); ?>" class="button button-secondary">📋 Все транзакции</a>
<a href="<?php echo esc_url(admin_url('options-general.php?page=csc_finance_payouts')); ?>" class="button button-secondary">💸 Управление выплатами</a>
</div>
</div>
<?php
}
}

// ============================================================
// 16. СТРАНИЦА: ТРАНЗАКЦИИ С ФИЛЬТРАЦИЕЙ И ПАГИНАЦИЕЙ
// ============================================================
if (!function_exists('csc_render_finance_transactions')) {
function csc_render_finance_transactions() {
// Обработка фильтров из GET
$f_type = sanitize_text_field($_GET['f_type'] ?? '');
$f_status = sanitize_text_field($_GET['f_status'] ?? '');
$f_master = sanitize_text_field($_GET['f_master'] ?? '');
$f_order = sanitize_text_field($_GET['f_order'] ?? '');
$f_date_from = sanitize_text_field($_GET['f_date_from'] ?? '');
$f_date_to = sanitize_text_field($_GET['f_date_to'] ?? '');
$page = max(1, intval($_GET['paged'] ?? 1));
$filters = [
'type' => $f_type,
'status' => $f_status,
'master_id' => $f_master,
'order_number' => $f_order,
'date_from' => $f_date_from,
'date_to' => $f_date_to,
];
$result = csc_finance_get_transactions_paged($filters, $page, 25);
$masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
$type_labels = [
'income' => 'Доход',
'expense' => 'Расход',
'refund' => 'Возврат',
'penalty' => 'Штраф',
'payout' => 'Выплата',
'commission' => 'Комиссия',
];
$status_labels = [
'pending' => '⏳ Ожидает',
'processing' => '⚙️ Обработка',
'completed' => '✅ Завершена',
'failed' => '❌ Ошибка',
'refunded' => '↩️ Возврат',
];
$base_url = admin_url('options-general.php?page=csc_finance_transactions');
// Формируем URL пагинации с учётом фильтров
$pagination_url = add_query_arg([
'f_type' => $f_type,
'f_status' => $f_status,
'f_master' => $f_master,
'f_order' => $f_order,
'f_date_from' => $f_date_from,
'f_date_to' => $f_date_to,
], $base_url);
?>
<div class="wrap">
<h1>📋 Транзакции <span style="font-size:14px; color:#64748b; font-weight:normal;">(<?php echo $result['total']; ?> записей)</span></h1>
<!-- Фильтры -->
<div style="margin:20px 0; padding:16px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
<input type="hidden" name="page" value="csc_finance_transactions">
<div>
<label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Тип</label>
<select name="f_type" style="min-width:130px;">
<option value="">Все</option>
<?php foreach ($type_labels as $val => $label): ?>
<option value="<?php echo esc_attr($val); ?>" <?php selected($f_type, $val); ?>><?php echo $label; ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Статус</label>
<select name="f_status" style="min-width:130px;">
<option value="">Все</option>
<?php foreach ($status_labels as $val => $label): ?>
<option value="<?php echo esc_attr($val); ?>" <?php selected($f_status, $val); ?>><?php echo $label; ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Мастер</label>
<select name="f_master" style="min-width:150px;">
<option value="">Все</option>
<?php foreach ($masters as $mid => $m): ?>
<option value="<?php echo esc_attr($mid); ?>" <?php selected($f_master, $mid); ?>><?php echo esc_html($m['name'] ?? $mid); ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">№ Заказа</label>
<input type="text" name="f_order" value="<?php echo esc_attr($f_order); ?>" placeholder="CSC-..." style="width:140px;">
</div>
<div>
<label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Дата с</label>
<input type="date" name="f_date_from" value="<?php echo esc_attr($f_date_from); ?>">
</div>
<div>
<label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Дата по</label>
<input type="date" name="f_date_to" value="<?php echo esc_attr($f_date_to); ?>">
</div>
<button type="submit" class="button button-primary">Фильтровать</button>
<a href="<?php echo esc_url($base_url); ?>" class="button">Сбросить</a>
</form>
</div>
<!-- Таблица транзакций -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
<table class="widefat striped" style="border:none;">
<thead>
<tr>
<th style="width:50px;">ID</th>
<th style="width:130px;">Дата</th>
<th>№ Заказа</th>
<th>Тип</th>
<th>Статус</th>
<th>Мастер</th>
<th>Метод</th>
<th style="text-align:right;">Сумма</th>
<th>Описание</th>
</tr>
</thead>
<tbody>
<?php if (empty($result['items'])): ?>
<tr><td colspan="9" style="text-align:center; padding:30px; color:#64748b;">Транзакции не найдены</td></tr>
<?php else: ?>
<?php foreach ($result['items'] as $txn): ?>
<tr>
<td><?php echo intval($txn['id']); ?></td>
<td style="white-space:nowrap;"><?php echo esc_html(date('d.m.Y H:i', strtotime($txn['created_at']))); ?></td>
<td><?php echo esc_html($txn['order_number'] ?: '—'); ?></td>
<td>
<span style="padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; background:#e0f2fe; color:#075985;">
<?php echo $type_labels[$txn['type']] ?? $txn['type']; ?>
</span>
</td>
<td><?php echo $status_labels[$txn['status']] ?? $txn['status']; ?></td>
<td><?php echo esc_html($masters[$txn['master_id']]['name'] ?? ($txn['master_id'] ?: '—')); ?></td>
<td><?php echo esc_html($txn['method'] ?? '—'); ?></td>
<td style="text-align:right; font-weight:700; white-space:nowrap; color:<?php echo in_array($txn['type'], ['income','commission','penalty']) ? '#065f46' : '#991b1b'; ?>;">
<?php echo csc_finance_format_money($txn['amount']); ?>
</td>
<td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr($txn['description'] ?? ''); ?>">
<?php echo esc_html($txn['description'] ?: '—'); ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<!-- Пагинация -->
<?php if ($result['pages'] > 1): ?>
<div class="tablenav" style="margin-top:16px;">
<div class="tablenav-pages">
<span class="displaying-num"><?php echo $result['total']; ?> записей</span>
<?php
echo paginate_links([
'base' => add_query_arg('paged', '%#%', $pagination_url),
'format' => '',
'current' => $result['page'],
'total' => $result['pages'],
'prev_text' => '« Назад',
'next_text' => 'Вперёд »',
'type' => 'plain',
]);
?>
</div>
</div>
<?php endif; ?>
</div>
<?php
}
}

// ============================================================
// 17. СТРАНИЦА: ВЫПЛАТЫ МАСТЕРАМ
// ============================================================
if (!function_exists('csc_render_finance_payouts')) {
function csc_render_finance_payouts() {
$nonce_action = 'csc_finance_payout_action';
// Обработка создания выплаты
if ($_POST && isset($_POST['csc_create_payout']) && wp_verify_nonce($_POST['_wpnonce'], $nonce_action)) {
$payout_master = sanitize_text_field($_POST['payout_master'] ?? '');
$payout_amount = floatval($_POST['payout_amount'] ?? 0);
$payout_method = sanitize_text_field($_POST['payout_method'] ?? 'cash');
$payout_comment = sanitize_textarea_field($_POST['payout_comment'] ?? '');
if (!empty($payout_master) && $payout_amount > 0) {
$payout_result = csc_finance_create_payout($payout_master, $payout_amount, $payout_method, $payout_comment);
if ($payout_result['success']) {
echo '<div class="notice notice-success"><p>✅ Выплата создана: ' . csc_finance_format_money($payout_amount) . ' для ' . esc_html($payout_result['master_name']) . '</p></div>';
} else {
echo '<div class="notice notice-error"><p>❌ ' . esc_html($payout_result['message']) . '</p></div>';
}
} else {
echo '<div class="notice notice-error"><p>❌ Укажите мастера и сумму выплаты.</p></div>';
}
}
// Обработка подтверждения выплаты
if ($_GET && isset($_GET['confirm_payout']) && isset($_GET['txn_id']) && isset($_GET['_wpnonce'])) {
if (wp_verify_nonce($_GET['_wpnonce'], 'csc_confirm_payout_' . intval($_GET['txn_id']))) {
$confirm_result = csc_finance_confirm_payout(intval($_GET['txn_id']));
if ($confirm_result['success']) {
echo '<div class="notice notice-success"><p>✅ Выплата #' . intval($_GET['txn_id']) . ' подтверждена.</p></div>';
} else {
echo '<div class="notice notice-error"><p>❌ ' . esc_html($confirm_result['message']) . '</p></div>';
}
}
}
$balances = csc_finance_get_master_balances();
$masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
$preselected_master = sanitize_text_field($_GET['master_id'] ?? '');
// Последние выплаты
global $wpdb;
$txn_table = $wpdb->prefix . 'csc_transactions';
$recent_payouts = $wpdb->get_results(
"SELECT * FROM {$txn_table} WHERE type = 'payout' ORDER BY created_at DESC LIMIT 20",
ARRAY_A
);
?>
<div class="wrap">
<h1>💸 Выплаты мастерам</h1>
<!-- Форма создания выплаты -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:24px; max-width:600px;">
<h2 style="margin-top:0;">Создать выплату</h2>
<form method="post">
<?php wp_nonce_field($nonce_action); ?>
<table class="form-table">
<tr>
<th>Мастер:</th>
<td>
<select name="payout_master" required style="min-width:200px;">
<option value="">— Выберите мастера —</option>
<?php foreach ($balances as $mid => $b): ?>
<option value="<?php echo esc_attr($mid); ?>" <?php selected($preselected_master, $mid); ?>>
<?php echo esc_html($b['name']); ?> (баланс: <?php echo csc_finance_format_money($b['balance']); ?>)
</option>
<?php endforeach; ?>
</select>
</td>
</tr>
<tr>
<th>Сумма (₽):</th>
<td><input type="number" name="payout_amount" min="1" step="0.01" required style="width:150px;" placeholder="0.00"></td>
</tr>
<tr>
<th>Метод выплаты:</th>
<td>
<select name="payout_method">
<option value="cash">Наличные</option>
<option value="transfer">Банковский перевод</option>
<option value="sbp">СБП</option>
<option value="card">На карту</option>
</select>
</td>
</tr>
<tr>
<th>Комментарий:</th>
<td><textarea name="payout_comment" rows="2" style="width:100%;" placeholder="Необязательно"></textarea></td>
</tr>
</table>
<p>
<button type="submit" name="csc_create_payout" class="button button-primary">💸 Создать выплату</button>
</p>
</form>
</div>
<!-- Балансы мастеров -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:24px;">
<h2 style="margin-top:0;">Балансы мастеров</h2>
<table class="widefat striped" style="margin-top:12px;">
<thead>
<tr>
<th>Мастер</th>
<th style="text-align:right;">Начислено</th>
<th style="text-align:right;">Выплачено</th>
<th style="text-align:right;">Штрафы</th>
<th style="text-align:right;">К выплате</th>
<th style="text-align:center;">Действие</th>
</tr>
</thead>
<tbody>
<?php foreach ($balances as $mid => $b): ?>
<tr>
<td><strong><?php echo esc_html($b['name']); ?></strong></td>
<td style="text-align:right;"><?php echo csc_finance_format_money($b['earned']); ?></td>
<td style="text-align:right;"><?php echo csc_finance_format_money($b['paid_out']); ?></td>
<td style="text-align:right; color:#dc3232;"><?php echo csc_finance_format_money($b['penalties']); ?></td>
<td style="text-align:right; font-weight:700; color:<?php echo $b['balance'] > 0 ? '#065f46' : '#64748b'; ?>;">
<?php echo csc_finance_format_money($b['balance']); ?>
</td>
<td style="text-align:center;">
<?php if ($b['balance'] > 0): ?>
<a href="<?php echo esc_url(add_query_arg('master_id', urlencode($mid), admin_url('options-general.php?page=csc_finance_payouts'))); ?>" class="button button-small button-primary">Выплатить</a>
<?php else: ?>
<span style="color:#94a3b8;">Нет задолженности</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- История выплат -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
<h2 style="margin-top:0;">История выплат (последние 20)</h2>
<?php if (empty($recent_payouts)): ?>
<p style="color:#64748b;">Выплат пока не было.</p>
<?php else: ?>
<table class="widefat striped" style="margin-top:12px;">
<thead>
<tr>
<th>ID</th>
<th>Дата</th>
<th>Мастер</th>
<th>Метод</th>
<th style="text-align:right;">Сумма</th>
<th>Статус</th>
<th>Комментарий</th>
<th style="text-align:center;">Действие</th>
</tr>
</thead>
<tbody>
<?php foreach ($recent_payouts as $p): ?>
<tr>
<td><?php echo intval($p['id']); ?></td>
<td style="white-space:nowrap;"><?php echo esc_html(date('d.m.Y H:i', strtotime($p['created_at']))); ?></td>
<td><?php echo esc_html($masters[$p['master_id']]['name'] ?? ($p['master_id'] ?: '—')); ?></td>
<td><?php echo esc_html($p['method'] ?? '—'); ?></td>
<td style="text-align:right; font-weight:700;"><?php echo csc_finance_format_money($p['amount']); ?></td>
<td>
<?php
$status_map = [
'pending' => '<span style="color:#d97706;">⏳ Ожидает</span>',
'processing' => '<span style="color:#2563eb;">⚙️ Обработка</span>',
'completed' => '<span style="color:#065f46;">✅ Завершена</span>',
'failed' => '<span style="color:#dc3232;">❌ Ошибка</span>',
];
echo $status_map[$p['status']] ?? esc_html($p['status']);
?>
</td>
<td style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
<?php echo esc_html($p['description'] ?: '—'); ?>
</td>
<td style="text-align:center;">
<?php if ($p['status'] === 'pending'): ?>
<a href="<?php echo esc_url(wp_nonce_url(
add_query_arg(['confirm_payout' => '1', 'txn_id' => $p['id']], admin_url('options-general.php?page=csc_finance_payouts')),
'csc_confirm_payout_' . intval($p['id'])
)); ?>" class="button button-small button-primary" onclick="return confirm('Подтвердить выплату #<?php echo intval($p['id']); ?>?');">✅ Подтвердить</a>
<?php else: ?>
<span style="color:#94a3b8;">—</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
<?php
}
}

// ============================================================
// 18. ФУНКЦИИ СОЗДАНИЯ И ПОДТВЕРЖДЕНИЯ ВЫПЛАТ
// ============================================================

/**
* Создание выплаты мастеру.
* Проводка: Дебет платформы → Кредит мастера (уменьшение баланса мастера)
*
* @param string $master_id
* @param float  $amount
* @param string $method cash|transfer|sbp|card
* @param string $comment
* @return array ['success' => bool, 'message' => string, 'master_name' => string]
*/
if (!function_exists('csc_finance_create_payout')) {
function csc_finance_create_payout($master_id, $amount, $method = 'cash', $comment = '') {
global $wpdb;
$amount = abs(floatval($amount));
if ($amount <= 0) {
return ['success' => false, 'message' => 'Сумма должна быть больше нуля', 'master_name' => ''];
}
$masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
if (!isset($masters[$master_id])) {
return ['success' => false, 'message' => 'Мастер не найден', 'master_name' => ''];
}
$master_name = $masters[$master_id]['name'] ?? 'Без имени';
// Проверяем баланс
$balance = csc_finance_get_balance('master_' . $master_id);
if ($amount > $balance) {
return ['success' => false, 'message' => 'Недостаточно средств. Баланс: ' . csc_finance_format_money($balance), 'master_name' => $master_name];
}
// Валидация метода
$valid_methods = ['cash', 'transfer', 'sbp', 'card'];
if (!in_array($method, $valid_methods)) {
$method = 'cash';
}
// Создаём транзакцию
$txn_table = $wpdb->prefix . 'csc_transactions';
$payment_id = 'PAY-' . date('Ymd') . '-' . strtoupper(wp_generate_password(6, false));
$wpdb->insert($txn_table, [
'order_number' => '',
'payment_id' => $payment_id,
'provider' => 'manual',
'method' => $method,
'payment_mode' => 'full',
'type' => 'payout',
'status' => 'pending',
'amount' => $amount,
'currency' => 'RUB',
'description' => 'Выплата мастеру ' . $master_name . ($comment ? '. ' . $comment : ''),
'master_id' => $master_id,
'client_phone' => '',
'created_at' => current_time('mysql'),
'updated_at' => current_time('mysql'),
], ['%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s']);
$txn_id = $wpdb->insert_id;
// Проводка: Дебет platform_cash → Кредит master_{id}
// Это уменьшает баланс мастера
if (function_exists('csc_finance_record_entry')) {
csc_finance_record_entry([
'account_debit' => 'platform_cash',
'account_credit' => 'master_' . $master_id,
'amount' => $amount,
'entity_type' => 'master',
'entity_id' => $master_id,
'transaction_id' => $txn_id,
'description' => 'Выплата мастеру ' . $master_name,
'created_by' => 'admin',
'created_by_id' => (string)get_current_user_id(),
]);
}
// Логирование
if (function_exists('csc_log_financial_event')) {
csc_log_financial_event('', 'payout_created', [
'amount' => $amount,
'master_id' => $master_id,
'method' => $method,
'transaction_id' => $payment_id,
], 'admin');
}
return ['success' => true, 'message' => 'Выплата создана', 'master_name' => $master_name];
}
}

/**
* Подтверждение (завершение) выплаты.
*
* @param int $txn_id ID транзакции
* @return array ['success' => bool, 'message' => string]
*/
if (!function_exists('csc_finance_confirm_payout')) {
function csc_finance_confirm_payout($txn_id) {
global $wpdb;
$txn_table = $wpdb->prefix . 'csc_transactions';
$txn = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$txn_table} WHERE id = %d", $txn_id), ARRAY_A);
if (!$txn) {
return ['success' => false, 'message' => 'Транзакция не найдена'];
}
if ($txn['type'] !== 'payout') {
return ['success' => false, 'message' => 'Это не выплата'];
}
if ($txn['status'] === 'completed') {
return ['success' => false, 'message' => 'Выплата уже подтверждена'];
}
$wpdb->update($txn_table, [
'status' => 'completed',
'paid_at' => current_time('mysql'),
'updated_at' => current_time('mysql'),
], ['id' => $txn_id], ['%s', '%s', '%s'], ['%d']);
// Логирование
if (function_exists('csc_log_financial_event')) {
csc_log_financial_event('', 'payout_completed', [
'amount' => floatval($txn['amount']),
'master_id' => $txn['master_id'],
'transaction_id' => $txn['payment_id'],
], 'admin');
}
return ['success' => true, 'message' => 'Выплата подтверждена'];
}
}
// ============================================================
// БЛОК 3/3: КВИТАНЦИИ, ФИСКАЛЬНЫЕ ЧЕКИ, ЭКСПОРТ, ХУКИ
// Продолжение csc-finance.php
// ============================================================

// ============================================================
// 19. КВИТАНЦИИ: ГЕНЕРАЦИЯ И УПРАВЛЕНИЕ
// ============================================================

/**
 * Генерация уникального номера квитанции.
 * Формат: INV-YYYYMMDD-XXXXX
 *
 * @return string Номер квитанции
 */
if (!function_exists('csc_finance_generate_invoice_number')) {
    function csc_finance_generate_invoice_number() {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_invoices';
        $date_prefix = wp_date('Ymd');

        // Атомарный счётчик через wp_options
        $counter_key = 'csc_invoice_counter_' . $date_prefix;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $counter_key
        ));
        $counter = (int)get_option($counter_key, 0);

        // Проверка уникальности
        do {
            $candidate = 'INV-' . $date_prefix . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);
            $counter++;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE invoice_number = %s",
                $candidate
            ));
        } while ($exists);

        if ($counter > (int)get_option($counter_key, 0)) {
            update_option($counter_key, $counter, false);
        }

        return $candidate;
    }
}

/**
 * Создание квитанции для заказа.
 * Вызывается при завершении заказа или вручную из админки.
 *
 * @param string $order_number Номер заказа
 * @param string $notes        Примечание (необязательно)
 * @return array ['success' => bool, 'invoice_id' => int, 'invoice_number' => string, 'error' => string]
 */
if (!function_exists('csc_finance_create_invoice')) {
    function csc_finance_create_invoice($order_number, $notes = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_invoices';

        if (empty($order_number)) {
            return ['success' => false, 'invoice_id' => 0, 'invoice_number' => '', 'error' => 'Не указан номер заказа'];
        }

        // Проверяем, нет ли уже квитанции для этого заказа
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_number = %s AND status != 'cancelled'",
            $order_number
        ));
        if ($existing) {
            return ['success' => false, 'invoice_id' => (int)$existing, 'invoice_number' => '', 'error' => 'Квитанция для этого заказа уже существует'];
        }

        // Получаем данные заказа
        $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if (!$order) {
            return ['success' => false, 'invoice_id' => 0, 'invoice_number' => '', 'error' => 'Заказ не найден'];
        }

        $invoice_number = csc_finance_generate_invoice_number();
        $client_phone = function_exists('csc_normalize_phone')
            ? csc_normalize_phone($order['phone'] ?? '')
            : preg_replace('/[^0-9]/', '', $order['phone'] ?? '');

        $services_json = wp_json_encode($order['services'] ?? []);

        $result = $wpdb->insert($table, [
            'order_number'   => sanitize_text_field($order_number),
            'invoice_number' => $invoice_number,
            'client_phone'   => sanitize_text_field($client_phone),
            'client_name'    => sanitize_text_field($order['name'] ?? ''),
            'total'          => floatval($order['total'] ?? 0),
            'discount'       => floatval($order['discount'] ?? 0),
            'commission'     => floatval($order['commission'] ?? 0),
            'master_income'  => floatval($order['master_income'] ?? 0),
            'status'         => 'issued',
            'services'       => $services_json,
            'notes'          => sanitize_textarea_field($notes),
            'issued_at'      => current_time('mysql'),
            'created_at'     => current_time('mysql'),
        ], ['%s','%s','%s','%s','%f','%f','%f','%f','%s','%s','%s','%s','%s']);

        if ($result === false) {
            error_log('[CSC_FINANCE] Ошибка создания квитанции: ' . $wpdb->last_error);
            return ['success' => false, 'invoice_id' => 0, 'invoice_number' => '', 'error' => 'Ошибка записи в БД'];
        }

        $invoice_id = $wpdb->insert_id;

        // Логирование
        if (function_exists('csc_log_financial_event')) {
            csc_log_financial_event($order_number, 'commission_calculated', [
                'amount'         => floatval($order['commission'] ?? 0),
                'invoice_number' => $invoice_number,
                'invoice_id'     => $invoice_id,
            ], 'finance');
        }

        // Хук после создания квитанции
        do_action('csc_finance_after_invoice_created', $invoice_id, $order_number, $invoice_number);

        return ['success' => true, 'invoice_id' => $invoice_id, 'invoice_number' => $invoice_number, 'error' => ''];
    }
}

/**
 * Получение списка квитанций с фильтрацией.
 *
 * @param array $filters ['status', 'order_number', 'client_phone', 'date_from', 'date_to']
 * @param int   $limit
 * @param int   $offset
 * @return array ['items' => [], 'total' => int]
 */
if (!function_exists('csc_finance_get_invoices')) {
    function csc_finance_get_invoices($filters = [], $limit = 25, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_invoices';
        $limit = max(1, min((int)$limit, 200));
        $offset = max(0, (int)$offset);

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($filters['status']);
        }
        if (!empty($filters['order_number'])) {
            $where[] = 'order_number = %s';
            $params[] = sanitize_text_field($filters['order_number']);
        }
        if (!empty($filters['client_phone'])) {
            $where[] = 'client_phone = %s';
            $params[] = sanitize_text_field($filters['client_phone']);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= %s';
            $params[] = sanitize_text_field($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= %s';
            $params[] = sanitize_text_field($filters['date_to']);
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total = $params
            ? (int)$wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int)$wpdb->get_var($count_sql);

        // Items
        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$limit, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        return ['items' => $items ?: [], 'total' => $total];
    }
}

/**
 * Получение одной квитанции по ID.
 *
 * @param int $invoice_id
 * @return array|null
 */
if (!function_exists('csc_finance_get_invoice')) {
    function csc_finance_get_invoice($invoice_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_invoices';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            (int)$invoice_id
        ), ARRAY_A);
    }
}

/**
 * Обновление статуса квитанции.
 *
 * @param int    $invoice_id
 * @param string $new_status draft|issued|paid|refunded|cancelled
 * @return bool
 */
if (!function_exists('csc_finance_update_invoice_status')) {
    function csc_finance_update_invoice_status($invoice_id, $new_status) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_invoices';

        $valid_statuses = ['draft', 'issued', 'paid', 'refunded', 'cancelled'];
        if (!in_array($new_status, $valid_statuses, true)) {
            return false;
        }

        $update_data = ['status' => $new_status];
        if ($new_status === 'paid') {
            $update_data['paid_at'] = current_time('mysql');
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            ['id' => (int)$invoice_id],
            array_fill(0, count($update_data), '%s'),
            ['%d']
        );

        return $result !== false;
    }
}

/**
 * HTML-представление квитанции для печати.
 *
 * @param array $invoice Данные квитанции
 * @return string HTML
 */
if (!function_exists('csc_finance_render_invoice_html')) {
    function csc_finance_render_invoice_html($invoice) {
        if (empty($invoice)) return '';

        $services = json_decode($invoice['services'] ?? '[]', true) ?: [];
        $site_name = get_bloginfo('name');
        $invoice_number = esc_html($invoice['invoice_number'] ?? '');
        $order_number = esc_html($invoice['order_number'] ?? '');
        $client_name = esc_html($invoice['client_name'] ?? '');
        $client_phone = esc_html($invoice['client_phone'] ?? '');
        $issued_at = !empty($invoice['issued_at'])
            ? date('d.m.Y H:i', strtotime($invoice['issued_at']))
            : '—';

        $html = '<div style="font-family:Arial,sans-serif; max-width:700px; margin:0 auto; padding:20px; border:2px solid #1e293b;">';
        $html .= '<div style="text-align:center; margin-bottom:20px;">';
        $html .= '<h1 style="margin:0; font-size:20px;">' . esc_html($site_name) . '</h1>';
        $html .= '<h2 style="margin:5px 0 0; font-size:16px;">КВИТАНЦИЯ № ' . $invoice_number . '</h2>';
        $html .= '<p style="margin:5px 0 0; font-size:12px; color:#64748b;">Дата: ' . $issued_at . '</p>';
        $html .= '</div>';

        $html .= '<table style="width:100%; border-collapse:collapse; margin-bottom:15px;">';
        $html .= '<tr><td style="padding:5px; border:1px solid #e2e8f0; font-weight:bold;">Заказ:</td><td style="padding:5px; border:1px solid #e2e8f0;">' . $order_number . '</td></tr>';
        $html .= '<tr><td style="padding:5px; border:1px solid #e2e8f0; font-weight:bold;">Клиент:</td><td style="padding:5px; border:1px solid #e2e8f0;">' . $client_name . '</td></tr>';
        $html .= '<tr><td style="padding:5px; border:1px solid #e2e8f0; font-weight:bold;">Телефон:</td><td style="padding:5px; border:1px solid #e2e8f0;">' . $client_phone . '</td></tr>';
        $html .= '</table>';

        if (!empty($services)) {
            $html .= '<table style="width:100%; border-collapse:collapse; margin-bottom:15px;">';
            $html .= '<tr style="background:#f1f5f9;"><th style="padding:8px; border:1px solid #e2e8f0; text-align:left;">Услуга</th></tr>';
            foreach ($services as $svc) {
                $svc_name = is_string($svc) ? $svc : ($svc['name'] ?? implode(', ', (array)$svc));
                $html .= '<tr><td style="padding:8px; border:1px solid #e2e8f0;">' . esc_html($svc_name) . '</td></tr>';
            }
            $html .= '</table>';
        }

        $total = number_format(floatval($invoice['total'] ?? 0), 2, '.', ' ');
        $discount = floatval($invoice['discount'] ?? 0);
        $commission = floatval($invoice['commission'] ?? 0);
        $master_income = floatval($invoice['master_income'] ?? 0);

        $html .= '<table style="width:100%; border-collapse:collapse;">';
        if ($discount > 0) {
            $html .= '<tr><td style="padding:5px; text-align:right; color:#64748b;">Скидка:</td><td style="padding:5px; text-align:right;">-' . number_format($discount, 2, '.', ' ') . ' ₽</td></tr>';
        }
        $html .= '<tr><td style="padding:5px; text-align:right; font-weight:bold; font-size:16px;">ИТОГО:</td><td style="padding:5px; text-align:right; font-weight:bold; font-size:16px;">' . $total . ' ₽</td></tr>';
        if ($commission > 0) {
            $html .= '<tr><td style="padding:5px; text-align:right; color:#64748b; font-size:12px;">Комиссия сервиса:</td><td style="padding:5px; text-align:right; font-size:12px;">' . number_format($commission, 2, '.', ' ') . ' ₽</td></tr>';
        }
        if ($master_income > 0) {
            $html .= '<tr><td style="padding:5px; text-align:right; color:#64748b; font-size:12px;">Доход мастера:</td><td style="padding:5px; text-align:right; font-size:12px;">' . number_format($master_income, 2, '.', ' ') . ' ₽</td></tr>';
        }
        $html .= '</table>';

        if (!empty($invoice['notes'])) {
            $html .= '<p style="margin-top:15px; font-size:12px; color:#64748b;">Примечание: ' . esc_html($invoice['notes']) . '</p>';
        }

        $html .= '<div style="margin-top:30px; text-align:center; font-size:11px; color:#94a3b8;">';
        $html .= 'Документ сформирован автоматически системой ' . esc_html($site_name) . '<br>';
        $html .= 'Квитанция носит информационный характер.';
        $html .= '</div>';
        $html .= '</div>';

        // Фильтр для кастомизации
        return apply_filters('csc_finance_invoice_html', $html, $invoice);
    }
}

// ============================================================
// 20. ФИСКАЛЬНЫЕ ЧЕКИ (ЗАДЕЛ ПОД 54-ФЗ)
// ============================================================

/**
 * Создание фискального чека.
 * На текущем этапе — запись в БД со статусом 'pending'.
 * Реальная отправка в ОФД подключается через фильтр/хук.
 *
 * @param string $order_number  Номер заказа
 * @param string $receipt_type  sale|refund
 * @param float  $amount        Сумма (0 = взять из заказа)
 * @return array ['success' => bool, 'receipt_id' => int, 'error' => string]
 */
if (!function_exists('csc_finance_create_fiscal_receipt')) {
    function csc_finance_create_fiscal_receipt($order_number, $receipt_type = 'sale', $amount = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_fiscal_receipts';

        if (empty($order_number)) {
            return ['success' => false, 'receipt_id' => 0, 'error' => 'Не указан номер заказа'];
        }

        $valid_types = ['sale', 'refund'];
        if (!in_array($receipt_type, $valid_types, true)) {
            $receipt_type = 'sale';
        }

        // Если сумма не указана — берём из заказа
        if ($amount <= 0) {
            $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
            if (!$order) {
                return ['success' => false, 'receipt_id' => 0, 'error' => 'Заказ не найден'];
            }
            $amount = floatval($order['total'] ?? 0);
        }

        if ($amount <= 0) {
            return ['success' => false, 'receipt_id' => 0, 'error' => 'Сумма чека должна быть больше нуля'];
        }

        // Проверяем, нет ли уже зарегистрированного чека
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_number = %s AND receipt_type = %s AND status IN ('pending', 'registered')",
            $order_number, $receipt_type
        ));
        if ($existing) {
            return ['success' => false, 'receipt_id' => (int)$existing, 'error' => 'Чек уже создан или зарегистрирован'];
        }

        // Получаем transaction_id если есть
        $transaction_id = null;
        $txn_table = $wpdb->prefix . 'csc_transactions';
        $txn = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$txn_table} WHERE order_number = %s AND status = 'completed' ORDER BY id DESC LIMIT 1",
            $order_number
        ));
        if ($txn) {
            $transaction_id = (int)$txn->id;
        }

        $result = $wpdb->insert($table, [
            'order_number'   => sanitize_text_field($order_number),
            'transaction_id' => $transaction_id,
            'provider'       => get_option('csc_fiscal_provider', ''),
            'receipt_type'   => $receipt_type,
            'amount'         => $amount,
            'status'         => 'pending',
            'created_at'     => current_time('mysql'),
        ], ['%s','%d','%s','%s','%f','%s','%s']);

        if ($result === false) {
            error_log('[CSC_FINANCE] Ошибка создания фискального чека: ' . $wpdb->last_error);
            return ['success' => false, 'receipt_id' => 0, 'error' => 'Ошибка записи в БД'];
        }

        $receipt_id = $wpdb->insert_id;

        // Хук: возможность отправить чек в ОФД через внешний модуль
        // Внешний модуль должен вызвать csc_finance_register_fiscal_receipt()
        do_action('csc_finance_fiscal_receipt_created', $receipt_id, $order_number, $receipt_type, $amount);

        return ['success' => true, 'receipt_id' => $receipt_id, 'error' => ''];
    }
}

/**
 * Регистрация фискального чека (вызывается после успешной отправки в ОФД).
 *
 * @param int    $receipt_id
 * @param string $fiscal_doc_number Номер фискального документа от ОФД
 * @param string $fiscal_sign       Фискальный признак от ОФД
 * @param string $provider_response Полный ответ провайдера (JSON)
 * @return bool
 */
if (!function_exists('csc_finance_register_fiscal_receipt')) {
    function csc_finance_register_fiscal_receipt($receipt_id, $fiscal_doc_number = '', $fiscal_sign = '', $provider_response = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_fiscal_receipts';

        $result = $wpdb->update(
            $table,
            [
                'status'                 => 'registered',
                'fiscal_document_number' => sanitize_text_field($fiscal_doc_number),
                'fiscal_sign'            => sanitize_text_field($fiscal_sign),
                'provider_response'      => $provider_response,
                'registered_at'          => current_time('mysql'),
            ],
            ['id' => (int)$receipt_id],
            ['%s','%s','%s','%s','%s'],
            ['%d']
        );

        if ($result !== false) {
            do_action('csc_finance_fiscal_receipt_registered', $receipt_id, $fiscal_doc_number);
            return true;
        }

        return false;
    }
}

/**
 * Пометить фискальный чек как ошибочный.
 *
 * @param int    $receipt_id
 * @param string $error_message Описание ошибки
 * @return bool
 */
if (!function_exists('csc_finance_fail_fiscal_receipt')) {
    function csc_finance_fail_fiscal_receipt($receipt_id, $error_message = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_fiscal_receipts';

        $result = $wpdb->update(
            $table,
            [
                'status'            => 'failed',
                'provider_response' => sanitize_textarea_field($error_message),
            ],
            ['id' => (int)$receipt_id],
            ['%s','%s'],
            ['%d']
        );

        if ($result !== false) {
            do_action('csc_finance_fiscal_receipt_failed', $receipt_id, $error_message);
            return true;
        }

        return false;
    }
}

/**
 * Получение списка фискальных чеков.
 *
 * @param array $filters ['status', 'receipt_type', 'order_number', 'date_from', 'date_to']
 * @param int   $limit
 * @param int   $offset
 * @return array ['items' => [], 'total' => int]
 */
if (!function_exists('csc_finance_get_fiscal_receipts')) {
    function csc_finance_get_fiscal_receipts($filters = [], $limit = 25, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_fiscal_receipts';
        $limit = max(1, min((int)$limit, 200));
        $offset = max(0, (int)$offset);

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($filters['status']);
        }
        if (!empty($filters['receipt_type'])) {
            $where[] = 'receipt_type = %s';
            $params[] = sanitize_key($filters['receipt_type']);
        }
        if (!empty($filters['order_number'])) {
            $where[] = 'order_number = %s';
            $params[] = sanitize_text_field($filters['order_number']);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= %s';
            $params[] = sanitize_text_field($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= %s';
            $params[] = sanitize_text_field($filters['date_to']);
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total = $params
            ? (int)$wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int)$wpdb->get_var($count_sql);

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$limit, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        return ['items' => $items ?: [], 'total' => $total];
    }
}

// ============================================================
// 21. ЭКСПОРТ ФИНАНСОВОЙ ВЫПИСКИ
// ============================================================

/**
 * Генерация данных финансовой выписки за период.
 *
 * @param string $start_date Дата начала (Y-m-d)
 * @param string $end_date   Дата окончания (Y-m-d)
 * @param string $master_id  Фильтр по мастеру (пусто = все)
 * @return array Данные выписки
 */
if (!function_exists('csc_finance_generate_statement')) {
    function csc_finance_generate_statement($start_date, $end_date, $master_id = '') {
        global $wpdb;

        if (!function_exists('csc_finance_validate_date_range')) {
            return ['error' => 'Некорректные даты'];
        }
        if (!csc_finance_validate_date_range($start_date, $end_date)) {
            return ['error' => 'Некорректный диапазон дат'];
        }

        $txn_table = $wpdb->prefix . 'csc_transactions';
        $ledger_table = $wpdb->prefix . 'csc_financial_ledger';
        $invoice_table = $wpdb->prefix . 'csc_invoices';
        $fiscal_table = $wpdb->prefix . 'csc_fiscal_receipts';

        // --- Транзакции ---
        $txn_where = ["DATE(created_at) >= %s", "DATE(created_at) <= %s"];
        $txn_params = [$start_date, $end_date];
        if (!empty($master_id)) {
            $txn_where[] = 'master_id = %s';
            $txn_params[] = sanitize_text_field($master_id);
        }
        $txn_where_sql = 'WHERE ' . implode(' AND ', $txn_where);
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$txn_table} {$txn_where_sql} ORDER BY created_at ASC",
            $txn_params
        ), ARRAY_A);

        // --- Проводки ---
        $ledger_where = ["DATE(created_at) >= %s", "DATE(created_at) <= %s"];
        $ledger_params = [$start_date, $end_date];
        $ledger_where_sql = 'WHERE ' . implode(' AND ', $ledger_where);
        $ledger_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$ledger_table} {$ledger_where_sql} ORDER BY created_at ASC",
            $ledger_params
        ), ARRAY_A);

        // --- Квитанции ---
        $inv_where = ["DATE(created_at) >= %s", "DATE(created_at) <= %s"];
        $inv_params = [$start_date, $end_date];
        $inv_where_sql = 'WHERE ' . implode(' AND ', $inv_where);
        $invoices = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$invoice_table} {$inv_where_sql} ORDER BY created_at ASC",
            $inv_params
        ), ARRAY_A);

        // --- Фискальные чеки ---
        $fisc_where = ["DATE(created_at) >= %s", "DATE(created_at) <= %s"];
        $fisc_params = [$start_date, $end_date];
        $fisc_where_sql = 'WHERE ' . implode(' AND ', $fisc_where);
        $fiscal_receipts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$fiscal_table} {$fisc_where_sql} ORDER BY created_at ASC",
            $fisc_params
        ), ARRAY_A);

        // --- Итоги ---
        $total_income = 0;
        $total_expense = 0;
        $total_commission = 0;
        $total_penalty = 0;
        $total_refund = 0;
        $total_payout = 0;

        foreach ($transactions as $txn) {
            $amt = floatval($txn['amount'] ?? 0);
            if ($txn['status'] !== 'completed') continue;
            switch ($txn['type']) {
                case 'income':     $total_income += $amt; break;
                case 'expense':    $total_expense += $amt; break;
                case 'commission': $total_commission += $amt; break;
                case 'penalty':    $total_penalty += $amt; break;
                case 'refund':     $total_refund += $amt; break;
                case 'payout':     $total_payout += $amt; break;
            }
        }

        // Балансы аккаунтов
        $balances = [];
        if (function_exists('csc_finance_get_balance')) {
            $accounts = ['platform_cash', 'platform_commission', 'client_prepay', 'penalties'];
            foreach ($accounts as $acc) {
                $balances[$acc] = csc_finance_get_balance($acc);
            }
        }

        return [
            'period'          => ['from' => $start_date, 'to' => $end_date],
            'master_filter'   => $master_id,
            'transactions'    => $transactions ?: [],
            'ledger_entries'  => $ledger_entries ?: [],
            'invoices'        => $invoices ?: [],
            'fiscal_receipts' => $fiscal_receipts ?: [],
            'summary'         => [
                'total_income'     => $total_income,
                'total_expense'    => $total_expense,
                'total_commission' => $total_commission,
                'total_penalty'    => $total_penalty,
                'total_refund'     => $total_refund,
                'total_payout'     => $total_payout,
                'net_profit'       => $total_income - $total_payout - $total_refund,
                'txn_count'        => count($transactions ?: []),
                'ledger_count'     => count($ledger_entries ?: []),
                'invoice_count'    => count($invoices ?: []),
                'fiscal_count'     => count($fiscal_receipts ?: []),
            ],
            'balances' => $balances,
            'generated_at' => current_time('mysql'),
        ];
    }
}

/**
 * Экспорт финансовой выписки в CSV.
 *
 * @param array $statement Данные выписки (из csc_finance_generate_statement)
 * @param string $filename Имя файла
 */
if (!function_exists('csc_finance_export_statement_csv')) {
    function csc_finance_export_statement_csv($statement, $filename = '') {
        if (empty($statement) || isset($statement['error'])) {
            wp_die('Ошибка формирования выписки');
        }

        if (empty($filename)) {
            $filename = 'csc-finance-statement-'
                . ($statement['period']['from'] ?? 'all')
                . '-to-'
                . ($statement['period']['to'] ?? 'all')
                . '-' . date('Ymd-His') . '.csv';
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');

        $out = fopen('php://output', 'w');

        // BOM для Excel
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // --- Заголовок ---
        fputcsv($out, ['ФИНАНСОВАЯ ВЫПИСКА CUSTOM SERVICE CALCULATOR'], ';');
        fputcsv($out, ['Период:', ($statement['period']['from'] ?? '') . ' — ' . ($statement['period']['to'] ?? '')], ';');
        fputcsv($out, ['Сформирована:', $statement['generated_at'] ?? ''], ';');
        if (!empty($statement['master_filter'])) {
            fputcsv($out, ['Фильтр по мастеру:', $statement['master_filter']], ';');
        }
        fputcsv($out, [], ';');

        // --- Сводка ---
        $s = $statement['summary'] ?? [];
        fputcsv($out, ['ПОКАЗАТЕЛЬ', 'ЗНАЧЕНИЕ'], ';');
        fputcsv($out, ['Доходы', number_format($s['total_income'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, ['Расходы', number_format($s['total_expense'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, ['Комиссия платформы', number_format($s['total_commission'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, ['Штрафы', number_format($s['total_penalty'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, ['Возвраты', number_format($s['total_refund'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, ['Выплаты мастерам', number_format($s['total_payout'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, ['Чистая прибыль', number_format($s['net_profit'] ?? 0, 2, '.', '')], ';');
        fputcsv($out, [], ';');

        // --- Балансы ---
        if (!empty($statement['balances'])) {
            fputcsv($out, ['БАЛАНСЫ АККАУНТОВ'], ';');
            $balance_labels = [
                'platform_cash'       => 'Касса платформы',
                'platform_commission' => 'Комиссия платформы',
                'client_prepay'       => 'Предоплата клиентов',
                'penalties'           => 'Штрафы',
            ];
            foreach ($statement['balances'] as $acc => $bal) {
                $label = $balance_labels[$acc] ?? $acc;
                fputcsv($out, [$label, number_format($bal, 2, '.', '')], ';');
            }
            fputcsv($out, [], ';');
        }

        // --- Транзакции ---
        if (!empty($statement['transactions'])) {
            fputcsv($out, ['ТРАНЗАКЦИИ'], ';');
            fputcsv($out, ['ID', 'Заказ', 'Платёж ID', 'Провайдер', 'Метод', 'Тип', 'Статус', 'Сумма', 'Мастер', 'Создана', 'Оплачена'], ';');
            foreach ($statement['transactions'] as $txn) {
                fputcsv($out, [
                    $txn['id'],
                    $txn['order_number'],
                    $txn['payment_id'],
                    $txn['provider'],
                    $txn['method'],
                    $txn['type'],
                    $txn['status'],
                    number_format(floatval($txn['amount']), 2, '.', ''),
                    $txn['master_id'],
                    $txn['created_at'],
                    $txn['paid_at'] ?? '',
                ], ';');
            }
            fputcsv($out, [], ';');
        }

        // --- Проводки ---
        if (!empty($statement['ledger_entries'])) {
            fputcsv($out, ['ФИНАНСОВЫЙ ЖУРНАЛ (ПРОВОДКИ)'], ';');
            fputcsv($out, ['ID', 'Тип', 'Аккаунт', 'Сумма', 'Баланс до', 'Баланс после', 'Заказ', 'Описание', 'Создана'], ';');
            foreach ($statement['ledger_entries'] as $entry) {
                fputcsv($out, [
                    $entry['id'],
                    $entry['type'],
                    $entry['account'],
                    number_format(floatval($entry['amount']), 2, '.', ''),
                    number_format(floatval($entry['balance_before']), 2, '.', ''),
                    number_format(floatval($entry['balance_after']), 2, '.', ''),
                    $entry['order_number'],
                    $entry['description'],
                    $entry['created_at'],
                ], ';');
            }
            fputcsv($out, [], ';');
        }

        // --- Квитанции ---
        if (!empty($statement['invoices'])) {
            fputcsv($out, ['КВИТАНЦИИ'], ';');
            fputcsv($out, ['ID', 'Номер квитанции', 'Заказ', 'Клиент', 'Телефон', 'Сумма', 'Скидка', 'Комиссия', 'Доход мастера', 'Статус', 'Дата'], ';');
            foreach ($statement['invoices'] as $inv) {
                fputcsv($out, [
                    $inv['id'],
                    $inv['invoice_number'],
                    $inv['order_number'],
                    $inv['client_name'],
                    $inv['client_phone'],
                    number_format(floatval($inv['total']), 2, '.', ''),
                    number_format(floatval($inv['discount']), 2, '.', ''),
                    number_format(floatval($inv['commission']), 2, '.', ''),
                    number_format(floatval($inv['master_income']), 2, '.', ''),
                    $inv['status'],
                    $inv['created_at'],
                ], ';');
            }
            fputcsv($out, [], ';');
        }

        // --- Фискальные чеки ---
        if (!empty($statement['fiscal_receipts'])) {
            fputcsv($out, ['ФИСКАЛЬНЫЕ ЧЕКИ'], ';');
            fputcsv($out, ['ID', 'Заказ', 'Тип', 'Сумма', 'Статус', 'Провайдер', 'ФД номер', 'ФП', 'Создан', 'Зарегистрирован'], ';');
            foreach ($statement['fiscal_receipts'] as $fisc) {
                fputcsv($out, [
                    $fisc['id'],
                    $fisc['order_number'],
                    $fisc['receipt_type'],
                    number_format(floatval($fisc['amount']), 2, '.', ''),
                    $fisc['status'],
                    $fisc['provider'],
                    $fisc['fiscal_document_number'] ?? '',
                    $fisc['fiscal_sign'] ?? '',
                    $fisc['created_at'],
                    $fisc['registered_at'] ?? '',
                ], ';');
            }
        }

        fclose($out);

        // Хук после экспорта
        do_action('csc_finance_statement_exported', $statement['period'], $statement['summary'] ?? []);

        exit;
    }
}

// ============================================================
// 22. ХУКИ ИНТЕГРАЦИИ С ДРУГИМИ МОДУЛЯМИ
// ============================================================

/**
 * Хук: автоматическое создание квитанции при завершении заказа.
 * Срабатывает после csc_order_completed_finance (блок 1/3).
 */
add_action('csc_order_completed_finance', 'csc_finance_auto_create_invoice', 20, 2);
if (!function_exists('csc_finance_auto_create_invoice')) {
    function csc_finance_auto_create_invoice($order_number, $order_data = null) {
        if (empty($order_number)) return;

        // Проверяем настройку автогенерации
        $auto_invoice = get_option('csc_finance_auto_invoice', true);
        if (!$auto_invoice) return;

        csc_finance_create_invoice($order_number, 'Автогенерация при завершении заказа');
    }
}

/**
 * Хук: автоматическое создание фискального чека при завершении заказа.
 */
add_action('csc_order_completed_finance', 'csc_finance_auto_create_fiscal', 30, 2);
if (!function_exists('csc_finance_auto_create_fiscal')) {
    function csc_finance_auto_create_fiscal($order_number, $order_data = null) {
        if (empty($order_number)) return;

        // Создаём чек только если настроен провайдер
        $fiscal_provider = get_option('csc_fiscal_provider', '');
        if (empty($fiscal_provider)) return;

        csc_finance_create_fiscal_receipt($order_number, 'sale');
    }
}

/**
 * Хук: создание чека возврата при возврате средств.
 */
add_action('csc_finance_refund', 'csc_finance_auto_create_refund_fiscal', 10, 3);
if (!function_exists('csc_finance_auto_create_refund_fiscal')) {
    function csc_finance_auto_create_refund_fiscal($order_number, $amount, $reason = '') {
        if (empty($order_number)) return;

        $fiscal_provider = get_option('csc_fiscal_provider', '');
        if (empty($fiscal_provider)) return;

        csc_finance_create_fiscal_receipt($order_number, 'refund', $amount);
    }
}

/**
 * Хук: логирование создания квитанции в историю заказа.
 */
add_action('csc_finance_after_invoice_created', 'csc_finance_log_invoice_to_history', 10, 3);
if (!function_exists('csc_finance_log_invoice_to_history')) {
    function csc_finance_log_invoice_to_history($invoice_id, $order_number, $invoice_number) {
        if (function_exists('csc_log_financial_event')) {
            csc_log_financial_event($order_number, 'commission_calculated', [
                'invoice_id'     => $invoice_id,
                'invoice_number' => $invoice_number,
            ], 'finance');
        }
    }
}

// ============================================================
// 23. AJAX-ОБРАБОТЧИКИ (КВИТАНЦИИ, ЧЕКИ, ЭКСПОРТ)
// ============================================================

/**
 * AJAX: Генерация квитанции для заказа.
 */
add_action('wp_ajax_csc_finance_generate_invoice', 'csc_finance_generate_invoice_ajax');
if (!function_exists('csc_finance_generate_invoice_ajax')) {
    function csc_finance_generate_invoice_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_finance_nonce', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $order_number = sanitize_text_field($_POST['order_number'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        if (empty($order_number)) {
            wp_send_json_error(['message' => 'Не указан номер заказа']);
        }

        $result = csc_finance_create_invoice($order_number, $notes);

        if ($result['success']) {
            wp_send_json_success([
                'message'        => 'Квитанция создана: ' . $result['invoice_number'],
                'invoice_id'     => $result['invoice_id'],
                'invoice_number' => $result['invoice_number'],
            ]);
        } else {
            wp_send_json_error(['message' => $result['error']]);
        }
    }
}

/**
 * AJAX: Просмотр квитанции (возвращает HTML).
 */
add_action('wp_ajax_csc_finance_view_invoice', 'csc_finance_view_invoice_ajax');
if (!function_exists('csc_finance_view_invoice_ajax')) {
    function csc_finance_view_invoice_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_finance_nonce', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            wp_send_json_error(['message' => 'Не указан ID квитанции']);
        }

        $invoice = csc_finance_get_invoice($invoice_id);
        if (!$invoice) {
            wp_send_json_error(['message' => 'Квитанция не найдена']);
        }

        $html = csc_finance_render_invoice_html($invoice);

        wp_send_json_success([
            'html'   => $html,
            'status' => $invoice['status'],
        ]);
    }
}

/**
 * AJAX: Обновление статуса квитанции.
 */
add_action('wp_ajax_csc_finance_update_invoice_status', 'csc_finance_update_invoice_status_ajax');
if (!function_exists('csc_finance_update_invoice_status_ajax')) {
    function csc_finance_update_invoice_status_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_finance_nonce', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $new_status = sanitize_key($_POST['status'] ?? '');

        if ($invoice_id <= 0 || empty($new_status)) {
            wp_send_json_error(['message' => 'Недостаточно данных']);
        }

        $result = csc_finance_update_invoice_status($invoice_id, $new_status);

        if ($result) {
            wp_send_json_success(['message' => 'Статус обновлён']);
        } else {
            wp_send_json_error(['message' => 'Ошибка обновления статуса']);
        }
    }
}

/**
 * AJAX: Экспорт финансовой выписки.
 */
add_action('wp_ajax_csc_finance_export_statement', 'csc_finance_export_statement_ajax');
if (!function_exists('csc_finance_export_statement_ajax')) {
    function csc_finance_export_statement_ajax() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещён');
        }
        if (!check_ajax_referer('csc_finance_nonce', '_wpnonce', false)) {
            wp_die('Ошибка безопасности');
        }

        $start_date = sanitize_text_field($_GET['start_date'] ?? date('Y-m-01'));
        $end_date = sanitize_text_field($_GET['end_date'] ?? date('Y-m-d'));
        $master_id = sanitize_text_field($_GET['master_id'] ?? '');

        $statement = csc_finance_generate_statement($start_date, $end_date, $master_id);

        if (isset($statement['error'])) {
            wp_die(esc_html($statement['error']));
        }

        csc_finance_export_statement_csv($statement);
        // exit вызывается внутри csc_finance_export_statement_csv
    }
}

// ============================================================
// 24. КРОН-ЗАДАЧИ ФИНАНСОВОГО МОДУЛЯ
// ============================================================

/**
 * Ежедневная крон-задача финансового модуля.
 * - Проверка просроченных квитанций (черновики старше 30 дней)
 * - Проверка незавершённых фискальных операций (старше 24 часов)
 * - Очистка старых счётчиков
 */
add_action('csc_finance_cron_hook', 'csc_finance_daily_cron');
if (!function_exists('csc_finance_daily_cron')) {
    function csc_finance_daily_cron() {
        global $wpdb;

        // --- Просроченные черновики квитанций (30 дней) ---
        $invoice_table = $wpdb->prefix . 'csc_invoices';
        $cutoff_invoice = date('Y-m-d H:i:s', strtotime('-30 days'));
        $expired_drafts = $wpdb->query($wpdb->prepare(
            "UPDATE {$invoice_table} SET status = 'cancelled'
             WHERE status = 'draft' AND created_at < %s",
            $cutoff_invoice
        ));
        if ($expired_drafts > 0) {
            error_log("[CSC_FINANCE] Отменено просроченных черновиков квитанций: {$expired_drafts}");
        }

        // --- Незавершённые фискальные чеки (24 часа) ---
        $fiscal_table = $wpdb->prefix . 'csc_fiscal_receipts';
        $cutoff_fiscal = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stuck_fiscals = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_number FROM {$fiscal_table}
             WHERE status = 'pending' AND created_at < %s",
            $cutoff_fiscal
        ));
        foreach ($stuck_fiscals as $fisc) {
            csc_finance_fail_fiscal_receipt(
                (int)$fisc->id,
                'Автоматическая отмена: чек не зарегистрирован в течение 24 часов'
            );
            error_log("[CSC_FINANCE] Отменён зависший фискальный чек #{$fisc->id} для заказа {$fisc->order_number}");
        }

        // --- Очистка старых счётчиков квитанций (старше 7 дней) ---
        $cutoff_counter = date('Ymd', strtotime('-7 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'csc_invoice_counter_%'
             AND option_name < %s",
            'csc_invoice_counter_' . $cutoff_counter
        ));

        do_action('csc_finance_daily_cron_completed');
    }
}

/**
 * Планирование крона при инициализации админки.
 */
add_action('admin_init', 'csc_finance_schedule_cron', 60);
if (!function_exists('csc_finance_schedule_cron')) {
    function csc_finance_schedule_cron() {
        if (!wp_next_scheduled('csc_finance_cron_hook')) {
            wp_schedule_event(time(), 'daily', 'csc_finance_cron_hook');
        }
    }
}

// ============================================================
// 25. УТИЛИТЫ ФОРМАТИРОВАНИЯ И ВАЛИДАЦИИ
// ============================================================

/**
 * Форматирование денежной суммы.
 *
 * @param float  $amount
 * @param bool   $with_currency
 * @return string
 */
if (!function_exists('csc_finance_format_amount')) {
    function csc_finance_format_amount($amount, $with_currency = true) {
        $formatted = number_format(floatval($amount), 2, ',', ' ');
        return $with_currency ? $formatted . ' ₽' : $formatted;
    }
}

/**
 * Лейбл статуса квитанции.
 *
 * @param string $status
 * @return string
 */
if (!function_exists('csc_finance_get_invoice_status_label')) {
    function csc_finance_get_invoice_status_label($status) {
        $labels = [
            'draft'     => '📝 Черновик',
            'issued'    => '📄 Выставлена',
            'paid'      => '✅ Оплачена',
            'refunded'  => '↩️ Возврат',
            'cancelled' => '❌ Отменена',
        ];
        return $labels[$status] ?? $status;
    }
}

/**
 * Лейбл статуса фискального чека.
 *
 * @param string $status
 * @return string
 */
if (!function_exists('csc_finance_get_fiscal_status_label')) {
    function csc_finance_get_fiscal_status_label($status) {
        $labels = [
            'pending'    => '⏳ Ожидает регистрации',
            'registered' => '✅ Зарегистрирован',
            'failed'     => '❌ Ошибка',
            'cancelled'  => '❌ Отменён',
        ];
        return $labels[$status] ?? $status;
    }
}

/**
 * Лейбл типа транзакции.
 *
 * @param string $type
 * @return string
 */
if (!function_exists('csc_finance_get_txn_type_label')) {
    function csc_finance_get_txn_type_label($type) {
        $labels = [
            'income'     => '📈 Доход',
            'expense'    => '📉 Расход',
            'refund'     => '↩️ Возврат',
            'penalty'    => '⚠️ Штраф',
            'payout'     => '💸 Выплата',
            'commission' => '💰 Комиссия',
        ];
        return $labels[$type] ?? $type;
    }
}

/**
 * Лейбл статуса транзакции.
 *
 * @param string $status
 * @return string
 */
if (!function_exists('csc_finance_get_txn_status_label')) {
    function csc_finance_get_txn_status_label($status) {
        $labels = [
            'pending'    => '⏳ Ожидает',
            'processing' => '⚙️ Обработка',
            'completed'  => '✅ Завершена',
            'failed'     => '❌ Ошибка',
            'refunded'   => '↩️ Возврат',
        ];
        return $labels[$status] ?? $status;
    }
}

/**
 * Валидация диапазона дат.
 *
 * @param string $start_date
 * @param string $end_date
 * @return bool
 */
if (!function_exists('csc_finance_validate_date_range')) {
    function csc_finance_validate_date_range($start_date, $end_date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) return false;
        if (strtotime($start_date) > strtotime($end_date)) return false;
        // Максимум 5 лет
        if ((strtotime($end_date) - strtotime($start_date)) > (5 * 365 * 24 * 60 * 60)) return false;
        return true;
    }
}

/**
 * Получение настроек фискального провайдера.
 *
 * @return array
 */
if (!function_exists('csc_finance_get_fiscal_settings')) {
    function csc_finance_get_fiscal_settings() {
        return [
            'provider'     => get_option('csc_fiscal_provider', ''),
            'api_url'      => get_option('csc_fiscal_api_url', ''),
            'api_key'      => get_option('csc_fiscal_api_key', ''),
            'auto_fiscal'  => (bool)get_option('csc_fiscal_auto', false),
            'auto_invoice' => (bool)get_option('csc_finance_auto_invoice', true),
        ];
    }
}
// ============================================================
// 26. МОСТЫ: ПРИВЯЗКА К СУЩЕСТВУЮЩИМ ХУКАМ ЗАВЕРШЕНИЯ ЗАКАЗОВ
// ============================================================

/**
* Мост: завершение через Telegram-кнопку "Заказ выполнен".
* Файл: csc-telegram-bot.php вызывает do_action('csc_tg_order_completed', ...)
*/
add_action('csc_tg_order_completed', 'csc_finance_bridge_tg_completed', 10, 3);
if (!function_exists('csc_finance_bridge_tg_completed')) {
    function csc_finance_bridge_tg_completed($order_number, $master_id = '', $chat_id = '') {
        $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if (!$order) return;
        do_action('csc_order_completed_finance', $order_number, $order);
    }
}

/**
* Мост: завершение через админку (смена статуса вручную).
* Вызывается из csc-admin-pages.php через do_action('csc_order_status_changed', ...)
*/
add_action('csc_order_status_changed', 'csc_finance_bridge_status_changed', 10, 6);
if (!function_exists('csc_finance_bridge_status_changed')) {
    function csc_finance_bridge_status_changed($order_number, $from_status, $to_status, $changed_by = 'system', $changed_by_id = '', $reason = '') {
        if ($to_status !== 'выполнено') return;
        $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if (!$order) return;
        do_action('csc_order_completed_finance', $order_number, $order);
    }
}

/**
* Мост: завершение через дашборд мастера (кнопка "Заказ выполнен" в кабинете).
* Файл: csc-master-dashboard.php вызывает do_action('csc_master_completed_order', ...)
*/
add_action('csc_master_completed_order', 'csc_finance_bridge_master_completed', 10, 2);
if (!function_exists('csc_finance_bridge_master_completed')) {
    function csc_finance_bridge_master_completed($order_number, $master_id = '') {
        $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if (!$order) return;
        do_action('csc_order_completed_finance', $order_number, $order);
    }
}

// ============================================================
// 27. РЕТРОАКТИВНАЯ ОБРАБОТКА УЖЕ ВЫПОЛНЕННЫХ ЗАКАЗОВ
// ============================================================

/**
* Однократная обработка уже выполненных заказов (миграция).
* Запускается один раз при загрузке админки.
*/
add_action('admin_init', 'csc_finance_retroactive_migration', 99);
if (!function_exists('csc_finance_retroactive_migration')) {
    function csc_finance_retroactive_migration() {
        if (get_option('csc_finance_retroactive_done', false)) return;

        global $wpdb;
        $table = $wpdb->prefix . 'csc_transactions';

        // Проверяем, есть ли уже транзакции (модуль уже работал)
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($existing > 0) {
            update_option('csc_finance_retroactive_done', true, false);
            return;
        }

        // Берём все выполненные заказы
        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : [];
        $processed = 0;

        foreach ($all_orders as $order_number => $order) {
            $status = $order['status'] ?? '';
            if (!in_array($status, ['выполнено', 'завершено', 'Выполнено'])) continue;

            $master_id = $order['master_id'] ?? '';
            if (empty($master_id)) continue;

            $total = floatval($order['total'] ?? 0);
            if ($total <= 0) continue;

            // Вызываем финансовый триггер
            do_action('csc_order_completed_finance', $order_number, $order);
            $processed++;
        }

        update_option('csc_finance_retroactive_done', true, false);
        error_log("[CSC_FINANCE] Ретроактивная обработка: обработано {$processed} выполненных заказов.");
    }
}
// ============================================================
// 28. АГРЕГАЦИЯ ФИНАНСОВЫХ ДАННЫХ ДЛЯ ЛК МАСТЕРА
// ============================================================

/**
 * Получить финансовый отчёт мастера за период.
 *
 * @param string $master_id ID мастера
 * @param int    $days      Период в днях (0 = всё время)
 * @return array [
 *   'balance'     => float,   // Текущий баланс (к выплате)
 *   'earned'      => float,   // Начислено за период
 *   'paid_out'    => float,   // Выплачено за период
 *   'penalties'   => float,   // Штрафы за период
 *   'operations'  => array,   // Список операций за период
 *   'payouts'     => array,   // История выплат
 *   'period'      => string,  // '7d'|'30d'|'90d'|'365d'|'all'
 *   'date_from'   => string,
 *   'date_to'     => string,
 * ]
 */
if (!function_exists('csc_finance_get_master_report')) {
    function csc_finance_get_master_report($master_id, $days = 30) {
        global $wpdb;
        $ledger_table = $wpdb->prefix . 'csc_financial_ledger';
        $txn_table    = $wpdb->prefix . 'csc_transactions';

        if (empty($master_id)) {
            return ['error' => 'master_id is required'];
        }

        $days = max(0, intval($days));
        $account = 'master_' . $master_id;

        // Определяем период
        $date_to   = current_time('mysql');
        $date_from = ($days > 0) ? date('Y-m-d H:i:s', strtotime("-{$days} days")) : '1970-01-01 00:00:00';
        $period_label = $days === 0 ? 'all' : $days . 'd';

        // === Текущий баланс (за всё время, без привязки к периоду) ===
        $balance_row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
             FROM {$ledger_table}
             WHERE account = %s",
            $account
        ), ARRAY_A);
        $balance = floatval($balance_row['total_debit']) - floatval($balance_row['total_credit']);

        // === Агрегаты за период ===
        $summary_row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as earned,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as paid_out
             FROM {$ledger_table}
             WHERE account = %s AND created_at >= %s AND created_at <= %s",
            $account, $date_from, $date_to
        ), ARRAY_A);

        $earned   = floatval($summary_row['earned'] ?? 0);
        $paid_out = floatval($summary_row['paid_out'] ?? 0);

        // Штрафы за период (из транзакций типа penalty)
        $penalties = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$txn_table}
             WHERE master_id = %s AND type = 'penalty' AND status = 'completed'
             AND created_at >= %s AND created_at <= %s",
            $master_id, $date_from, $date_to
        ));

        // === Операции за период (проводки по аккаунту мастера) ===
        $operations = $wpdb->get_results($wpdb->prepare(
            "SELECT id, entity_type, order_number, type, account, amount,
                    balance_before, balance_after, description, created_at
             FROM {$ledger_table}
             WHERE account = %s AND created_at >= %s AND created_at <= %s
             ORDER BY created_at DESC
             LIMIT 200",
            $account, $date_from, $date_to
        ), ARRAY_A);

        // Нормализуем операции для UI
        $operations_normalized = [];
        foreach (($operations ?: []) as $op) {
            $is_debit = ($op['type'] === 'debit');
            $operations_normalized[] = [
                'id'             => (int)$op['id'],
                'date'           => $op['created_at'],
                'date_formatted' => date('d.m.Y H:i', strtotime($op['created_at'])),
                'type'           => $is_debit ? 'income' : 'payout',
                'type_label'     => $is_debit ? 'Доход' : 'Выплата',
                'amount'         => floatval($op['amount']),
                'amount_signed'  => $is_debit ? floatval($op['amount']) : -floatval($op['amount']),
                'balance_after'  => floatval($op['balance_after']),
                'description'    => $op['description'],
                'order_number'   => $op['order_number'],
            ];
        }

        // === История выплат (транзакции типа payout) ===
        $payouts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payment_id, amount, method, status, description, created_at, paid_at
             FROM {$txn_table}
             WHERE master_id = %s AND type = 'payout'
             AND created_at >= %s AND created_at <= %s
             ORDER BY created_at DESC
             LIMIT 100",
            $master_id, $date_from, $date_to
        ), ARRAY_A);

        $method_labels = [
            'cash' => 'Наличные',
            'transfer' => 'Перевод',
            'sbp' => 'СБП',
            'card' => 'На карту',
        ];
        $status_labels = [
            'pending' => 'Ожидает',
            'processing' => 'Обработка',
            'completed' => 'Выплачено',
            'failed' => 'Ошибка',
        ];

        $payouts_normalized = [];
        foreach (($payouts ?: []) as $p) {
            $payouts_normalized[] = [
                'id'            => (int)$p['id'],
                'payment_id'    => $p['payment_id'],
                'date'          => $p['created_at'],
                'date_formatted'=> date('d.m.Y H:i', strtotime($p['created_at'])),
                'amount'        => floatval($p['amount']),
                'method'        => $p['method'],
                'method_label'  => $method_labels[$p['method']] ?? $p['method'],
                'status'        => $p['status'],
                'status_label'  => $status_labels[$p['status']] ?? $p['status'],
                'description'   => $p['description'],
            ];
        }

        return [
            'balance'         => $balance,
            'earned'          => $earned,
            'paid_out'        => $paid_out,
            'penalties'       => $penalties,
            'net_income'      => $earned - $paid_out - $penalties,
            'operations'      => $operations_normalized,
            'payouts'         => $payouts_normalized,
            'operations_count'=> count($operations_normalized),
            'payouts_count'   => count($payouts_normalized),
            'period'          => $period_label,
            'date_from'       => $date_from,
            'date_to'         => $date_to,
        ];
    }
}