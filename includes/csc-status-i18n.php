<?php
/**
 * CSC: Единый словарь статусов заказа (3.5 — статусы на латиницу)
 *
 * Хранение/сравнение — только латинские ключи (pending, processing и т.д.).
 * Human-readable подписи (русские) возвращаются функцией csc_status_label().
 *
 * Подключение: до csc-core-functions.php (см. custom-service-calculator.php).
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// КОНСТАНТЫ СТАТУСОВ
// ============================================================
if (!defined('CSC_STATUS_PENDING'))          define('CSC_STATUS_PENDING', 'pending');            // Ожидает
if (!defined('CSC_STATUS_SEARCHING'))        define('CSC_STATUS_SEARCHING', 'searching');        // Поиск мастера
if (!defined('CSC_STATUS_AWAITING_PAYMENT')) define('CSC_STATUS_AWAITING_PAYMENT', 'awaiting_payment'); // Ожидает оплаты
if (!defined('CSC_STATUS_PROCESSING'))       define('CSC_STATUS_PROCESSING', 'processing');      // В работе
if (!defined('CSC_STATUS_COMPLETED'))        define('CSC_STATUS_COMPLETED', 'completed');        // Выполнено / Завершено
if (!defined('CSC_STATUS_CANCELLED'))        define('CSC_STATUS_CANCELLED', 'cancelled');        // Отменено
if (!defined('CSC_STATUS_NO_MASTERS'))       define('CSC_STATUS_NO_MASTERS', 'no_masters');      // Нет мастеров
if (!defined('CSC_STATUS_ROUTING_TIMEOUT'))  define('CSC_STATUS_ROUTING_TIMEOUT', 'routing_timeout'); // Ошибка: время поиска истекло

if (!function_exists('csc_get_all_statuses')) {
    /**
     * Все канонические статусы заказа.
     */
    function csc_get_all_statuses() {
        return [
            CSC_STATUS_PENDING,
            CSC_STATUS_SEARCHING,
            CSC_STATUS_AWAITING_PAYMENT,
            CSC_STATUS_PROCESSING,
            CSC_STATUS_COMPLETED,
            CSC_STATUS_CANCELLED,
            CSC_STATUS_NO_MASTERS,
            CSC_STATUS_ROUTING_TIMEOUT,
        ];
    }
}

if (!function_exists('csc_status_label')) {
    /**
     * Человекочитаемая подпись статуса (для UI, Telegram, e-mail).
     *
     * @param string $status Латинский ключ статуса.
     * @return string
     */
    function csc_status_label($status) {
        $labels = [
            CSC_STATUS_PENDING          => __('Ожидает', 'csc'),
            CSC_STATUS_SEARCHING        => __('Поиск мастера', 'csc'),
            CSC_STATUS_AWAITING_PAYMENT => __('Ожидает оплаты', 'csc'),
            CSC_STATUS_PROCESSING       => __('В работе', 'csc'),
            CSC_STATUS_COMPLETED        => __('Выполнено', 'csc'),
            CSC_STATUS_CANCELLED        => __('Отменено', 'csc'),
            CSC_STATUS_NO_MASTERS       => __('Нет мастеров', 'csc'),
            CSC_STATUS_ROUTING_TIMEOUT  => __('Ошибка: время поиска истекло', 'csc'),
        ];
        $key = csc_status_normalize(is_string($status) ? $status : '');
        return $labels[$key] ?? $key;
    }
}

if (!function_exists('csc_status_labels_js')) {
    /**
     * Карта «латинский ключ => локализованная подпись» для передачи во фронтенд (JS).
     * Позволяет перевести статусы, отображаемые на клиенте (ЛК клиента/мастера).
     *
     * @return array<string,string>
     */
    function csc_status_labels_js() {
        $map = [];
        foreach (csc_get_all_statuses() as $st) {
            $map[$st] = csc_status_label($st);
        }
        return $map;
    }
}

if (!function_exists('csc_status_normalize')) {
    /**
     * Нормализация статуса в латинский канонический ключ.
     *
     * Принимает старые кириллические значения, новые латинские ключи,
     * а также варианты написания (completed/completed2, finished, canceled/cancelled).
     * Пустые/неизвестные значения возвращаются как есть (например, 'financial:*' маркеры истории).
     *
     * @param string $status
     * @return string
     */
    function csc_status_normalize($status) {
        static $map = null;
        if ($map === null) {
            $map = [
                // --- Старые кириллические значения ---
                'ожидает'                    => CSC_STATUS_PENDING,
                'поиск мастера'              => CSC_STATUS_SEARCHING,
                'ожидает оплаты'             => CSC_STATUS_AWAITING_PAYMENT,
                'в работе'                   => CSC_STATUS_PROCESSING,
                'выполнено'                  => CSC_STATUS_COMPLETED,
                'завершено'                  => CSC_STATUS_COMPLETED,
                'отменено'                   => CSC_STATUS_CANCELLED,
                'нет мастеров'               => CSC_STATUS_NO_MASTERS,
                'ошибка: время поиска истекло' => CSC_STATUS_ROUTING_TIMEOUT,
                // --- Латинские ключи (idempotent) + распространённые синонимы ---
                'pending'           => CSC_STATUS_PENDING,
                'searching'         => CSC_STATUS_SEARCHING,
                'awaiting_payment'  => CSC_STATUS_AWAITING_PAYMENT,
                'processing'        => CSC_STATUS_PROCESSING,
                'completed'         => CSC_STATUS_COMPLETED,
                'complete'          => CSC_STATUS_COMPLETED,
                'finished'          => CSC_STATUS_COMPLETED,
                'cancelled'         => CSC_STATUS_CANCELLED,
                'canceled'          => CSC_STATUS_CANCELLED,
                'no_masters'        => CSC_STATUS_NO_MASTERS,
                'routing_timeout'   => CSC_STATUS_ROUTING_TIMEOUT,
            ];
        }

        $s = trim((string) $status);
        if ($s === '') return '';

        // mbstring может быть отсутствовать — фолбэк на посимвольный UTF-8 lower для кириллицы
        if (function_exists('mb_strtolower')) {
            $key = mb_strtolower($s, 'UTF-8');
        } else {
            static $upper = ['А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'];
            static $lower = ['а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я'];
            $key = str_replace($upper, $lower, $s);
        }
        return $map[$key] ?? $s;
    }
}
