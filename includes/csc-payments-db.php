<?php
/**
 * Файл: csc-payments-db.php
 * Описание: Создание таблиц для платёжной системы.
 * Версия: 1.0
 *
 * Таблицы:
 * - {prefix}csc_transactions      — платёжные транзакции
 * - {prefix}csc_financial_ledger  — финансовый журнал
 * - {prefix}csc_invoices          — квитанции
 * - {prefix}csc_fiscal_receipts   — фискальные чеки
 * - Миграция колонок в {prefix}csc_orders
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. СОЗДАНИЕ ТАБЛИЦ ПЛАТЁЖНОЙ СИСТЕМЫ
// ============================================================
if (!function_exists('csc_payments_install_tables')) {
function csc_payments_install_tables() {
global $wpdb;
$charset_collate = $wpdb->get_charset_collate();

if (!function_exists('dbDelta')) {
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
}

// ----------------------------------------------------------
// 1.1 ТАБЛИЦА ТРАНЗАКЦИЙ
// ----------------------------------------------------------
$table_transactions = $wpdb->prefix . 'csc_transactions';
$sql_transactions = "CREATE TABLE $table_transactions (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
order_number VARCHAR(50) NOT NULL DEFAULT '',
payment_id VARCHAR(255) NOT NULL DEFAULT '',
provider VARCHAR(50) NOT NULL DEFAULT '',
method VARCHAR(50) NOT NULL DEFAULT '',
payment_mode VARCHAR(50) NOT NULL DEFAULT '',
type ENUM('payment','refund','payout','penalty','commission','cash_exclusion','adjustment') NOT NULL DEFAULT 'payment',
status ENUM('pending','processing','succeeded','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
description TEXT,
provider_response LONGTEXT,
webhook_received_at DATETIME NULL DEFAULT NULL,
paid_at DATETIME NULL DEFAULT NULL,
refunded_at DATETIME NULL DEFAULT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY idx_order (order_number),
KEY idx_payment_id (payment_id),
KEY idx_provider (provider),
KEY idx_method (method),
KEY idx_status (status),
KEY idx_type (type),
KEY idx_created (created_at)
) $charset_collate;";
dbDelta($sql_transactions);

// ----------------------------------------------------------
// 1.2 ТАБЛИЦА ФИНАНСОВОГО ЖУРНАЛА
// ----------------------------------------------------------
$table_ledger = $wpdb->prefix . 'csc_financial_ledger';
$sql_ledger = "CREATE TABLE $table_ledger (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
entity_type ENUM('master','client','platform') NOT NULL DEFAULT 'platform',
entity_id VARCHAR(100) NOT NULL DEFAULT '',
order_number VARCHAR(50) NOT NULL DEFAULT '',
transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
type ENUM(
'income','expense','commission','penalty','refund',
'payout_bank','payout_cash','cash_exclusion','cash_received',
'bonus','adjustment_income','adjustment_expense','tax'
) NOT NULL DEFAULT 'income',
amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
balance_before DECIMAL(12,2) NOT NULL DEFAULT 0.00,
balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
description TEXT,
created_by ENUM('system','admin','payment_gateway','cron') NOT NULL DEFAULT 'system',
created_by_id VARCHAR(100) NOT NULL DEFAULT '',
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY idx_entity (entity_type, entity_id),
KEY idx_order (order_number),
KEY idx_type (type),
KEY idx_created (created_at)
) $charset_collate;";
dbDelta($sql_ledger);

// ----------------------------------------------------------
// 1.3 ТАБЛИЦА КВИТАНЦИЙ
// ----------------------------------------------------------
$table_invoices = $wpdb->prefix . 'csc_invoices';
$sql_invoices = "CREATE TABLE $table_invoices (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
order_number VARCHAR(50) NOT NULL DEFAULT '',
file_path TEXT NOT NULL,
file_url TEXT NOT NULL,
total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
status VARCHAR(50) NOT NULL DEFAULT 'issued',
issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
expires_at DATETIME NOT NULL DEFAULT '2099-12-31 23:59:59',
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY idx_order (order_number),
KEY idx_expires (expires_at)
) $charset_collate;";
dbDelta($sql_invoices);

// ----------------------------------------------------------
// 1.4 ТАБЛИЦА ФИСКАЛЬНЫХ ЧЕКОВ
// ----------------------------------------------------------
$table_fiscal = $wpdb->prefix . 'csc_fiscal_receipts';
$sql_fiscal = "CREATE TABLE $table_fiscal (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
order_number VARCHAR(50) NOT NULL DEFAULT '',
provider VARCHAR(50) NOT NULL DEFAULT '',
receipt_type ENUM('payment','refund','correction') NOT NULL DEFAULT 'payment',
amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
status ENUM('pending','sent','registered','failed') NOT NULL DEFAULT 'pending',
fiscal_document_number VARCHAR(100) NOT NULL DEFAULT '',
fiscal_sign VARCHAR(100) NOT NULL DEFAULT '',
provider_response LONGTEXT,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
registered_at DATETIME NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY idx_order (order_number),
KEY idx_status (status),
KEY idx_provider (provider)
) $charset_collate;";
dbDelta($sql_fiscal);

// ----------------------------------------------------------
// 1.5 МИГРАЦИЯ КОЛОНОК В ТАБЛИЦУ ЗАКАЗОВ
// ----------------------------------------------------------
$table_orders = $wpdb->prefix . 'csc_orders';
$existing_columns = $wpdb->get_col("SHOW COLUMNS FROM $table_orders", 0);

if (!in_array('payment_status', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN payment_status ENUM(
'no_payment_required','unpaid','pending','processing',
'partially_paid','paid','refunded','partially_refunded'
) NOT NULL DEFAULT 'no_payment_required' AFTER payment_method");
}

if (!in_array('payment_provider', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN payment_provider VARCHAR(50) NOT NULL DEFAULT '' AFTER payment_status");
}

if (!in_array('payment_mode', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN payment_mode VARCHAR(50) NOT NULL DEFAULT '' AFTER payment_provider");
}

if (!in_array('paid_amount', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total");
}

if (!in_array('remaining_amount', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount");
}

if (!in_array('gateway_fee', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN gateway_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER master_income");
}

if (!in_array('platform_commission', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN platform_commission DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER gateway_fee");
}

if (!in_array('tax_amount', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER platform_commission");
}

if (!in_array('paid_at', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN paid_at DATETIME NULL DEFAULT NULL AFTER tax_amount");
}

if (!in_array('refunded_amount', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_at");
}

if (!in_array('invoice_id', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN invoice_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER refunded_amount");
}

if (!in_array('payout_status', $existing_columns)) {
$wpdb->query("ALTER TABLE $table_orders ADD COLUMN payout_status ENUM(
'not_required','pending','partially_paid','paid'
) NOT NULL DEFAULT 'not_required' AFTER invoice_id");
}

// Индекс на платёжный статус для выборки неоплаченных заказов
$index_exists = $wpdb->get_results("SHOW INDEX FROM $table_orders WHERE Key_name = 'idx_payment_status'");
if (empty($index_exists)) {
$wpdb->query("ALTER TABLE $table_orders ADD INDEX idx_payment_status (payment_status)");
}

// Сохраняем версию БД платёжной системы
update_option('csc_payments_db_version', '1.0', false);
}
}

// ============================================================
// 2. ХУК АКТИВАЦИИ / ОБНОВЛЕНИЯ
// ============================================================
add_action('admin_init', 'csc_payments_maybe_install', 5);
if (!function_exists('csc_payments_maybe_install')) {
function csc_payments_maybe_install() {
$installed_version = get_option('csc_payments_db_version', '0');
$target_version = '1.0';
if (version_compare($installed_version, $target_version, '<')) {
csc_payments_install_tables();
}
}
}

// ============================================================
// 3. УДАЛЕНИЕ ТАБЛИЦ ПРИ ДЕИНСТАЛЛЯЦИИ
// ============================================================
if (!function_exists('csc_payments_uninstall_tables')) {
function csc_payments_uninstall_tables() {
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}csc_transactions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}csc_financial_ledger");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}csc_invoices");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}csc_fiscal_receipts");
delete_option('csc_payments_db_version');
}
}
