<?php
/**
 * Централизованный логгер плагина CSC.
 *
 * Заменяет разрозненные вызовы error_log() на единую точку записи:
 * - уровень отладки включается константой CSC_DEBUG (wp-config.php)
 *   или опцией csc_debug_logging (страница «Настройки» админки);
 * - debug-сообщения пишутся ТОЛЬКО при включённом флаге;
 * - warning/error пишутся всегда, но не чаще одного раза в минуту на
 *   уникальное сообщение (анти-спам при падающем кроне/webhook);
 * - запись идёт в /uploads/csc-logs/csc.log с ротацией по размеру.
 *
 * Совместимость: функции существуют даже до подключения модуля —
 * все точки вызова используют if (function_exists('csc_log')).
 */

if (!defined('ABSPATH')) exit;

// Флаг отладки: define('CSC_DEBUG', true) в wp-config.php ИЛИ опция csc_debug_logging = 1.
if (!defined('CSC_DEBUG')) {
    define('CSC_DEBUG', false);
}

if (!function_exists('csc_debug_enabled')) {
    /**
     * Активен ли режим отладки: константа CSC_DEBUG ИЛИ опция администратора.
     * Опция: csc_main_settings['debug_logging'] (чекбокс «Журналирование» в админке),
     * совместимость со старым флагом routing_log_enabled; плюс standalone-опция
     * csc_debug_logging для ручного включения через wp_options.
     * @return bool
     */
    function csc_debug_enabled() {
        if (CSC_DEBUG === true) {
            return true;
        }
        // Настройки читаются один раз за запрос (через кэшированный хелпер ядра).
        static $enabled = null;
        if ($enabled === null) {
            $enabled = false;
            if (function_exists('csc_get_settings_cached')) {
                $s = csc_get_settings_cached();
                if (!empty($s['debug_logging']) || !empty($s['routing_log_enabled'])) {
                    $enabled = true;
                }
            }
            if (!$enabled && get_option('csc_debug_logging', false)) {
                $enabled = true;
            }
        }
        return $enabled;
    }
}

if (!function_exists('csc_get_log_dir')) {
    /**
     * Каталог логов: /uploads/csc-logs/ (+ .htaccess для запрета прямого доступа).
     * @return string|false Абсолютный путь или false при неудаче.
     */
    function csc_get_log_dir() {
        static $dir = null;
        if ($dir !== null) {
            return $dir ?: false;
        }
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            $dir = false;
            return false;
        }
        $candidate = trailingslashit($uploads['basedir']) . 'csc-logs';
        if (!is_dir($candidate)) {
            if (!wp_mkdir_p($candidate)) {
                $dir = false;
                return false;
            }
        }
        // Защита от прямого HTTP-доступа к логам.
        $htaccess = $candidate . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $index = $candidate . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden.\n");
        }
        $dir = $candidate;
        return $dir;
    }
}

if (!function_exists('csc_log')) {
    /**
     * Единая точка записи логов плагина.
     *
     * @param string $message Текст сообщения.
     * @param string $level   Один из: debug|info|notice|warning|error.
     * @param string $context Краткий тег подсистемы (GEOCODE, BACKUP, ...).
     * @return bool Удалось ли записать.
     */
    function csc_log($message, $level = 'info', $context = '') {
        $levels = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4];
        $level  = isset($levels[$level]) ? $level : 'info';

        // DEBUG-сообщения — только при включённом флаге отладки.
        if ($level === 'debug' && !csc_debug_enabled()) {
            return false;
        }

        // Анти-спам: warning/error с одинаковым текстом пишем не чаще 1 раза в минуту.
        if (($level === 'warning' || $level === 'error') && did_action('init')) {
            $key = 'csc_log_throttle_' . md5($level . '|' . $message);
            if (get_transient($key)) {
                return false;
            }
            set_transient($key, 1, MINUTE_IN_SECONDS);
        }

        $dir = csc_get_log_dir();
        if (!$dir) {
            // Фолбэк: если логи писать некуда — не молчим о критичном.
            if ($level === 'error' || $level === 'warning') {
                error_log('[CSC] ' . strtoupper($level) . ($context ? " [$context]" : '') . ': ' . $message);
            }
            return false;
        }

        $file = $dir . '/csc.log';

        // Ротация: при превышении 2 МБ переносим в csc-1.log (один резерв).
        if (file_exists($file) && @filesize($file) > 2 * 1024 * 1024) {
            @rename($file, $dir . '/csc-1.log');
        }

        $line = sprintf(
            "[%s] %s%s: %s\n",
            current_time('Y-m-d H:i:s'),
            strtoupper($level),
            $context !== '' ? " [$context]" : '',
            $message
        );

        return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('csc_log_debug')) {
    /** Ярлык: debug-уровень. */
    function csc_log_debug($message, $context = '') {
        return csc_log($message, 'debug', $context);
    }
}

if (!function_exists('csc_log_error')) {
    /** Ярлык: error-уровень. */
    function csc_log_error($message, $context = '') {
        return csc_log($message, 'error', $context);
    }
}

if (!function_exists('csc_log_warning')) {
    /** Ярлык: warning-уровень. */
    function csc_log_warning($message, $context = '') {
        return csc_log($message, 'warning', $context);
    }
}

if (!function_exists('csc_get_log_file')) {
    /** Путь к файлу лога (для админской страницы просмотра/скачивания). */
    function csc_get_log_file() {
        $dir = csc_get_log_dir();
        return $dir ? $dir . '/csc.log' : '';
    }
}

if (!function_exists('csc_clear_logs')) {
    /** Очистка логов (из админки). */
    function csc_clear_logs() {
        $dir = csc_get_log_dir();
        if (!$dir) {
            return false;
        }
        @unlink($dir . '/csc.log');
        @unlink($dir . '/csc-1.log');
        return true;
    }
}

// ============================================================
// НАСТРОЙКА ОТЛАДКИ В АДМИНКЕ (опция csc_debug_logging)
// ============================================================
if (!function_exists('csc_register_debug_setting')) {
    function csc_register_debug_setting() {
        register_setting('csc_settings_group', 'csc_debug_logging', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);
    }
    add_action('admin_init', 'csc_register_debug_setting');
}

if (!function_exists('csc_render_debug_setting_field')) {
    /**
     * Поле чекбокса «Журнал отладки». Вызывается из шаблона настроек
     * (do_settings_sections / add_settings_field подключаются в csc-admin-pages.php
     * при наличии хука; здесь — безопасная standalone-функция рендера).
     */
    function csc_render_debug_setting_field() {
        printf(
            '<label><input type="checkbox" name="csc_debug_logging" value="1"%s /> %s</label>' .
            '<p class="description">%s</p>',
            checked(csc_debug_enabled(), true, false),
            esc_html__('Расширенное журналирование (отладка)', 'csc'),
            esc_html__('Пишет debug-события в файл /wp-content/uploads/csc-logs/csc.log. Отключите в рабочем режиме.', 'csc')
        );
    }
    add_action('csc_settings_fields', 'csc_render_debug_setting_field');
}
