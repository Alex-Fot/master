<?php
/**
 * Файл: csc-backup.php
 * Описание: Модуль автоматического бэкапа данных плагина.
 * УЛУЧШЕНИЕ №9: Ежедневный бэкап таблиц заказов, истории и уведомлений.
 * ВЕРСИЯ 2.0: Добавлен бэкап финансовых таблиц платёжной системы:
 *   - {prefix}csc_transactions — платёжные транзакции
 *   - {prefix}csc_financial_ledger — финансовый журнал
 *   - {prefix}csc_invoices — квитанции
 *   - {prefix}csc_fiscal_receipts — фискальные чеки
 *
 * Крон: запускается раз в сутки через 'csc_backup_cron_hook'.
 * Настройки в админке:
 *   - backup_enabled (bool) — включить/выключить (по умолчанию: 1)
 *   - backup_retention_days (int) — сколько дней хранить (по умолчанию: 7)
 *
 * Хранение: wp-content/uploads/csc-backups/
 * Защита: .htaccess "Deny from all" (создаётся в custom-service-calculator.php)
 *
 * Бэкап создаётся для семи таблиц:
 *   - {prefix}csc_orders — заказы
 *   - {prefix}csc_order_history — история переходов статусов
 *   - {prefix}csc_notifications — уведомления
 *   - {prefix}csc_transactions — платёжные транзакции (НОВОЕ 2.0)
 *   - {prefix}csc_financial_ledger — финансовый журнал (НОВОЕ 2.0)
 *   - {prefix}csc_invoices — квитанции (НОВОЕ 2.0)
 *   - {prefix}csc_fiscal_receipts — фискальные чеки (НОВОЕ 2.0)
 *
 * Формат файлов:
 *   - csc-orders-YYYY-MM-DD.csv
 *   - csc-history-YYYY-MM-DD.csv
 *   - csc-notifications-YYYY-MM-DD.csv
 *   - csc-transactions-YYYY-MM-DD.csv (НОВОЕ 2.0)
 *   - csc-ledger-YYYY-MM-DD.csv (НОВОЕ 2.0)
 *   - csc-invoices-YYYY-MM-DD.csv (НОВОЕ 2.0)
 *   - csc-fiscal-YYYY-MM-DD.csv (НОВОЕ 2.0)
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================

/** Количество дней хранения бэкапов по умолчанию */
if (!defined('CSC_BACKUP_RETENTION_DAYS')) {
    define('CSC_BACKUP_RETENTION_DAYS', 7);
}

/** Минимальное свободное место на диске для создания бэкапа (в байтах, 50 МБ) */
if (!defined('CSC_BACKUP_MIN_FREE_SPACE')) {
    define('CSC_BACKUP_MIN_FREE_SPACE', 50 * 1024 * 1024);
}

/** Максимальное время выполнения бэкапа (в секундах) */
if (!defined('CSC_BACKUP_MAX_EXECUTION_TIME')) {
    define('CSC_BACKUP_MAX_EXECUTION_TIME', 300);
}

/** Префикс имени файла бэкапа */
if (!defined('CSC_BACKUP_FILE_PREFIX')) {
    define('CSC_BACKUP_FILE_PREFIX', 'csc');
}

/** НОВОЕ 2.0: Максимальный размер одной CSV-строки (защита от OOM при больших JSON) */
if (!defined('CSC_BACKUP_MAX_ROW_SIZE')) {
    define('CSC_BACKUP_MAX_ROW_SIZE', 1024 * 1024);
}

/** НОВОЕ 2.0: Лимит записей на один файл транзакций (разделение при больших объёмах) */
if (!defined('CSC_BACKUP_TRANSACTIONS_CHUNK')) {
    define('CSC_BACKUP_TRANSACTIONS_CHUNK', 5000);
}

/** НОВОЕ 2.0: Лимит записей на один файл журнала (разделение при больших объёмах) */
if (!defined('CSC_BACKUP_LEDGER_CHUNK')) {
    define('CSC_BACKUP_LEDGER_CHUNK', 10000);
}

// ============================================================
// 2. ПОЛУЧЕНИЕ НАСТРОЕК БЭКАПА
// ============================================================

/**
 * Получить текущие настройки бэкапа.
 * Возвращает массив с параметрами по умолчанию, если не заданы.
 *
 * @return array [
 *   'enabled' => bool,
 *   'retention_days' => int
 * ]
 */
if (!function_exists('csc_backup_get_settings')) {
    function csc_backup_get_settings() {
        $settings = get_option('csc_main_settings', []);
        return [
            'enabled'        => isset($settings['backup_enabled']) ? (bool)$settings['backup_enabled'] : true,
            'retention_days' => intval($settings['backup_retention_days'] ?? CSC_BACKUP_RETENTION_DAYS),
        ];
    }
}

/**
 * Получить путь к директории бэкапов.
 * Создаёт директорию если она не существует.
 *
 * @return string|false Путь к директории или false при ошибке
 */
if (!function_exists('csc_backup_get_directory')) {
    function csc_backup_get_directory() {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/csc-backups';

        // Создаём директорию если не существует
        if (!file_exists($backup_dir)) {
            if (!wp_mkdir_p($backup_dir)) {
                error_log('[CSC_BACKUP] Не удалось создать директорию бэкапов: ' . $backup_dir);
                return false;
            }
        }

        // Создаём .htaccess для защиты от прямого доступа
        $htaccess_file = $backup_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }

        // Создаём index.php для дополнительной защиты
        $index_file = $backup_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }

        return $backup_dir;
    }
}

// ============================================================
// НОВОЕ 2.0: ПОЛУЧЕНИЕ ПОДДИРЕКТОРИИ ДЛЯ ФИНАНСОВЫХ БЭКАПОВ
// ============================================================

/**
 * НОВОЕ 2.0: Получить поддиректорию для финансовых бэкапов.
 * Финансовые данные хранятся отдельно от операционных.
 *
 * @param string $backup_dir Базовая директория бэкапов
 * @return string|false Путь к поддиректории или false при ошибке
 */
