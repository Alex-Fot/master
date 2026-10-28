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
            // Общие заголовки тостов и сообщения дашборда
            'error'             => csc_t('Ошибка'),
            'auth_error'        => csc_t('Ошибка авторизации'),
            'auth_error_text'   => csc_t('Токен доступа недействителен. Обновите страницу.'),
            'net_check_conn'    => csc_t('Проверьте подключение'),
            'orders_load_fail'  => csc_t('Не удалось загрузить заказы'),
            'reviews_load_fail' => csc_t('Не удалось загрузить отзывы'),
            'balance_load_fail' => csc_t('Не удалось загрузить баланс'),
            'refresh'           => csc_t('🔄 Обновить'),
            'no_reviews'        => csc_t('Пока нет отзывов'),
            'no_reviews_text'   => csc_t('Отзывы клиентов появятся здесь после выполнения заказов'),
            'in_progress_n'     => csc_t('В работе:'),
            'waiting_n'         => csc_t('Ожидают:'),
            'ready_to_work'     => csc_t('Готов к работе'),
            'confirm_action_n'  => csc_t('Подтвердите действие'),
            'done'              => csc_t('Готово'),
            'order_updated'     => csc_t('обновлён'),
            'action_fail_msg'   => csc_t('Не удалось выполнить действие'),
            'uploaded'          => csc_t('Загружено'),
            'files_count'       => csc_t('файл(ов)'),
            'uploading'         => csc_t('Загрузка...'),
            'files_not_loaded'  => csc_t('Файлы не загружены'),
            'delete_file_q'     => csc_t('Удалить этот файл из отчёта?'),
            'confirm_completion'=> csc_t('✓ Подтвердить выполнение'),
            'confirm_order_q'   => csc_t('Подтвердить выполнение заказа'),
            'order_completed'   => csc_t('Заказ выполнен'),
            'marked_completed'  => csc_t('отмечен как выполненный'),
            'status_change_fail'=> csc_t('Не удалось изменить статус'),
            'active_label'      => csc_t('Активен'),
            'inactive_label'    => csc_t('Неактивен'),
            'you_active'        => csc_t('Вы активны'),
            'you_inactive'      => csc_t('Вы деактивированы'),
            'saving'            => csc_t('Сохранение...'),
            'saved'             => csc_t('Сохранено'),
            'zone_updated'      => csc_t('Зона обслуживания обновлена'),
            'zone_save_fail'    => csc_t('Не удалось сохранить зону'),
            'save_zone_btn'     => csc_t('💾 Сохранить зону'),
            'notification'      => csc_t('Уведомление'),
            'loading_order'     => csc_t('Загрузка заказа'),
            'no_notifications'  => csc_t('Уведомлений нет'),
            'nothing_to_clear'  => csc_t('Нечего очищать'),
            'clear_all_notif_q' => csc_t('Удалить все уведомления? Это действие необратимо.'),
            'cleared'           => csc_t('Очищено'),
            'all_notif_cleared' => csc_t('Все уведомления удалены'),
            'no_access'         => csc_t('Нет доступа'),
            'server_error'      => csc_t('Ошибка сервера'),
            'clear_unavailable' => csc_t('Очистка недоступна'),
            'clear_failed'      => csc_t('Не удалось очистить'),
            'order_updated_prefix'=> csc_t('Заказ'),
            'new_order_toast'   => csc_t('🆕 Новый заказ #'),
            'client_label'      => csc_t('Клиент'),
            'check_waiting_tab' => csc_t('. Проверьте вкладку «Ожидают».'),
            'no_waiting_orders' => csc_t('Нет ожидающих заказов'),
            'new_orders_here'   => csc_t('Новые заказы появятся здесь'),
            'no_active_orders'  => csc_t('Нет заказов в работе'),
            'accept_from_waiting'=> csc_t('Примите заказ из вкладки «Ожидают»'),
            'no_completed_orders'=> csc_t('Нет выполненных заказов'),
            'completed_here'    => csc_t('Завершённые заказы появятся здесь'),
            'no_cancelled_orders'=> csc_t('Нет отменённых заказов'),
            'all_good'          => csc_t('Всё хорошо!'),
            'no_address'        => csc_t('Адрес не указан'),
            'no_name'           => csc_t('Без имени'),
            'act_accept'        => csc_t('Принять заказ'),
            'act_complete'      => csc_t('Отметить выполненным'),
            'upload_limit'      => csc_t('Достигнут лимит в'),
            'upload_error'      => csc_t('Ошибка загрузки'),
            'network_error_2'   => csc_t('Ошибка сети. Проверьте подключение.'),
            'attach_min_file_q' => csc_t('Прикрепите хотя бы один файл'),
            'confirm_exec_q'    => csc_t('Подтвердить выполнение заказа'),
            'confirm_exec_note' => csc_t('? После этого статус изменится на \"Выполнено\".'),
            'confirm_fail'      => csc_t('Ошибка подтверждения'),
            'deactivate_word'   => csc_t('деактивировать'),
            'activate_word'     => csc_t('активировать'),
            'stop_new_orders'   => csc_t('\nВы перестанете получать новые заказы.'),
            'start_new_orders'  => csc_t('\nВы начнёте получать новые заказы.'),
            'status_active_full'=> csc_t('🟢 Активен - принимаете заказы'),
            'exit_point'        => csc_t('Точка выезда'),
            'session_expired'   => csc_t('Сессия истекла или токен недействителен. Обновите страницу и повторите попытку.'),
            'files_count_max'   => csc_t('файлов'),
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
