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
// ИСПРАВЛЕНО: дубли функций csc_generate_vapid_keys(), csc_base64url_encode(),
// csc_base64url_decode() и csc_der_to_raw_signature() удалены. Единые
// канонические реализации находятся в includes/csc-notifications.php
// (загружается из ядра раньше этого файла). Модуль push использует их как API.

// ============================================================
// 2. ИНИЦИАЛИЗАЦИЯ VAPID ПРИ АКТИВАЦИИ
// ============================================================

/**
 * Убеждается, что VAPID-ключи существуют.
 * Если нет — генерирует и сохраняет.
 */
if (!function_exists('csc_ensure_vapid_keys')) {
    function csc_ensure_vapid_keys() {
        $public = get_option('csc_vapid_public_key', '');
        $private = get_option('csc_vapid_private_key', '');
        
        if (!empty($public) && !empty($private)) {
            return ['public' => $public, 'private' => $private];
        }
        
        $keys = csc_generate_vapid_keys();
        if (!$keys) {
            return false;
        }
        
        update_option('csc_vapid_public_key', $keys['public'], false);
        update_option('csc_vapid_private_key', $keys['private'], false);
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
// 4. JWT-ПОДПИСЬ ДЛЯ VAPID
// ============================================================

/**
 * Создание JWT-токена для VAPID.
 */
if (!function_exists('csc_create_vapid_jwt')) {
    function csc_create_vapid_jwt($audience, $subject, $private_key_b64url) {
        $private_key_raw = csc_base64url_decode($private_key_b64url);
        
        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256',
        ];
        
        // Payload: aud (audience) + exp (expires, макс 24 часа) + sub (subject)
        $payload = [
            'aud' => $audience,
            'exp' => time() + 12 * 3600, // 12 часов
            'sub' => $subject,
        ];
        
        $header_b64  = csc_base64url_encode(json_encode($header));
        $payload_b64 = csc_base64url_encode(json_encode($payload));
        
        $unsigned = $header_b64 . '.' . $payload_b64;
        
        // Конвертируем raw private key в PEM формат для openssl
        $pem = csc_raw_ec_private_to_pem($private_key_raw);
        
        $pkey = openssl_pkey_get_private($pem);
        if (!$pkey) {
            return false;
        }
        
        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            return false;
        }
        
        // Конвертируем DER-подпись в R||S формат (raw, 64 байта)
        $raw_signature = csc_der_to_raw_signature($signature);
        if (!$raw_signature) {
            return false;
        }
        
        return $unsigned . '.' . csc_base64url_encode($raw_signature);
    }
}

/**
 * Конвертирует raw EC private key (32 байта) в PEM.
 */
if (!function_exists('csc_raw_ec_private_to_pem')) {
    function csc_raw_ec_private_to_pem($private_key_raw) {
        // OID для P-256: 1.2.840.10045.3.1.7
        // ASN.1 структура ECPrivateKey
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $priv_octet = "\x04\x20" . $private_key_raw; // 0x04 + length 32 + key
        
        $ec_priv = "\x30" . chr(strlen($priv_octet) + strlen($oid) + 11)
                 . "\x02\x01\x01" // version
                 . $priv_octet
                 . "\xa0" . chr(strlen($oid)) . $oid;
        
        $der = "\x30" . chr(strlen($ec_priv) + 2) . $ec_priv;
        
        // Оборачиваем в BIT STRING (SEQUENCE для PKCS8)
        $pkcs8_header = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $full = $pkcs8_header . "\x04" . chr(strlen($ec_priv)) . $ec_priv;
        
        // Простой способ: используем openssl для создания правильного PEM
        // Создаём EC key из приватного raw key через openssl_pkey_new
        $pkey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        
        // Заменяем приватную часть на нашу
        $details = openssl_pkey_get_details($pkey);
        
        // Используем более надёжный метод: создаём PEM вручную
        $asn1 = csc_build_ec_private_key_asn1($private_key_raw);
        
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($asn1), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
        
        return $pem;
    }
}

/**
 * Построение ASN.1 ECPrivateKey.
 */
if (!function_exists('csc_build_ec_private_key_asn1')) {
    function csc_build_ec_private_key_asn1($private_key_raw) {
        // OID secp256r1: 1.2.840.10045.3.1.7
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        
        // PrivateKey OCTET STRING (0x04 + 32 bytes)
        $priv_octet = "\x04\x20" . $private_key_raw;
        
        // ECPrivateKey SEQUENCE:
        //   version INTEGER 1
        //   privateKey OCTET STRING
        //   parameters OID (optional)
        $version = "\x02\x01\x01";
        $params  = "\xa0" . chr(strlen($oid)) . $oid;
        
        $inner = $version . $priv_octet . $params;
        return "\x30" . csc_asn1_length(strlen($inner)) . $inner;
    }
}

if (!function_exists('csc_asn1_length')) {
    function csc_asn1_length($len) {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = '';
        $temp = $len;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF) . $bytes;
            $temp >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}

// ИСПРАВЛЕНО: дубль csc_der_to_raw_signature() удалён — каноническая
// реализация находится в includes/csc-notifications.php.

// ============================================================
// 5. ОТПРАВКА PUSH-УВЕДОМЛЕНИЯ
// ============================================================

/**
 * Отправка Web Push уведомления.
 *
 * @param string $subscription_json JSON подписки (из PushManager.subscribe)
 * @param string $title            Заголовок
 * @param string $body             Тело сообщения
 * @param string $order_number     Номер заказа (для deep-link)
 * @param string $recipient_type   master|customer
 * @param string $recipient_id     ID получателя
 */
// ИСПРАВЛЕНО: дубль csc_send_web_push_notification() удалён. Каноническая
// реализация (шифрование RFC 8188 + трекинг статуса в БД уведомлений)
// находится в includes/csc-notifications.php и загружается из ядра раньше.
// Старая незащищённая версия (отправка открытого JSON без шифрования) более
// не используется — все вызовы этого модуля идут через единый движок.

/**
 * Удаление невалидной подписки.
 */
if (!function_exists('csc_remove_push_subscription')) {
    function csc_remove_push_subscription($recipient_type, $recipient_id, $endpoint) {
        if (empty($recipient_type) || empty($recipient_id)) return;
        
        $option_key = 'csc_push_subs_' . $recipient_type . '_' . $recipient_id;
        $subs = get_option($option_key, []);
        if (!is_array($subs)) return;
        
        $subs = array_values(array_filter($subs, function($s) use ($endpoint) {
            $decoded = is_array($s) ? $s : json_decode($s, true);
            return ($decoded['endpoint'] ?? '') !== $endpoint;
        }));
        
        update_option($option_key, $subs, false);
    }
}

// ============================================================
// 6. АВТОМАТИЧЕСКАЯ УСТАНОВКА ПРИ ЗАГРУЗКЕ АДМИНКИ
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
// 7. АДМИН-СТРАНИЦА НАСТРОЕК PUSH
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
// 8. AJAX: ТЕСТОВАЯ ОТПРАВКА PUSH
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