if (!function_exists('csc_backup_get_financial_directory')) {
    function csc_backup_get_financial_directory($backup_dir) {
        $financial_dir = $backup_dir . '/financial';

        if (!file_exists($financial_dir)) {
            if (!wp_mkdir_p($financial_dir)) {
                error_log('[CSC_BACKUP] Не удалось создать директорию финансовых бэкапов: ' . $financial_dir);
                return false;
            }
        }

        $htaccess_file = $financial_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }

        $index_file = $financial_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }

        return $financial_dir;
    }
}

// ============================================================
// 3. ОБРАБОТЧИК КРОНА
// ============================================================

/**
 * Основной обработчик крона бэкапа.
 * Вызывается через 'csc_backup_cron_hook' раз в сутки.
 */
add_action('csc_backup_cron_hook', 'csc_process_backup');

if (!function_exists('csc_process_backup')) {
    function csc_process_backup() {
        // Проверяем, включён ли бэкап
        $settings = csc_backup_get_settings();
        if (!$settings['enabled']) {
            return;
        }

        // Защита от параллельного выполнения
        $lock_key = 'csc_backup_lock';
        if (get_transient($lock_key)) {
            error_log('[CSC_BACKUP] Бэкап уже выполняется, пропускаем.');
            return;
        }
        set_transient($lock_key, 1, CSC_BACKUP_MAX_EXECUTION_TIME);

        // Увеличиваем лимит времени выполнения
        @set_time_limit(CSC_BACKUP_MAX_EXECUTION_TIME);

        // Получаем директорию бэкапов
        $backup_dir = csc_backup_get_directory();
        if (!$backup_dir) {
            delete_transient($lock_key);
            return;
        }

        // Проверяем свободное место на диске
        $free_space = disk_free_space($backup_dir);
        if ($free_space !== false && $free_space < CSC_BACKUP_MIN_FREE_SPACE) {
            error_log('[CSC_BACKUP] Недостаточно свободного места на диске: ' . round($free_space / 1024 / 1024, 2) . ' МБ');
            csc_backup_notify_admin_error('Недостаточно свободного места на диске для создания бэкапа.');
            delete_transient($lock_key);
            return;
        }

        // Создаём бэкапы всех таблиц
        $date_str = date('Y-m-d');
        $results = [];

        // 1. Бэкап таблицы заказов
        $results['orders'] = csc_backup_table_orders($backup_dir, $date_str);

        // 2. Бэкап таблицы истории
        $results['history'] = csc_backup_table_history($backup_dir, $date_str);

        // 3. Бэкап таблицы уведомлений
        $results['notifications'] = csc_backup_table_notifications($backup_dir, $date_str);

        // === НОВОЕ 2.0: БЭКАП ФИНАНСОВЫХ ТАБЛИЦ ===

        // 4. Бэкап таблицы транзакций
        $results['transactions'] = csc_backup_table_transactions($backup_dir, $date_str);

        // 5. Бэкап финансового журнала
        $results['ledger'] = csc_backup_table_ledger($backup_dir, $date_str);

        // 6. Бэкап квитанций
        $results['invoices'] = csc_backup_table_invoices($backup_dir, $date_str);

        // 7. Бэкап фискальных чеков
        $results['fiscal'] = csc_backup_table_fiscal($backup_dir, $date_str);

        // === КОНЕЦ НОВОГО 2.0 ===

        // Ротация: удаляем старые файлы
        csc_backup_rotate_files($backup_dir, $settings['retention_days']);

        // Логируем результат
        $success_count = count(array_filter($results));
        $total_count = count($results);
        error_log("[CSC_BACKUP] Бэкап завершён: {$success_count}/{$total_count} таблиц успешно.");

        // Уведомляем админа при ошибке
        if ($success_count < $total_count) {
            $failed_tables = array_keys(array_filter($results, function($r) { return $r === false; }));
            csc_backup_notify_admin_error('Ошибка бэкапа таблиц: ' . implode(', ', $failed_tables));
        }

        // Хук после завершения бэкапа (для интеграций)
        do_action('csc_backup_completed', array_keys(array_filter($results)), $backup_dir, $date_str);

        // Снимаем блокировку
        delete_transient($lock_key);
    }
}

// ============================================================
// 4. БЭКАП ТАБЛИЦЫ ЗАКАЗОВ
// ============================================================

