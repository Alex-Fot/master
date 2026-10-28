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
            // --- Валидация шагов формы (master-registration.js) ---
            'agree_required'    => csc_t('Необходимо принять условия соглашения'),
            'name_required'     => csc_t('Укажите ФИО'),
            'phone_required'    => csc_t('Укажите телефон'),
            'email_invalid'     => csc_t('Укажите корректный email'),
            'spec_required'     => csc_t('Выберите хотя бы одну специализацию'),
            'address_required'  => csc_t('Укажите адрес проживания / точку выезда'),
            'map_point_required'=> csc_t('Укажите точку выезда на карте. Нажмите «Найти на карте» или поставьте маркер вручную.'),
            'doc_required'      => csc_t('Загрузите документ'),
            'day_required'      => csc_t('Выберите хотя бы один рабочий день'),
            'day_unknown'       => csc_t('Не удалось определить день недели в расписании'),
            'day_time_required' => csc_t('Укажите время работы в день:'),
            'day_time_order'    => csc_t('время окончания должно быть позже времени начала'),
            'day_min_hours'     => csc_t('минимальная продолжительность рабочего дня — 4 часа. Сейчас:'),
            'hours_short'       => csc_t('ч'),
            'minutes_short'     => csc_t('мин'),
            // --- Отправка / сеть ---
            'server_error'      => csc_t('Ошибка сервера:'),
            'submit_error'      => csc_t('Ошибка при отправке'),
            'network_error2'    => csc_t('Ошибка сети. Проверьте подключение.'),
            // --- Карта / геокодирование ---
            'departure_point'   => csc_t('Точка выезда'),
            'zone_free'         => csc_t('Зона свободна: занято %1$s из %2$s мест.'),
            'zone_full'         => csc_t('⚠ В этой зоне уже %1$s мастеров при лимите %2$s. Заявка будет отклонена — выберите другую точку выезда или уменьшите радиус.'),
            'address_for_search'=> csc_t('Введите адрес для поиска на карте'),
            'searching'         => csc_t('Поиск...'),
            'map_not_ready'     => csc_t('Карта не готова. Поставьте точку вручную.'),
            'address_not_found' => csc_t('Адрес не найден'),
            'manual_point_hint' => csc_t('. Поставьте точку на карте вручную.'),
            'geocode_error'     => csc_t('Ошибка геокодирования.'),
            // --- Дни недели ---
            'monday'            => csc_t('Понедельник'),
            'tuesday'           => csc_t('Вторник'),
            'wednesday'         => csc_t('Среда'),
            'thursday'          => csc_t('Четверг'),
            'friday'            => csc_t('Пятница'),
            'saturday'          => csc_t('Суббота'),
            'sunday'            => csc_t('Воскресенье'),
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
