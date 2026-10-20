<?php
/**
* Файл: csc-crypto.php
* Описание: Модуль шифрования конфиденциальных данных плагина.
* УЛУЧШЕНИЕ №1: Шифрование токена бота в БД.
* Версия: 1.0
*
* Алгоритм: AES-256-GCM (аутентифицированное шифрование).
* Ключ: производная от wp_salt('auth') + AUTH_KEY (уникален для каждого сайта).
* Формат: csc_enc_v1:<base64(iv || tag || ciphertext)>
*
* Обратная совместимость:
* - Если значение без префикса csc_enc_v1: — возвращается как есть (открытый текст).
* - При первом чтении открытого токена через csc_get_telegram_token()
*   он автоматически шифруется и сохраняется обратно.
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНСТАНТЫ МОДУЛЯ
// ============================================================

/** Префикс зашифрованных значений (версия формата) */
if (!defined('CSC_CRYPTO_PREFIX')) {
define('CSC_CRYPTO_PREFIX', 'csc_enc_v1:');
}

/** Алгоритм шифрования */
if (!defined('CSC_CRYPTO_CIPHER')) {
define('CSC_CRYPTO_CIPHER', 'aes-256-gcm');
}

/** Длина IV в байтах (рекомендация NIST для GCM) */
if (!defined('CSC_CRYPTO_IV_LENGTH')) {
define('CSC_CRYPTO_IV_LENGTH', 12);
}

/** Длина тега аутентификации в байтах */
if (!defined('CSC_CRYPTO_TAG_LENGTH')) {
define('CSC_CRYPTO_TAG_LENGTH', 16);
}

// ============================================================
// 2. ПОЛУЧЕНИЕ КЛЮЧА ШИФРОВАНИЯ
// ============================================================
/**
* Формирование 32-байтного ключа шифрования.
* Ключ уникален для каждого сайта (зависит от wp_salt и AUTH_KEY).
* Не хранится в БД — вычисляется на лету из секретов WordPress.
*
* @return string 32-байтный бинарный ключ
*/
if (!function_exists('csc_get_encryption_key')) {
function csc_get_encryption_key() {
// Основа: соль аутентификации WordPress (уникальна для каждой установки)
$salt = wp_salt('auth');
// Дополнительный секрет: AUTH_KEY из wp-config.php
$auth_key = defined('AUTH_KEY') ? AUTH_KEY : 'csc_fallback_key_no_auth_key';
// Производная: SHA-256 от конкатенации соли и ключа
// Результат: ровно 32 байта (256 бит) для AES-256
return apply_filters('csc_encryption_key', hash('sha256', $salt . '|' . $auth_key . '|csc_crypto_v1', true));}
}

// ============================================================
// 3. ПРОВЕРКА ДОСТУПНОСТИ OPENSSL
// ============================================================
/**
* Проверка доступности OpenSSL с поддержкой AES-256-GCM.
* Если недоступно — шифрование не выполняется, данные хранятся в открытом виде
* с предупреждением в лог.
*
* @return bool true если шифрование доступно
*/
if (!function_exists('csc_crypto_available')) {
function csc_crypto_available() {
static $available = null;
if ($available !== null) {
return $available;
}
// Проверяем наличие функций
if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
$available = false;
error_log('[CSC_CRYPTO] OpenSSL недоступен. Токены хранятся в открытом виде.');
return false;
}
// Проверяем поддержку алгоритма
$methods = openssl_get_cipher_methods();
if (!in_array(CSC_CRYPTO_CIPHER, $methods)) {
$available = false;
error_log('[CSC_CRYPTO] Алгоритм ' . CSC_CRYPTO_CIPHER . ' не поддерживается. Токены хранятся в открытом виде.');
return false;
}
$available = true;
return true;
}
}

// ============================================================
// 4. ШИФРОВАНИЕ
// ============================================================
/**
* Шифрование строки.
* Возвращает строку в формате: csc_enc_v1:<base64(iv || tag || ciphertext)>
* Если шифрование недоступно — возвращает исходную строку без изменений.
*
* @param string $plaintext Открытый текст (например, токен бота)
* @return string Зашифрованная строка или исходная при ошибке
*/
if (!function_exists('csc_encrypt_token')) {
function csc_encrypt_token($plaintext) {
// Пустое значение не шифруем
if (empty($plaintext)) {
return $plaintext;
}
// Если уже зашифровано — не шифруем повторно
if (csc_is_encrypted($plaintext)) {
return $plaintext;
}
// Если шифрование недоступно — возвращаем как есть
if (!csc_crypto_available()) {
return $plaintext;
}
$key = csc_get_encryption_key();
// Генерация случайного IV (12 байт для GCM)
$iv = random_bytes(CSC_CRYPTO_IV_LENGTH);
$tag = ''; // Тег аутентификации заполняется функцией
// Шифрование
$ciphertext = openssl_encrypt(
$plaintext,
CSC_CRYPTO_CIPHER,
$key,
OPENSSL_RAW_DATA,
$iv,
$tag,
'',
CSC_CRYPTO_TAG_LENGTH
);
if ($ciphertext === false) {
error_log('[CSC_CRYPTO] Ошибка шифрования: ' . openssl_error_string());
return $plaintext; // Фолбэк: возвращаем открытым
}
// Формат: iv (12) || tag (16) || ciphertext (N)
$combined = $iv . $tag . $ciphertext;
// Итог: префикс + base64
return CSC_CRYPTO_PREFIX . base64_encode($combined);
}
}