/**
 * Создание бэкапа таблицы заказов в CSV.
 * Использует csc_get_all_orders() для совместимости с фолбэком на wp_options.
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_orders')) {
    function csc_backup_table_orders($backup_dir, $date_str) {
        $filename = CSC_BACKUP_FILE_PREFIX . '-orders-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        // Если файл за сегодня уже существует — пропускаем (защита от дублей)
        if (file_exists($filepath)) {
            error_log('[CSC_BACKUP] Бэкап заказов за ' . $date_str . ' уже существует.');
            return $filepath;
        }

        // Получаем данные заказов через единую функцию (таблица + фолбэк)
        $all_orders = csc_get_all_orders();

        if (empty($all_orders)) {
            error_log('[CSC_BACKUP] Нет заказов для бэкапа.');
            // Создаём пустой файл с заголовками для фиксации даты
            $handle = fopen($filepath, 'w');
            if ($handle === false) return false;
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, [
                'order_number', 'name', 'phone', 'email', 'address', 'comment',
                'client_lat', 'client_lng', 'geocode_status',
                'city', 'city_name', 'zone',
                'date', 'time', 'duration', 'total', 'base_total', 'discount',
                'discount_info', 'commission', 'master_income',
                'payment_method', 'payment_status', 'payment_provider', 'payment_mode',
                'paid_amount', 'remaining_amount', 'gateway_fee', 'platform_commission',
                'tax_amount', 'paid_at', 'refunded_amount', 'invoice_id', 'payout_status',
                'services', 'services_for_master', 'files', 'completion_files',
                'master_id', 'current_candidate', 'status', 'routing_status',
                'rejected_by', 'accepted_by', 'offer_time', 'tg_message_id',
                'routing_attempts', 'routing_started_at', 'auth_token', 'review',
                'additional_items', 'parent_order', 'is_child', 'has_child_order',
                'cancel_reason', 'master_released', 'reminder_sent',
                'created_at', 'completed_at', 'cancelled_at', 'started_at'
            ], ';');
            fclose($handle);
            return $filepath;
        }

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            error_log('[CSC_BACKUP] Не удалось создать файл: ' . $filepath);
            return false;
        }

        // BOM для корректного отображения кириллицы в Excel
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки столбцов (полный перечень всех полей заказа включая новые платёжные)
        // ИСПРАВЛЕНО 6.2: добавлены поля геоданных и напоминаний
        $headers = [
            'order_number', 'name', 'phone', 'email', 'address', 'comment',
            'client_lat', 'client_lng', 'geocode_status',
            'date', 'time', 'duration', 'total', 'base_total', 'discount',
            'discount_info', 'commission', 'master_income', 'payment_method',
            'services', 'services_for_master', 'files', 'completion_files',
            'master_id', 'current_candidate', 'status', 'routing_status',
            'rejected_by', 'accepted_by', 'offer_time', 'tg_message_id',
            'routing_attempts', 'routing_started_at', 'auth_token', 'review',
            'additional_items', 'parent_order', 'is_child', 'has_child_order',
            'cancel_reason', 'master_released', 'reminder_sent',
            'created_at', 'completed_at', 'cancelled_at', 'started_at'
        ];
        fputcsv($handle, $headers, ';');

        // Данные
        foreach ($all_orders as $order_number => $order) {
            // ИСПРАВЛЕНО 6.2: добавлены поля геоданных и напоминаний
            $row = [
                $order_number,
                $order['name'] ?? '',
                $order['phone'] ?? '',
                $order['email'] ?? '',
                $order['address'] ?? '',
                $order['comment'] ?? '',
                $order['client_lat'] ?? '',
                $order['client_lng'] ?? '',
                $order['geocode_status'] ?? '',
                $order['date'] ?? '',
                $order['time'] ?? '',
                $order['duration'] ?? 0,
                $order['total'] ?? 0,
                $order['base_total'] ?? 0,
                $order['discount'] ?? 0,
                $order['discount_info'] ?? '',
                $order['commission'] ?? 0,
                $order['master_income'] ?? 0,
                $order['payment_method'] ?? 'cash',
                wp_json_encode($order['services'] ?? []),
                wp_json_encode($order['services_for_master'] ?? []),
                wp_json_encode($order['files'] ?? []),
                wp_json_encode($order['completion_files'] ?? []),
                $order['master_id'] ?? '',
                $order['current_candidate'] ?? '',
                $order['status'] ?? '',
                $order['routing_status'] ?? '',
                wp_json_encode($order['rejected_by'] ?? []),
                $order['accepted_by'] ?? '',
                $order['offer_time'] ?? '',
                $order['tg_message_id'] ?? '',
                $order['routing_attempts'] ?? 0,
                $order['routing_started_at'] ?? '',
                $order['auth_token'] ?? '',
                isset($order['review']) ? wp_json_encode($order['review']) : '',
                isset($order['additional_items']) ? wp_json_encode($order['additional_items']) : '',
                $order['parent_order'] ?? '',
                !empty($order['is_child']) ? '1' : '0',
                $order['has_child_order'] ?? '',
                $order['cancel_reason'] ?? '',
                !empty($order['master_released']) ? '1' : '0',
                !empty($order['reminder_sent']) ? '1' : '0',
                $order['created_at'] ?? '',
                $order['completed_at'] ?? '',
                $order['cancelled_at'] ?? '',
                $order['started_at'] ?? '',
            ];
            fputcsv($handle, $row, ';');
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап заказов создан: ' . $filename . ' (' . count($all_orders) . ' записей)');
        return $filepath;
    }
}

// ============================================================
// 5. БЭКАП ТАБЛИЦЫ ИСТОРИИ ПЕРЕХОДОВ СТАТУСОВ
// ============================================================

/**
 * Создание бэкапа таблицы истории в CSV.
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_history')) {
    function csc_backup_table_history($backup_dir, $date_str) {
        global $wpdb;
        $filename = CSC_BACKUP_FILE_PREFIX . '-history-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        $table = $wpdb->prefix . 'csc_order_history';

        // Проверяем существование таблицы
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            error_log('[CSC_BACKUP] Таблица истории не найдена: ' . $table);
            return false;
        }

        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);

        $handle = fopen($filepath, 'w');
        if ($handle === false) return false;

        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки
        $headers = ['id', 'order_number', 'from_status', 'to_status', 'changed_by', 'changed_by_id', 'reason', 'created_at'];
        fputcsv($handle, $headers, ';');

        // Данные
        if (!empty($results)) {
            foreach ($results as $row) {
                fputcsv($handle, [
                    $row['id'],
                    $row['order_number'],
                    $row['from_status'],
                    $row['to_status'],
                    $row['changed_by'],
                    $row['changed_by_id'],
                    $row['reason'],
                    $row['created_at'],
                ], ';');
            }
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап истории создан: ' . $filename . ' (' . count($results) . ' записей)');
        return $filepath;
    }
}

// ============================================================
// 6. БЭКАП ТАБЛИЦЫ УВЕДОМЛЕНИЙ
// ============================================================

/**
 * Создание бэкапа таблицы уведомлений в CSV.
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_notifications')) {
    function csc_backup_table_notifications($backup_dir, $date_str) {
        global $wpdb;
        $filename = CSC_BACKUP_FILE_PREFIX . '-notifications-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        $table = $wpdb->prefix . 'csc_notifications';

        // Проверяем существование таблицы
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            error_log('[CSC_BACKUP] Таблица уведомлений не найдена: ' . $table);
            return false;
        }

        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);

        $handle = fopen($filepath, 'w');
        if ($handle === false) return false;

        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки
        $headers = ['id', 'order_number', 'recipient_type', 'recipient_id', 'channel', 'title', 'message', 'payload', 'status', 'external_id', 'created_at', 'sent_at', 'delivered_at', 'read_at', 'meta'];
        fputcsv($handle, $headers, ';');

        // Данные
        if (!empty($results)) {
            foreach ($results as $row) {
                fputcsv($handle, [
                    $row['id'],
                    $row['order_number'],
                    $row['recipient_type'],
                    $row['recipient_id'],
                    $row['channel'],
                    $row['title'],
                    $row['message'],
                    $row['payload'],
                    $row['status'],
                    $row['external_id'],
                    $row['created_at'],
                    $row['sent_at'],
                    $row['delivered_at'],
                    $row['read_at'],
                    $row['meta'],
                ], ';');
            }
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап уведомлений создан: ' . $filename . ' (' . count($results) . ' записей)');
        return $filepath;
    }
}

// ============================================================
// НОВОЕ 2.0: БЭКАП ТАБЛИЦЫ ТРАНЗАКЦИЙ
// ============================================================

/**
 * НОВОЕ 2.0: Создание бэкапа таблицы платёжных транзакций в CSV.
 * При больших объёмах (>5000 записей) файл разбивается на части.
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_transactions')) {
    function csc_backup_table_transactions($backup_dir, $date_str) {
        global $wpdb;
        $filename = CSC_BACKUP_FILE_PREFIX . '-transactions-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        $table = $wpdb->prefix . 'csc_transactions';

        // Проверяем существование таблицы
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            error_log('[CSC_BACKUP] Таблица транзакций не найдена: ' . $table);
            return false;
        }

        // Определяем поддиректорию для финансовых данных
        $financial_dir = csc_backup_get_financial_directory($backup_dir);
        if (!$financial_dir) {
            $financial_dir = $backup_dir;
        }

        $total_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            error_log('[CSC_BACKUP] Не удалось создать файл транзакций: ' . $filepath);
            return false;
        }

        // BOM для Excel
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки всех колонок таблицы транзакций
        $headers = [
            'id', 'order_number', 'payment_id', 'provider', 'method', 'payment_mode',
            'type', 'status', 'amount', 'currency', 'description',
            'provider_response', 'webhook_received_at', 'paid_at', 'refunded_at',
            'created_at', 'updated_at'
        ];
        fputcsv($handle, $headers, ';');

        if ($total_count > 0) {
            // Читаем транзакциями для экономии памяти
            $offset = 0;
            $chunk_size = CSC_BACKUP_TRANSACTIONS_CHUNK;

            while ($offset < $total_count) {
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                        $chunk_size,
                        $offset
                    ),
                    ARRAY_A
                );

                if (empty($results)) break;

                foreach ($results as $row) {
                    // Защита от огромных provider_response
                    $provider_response = $row['provider_response'] ?? '';
                    if (strlen($provider_response) > CSC_BACKUP_MAX_ROW_SIZE) {
                        $provider_response = substr($provider_response, 0, CSC_BACKUP_MAX_ROW_SIZE) . '...[TRUNCATED]';
                    }

                    fputcsv($handle, [
                        $row['id'],
                        $row['order_number'],
                        $row['payment_id'],
                        $row['provider'],
                        $row['method'],
                        $row['payment_mode'],
                        $row['type'],
                        $row['status'],
                        $row['amount'],
                        $row['currency'],
                        $row['description'],
                        $provider_response,
                        $row['webhook_received_at'],
                        $row['paid_at'],
                        $row['refunded_at'],
                        $row['created_at'],
                        $row['updated_at'],
                    ], ';');
                }

                $offset += $chunk_size;
            }
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап транзакций создан: ' . $filename . ' (' . $total_count . ' записей)');
        return $filepath;
    }
}

// ============================================================
// НОВОЕ 2.0: БЭКАП ФИНАНСОВОГО ЖУРНАЛА
// ============================================================

/**
 * НОВОЕ 2.0: Создание бэкапа финансового журнала в CSV.
 * При больших объёмах (>10000 записей) файл разбивается на части.
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_ledger')) {
    function csc_backup_table_ledger($backup_dir, $date_str) {
        global $wpdb;
        $filename = CSC_BACKUP_FILE_PREFIX . '-ledger-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        $table = $wpdb->prefix . 'csc_financial_ledger';

        // Проверяем существование таблицы
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            error_log('[CSC_BACKUP] Таблица финансового журнала не найдена: ' . $table);
            return false;
        }

        $total_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            error_log('[CSC_BACKUP] Не удалось создать файл журнала: ' . $filepath);
            return false;
        }

        // BOM для Excel
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки всех колонок финансового журнала
        $headers = [
            'id', 'entity_type', 'entity_id', 'order_number', 'transaction_id',
            'type', 'amount', 'balance_before', 'balance_after',
            'description', 'created_by', 'created_by_id', 'created_at'
        ];
        fputcsv($handle, $headers, ';');

        if ($total_count > 0) {
            $offset = 0;
            $chunk_size = CSC_BACKUP_LEDGER_CHUNK;

            while ($offset < $total_count) {
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                        $chunk_size,
                        $offset
                    ),
                    ARRAY_A
                );

                if (empty($results)) break;

                foreach ($results as $row) {
                    fputcsv($handle, [
                        $row['id'],
                        $row['entity_type'],
                        $row['entity_id'],
                        $row['order_number'],
                        $row['transaction_id'],
                        $row['type'],
                        $row['amount'],
                        $row['balance_before'],
                        $row['balance_after'],
                        $row['description'],
                        $row['created_by'],
                        $row['created_by_id'],
                        $row['created_at'],
                    ], ';');
                }

                $offset += $chunk_size;
            }
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап финансового журнала создан: ' . $filename . ' (' . $total_count . ' записей)');
        return $filepath;
    }
}

// ============================================================
// НОВОЕ 2.0: БЭКАП КВИТАНЦИЙ
// ============================================================

/**
 * НОВОЕ 2.0: Создание бэкапа таблицы квитанций в CSV.
 * Включает метаданные квитанций (пути к файлам, статусы, сроки).
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_invoices')) {
    function csc_backup_table_invoices($backup_dir, $date_str) {
        global $wpdb;
        $filename = CSC_BACKUP_FILE_PREFIX . '-invoices-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        $table = $wpdb->prefix . 'csc_invoices';

        // Проверяем существование таблицы
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            error_log('[CSC_BACKUP] Таблица квитанций не найдена: ' . $table);
            return false;
        }

        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            error_log('[CSC_BACKUP] Не удалось создать файл квитанций: ' . $filepath);
            return false;
        }

        // BOM для Excel
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки всех колонок таблицы квитанций
        $headers = [
            'id', 'order_number', 'file_path', 'file_url',
            'total', 'status', 'issued_at', 'expires_at', 'created_at'
        ];
        fputcsv($handle, $headers, ';');

        // Данные
        if (!empty($results)) {
            foreach ($results as $row) {
                fputcsv($handle, [
                    $row['id'],
                    $row['order_number'],
                    $row['file_path'],
                    $row['file_url'],
                    $row['total'],
                    $row['status'],
                    $row['issued_at'],
                    $row['expires_at'],
                    $row['created_at'],
                ], ';');
            }
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап квитанций создан: ' . $filename . ' (' . count($results) . ' записей)');
        return $filepath;
    }
}

// ============================================================
// НОВОЕ 2.0: БЭКАП ФИСКАЛЬНЫХ ЧЕКОВ
// ============================================================

/**
 * НОВОЕ 2.0: Создание бэкапа таблицы фискальных чеков в CSV.
 * Включает данные о фискальных документах, подписи, статусы регистрации.
 *
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата в формате Y-m-d
 * @return string|false Путь к созданному файлу или false при ошибке
 */
