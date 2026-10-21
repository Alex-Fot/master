<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// CSC SECURITY MODULE (v1.0)
// Единая точка принудительной проверки CSRF (nonce) для AJAX-эндпоинтов.
// Плагин: Custom Service Calculator
//
// ВАЖНОЕ ПОВЕДЕНИЕ:
// - Проверка строгая: неверный/отсутствующий nonce = отклонение запроса.
// - Совместимость со старыми клиентами: если на странице был включён
//   режим "мягкой" проверки (csc_set_nonce_soft_mode(true), например при
//   агрессивном page-кэшировании), то вместо ошибки возвращается false,
//   а обработчик получает предупреждение в лог. По умолчанию — строго.
// ============================================================

if (!function_exists('csc_set_nonce_soft_mode')) {
    /**
     * Включить/выключить глобальный «мягкий» режим проверки nonce.
     * Мягкий режим: неверный nonce НЕ блокирует запрос (возврат false + log).
     * Используйте только как аварийный совместимый флаг (page cache и т.п.).
     *
     * @param bool $soft
     */
    function csc_set_nonce_soft_mode($soft = true) {
        apply_filters('csc_nonce_soft_mode_override', null); // hook-point для админской настройки
        $GLOBALS['csc_nonce_soft_mode'] = (bool) $soft;
    }
}

if (!function_exists('csc_is_nonce_soft_mode')) {
    function csc_is_nonce_soft_mode() {
        // Фильтр имеет приоритет над флагом: add_filter('csc_nonce_soft_mode', '__return_true');
        $filtered = apply_filters('csc_nonce_soft_mode', null);
        if ($filtered !== null) {
            return (bool) $filtered;
        }
        return !empty($GLOBALS['csc_nonce_soft_mode']);
    }
}

if (!function_exists('csc_verify_ajax_nonce')) {
    /**
     * Принудительная проверка CSRF-токена AJAX-запроса.
     *
     * @param string $nonce_action Действие nonce (например 'csc_cabinet_nonce').
     * @param string $nonce_param  Имя параметра ('_wpnonce' по умолчанию).
     * @param string $error_msg    Сообщение об ошибке для клиента.
     * @return bool true — nonce корректен; false — только в мягком режиме.
     *               В строгом режиме при неудаче завершает запрос (wp_send_json_error / wp_die).
     */
    function csc_verify_ajax_nonce($nonce_action, $nonce_param = '_wpnonce', $error_msg = 'Ошибка безопасности. Обновите страницу.') {
        $valid = wp_verify_nonce(
            isset($_REQUEST[$nonce_param]) ? sanitize_text_field(wp_unslash($_REQUEST[$nonce_param])) : '',
            $nonce_action
        );

        if ($valid !== false) {
            return true;
        }

        if (csc_is_nonce_soft_mode()) {
            error_log('[CSC_SECURITY] Soft-mode: invalid nonce for action "' . $nonce_action . '" from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            return false;
        }

        // Строгий режим: прерываем выполнение.
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => $error_msg, 'code' => 'invalid_nonce'], 403);
        }
        wp_die(esc_html($error_msg), 'CSRF', ['response' => 403]);
    }
}

if (!function_exists('csc_require_login_or_token')) {
    /**
     * Двойная защита эндпоинтов ЛК: авторизованный пользователь WordPress
     * ИЛИ валидный токен заказа (auth_token), переданный клиентом.
     * Используется вместе с привязкой к телефону/номеру заявки.
     *
     * @param array $orders Коллекция заказов [num => order], среди которых ищем токен.
     * @param string $submitted_token Токен из запроса.
     * @return array|false Массив найденных заказов либо false при провале.
     */
    function csc_match_orders_by_token(array $orders, $submitted_token) {
        if (empty($submitted_token)) {
            return false;
        }
        $matched = [];
        foreach ($orders as $num => $o) {
            $order_token = (string) ($o['auth_token'] ?? '');
            if ($order_token === '' || strlen($order_token) < 16) {
                continue; // пропускаем legacy/пустые токены
            }
            if (hash_equals($order_token, $submitted_token)) {
                $matched[$num] = $o;
            }
        }
        return $matched ?: false;
    }
}