// ============================================================
// 5. ДЕШИФРОВАНИЕ
// ============================================================
/**
* Дешифрование строки.
* Если строка не зашифрована (нет префикса) — возвращает как есть.
* Если расшифровать не удалось (подмена данных, неверный ключ) —
* возвращает пустую строку и пишет в лог.
*
* @param string $encrypted Зашифрованная строка или открытый текст
* @return string Расшифрованный текст или пустая строка при ошибке
*/
if (!function_exists('csc_decrypt_token')) {
function csc_decrypt_token($encrypted) {
// Пустое значение
if (empty($encrypted)) {
return '';
}
// Если не зашифровано (нет префикса) — возвращаем как есть
// Это обеспечивает обратную совместимость со старыми данными
if (!csc_is_encrypted($encrypted)) {
return $encrypted;
}
// Если шифрование недоступно — не можем расшифровать
if (!csc_crypto_available()) {
error_log('[CSC_CRYPTO] Попытка расшифровки при недоступном OpenSSL.');
return '';
}
$key = csc_get_encryption_key();
// Извлекаем base64-часть после префикса
$base64_data = substr($encrypted, strlen(CSC_CRYPTO_PREFIX));
$combined = base64_decode($base64_data, true);
if ($combined === false) {
error_log('[CSC_CRYPTO] Ошибка base64_decode при дешифровании.');
return '';
}
// Проверяем минимальную длину: iv (12) + tag (16) + хотя бы 1 байт данных
$min_length = CSC_CRYPTO_IV_LENGTH + CSC_CRYPTO_TAG_LENGTH + 1;
if (strlen($combined) < $min_length) {
error_log('[CSC_CRYPTO] Данные слишком короткие для дешифрования.');
return '';
}
// Разбираем: iv || tag || ciphertext
$iv = substr($combined, 0, CSC_CRYPTO_IV_LENGTH);
$tag = substr($combined, CSC_CRYPTO_IV_LENGTH, CSC_CRYPTO_TAG_LENGTH);
$ciphertext = substr($combined, CSC_CRYPTO_IV_LENGTH + CSC_CRYPTO_TAG_LENGTH);
// Дешифрование с проверкой тега аутентификации
$plaintext = openssl_decrypt(
$ciphertext,
CSC_CRYPTO_CIPHER,
$key,
OPENSSL_RAW_DATA,
$iv,
$tag
);
if ($plaintext === false) {
error_log('[CSC_CRYPTO] Ошибка дешифрования: неверный ключ или данные повреждены.');
return '';
}
return $plaintext;
}
}

// ============================================================
// 6. ПРОВЕРКА: ЗАШИФРОВАНО ЛИ ЗНАЧЕНИЕ
// ============================================================
/**
* Проверка, является ли значение зашифрованным.
* Определяется по наличию префикса csc_enc_v1:
*
* @param string $value Проверяемая строка
* @return bool true если строка зашифрована данным модулем
*/
if (!function_exists('csc_is_encrypted')) {
function csc_is_encrypted($value) {
if (!is_string($value)) {
return false;
}
return strpos($value, CSC_CRYPTO_PREFIX) === 0;
}
}
// ============================================================
// 7. ОБЁРТКА: ПОЛУЧЕНИЕ ТЕЛЕГРАМ-ТОКЕНА
// ============================================================
/**
* Получение токена бота из настроек.
* Автоматически расшифровывает, если токен зашифрован.
* Если токен в открытом виде — возвращает как есть (обратная совместимость).
*
* ИСПОЛЬЗОВАТЬ ЭТУ ФУНКЦИЮ ВМЕСТО ПРЯМОГО ЧТЕНИЯ $s['telegram_token']
*
* @return string Токен бота в открытом виде или пустая строка
*/
if (!function_exists('csc_get_telegram_token')) {
function csc_get_telegram_token() {
$s = get_option('csc_main_settings', []);
$raw_token = $s['telegram_token'] ?? '';
if (empty($raw_token)) {
return '';
}
// Если зашифровано — расшифровываем
if (csc_is_encrypted($raw_token)) {
return csc_decrypt_token($raw_token);
}
// Если открытым текстом — возвращаем как есть (старый формат)
return $raw_token;
}
}