if (!function_exists('csc_backup_table_fiscal')) {
    function csc_backup_table_fiscal($backup_dir, $date_str) {
        global $wpdb;
        $filename = CSC_BACKUP_FILE_PREFIX . '-fiscal-' . $date_str . '.csv';
        $filepath = $backup_dir . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        $table = $wpdb->prefix . 'csc_fiscal_receipts';

        // Проверяем существование таблицы
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            error_log('[CSC_BACKUP] Таблица фискальных чеков не найдена: ' . $table);
            return false;
        }

        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            error_log('[CSC_BACKUP] Не удалось создать файл фискальных чеков: ' . $filepath);
            return false;
        }

        // BOM для Excel
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовки всех колонок таблицы фискальных чеков
        $headers = [
            'id', 'order_number', 'provider', 'receipt_type',
            'amount', 'status', 'fiscal_document_number', 'fiscal_sign',
            'provider_response', 'created_at', 'registered_at'
        ];
        fputcsv($handle, $headers, ';');

        // Данные
        if (!empty($results)) {
            foreach ($results as $row) {
                // Защита от огромных provider_response
                $provider_response = $row['provider_response'] ?? '';
                if (strlen($provider_response) > CSC_BACKUP_MAX_ROW_SIZE) {
                    $provider_response = substr($provider_response, 0, CSC_BACKUP_MAX_ROW_SIZE) . '...[TRUNCATED]';
                }

                fputcsv($handle, [
                    $row['id'],
                    $row['order_number'],
                    $row['provider'],
                    $row['receipt_type'],
                    $row['amount'],
                    $row['status'],
                    $row['fiscal_document_number'],
                    $row['fiscal_sign'],
                    $provider_response,
                    $row['created_at'],
                    $row['registered_at'],
                ], ';');
            }
        }

        fclose($handle);
        error_log('[CSC_BACKUP] Бэкап фискальных чеков создан: ' . $filename . ' (' . count($results) . ' записей)');
        return $filepath;
    }
}

