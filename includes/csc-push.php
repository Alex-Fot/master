<?php
/**
 * Файл: csc-push.php
 * Версия: 1.0
 * 
 * Web Push уведомления (VAPID).
 * Отправка push-сообщений в браузеры мастеров и клиентов.
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. ГЕНЕРАЦИЯ VAPID-КЛЮЧЕЙ
// ============================================================

/**
 * Генерация пары VAPID-ключей (ECDSA P-256).
 * Используется openssl для создания пары ключей.
 */
if (!function_exists('csc_generate_vapid_keys')) {
    function csc_generate_vapid_keys() {
        // Генерируем ECDSA ключ на кривой P-256 (prime256v1)
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        
        if (!$key) {
            return false;
        }
        
        $details = openssl_pkey_get_details($key);
        if (!$details || !isset($details['ec'])) {
            return false;
        }
        
        // Извлекаем координаты X и Y публичного ключа (по 32 байта каждая)
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        
        // Публичный ключ = 0x04 + X + Y (65 байт)
        $publicKeyRaw = "\x04" . $x . $y;
        
        // Приватный ключ = d (32 байта)
        $privateKeyRaw = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
        
        // Кодируем в base64url
        return [
            'public'  => csc_base64url_encode($publicKeyRaw),
            'private' => csc_base64url_encode($privateKeyRaw),
        ];
    }
}

/**
 * Base64url encode (без = и +/)
 */