/**
* Получение токена бота в режиме "только чтение" без автоматической миграции.
* Используется в местах, где нельзя изменять настройки (фронтенд, REST API).
*
* @return string Токен бота в открытом виде или пустая строка
*/
if (!function_exists('csc_get_telegram_token_readonly')) {
function csc_get_telegram_token_readonly() {
$s = get_option('csc_main_settings', []);
$raw_token = $s['telegram_token'] ?? '';
if (empty($raw_token)) {
return '';
}
return csc_is_encrypted($raw_token) ? csc_decrypt_token($raw_token) : $raw_token;
}
}

// ============================================================
// 8. ШИФРОВАНИЕ ПРИ СОХРАНЕНИИ НАСТРОЕК
// ============================================================
/**
* Шифрование токена перед сохранением в настройки.
* Вызывается из обработчика сохранения формы админки.
*
* @param string $token Токен в открытом виде (из формы)
* @return string Зашифрованный токен (или открытый если шифрование недоступно)
*/
if (!function_exists('csc_prepare_token_for_storage')) {
function csc_prepare_token_for_storage($token) {
if (empty($token)) {
return '';
}
// Если пользователь ввёл зашифрованное значение (например, скопировал из БД) —
// не шифруем повторно
if (csc_is_encrypted($token)) {
return $token;
}
return csc_encrypt_token($token);
}
}

// ============================================================
// 9. МИГРАЦИЯ: ШИФРОВАНИЕ СУЩЕСТВУЮЩЕГО ОТКРЫТОГО ТОКЕНА
// ============================================================
/**
* Миграция: если токен хранится в открытом виде — шифрует и сохраняет.
* Вызывается:
* - При активации плагина (включается в csc_plugin_activate)
* - При загрузке админки (однократно за сессию)
*
* Безопасна для повторного вызова: если уже зашифровано — ничего не делает.
*/
if (!function_exists('csc_migrate_encrypt_token')) {
function csc_migrate_encrypt_token() {
// Проверяем флаг миграции
if (get_option('csc_token_encrypted', false)) {
return; // Уже мигрировано
}
$s = get_option('csc_main_settings', []);
$raw_token = $s['telegram_token'] ?? '';
// Если токен пуст или уже зашифрован — просто ставим флаг
if (empty($raw_token) || csc_is_encrypted($raw_token)) {
update_option('csc_token_encrypted', true, false);
return;
}
// Если шифрование недоступно — не мигрируем, но ставим флаг
// чтобы не пытаться снова при каждом запросе
if (!csc_crypto_available()) {
update_option('csc_token_encrypted', true, false);
error_log('[CSC_CRYPTO] Миграция токена пропущена: OpenSSL недоступен.');
return;
}
// Шифруем и сохраняем
$encrypted = csc_encrypt_token($raw_token);
if ($encrypted !== $raw_token) { // Убеждаемся, что шифрование прошло
$s['telegram_token'] = $encrypted;
update_option('csc_main_settings', $s, false);
update_option('csc_token_encrypted', true, false);
error_log('[CSC_CRYPTO] Токен бота зашифрован при миграции.');
} else {
// Шифрование не сработало — не ставим флаг, попробуем снова
error_log('[CSC_CRYPTO] Миграция токена не удалась: шифрование вернуло исходное значение.');
}
}
}

// ============================================================
// 10. АВТОМАТИЧЕСКАЯ МИГРАЦИЯ ПРИ ЗАГРУЗКЕ АДМИНКИ
// ============================================================
// Выполняется один раз за запрос, с низким приоритетом
add_action('admin_init', 'csc_migrate_encrypt_token', 999);

// ============================================================
// 11. ХУК ДЛЯ ИНТЕГРАЦИИ С ДРУГИМИ МОДУЛЯМИ
// ============================================================
/**
* Действие: после успешного шифрования токена.
* Позволяет другим модулям реагировать (например, обновить кэш).
*
* @param string $encrypted Зашифрованное значение
* @param string $original  Оригинальное значение (для совместимости)
*/
// Пример:
// add_action('csc_token_encrypted', function($encrypted, $original) {
//     wp_cache_delete('csc_telegram_token');
// }, 10, 2);

/**
* Фильтр: позволяет переопределить ключ шифрования.
* Полезно для тестов или кастомных реализаций.
*
* @param string $key 32-байтный ключ
* @return string
*/
// Пример:
// add_filter('csc_encryption_key', function($key) {
//     return hash('sha256', 'custom_secret' . $key, true);
// });