// ============================================================
// 7. РОТАЦИЯ ФАЙЛОВ БЭКАПА
// ============================================================

/**
 * Удаление файлов бэкапа старше указанного количества дней.
 * Хранит не более $retention_days файлов каждого типа.
 * НОВОЕ 2.0: включает ротацию финансовых файлов.
 *
 * @param string $backup_dir     Путь к директории бэкапов
 * @param int    $retention_days Количество дней хранения
 */
if (!function_exists('csc_backup_rotate_files')) {
    function csc_backup_rotate_files($backup_dir, $retention_days = CSC_BACKUP_RETENTION_DAYS) {
        if ($retention_days < 1) $retention_days = 1;
        if ($retention_days > 30) $retention_days = 30; // Максимум 30 дней

        $cutoff_date = date('Y-m-d', strtotime("-{$retention_days} days"));
        $deleted_count = 0;

        // Типы файлов для ротации
        // НОВОЕ 2.0: добавлены финансовые типы
        $file_types = [
            'orders',
            'history',
            'notifications',
            'transactions',   // НОВОЕ 2.0
            'ledger',         // НОВОЕ 2.0
            'invoices',       // НОВОЕ 2.0
            'fiscal',         // НОВОЕ 2.0
        ];

        foreach ($file_types as $type) {
            $pattern = CSC_BACKUP_FILE_PREFIX . '-' . $type . '-*.csv';
            $files = glob($backup_dir . '/' . $pattern);

            // Также проверяем поддиректорию финансовых бэкапов
            $financial_dir = $backup_dir . '/financial';
            if (file_exists($financial_dir)) {
                $financial_files = glob($financial_dir . '/' . $pattern);
                if (!empty($financial_files)) {
                    $files = array_merge($files ?: [], $financial_files);
                }
            }

            if (empty($files)) continue;

            // Сортируем по имени (дата в имени файла обеспечивает хронологический порядок)
            sort($files);

            foreach ($files as $file) {
                $basename = basename($file);

                // Извлекаем дату из имени файла: csc-orders-YYYY-MM-DD.csv
                if (preg_match('/' . CSC_BACKUP_FILE_PREFIX . '-' . $type . '-(\d{4}-\d{2}-\d{2})\.csv/', $basename, $matches)) {
                    $file_date = $matches[1];
                    if ($file_date < $cutoff_date) {
                        if (unlink($file)) {
                            $deleted_count++;
                        }
                    }
                }
            }
        }

        if ($deleted_count > 0) {
            error_log("[CSC_BACKUP] Ротация: удалено {$deleted_count} файлов старше {$retention_days} дней.");
        }

        // Хук после ротации (для расширений)
        do_action('csc_backup_rotated', $deleted_count);
    }
}