if (!function_exists('csc_base64url_encode')) {
    function csc_base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * Base64url decode
 */
if (!function_exists('csc_base64url_decode')) {
    function csc_base64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

// ============================================================
// 2. ИНИЦИАЛИЗАЦИЯ VAPID ПРИ АКТИВАЦИИ
// ============================================================

/**
 * Убеждается, что VAPID-ключи существуют.
 * Если нет — генерирует и сохраняет.
 */
if (!function_exists('csc_ensure_vapid_keys')) {
    function csc_ensure_vapid_keys() {
        $public  = get_option('csc_vapid_public_key', '');
        $private = get_option('csc_vapid_private_key', '');

        if (!empty($public) && !empty($private)) {
            // Одноразовая миграция: приватный ключ лежал в БД открытым текстом.
            if (function_exists('csc_is_encrypted') && !csc_is_encrypted($private)
                && function_exists('csc_encrypt_token') && strpos($private, '.') === false) {
                update_option('csc_vapid_private_key', csc_encrypt_token($private), false);
            }
            return ['public' => $public, 'private' => $private];
        }

        $keys = csc_generate_vapid_keys();
        if (!$keys) {
            return false;
        }

        update_option('csc_vapid_public_key', $keys['public'], false);
        // Приватный ключ храним только в зашифрованном виде (AES-256-GCM, csc-crypto.php).
        update_option('csc_vapid_private_key', function_exists('csc_encrypt_token') ? csc_encrypt_token($keys['private']) : $keys['private'], false);
        update_option('csc_vapid_subject', 'mailto:' . get_option('admin_email'), false);

        return $keys;
    }
}

// ============================================================
// 3. КОПИРОВАНИЕ SERVICE WORKER В КОРЕНЬ САЙТА
// ============================================================

/**
 * Копирует csc-sw.js в корень WordPress (wp-content/../..).
 * Это нужно, чтобы scope Service Worker был "/" (весь сайт).
 */
if (!function_exists('csc_install_sw_to_root')) {
    function csc_install_sw_to_root() {
        $source = WP_PLUGIN_DIR . '/custom-service-calculator/assets/csc-sw.js';
        $target = ABSPATH . 'csc-sw.js';
        
        if (!file_exists($source)) {
            return false;
        }
        
        // Копируем только если файл не существует или отличается
        if (!file_exists($target) || md5_file($source) !== md5_file($target)) {
            @copy($source, $target);
            return true;
        }
        return false;
    }
}

// ============================================================
// 4. ОТПРАВКА PUSH-УВЕДОМЛЕНИЯ
// ============================================================

// Рабочая реализация csc_send_web_push_notification() находится в
// includes/csc-notifications.php (загружается раньше этого файла):
// полноценное шифрование payload по RFC 8291 (aes128gcm), корректные
// заголовки Crypto-Key/Authorization (VAPID RFC 8292), лог доставки
// и очистка невалидных подписок (HTTP 404/410).
// Старый дубль без шифрования (открытый payload с невалидными
// заголовками aes128gcm/Crypto-Key) и связанный с ним мёртвый
// JWT-код (csc_create_vapid_jwt, csc_raw_ec_private_to_pem,
// csc_build_ec_private_key_asn1, csc_asn1_length,
// csc_der_to_raw_signature, csc_remove_push_subscription) удалены.

// ============================================================
// 5. АВТОМАТИЧЕСКАЯ УСТАНОВКА ПРИ ЗАГРУЗКЕ АДМИНКИ
// ============================================================

add_action('admin_init', 'csc_push_auto_setup', 20);
if (!function_exists('csc_push_auto_setup')) {
    function csc_push_auto_setup() {
        // Генерируем VAPID если их нет
        csc_ensure_vapid_keys();
        // Копируем SW в корень
        csc_install_sw_to_root();
    }
}

// При активации плагина (register_activation_hook)
add_action('csc_plugin_activate', 'csc_push_on_activate');
if (!function_exists('csc_push_on_activate')) {
    function csc_push_on_activate() {
        csc_ensure_vapid_keys();
        csc_install_sw_to_root();
    }
}

// При деактивации — удаляем SW из корня
add_action('csc_plugin_deactivate', 'csc_push_on_deactivate');
if (!function_exists('csc_push_on_deactivate')) {
    function csc_push_on_deactivate() {
        $target = ABSPATH . 'csc-sw.js';
        if (file_exists($target)) {
            @unlink($target);
        }
    }
}

// ============================================================
// 6. АДМИН-СТРАНИЦА НАСТРОЕК PUSH
// ============================================================

add_action('admin_menu', 'csc_push_add_admin_menu', 40);
if (!function_exists('csc_push_add_admin_menu')) {
    function csc_push_add_admin_menu() {
        add_submenu_page(
            'options-general.php',
            'Push-уведомления (CSC)',
            '🔔 Push (CSC)',
            'manage_options',
            'csc_push_settings',
            'csc_render_push_settings'
        );
    }
}

if (!function_exists('csc_render_push_settings')) {
    function csc_render_push_settings() {
        // Обработка сохранения
        if (isset($_POST['csc_push_save']) && check_admin_referer('csc_push_settings_action')) {
            $subject = sanitize_text_field($_POST['csc_vapid_subject'] ?? '');
            update_option('csc_vapid_subject', $subject ?: 'mailto:' . get_option('admin_email'), false);
            echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>';
        }
        
        // Регенерация ключей
        if (isset($_POST['csc_regenerate_keys']) && check_admin_referer('csc_push_settings_action')) {
            delete_option('csc_vapid_public_key');
            delete_option('csc_vapid_private_key');
            csc_ensure_vapid_keys();
            echo '<div class="notice notice-success is-dismissible"><p>VAPID-ключи перегенерированы. Обновите подписки у пользователей.</p></div>';
        }
        
        $public  = get_option('csc_vapid_public_key', '');
        $subject = get_option('csc_vapid_subject', '');
        
        // Подсчёт подписок
        global $wpdb;
        $subs_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'csc_push_subs_%'");
        
        // Проверка Service Worker
        $sw_installed = file_exists(ABSPATH . 'csc-sw.js');
        $sw_source = file_exists(WP_PLUGIN_DIR . '/custom-service-calculator/assets/csc-sw.js');
        
        ?>
        <div class="wrap">
            <h1>🔔 Push-уведомления (Web Push)</h1>
            
            <div class="card" style="max-width:800px; padding:20px; margin-top:20px;">
                <h2>Статус</h2>
                <table class="form-table">
                    <tr>
                        <th>VAPID публичный ключ</th>
                        <td>
                            <?php if ($public): ?>
                                <span style="color:#065f46;">✅ Сгенерирован</span>
                                <code style="display:block; margin-top:8px; word-break:break-all; padding:8px; background:#f1f5f9; border-radius:4px; font-size:11px;"><?php echo esc_html($public); ?></code>
                            <?php else: ?>
                                <span style="color:#991b1b;">❌ Не сгенерирован</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Service Worker</th>
                        <td>
                            <?php if ($sw_installed): ?>
                                <span style="color:#065f46;">✅ Установлен в корне сайта</span>
                                <code style="display:block; margin-top:4px; font-size:11px;"><?php echo esc_html(home_url('/csc-sw.js')); ?></code>
                            <?php elseif ($sw_source): ?>
                                <span style="color:#d97706;">⚠️ Файл есть, но не скопирован в корень</span>
                            <?php else: ?>
                                <span style="color:#991b1b;">❌ Файл csc-sw.js не найден</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Активных подписок</th>
                        <td><strong><?php echo $subs_count; ?></strong> пользователей подписаны на push</td>
                    </tr>
                </table>
            </div>
            
            <div class="card" style="max-width:800px; padding:20px; margin-top:20px;">
                <h2>Настройки</h2>
                <form method="post">
                    <?php wp_nonce_field('csc_push_settings_action'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="csc_vapid_subject">Subject (mailto)</label></th>
                            <td>
                                <input type="email" id="csc_vapid_subject" name="csc_vapid_subject" 
                                       value="<?php echo esc_attr($subject ?: get_option('admin_email')); ?>" 
                                       class="regular-text" placeholder="admin@yoursite.com">
                                <p class="description">Контактный email для идентификации отправителя push-уведомлений.</p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="csc_push_save" class="button button-primary">💾 Сохранить</button>
                        <button type="submit" name="csc_regenerate_keys" class="button" 
                                onclick="return confirm('Перегенерировать VAPID-ключи? Все существующие подписки у пользователей станут невалидными и будут пересозданы при следующем входе.');">
                            🔄 Перегенерировать ключи
                        </button>
                    </p>
                </form>
            </div>
            
            <div class="card" style="max-width:800px; padding:20px; margin-top:20px; border-left:4px solid #f59e0b;">
                <h2>⚙️ Как это работает</h2>
                <ol>
                    <li>Когда мастер или клиент открывает дашборд/кабинет — браузер запрашивает разрешение на уведомления</li>
                    <li>После разрешения создаётся push-подписка и сохраняется на сервере</li>
                    <li>При создании заявки, смене статуса и т.д. сервер отправляет push через Web Push API</li>
                    <li>Уведомление приходит даже если сайт закрыт, но браузер открыт</li>
                </ol>
                <p><strong>Важно:</strong> Push работает только по HTTPS (кроме localhost).</p>
            </div>
            
            <div class="card" style="max-width:800px; padding:20px; margin-top:20px;">
                <h2>🧪 Тест отправки Push</h2>
                <p>Отправить тестовое уведомление всем подписанным мастерам:</p>
                <button type="button" class="button" id="csc-push-test-btn">📡 Отправить тест</button>
                <div id="csc-push-test-result" style="margin-top:12px; padding:10px; background:#f8fafc; border-radius:6px; display:none;"></div>
            </div>
        </div>
        
        <script>
        jQuery(function($){
            $('#csc-push-test-btn').on('click', function(){
                const btn = $(this);
                btn.prop('disabled', true).text('Отправка...');
                $.post(ajaxurl, {
                    action: 'csc_push_test_send',
                    _wpnonce: '<?php echo wp_create_nonce("csc_admin_nonce"); ?>'
                }, function(res){
                    const result = $('#csc-push-test-result');
                    result.show();
                    if (res.success) {
                        result.html('<span style="color:#065f46;">✅ ' + res.data.message + '</span>');
                    } else {
                        result.html('<span style="color:#991b1b;">❌ ' + (res.data.message || 'Ошибка') + '</span>');
                    }
                    btn.prop('disabled', false).text('📡 Отправить тест');
                });
            });
        });
        </script>
        <?php
    }
}

// ============================================================
// 7. AJAX: ТЕСТОВАЯ ОТПРАВКА PUSH
// ============================================================

add_action('wp_ajax_csc_push_test_send', 'csc_push_test_send_ajax');
if (!function_exists('csc_push_test_send_ajax')) {
    function csc_push_test_send_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещён']);
        }
        if (!check_ajax_referer('csc_admin_nonce', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }
        
        global $wpdb;
        $subs_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'csc_push_subs_master_%'");
        
        $sent = 0;
        $failed = 0;
        
        foreach ($subs_options as $row) {
            $subs = maybe_unserialize($row->option_value);
            if (!is_array($subs)) {
                $subs = json_decode($row->option_value, true) ?: [];
            }
            if (!is_array($subs)) continue;
            
            foreach ($subs as $sub) {
                $ok = csc_send_web_push_notification(
                    $sub,
                    '🔔 Тестовое уведомление',
                    'Push работает корректно! Это тестовое сообщение из админ-панели.',
                    'TEST-' . date('His'),
                    'master',
                    ''
                );
                $ok ? $sent++ : $failed++;
            }
        }
        
        wp_send_json_success([
            'message' => "Отправлено: {$sent}, ошибок: {$failed}",
        ]);
    }
}