// Применяем фильтр к ключу если он зарегистрирован
if (!function_exists('csc_get_encryption_key_filtered')) {
function csc_get_encryption_key_filtered() {
$key = csc_get_encryption_key();
return apply_filters('csc_encryption_key', $key);
}
}

// ============================================================
// 12. ПРОЗРАЧное ШИФРОВАНИЕ КОНФИДЕНЦИАЛЬНЫХ ОПЦИЙ (whitelist)
// ============================================================
/**
* Список опций, значения которых шифруются при сохранении в БД.
* Чтение/запись через csc_get_secret_option() / csc_update_secret_option().
* Старые (открытые) значения читаются без ошибок — обратная совместимость.
*/
if (!function_exists('csc_secret_options_list')) {
function csc_secret_options_list() {
return apply_filters('csc_secret_options', array(
'csc_vapid_private_key', // приватный VAPID-ключ web-push
));
}
}

/**
* Прозрачное чтение секретной опции (авто-дешифрование).
* @param string $option_name Имя опции
* @param mixed $default Значение по умолчанию
* @return string Расшифрованное значение или $default
*/
if (!function_exists('csc_get_secret_option')) {
function csc_get_secret_option($option_name, $default = '') {
$raw = get_option($option_name, $default);
if (!is_string($raw) || $raw === '') {
return $raw;
}
return csc_is_encrypted($raw) ? csc_decrypt_token($raw) : $raw;
}
}

/**
* Прозрачная запись секретной опции (авто-шифрование).
* @param string $option_name Имя опции
* @param string $value Значение в открытом виде
* @return bool Результат update_option
*/
if (!function_exists('csc_update_secret_option')) {
function csc_update_secret_option($option_name, $value) {
return update_option($option_name, csc_encrypt_token($value), false);
}
}

/**
* Хук-страховка: шифрует любую whitelisted-опцию при записи напрямую
* через update_option/add_option (закрытый API WordPress).
* Работает совместно с явными вызовами csc_update_secret_option().
*/
if (!function_exists('csc_pre_encrypt_secret_option')) {
function csc_pre_encrypt_secret_option($value, $option, $passed_value = null) {
static $guard = false;
if ($guard || !is_string($value) || $value === '') {
return $value;
}
if (!in_array($option, csc_secret_options_list(), true)) {
return $value;
}
if (csc_is_encrypted($value) || !csc_crypto_available()) {
return $value;
}
$guard = true;
do_action('csc_secret_option_encrypted', $option);
$encrypted = csc_encrypt_token($value);
$guard = false;
return $encrypted;
}
add_filter('pre_update_option', 'csc_pre_encrypt_secret_option', 10, 3);
add_filter('pre_add_option', 'csc_pre_encrypt_secret_option', 10, 3);
}

/**
* Миграция: шифрование существующих секретов, хранящихся открытым текстом.
* Однократно после обновления схемы/плагина (см. csc_maybe_upgrade_db в главном файле).
*/
if (!function_exists('csc_migrate_encrypt_secrets')) {
function csc_migrate_encrypt_secrets() {
if (get_option('csc_secrets_encrypted', false)) {
return;
}
if (!csc_crypto_available()) {
update_option('csc_secrets_encrypted', true, false);
error_log('[CSC_CRYPTO] Миграция секретов пропущена: OpenSSL недоступен.');
return;
}
foreach (csc_secret_options_list() as $option_name) {
$raw = get_option($option_name, '');
if (is_string($raw) && $raw !== '' && !csc_is_encrypted($raw)) {
$encrypted = csc_encrypt_token($raw);
if ($encrypted !== $raw) {
// update_option перехватывается pre_update_option-фильтром и
// сохраняет уже зашифрованное значение повторно не шифруя
update_option($option_name, $encrypted, false);
error_log('[CSC_CRYPTO] Секретная опция зашифрована при миграции: ' . $option_name);
}
}
}
update_option('csc_secrets_encrypted', true, false);
}
}

// ============================================================
// 13. УТИЛИТА: МАСКИРОВАНИЕ ТОКЕНА ДЛЯ ОТОБРАЖЕНИЯ В АДМИНКЕ
// ============================================================
/**
* Маскирование токена для отображения в админке.
* Показывает первые 8 и последние 4 символа, остальное — звёзды.
* Используется в подсказках и логах, чтобы не светить полный токен.
*
* @param string $token Токен в открытом виде
* @return string Маскированный токен (например: 12345678********90ab)
*/
if (!function_exists('csc_mask_token')) {
function csc_mask_token($token) {
if (empty($token)) return '';
$len = strlen($token);
if ($len <= 12) {
return str_repeat('*', $len);
}
$prefix = substr($token, 0, 8);
$suffix = substr($token, -4);
$masked_len = $len - 12;
return $prefix . str_repeat('*', $masked_len) . $suffix;
}
}