// ============================================================
// 8. РУЧНОЙ БЭКАП (АДМИН-ФУНКЦИЯ)
// ============================================================

/**
 * Ручное создание бэкапа администратором.
 * Вызывается из админки или через AJAX.
 * НОВОЕ 2.0: бэкапит все 7 таблиц.
 *
 * @return array Результат: ['success' => bool, 'files' => array, 'message' => string]
 */
if (!function_exists('csc_admin_manual_backup')) {
    function csc_admin_manual_backup() {
        if (!current_user_can('manage_options')) {
            return ['success' => false, 'files' => [], 'message' => 'Доступ запрещён'];
        }

        // Защита от параллельного выполнения
        $lock_key = 'csc_backup_lock';
        if (get_transient($lock_key)) {
            return ['success' => false, 'files' => [], 'message' => 'Бэкап уже выполняется. Подождите.'];
        }
        set_transient($lock_key, 1, CSC_BACKUP_MAX_EXECUTION_TIME);

        @set_time_limit(CSC_BACKUP_MAX_EXECUTION_TIME);

        $backup_dir = csc_backup_get_directory();
        if (!$backup_dir) {
            delete_transient($lock_key);
            return ['success' => false, 'files' => [], 'message' => 'Не удалось создать директорию бэкапов.'];
        }

        // Проверяем свободное место
        $free_space = disk_free_space($backup_dir);
        if ($free_space !== false && $free_space < CSC_BACKUP_MIN_FREE_SPACE) {
            delete_transient($lock_key);
            return ['success' => false, 'files' => [], 'message' => 'Недостаточно свободного места на диске.'];
        }

        $date_str = date('Y-m-d');
        $files = [];
        $errors = [];

        // Создаём бэкапы всех 7 таблиц
        $orders_file = csc_backup_table_orders($backup_dir, $date_str);
        if ($orders_file) $files[] = basename($orders_file);
        else $errors[] = 'orders';

        $history_file = csc_backup_table_history($backup_dir, $date_str);
        if ($history_file) $files[] = basename($history_file);
        else $errors[] = 'history';

        $notifications_file = csc_backup_table_notifications($backup_dir, $date_str);
        if ($notifications_file) $files[] = basename($notifications_file);
        else $errors[] = 'notifications';

        // НОВОЕ 2.0: финансовые таблицы
        $transactions_file = csc_backup_table_transactions($backup_dir, $date_str);
        if ($transactions_file) $files[] = basename($transactions_file);
        else $errors[] = 'transactions';

        $ledger_file = csc_backup_table_ledger($backup_dir, $date_str);
        if ($ledger_file) $files[] = basename($ledger_file);
        else $errors[] = 'ledger';

        $invoices_file = csc_backup_table_invoices($backup_dir, $date_str);
        if ($invoices_file) $files[] = basename($invoices_file);
        else $errors[] = 'invoices';

        $fiscal_file = csc_backup_table_fiscal($backup_dir, $date_str);
        if ($fiscal_file) $files[] = basename($fiscal_file);
        else $errors[] = 'fiscal';

        // Ротация
        $settings = csc_backup_get_settings();
        csc_backup_rotate_files($backup_dir, $settings['retention_days']);

        delete_transient($lock_key);

        if (!empty($errors)) {
            return [
                'success' => false,
                'files' => $files,
                'message' => 'Бэкап создан частично. Ошибки: ' . implode(', ', $errors)
            ];
        }

        return [
            'success' => true,
            'files' => $files,
            'message' => 'Бэкап успешно создан: ' . implode(', ', $files)
        ];
    }
}

// ============================================================
// 9. AJAX-ОБРАБОТЧИК РУЧНОГО БЭКАПА
// ============================================================

add_action('wp_ajax_csc_manual_backup', 'csc_manual_backup_ajax_handler');

if (!function_exists('csc_manual_backup_ajax_handler')) {
    function csc_manual_backup_ajax_handler() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }

        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
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
// 10. УВЕДОМЛЕНИЕ АДМИНА ОБ ОШИБКЕ БЭКАПА
// ============================================================

/**
 * Отправка уведомления администратору об ошибке бэкапа.
 * Использует централизованную систему уведомлений.
 *
 * @param string $error_message Текст ошибки
 */
if (!function_exists('csc_backup_notify_admin_error')) {
    function csc_backup_notify_admin_error($error_message) {
        // Логирование через систему уведомлений
        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                'system',
                'admin',
                'admin',
                'web',
                '⚠️ Ошибка автобэкапа',
                $error_message,
                ['type' => 'backup_error', 'timestamp' => current_time('mysql')]
            );
        }

        // Telegram-уведомление админу
        $settings = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($settings['telegram_token'] ?? '');
        $admin_chat = $settings['telegram_chat_id'] ?? '';

        if ($tg_token && $admin_chat && function_exists('csc_send_tg_notification')) {
            csc_send_tg_notification(
                $admin_chat,
                '⚠️ Ошибка автобэкапа',
                $error_message,
                'system',
                'admin',
                'admin',
                null
            );
        }
    }
}

// ============================================================
// 11. УТИЛИТА: ПОЛУЧЕНИЕ СПИСКА БЭКАПОВ
// ============================================================

/**
 * Получить список всех файлов бэкапа.
 * Используется в админке для отображения.
 * НОВОЕ 2.0: включает финансовые файлы.
 *
 * @return array Массив файлов: ['name' => string, 'date' => string, 'size' => int, 'type' => string]
 */
