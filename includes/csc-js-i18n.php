<?php
/**
 * CSC i18n для JS: серверный перевод строк инлайнового/внешнего JavaScript.
 *
 * Внешние .js-файлы не проходят через gettext напрямую, поэтому словарь
 * переводов формируется на PHP-стороне (функция csc_t() оборачивает строки
 * в __() с доменом 'csc' — они попадают в POT при сканировании token_get_all)
 * и передаётся в браузер через wp_localize_script как объект window.CSC_I18N.
 *
 * Использование в JS:  cscI18n.t('Сохранить') || 'Сохранить'
 *   (хелпер cscI18n добавлен в master-dashboard.js / master-registration.js;
 *    в инлайновых скриптах шорткодов — аналогичная локальная функция).
 *
 * Подключение: после includes/csc-status-i18n.php, до ajax/шорткод-модулей.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('csc_t')) {
    /**
     * Переводимая строка интерфейса (для последующей передачи в JS-словарь
     * или использования в PHP). Строки проходят через make-pot.php.
     *
     * @param string $text Оригинальный текст (русский — исходный язык плагина).
     * @return string Локализованный текст.
     */
    function csc_t($text) {
        return __($text, 'csc');
    }
}

if (!function_exists('csc_js_strings_dashboard')) {
    /**
     * Словарь строк кабинета мастера (assets/js/master-dashboard.js).
     * Ключи зафиксированы — JS обращается к ним по имени.
     */
    function csc_js_strings_dashboard() {
        return [
            // Статусы (расширенный набор дашборда; базовые — statusLabels)
            'new'               => csc_t('Новая'),
            'accepted'          => csc_t('Принят'),
            'in_progress'       => csc_t('В работе'),
            'completed'         => csc_t('Выполнен'),
            'cancelled'         => csc_t('Отменён'),
            // Вкладки
            'tab_active'        => csc_t('Активные'),
            'tab_history'       => csc_t('История'),
            'tab_profile'       => csc_t('Профиль'),
            // Пустые состояния
            'empty_active'      => csc_t('Активных заявок нет'),
            'empty_history'     => csc_t('История заказов пуста'),
            // Push-уведомления / тосты
            'toast_new_order'   => csc_t('Новая заявка!'),
            'toast_order_taken' => csc_t('Заявку забрал другой мастер'),
            'toast_cancelled'   => csc_t('Заявка отменена'),
            // Сводка
            'sum_in_progress'   => csc_t('В работе'),
            'sum_waiting'       => csc_t('Ожидают'),
            // Действия
            'btn_accept'        => csc_t('Принять'),
            'btn_decline'       => csc_t('Отклонить'),
            'btn_complete'      => csc_t('Завершить'),
            'btn_cancel'        => csc_t('Отменить'),
            'btn_save'          => csc_t('Сохранить'),
            'btn_close'         => csc_t('Закрыть'),
            'confirm_action'    => csc_t('Вы уверены?'),
            // Сообщения
            'loading'           => csc_t('Загрузка...'),
            'network_error'     => csc_t('Ошибка сети. Проверьте соединение.'),
            'save_ok'           => csc_t('Изменения сохранены'),
            'save_fail'         => csc_t('Не удалось сохранить изменения'),
            'action_fail'       => csc_t('Действие не выполнено'),
        ];
    }
}

if (!function_exists('csc_js_strings_registration')) {
    /**
     * Словарь строк формы регистрации мастера (assets/js/master-registration.js).
     */
    function csc_js_strings_registration() {
        return [
            'step'              => csc_t('Шаг'),
            'of'                => csc_t('из'),
            'next'              => csc_t('Далее'),
            'back'              => csc_t('Назад'),
            'submit'            => csc_t('Отправить заявку'),
            'sending'           => csc_t('Отправка...'),
            'required_field'    => csc_t('Заполните обязательное поле'),
            'invalid_phone'     => csc_t('Укажите корректный номер телефона'),
            'invalid_email'     => csc_t('Укажите корректный e-mail'),
            'select_city'       => csc_t('Выберите город'),
            'select_services'   => csc_t('Выберите хотя бы одну услугу'),
            'success_title'     => csc_t('Заявка отправлена!'),
            'success_text'      => csc_t('Мы свяжемся с вами после проверки анкеты.'),
            'error_title'       => csc_t('Ошибка отправки'),
            'retry'             => csc_t('Попробовать снова'),
            'network_error'     => csc_t('Ошибка сети. Проверьте соединение.'),
        ];
    }
}

if (!function_exists('csc_js_i18n_inline_helper')) {
    /**
     * JS-хелпер доступа к словарю CSC_I18N (печатается в инлайновых скриптах
     * шорткодов ЛК; во внешних файлах продублирован как cscI18n).
     */
    function csc_js_i18n_inline_helper() {
        return "if (typeof window.cscI18n === 'undefined') { window.cscI18n = { t: function (k, fb) { var d = (typeof CSC_I18N !== 'undefined') ? CSC_I18N : {}; return (d && d[k]) ? d[k] : (fb !== undefined ? fb : k); } }; }";
    }
}
