<?php
/**
 * Файл: csc-payment-provider-interface.php
 * Описание: Интерфейс платёжного провайдера.
 * Версия: 1.0
 *
 * Все платёжные провайдеры должны реализовывать этот интерфейс.
 */
if (!defined('ABSPATH')) exit;

if (!interface_exists('CSC_Payment_Provider')) {
interface CSC_Payment_Provider {

/**
* Уникальный идентификатор провайдера.
* Примеры: 'yoo', 'tinkoff', 'sber', 'alfa', 'sbp'
*
* @return string
*/
public function get_id(): string;

/**
* Человекочитаемое название провайдера.
* Примеры: 'ЮKassa', 'Тинькофф', 'Сбербанк', 'Альфа-Банк', 'СБП'
*
* @return string
*/
public function get_name(): string;

/**
* Поддерживаемые способы оплаты.
* Примеры: ['card', 'sbp', 'installment', 'qr']
*
* @return array
*/
public function get_supported_methods(): array;

/**
* Включён ли провайдер в настройках.
*
* @return bool
*/
public function is_enabled(): bool;

/**
* Приоритет провайдера (чем меньше, тем выше приоритет).
*
* @return int
*/
public function get_priority(): int;

/**
* Создание платежа.
*
* @param array $params Параметры платежа:
*   - order_number   string  Номер заказа
*   - amount         float   Сумма в рублях
*   - currency       string  Валюта (по умолчанию 'RUB')
*   - description    string  Описание платежа
*   - customer_email string  Email клиента
*   - customer_phone string  Телефон клиента
*   - return_url     string  URL возврата после оплаты
*   - cancel_url     string  URL отмены
*   - metadata       array   Дополнительные данные
*   - method         string  Способ оплаты (если провайдер поддерживает несколько)
*   - receipt        array   Данные чека для фискализации
* @return array Результат:
*   - success          bool    Успех создания
*   - payment_id       string  ID платежа у провайдера
*   - confirmation_url string  URL для перенаправления клиента
*   - qr_code          string  URL QR-кода (если применимо)
*   - status           string  Статус платежа
*   - error_message    string  Сообщение об ошибке (если не успех)
*   - raw_response     array   Сырой ответ от провайдера
*/
public function create_payment(array $params): array;

/**
* Получение статуса платежа.
*
* @param string $payment_id ID платежа у провайдера
* @return array Результат:
*   - success      bool    Успех запроса
*   - status       string  Статус платежа
*   - paid_at      string  Дата оплаты (если оплачен)
*   - amount       float   Сумма платежа
*   - raw_response array   Сырой ответ от провайдера
*/
public function get_payment_status(string $payment_id): array;

/**
* Возврат платежа (полный или частичный).
*
* @param string $payment_id ID платежа у провайдера
* @param float  $amount     Сумма возврата (0 = полный возврат)
* @param string $reason     Причина возврата
* @return array Результат:
*   - success       bool    Успех возврата
*   - refund_id     string  ID возврата у провайдера
*   - status        string  Статус возврата
*   - error_message string  Сообщение об ошибке
*   - raw_response  array   Сырой ответ от провайдера
*/
public function refund_payment(string $payment_id, float $amount = 0, string $reason = ''): array;

/**
* Проверка подписи вебхука.
*
* @param array  $headers HTTP-заголовки запроса
* @param string $body    Тело запроса
* @return bool true если подпись валидна
*/
public function verify_webhook(array $headers, string $body): bool;

/**
* Парсинг вебхука.
*
* @param string $body Тело запроса
* @return array Результат:
*   - event_type    string  Тип события (например: 'payment.succeeded')
*   - payment_id    string  ID платежа
*   - status        string  Статус платежа
*   - order_number  string  Номер заказа (из metadata)
*   - amount        float   Сумма
*   - raw_data      array   Сырые данные
*/
public function parse_webhook(string $body): array;

/**
* Получение комиссии провайдера для конкретной суммы.
* Используется для расчёта чистой прибыли.
*
* @param float  $amount Сумма платежа
* @param string $method Способ оплаты
* @return float Сумма комиссии провайдера в рублях
*/
public function get_gateway_fee(float $amount, string $method = 'card'): float;

/**
* Поддерживает ли провайдер частичный возврат.
*
* @return bool
*/
public function supports_partial_refund(): bool;

/**
* Поддерживает ли провайдер QR-коды для оплаты.
*
* @return bool
*/
public function supports_qr(): bool;

/**
* Поддерживает ли провайдер рассрочку.
*
* @return bool
*/
public function supports_installment(): bool;

/**
* Получение настроек провайдера из опций.
*
* @return array Настройки провайдера
*/
public function get_settings(): array;

/**
* Сохранение настроек провайдера.
*
* @param array $settings Новые настройки
* @return bool Успех сохранения
*/
public function save_settings(array $settings): bool;

/**
* Проверка валидности настроек провайдера.
*
* @return array Результат:
*   - valid    bool    Настройки валидны
*   - errors   array   Список ошибок
*/
public function validate_settings(): array;

/**
* Тестовый запрос для проверки соединения.
*
* @return array Результат:
*   - success bool    Соединение установлено
*   - message string  Сообщение
*/
public function test_connection(): array;
}
}