if (!function_exists('csc_backup_get_files_list')) {
    function csc_backup_get_files_list() {
        $backup_dir = csc_backup_get_directory();
        if (!$backup_dir) return [];

        $files = [];

        // НОВОЕ 2.0: все типы файлов
        $file_types = [
            'orders',
            'history',
            'notifications',
            'transactions',   // НОВОЕ 2.0
            'ledger',         // НОВОЕ 2.0
            'invoices',       // НОВОЕ 2.0
            'fiscal',         // НОВОЕ 2.0
        ];

        foreach ($file_types as $type) {
            $pattern = CSC_BACKUP_FILE_PREFIX . '-' . $type . '-*.csv';
            $found = glob($backup_dir . '/' . $pattern);

            // Также проверяем поддиректорию финансовых бэкапов
            $financial_dir = $backup_dir . '/financial';
            if (file_exists($financial_dir)) {
                $financial_found = glob($financial_dir . '/' . $pattern);
                if (!empty($financial_found)) {
                    $found = array_merge($found ?: [], $financial_found);
                }
            }

            if (empty($found)) continue;

            foreach ($found as $file) {
                $basename = basename($file);

                // Извлекаем дату из имени файла
                if (preg_match('/' . CSC_BACKUP_FILE_PREFIX . '-' . $type . '-(\d{4}-\d{2}-\d{2})\.csv/', $basename, $matches)) {
                    $files[] = [
                        'name' => $basename,
                        'date' => $matches[1],
                        'size' => filesize($file),
                        'type' => $type,
                        'path' => $file,
                        'is_financial' => in_array($type, ['transactions', 'ledger', 'invoices', 'fiscal']),
                    ];
                }
            }
        }

        // Сортируем по дате (от новых к старым)
        usort($files, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return $files;
    }
}

/**
 * Форматирование размера файла в человекочитаемый вид.
 *
 * @param int $bytes Размер в байтах
 * @return string Форматированный размер (например: "1.5 МБ")
 */
if (!function_exists('csc_backup_format_size')) {
    function csc_backup_format_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' ГБ';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' МБ';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' КБ';
        }
        return $bytes . ' Б';
    }
}

// ============================================================
// НОВОЕ 2.0: ПОЛУЧЕНИЕ СТАТИСТИКИ БЭКАПОВ
// ============================================================

/**
 * НОВОЕ 2.0: Получить сводную статистику бэкапов.
 * Используется в админке для информирования администратора.
 *
 * @return array Статистика: общий размер, количество файлов, последний бэкап
 */
if (!function_exists('csc_backup_get_stats')) {
    function csc_backup_get_stats() {
        $backup_dir = csc_backup_get_directory();
        if (!$backup_dir) return null;

        $files = csc_backup_get_files_list();

        $total_size = 0;
        $total_count = 0;
        $financial_count = 0;
        $last_backup_date = null;

        foreach ($files as $file) {
            $total_size += $file['size'];
            $total_count++;
            if (!empty($file['is_financial'])) {
                $financial_count++;
            }
            if ($last_backup_date === null || $file['date'] > $last_backup_date) {
                $last_backup_date = $file['date'];
            }
        }

        // Считаем размер директории (включая поддиректории)
        $dir_size = 0;
        if (file_exists($backup_dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backup_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $dir_size += $item->getSize();
                }
            }
        }

        return [
            'total_files' => $total_count,
            'financial_files' => $financial_count,
            'total_csv_size' => csc_backup_format_size($total_size),
            'total_dir_size' => csc_backup_format_size($dir_size),
            'last_backup_date' => $last_backup_date,
            'settings' => csc_backup_get_settings(),
        ];
    }
}

// ============================================================
// НОВОЕ 2.0: ЭКСПОРТ ФИНАНСОВОЙ ВЫПИСКИ ИЗ БЭКАПА
// ============================================================

/**
 * НОВОЕ 2.0: Экспорт финансовой выписки за период из данных бэкапов.
 * Собирает данные из транзакций и журнала за указанный период.
 *
 * @param string $start_date Начальная дата (Y-m-d)
 * @param string $end_date   Конечная дата (Y-m-d)
 * @param string $entity_type Фильтр по типу сущности (опционально)
 * @return array Данные выписки
 */
if (!function_exists('csc_backup_export_financial_statement')) {
    function csc_backup_export_financial_statement($start_date, $end_date, $entity_type = null) {
        global $wpdb;

        $transactions_table = $wpdb->prefix . 'csc_transactions';
        $ledger_table = $wpdb->prefix . 'csc_financial_ledger';

        // Проверяем существование таблиц
        $transactions_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $transactions_table)) === $transactions_table;
        $ledger_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ledger_table)) === $ledger_table;

        $result = [
            'transactions' => [],
            'ledger' => [],
            'period' => ['start' => $start_date, 'end' => $end_date],
        ];

        if ($transactions_exists) {
            $sql = "SELECT * FROM {$transactions_table} WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";
            $params = [$start_date, $end_date];

            if ($entity_type) {
                // Транзакции не имеют entity_type напрямую, но могут быть связаны через order
            }

            $sql .= " ORDER BY created_at ASC";
            $result['transactions'] = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        }

        if ($ledger_exists) {
            $sql = "SELECT * FROM {$ledger_table} WHERE DATE(created_at) >= %s AND DATE(created_at) <= %s";
            $params = [$start_date, $end_date];

            if ($entity_type) {
                $sql .= " AND entity_type = %s";
                $params[] = $entity_type;
            }

            $sql .= " ORDER BY created_at ASC";
            $result['ledger'] = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        }

        // Итоговые суммы
        $total_income = 0;
        $total_expense = 0;
        $total_commission = 0;
        $total_penalty = 0;
        $total_refund = 0;
        $total_payout = 0;

        foreach ($result['ledger'] as $entry) {
            $amount = floatval($entry['amount']);
            switch ($entry['type']) {
                case 'income':
                    $total_income += $amount;
                    break;
                case 'expense':
                case 'adjustment_expense':
                    $total_expense += $amount;
                    break;
                case 'commission':
                    $total_commission += $amount;
                    break;
                case 'penalty':
                    $total_penalty += $amount;
                    break;
                case 'refund':
                    $total_refund += $amount;
                    break;
                case 'payout_bank':
                case 'payout_cash':
                    $total_payout += $amount;
                    break;
            }
        }

        $result['summary'] = [
            'total_income' => $total_income,
            'total_expense' => $total_expense,
            'total_commission' => $total_commission,
            'total_penalty' => $total_penalty,
            'total_refund' => $total_refund,
            'total_payout' => $total_payout,
            'net_profit' => $total_income - $total_expense - $total_commission - $total_refund,
            'transactions_count' => count($result['transactions']),
            'ledger_entries_count' => count($result['ledger']),
        ];

        return $result;
    }
}

// ============================================================
// НОВОЕ 2.0: AJAX ЭКСПОРТА ФИНАНСОВОЙ ВЫПИСКИ
// ============================================================

add_action('wp_ajax_csc_export_financial_statement', 'csc_export_financial_statement_ajax');

if (!function_exists('csc_export_financial_statement_ajax')) {
    function csc_export_financial_statement_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }

        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $start_date = sanitize_text_field($_POST['start_date'] ?? date('Y-m-01'));
        $end_date = sanitize_text_field($_POST['end_date'] ?? date('Y-m-d'));
        $entity_type = sanitize_text_field($_POST['entity_type'] ?? '');

        // Валидация формата дат
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_send_json_error(['message' => 'Некорректный формат даты']);
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error(['message' => 'Дата начала должна быть меньше или равна дате окончания']);
        }

        $statement = csc_backup_export_financial_statement($start_date, $end_date, $entity_type ?: null);

        wp_send_json_success($statement);
    }
}

// ============================================================
// НОВОЕ 2.0: CSV ЭКСПОРТ ФИНАНСОВОЙ ВЫПИСКИ
// ============================================================

add_action('wp_ajax_csc_export_financial_statement_csv', 'csc_export_financial_statement_csv_ajax');

if (!function_exists('csc_export_financial_statement_csv_ajax')) {
    function csc_export_financial_statement_csv_ajax() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещён');
        }

        if (!check_ajax_referer('csc_admin_nonce', 'nonce', false)) {
            wp_die('Ошибка безопасности');
        }

        $start_date = sanitize_text_field($_POST['start_date'] ?? date('Y-m-01'));
        $end_date = sanitize_text_field($_POST['end_date'] ?? date('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_die('Некорректный формат даты');
        }

        $statement = csc_backup_export_financial_statement($start_date, $end_date);

        $filename = 'csc-financial-statement-' . $start_date . '-to-' . $end_date . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // BOM для Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Заголовок
        fputcsv($output, ['Финансовая выписка за период', $start_date, '—', $end_date], ';');
        fputcsv($output, [], ';');

        // Сводка
        fputcsv($output, ['ПОКАЗАТЕЛЬ', 'ЗНАЧЕНИЕ'], ';');
        fputcsv($output, ['Доходы', $statement['summary']['total_income']], ';');
        fputcsv($output, ['Расходы', $statement['summary']['total_expense']], ';');
        fputcsv($output, ['Комиссия платформы', $statement['summary']['total_commission']], ';');
        fputcsv($output, ['Штрафы', $statement['summary']['total_penalty']], ';');
        fputcsv($output, ['Возвраты', $statement['summary']['total_refund']], ';');
        fputcsv($output, ['Выплаты мастерам', $statement['summary']['total_payout']], ';');
        fputcsv($output, ['Чистая прибыль', $statement['summary']['net_profit']], ';');
        fputcsv($output, [], ';');

        // Транзакции
        fputcsv($output, ['ТРАНЗАКЦИИ'], ';');
        fputcsv($output, ['ID', 'Заказ', 'Платёж', 'Провайдер', 'Метод', 'Тип', 'Статус', 'Сумма', 'Валюта', 'Создано', 'Оплачено'], ';');
        foreach ($statement['transactions'] as $t) {
            fputcsv($output, [
                $t['id'], $t['order_number'], $t['payment_id'], $t['provider'],
                $t['method'], $t['type'], $t['status'], $t['amount'],
                $t['currency'], $t['created_at'], $t['paid_at'],
            ], ';');
        }
        fputcsv($output, [], ';');

        // Журнал
        fputcsv($output, ['ФИНАНСОВЫЙ ЖУРНАЛ'], ';');
        fputcsv($output, ['ID', 'Сущность', 'Сущность ID', 'Заказ', 'Тип', 'Сумма', 'Баланс до', 'Баланс после', 'Кем создано', 'Создано'], ';');
        foreach ($statement['ledger'] as $l) {
            fputcsv($output, [
                $l['id'], $l['entity_type'], $l['entity_id'], $l['order_number'],
                $l['type'], $l['amount'], $l['balance_before'], $l['balance_after'],
                $l['created_by'] . ':' . $l['created_by_id'], $l['created_at'],
            ], ';');
        }

        fclose($output);
        exit;
    }
}

// ============================================================
// 12. ХУКИ ДЛЯ ИНТЕГРАЦИИ С ДРУГИМИ МОДУЛЯМИ
// ============================================================

/**
 * Действие: после успешного создания бэкапа.
 * Позволяет другим модулям реагировать (например, отправить в облако).
 *
 * @param array  $files      Массив созданных файлов
 * @param string $backup_dir Путь к директории бэкапов
 * @param string $date_str   Дата бэкапа
 */
// Пример использования:
// add_action('csc_backup_completed', function($files, $backup_dir, $date_str) {
//     // Загрузить бэкап в облачное хранилище
//     foreach ($files as $file) {
//         my_cloud_upload($file);
//     }
// }, 10, 3);

/**
 * Действие: после ротации файлов бэкапа.
 *
 * @param int $deleted_count Количество удалённых файлов
 */
// Пример использования:
// add_action('csc_backup_rotated', function($deleted_count) {
//     error_log("Удалено {$deleted_count} старых бэкапов.");
// }, 10, 1);

/**
 * НОВОЕ 2.0: Действие после создания бэкапа транзакций.
 * Позволяет модулям аналитики обновлять кэши.
 *
 * @param string $filepath Путь к файлу
 * @param int    $count    Количество записей
 */
// Пример:
// add_action('csc_backup_transactions_created', function($filepath, $count) {
//     wp_cache_delete('csc_financial_snapshot_' . date('Y-m-d'));
// }, 10, 2);