<?php
if (!defined('ABSPATH')) exit;

/**
 * Модуль централизованных уведомлений и логирования (CSC Notifications Engine)
 * Каналы: Telegram, Web Push, Internal DB
 * Статусы: pending -> sent -> delivered -> read / failed
 *
 * ИСПРАВЛЕНО: убраны все дублирующиеся AJAX-хуки.
 * AJAX-обработчики (push_subscribe, mark_read, get_notifications, clear_subs)
 * теперь определены ТОЛЬКО в csc-ajax-handlers.php.
 *
 * Версия: 3.0
 */

// ============================================================
// 1. ИНИЦИАЛИЗАЦИЯ БАЗЫ ДАННЫХ
// ============================================================
add_action('admin_init', 'csc_init_notification_db');

if (!function_exists('csc_init_notification_db')) {
    function csc_init_notification_db() {
        global $wpdb;
        
        $db_version = '1.2';
        $table_name = $wpdb->prefix . 'csc_notifications';
        $charset_collate = $wpdb->get_charset_collate();
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (get_option('csc_notifications_db_version') !== $db_version || !$table_exists) {
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number VARCHAR(50) NOT NULL DEFAULT '',
                recipient_type VARCHAR(20) NOT NULL DEFAULT '',
                recipient_id VARCHAR(100) NOT NULL DEFAULT '',
                channel VARCHAR(20) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL DEFAULT '',
                message TEXT NOT NULL,
                payload TEXT NOT NULL,
                status ENUM('pending','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
                external_id VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL DEFAULT NULL,
                delivered_at DATETIME NULL DEFAULT NULL,
                read_at DATETIME NULL DEFAULT NULL,
                meta TEXT NULL,
                PRIMARY KEY  (id),
                INDEX idx_order (order_number),
                INDEX idx_recipient (recipient_type, recipient_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) $charset_collate;";

            if (!function_exists('dbDelta')) {
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            }
            dbDelta($sql);
            update_option('csc_notifications_db_version', $db_version, false);
        }

        // Генерация ВAPID-ключей при первом запуске
        if (empty(get_option('csc_vapid_public_key')) || empty(get_option('csc_vapid_private_key'))) {
            $keys = csc_generate_vapid_keys();
            if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
                update_option('csc_vapid_public_key', $keys['publicKey'], false);
                update_option('csc_vapid_private_key', $keys['privateKey'], false);
                update_option('csc_vapid_subject', 'mailto:' . get_option('admin_email'), false);
            }
        }
    }
}

// ============================================================
// 2. ГЕНЕРАЦИЯ ВAPIД КЛЮЧЕЙ (Web Push)
// ============================================================
if (!function_exists('csc_generate_vapid_keys')) {
    function csc_generate_vapid_keys() {
        if (!function_exists('openssl_pkey_new')) {
            return ['publicKey' => '', 'privateKey' => ''];
        }

        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC
        ];

        $res = openssl_pkey_new($config);
        if (!$res) {
            return ['publicKey' => '', 'privateKey' => ''];
        }

        $privateKey = '';
        if (!openssl_pkey_export($res, $privateKey)) {
            return ['publicKey' => '', 'privateKey' => ''];
        }

        $details = openssl_pkey_get_details($res);
        if (!$details || empty($details['key'])) {
            return ['publicKey' => '', 'privateKey' => ''];
        }
        $publicKey = $details['key'];

        $pubRaw = csc_extract_ec_public_key($publicKey);
        $privRaw = csc_extract_ec_private_key($privateKey);

        if (empty($pubRaw) || empty($privRaw)) {
            return ['publicKey' => '', 'privateKey' => ''];
        }

        return [
            'publicKey' => base64_encode($pubRaw),
            'privateKey' => base64_encode($privRaw)
        ];
    }
}

if (!function_exists('csc_extract_ec_public_key')) {
    function csc_extract_ec_public_key($pem) {
        $pem_clean = preg_replace('/^-+.*-+$/m', '', $pem);
        $pem_clean = str_replace(["\r", "\n"], '', $pem_clean);
        $der = base64_decode($pem_clean);
        if ($der === false || strlen($der) < 91) return '';
        return substr($der, -65);
    }
}

if (!function_exists('csc_extract_ec_private_key')) {
    function csc_extract_ec_private_key($pem) {
        $pem_clean = preg_replace('/^-+.*-+$/m', '', $pem);
        $pem_clean = str_replace(["\r", "\n"], '', $pem_clean);
        $der = base64_decode($pem_clean);
        if ($der === false || strlen($der) < 36) return '';
        
        $pos = 0;
        $len = strlen($der);
        while ($pos < $len - 33) {
            if (ord($der[$pos]) === 0x04 && ord($der[$pos + 1]) === 0x20) {
                return substr($der, $pos + 2, 32);
            }
            $pos++;
        }
        return '';
    }
}

// ============================================================
// 3. ЯДРО ЛОГИРОВАНИЯ И СТАТУСОВ
// ============================================================
if (!function_exists('csc_log_notification')) {
    function csc_log_notification($order_number, $recipient_type, $recipient_id, $channel, $title, $message, $payload = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_notifications';

        $payload_encoded = '';
        if (is_array($payload)) {
            $payload_encoded = wp_json_encode($payload);
        } elseif (is_string($payload)) {
            $payload_encoded = $payload;
        } else {
            $payload_encoded = wp_json_encode([]);
        }

        $data = [
            'order_number'   => sanitize_text_field($order_number),
            'recipient_type' => sanitize_key($recipient_type),
            'recipient_id'   => sanitize_text_field($recipient_id),
            'channel'        => sanitize_key($channel),
            'title'          => sanitize_text_field($title),
            'message'        => wp_kses_post($message),
            'payload'        => $payload_encoded,
            'status'         => 'pending',
            'created_at'     => current_time('mysql')
        ];

        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            csc_log('Ошибка вставки уведомления: ' . $wpdb->last_error);
            return 0;
        }
        return $wpdb->insert_id;
    }
}

if (!function_exists('csc_update_notification_status')) {
    function csc_update_notification_status($log_id, $new_status, $external_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_notifications';
        $valid_statuses = ['sent', 'delivered', 'read', 'failed'];
        
        if (!in_array($new_status, $valid_statuses)) return false;
        if (empty($log_id) || !is_numeric($log_id)) return false;

        $data = ['status' => $new_status];
        $where = ['id' => (int)$log_id];

        if ($new_status === 'sent') $data['sent_at'] = current_time('mysql');
        elseif ($new_status === 'delivered') $data['delivered_at'] = current_time('mysql');
        elseif ($new_status === 'read') $data['read_at'] = current_time('mysql');

        if ($external_id !== null && $external_id !== '') {
            $data['external_id'] = sanitize_text_field((string)$external_id);
        }

        $wpdb->update($table, $data, $where);
        return true;
    }
}

if (!function_exists('csc_mark_notifications_read')) {
    function csc_mark_notifications_read($recipient_type, $recipient_id, $order_number = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_notifications';

        $where = [
            'recipient_type' => sanitize_key($recipient_type),
            'recipient_id'   => sanitize_text_field($recipient_id),
            'status'         => 'delivered'
        ];
        if ($order_number) {
            $where['order_number'] = sanitize_text_field($order_number);
        }

        return $wpdb->update(
            $table,
            ['status' => 'read', 'read_at' => current_time('mysql')],
            $where
        );
    }
}

if (!function_exists('csc_get_notifications_by_order')) {
    function csc_get_notifications_by_order($order_number, $limit = 500) {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_notifications';
        $limit = max(1, min((int)$limit, 2000));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE order_number = %s ORDER BY created_at DESC LIMIT %d",
            $order_number,
            $limit
        ), ARRAY_A);
    }
}

// ============================================================
// 4. ОТПРАВКА ЧЕРЕЗ TELEGRAM С ТРЕКИНГОМ
// ============================================================
if (!function_exists('csc_send_tg_notification')) {
    function csc_send_tg_notification($chat_id, $title, $message, $order_number, $recipient_type, $recipient_id, $keyboard = null, $parse_mode = 'HTML') {
        $s = get_option('csc_main_settings', []);
// ИСПРАВЛЕНО: расшифровка токена через csc_get_telegram_token()
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        if (!$tg_token || !$chat_id) {
            $log_id = csc_log_notification($order_number, $recipient_type, $recipient_id, 'telegram', $title, $message, []);
            csc_update_notification_status($log_id, 'failed');
            return false;
        }

        $api_url = "https://api.telegram.org/bot{$tg_token}/sendMessage";
        $body = [
            'chat_id'    => $chat_id,
            'text'       => $title . "\n" . $message,
            'parse_mode' => $parse_mode
        ];
        if ($keyboard) {
            $body['reply_markup'] = wp_json_encode($keyboard);
        }

        $log_id = csc_log_notification($order_number, $recipient_type, $recipient_id, 'telegram', $title, $message, $body);
        csc_update_notification_status($log_id, 'sent');

        $response = wp_remote_post($api_url, [
            'body'    => wp_json_encode($body),
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            csc_update_notification_status($log_id, 'failed', $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $resp = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && ($resp['ok'] ?? false)) {
            $msg_id = $resp['result']['message_id'] ?? null;
            csc_update_notification_status($log_id, 'delivered', $msg_id);
            return $msg_id;
        } else {
            $error_desc = $resp['description'] ?? 'Unknown TG error';
            csc_update_notification_status($log_id, 'failed', wp_json_encode($error_desc));
            return false;
        }
    }
}

// ============================================================
// 5. ОТПРАВКА ЧЕРЕЗ WEB PUSH С ТРЕКИНГОМ (VAPID + RFC 8188)
// ============================================================

if (!function_exists('csc_base64url_encode')) {
    function csc_base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('csc_base64url_decode')) {
    function csc_base64url_decode($data) {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
        return base64_decode(strtr($padded, '-_', '+/'));
    }
}

if (!function_exists('csc_vapid_generate_jwt')) {
    function csc_vapid_generate_jwt($endpoint, $subject, $priv_pem) {
        $parsed = parse_url($endpoint);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $audience = $parsed['scheme'] . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $audience .= ':' . $parsed['port'];
        }

        $header  = ['typ' => 'JWT', 'alg' => 'ES256'];
        $payload = [
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $subject
        ];

        $header_b64  = csc_base64url_encode(json_encode($header));
        $payload_b64 = csc_base64url_encode(json_encode($payload));
        $sign_input  = $header_b64 . '.' . $payload_b64;

        $priv_key = openssl_pkey_get_private($priv_pem);
        if (!$priv_key) return false;

        $signature = '';
        if (!openssl_sign($sign_input, $signature, $priv_key, OPENSSL_ALGO_SHA256)) {
            return false;
        }

        $raw_sig = csc_der_to_raw_signature($signature, 32);
        return $sign_input . '.' . csc_base64url_encode($raw_sig);
    }
}

if (!function_exists('csc_der_to_raw_signature')) {
    // ИСПРАВЛЕНО: единая каноническая реализация (используется и модулем push).
    // $part_len теперь опционален (по умолчанию 32 — стандарт для P-256).
    function csc_der_to_raw_signature($der, $part_len = 32) {
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) return $der;
        $pos++; 

        $r = csc_read_der_integer($der, $pos);
        $s = csc_read_der_integer($der, $pos);

        $r = str_pad(ltrim($r, "\x00"), $part_len, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), $part_len, "\x00", STR_PAD_LEFT);

        if (strlen($r) > $part_len) $r = substr($r, -$part_len);
        if (strlen($s) > $part_len) $s = substr($s, -$part_len);

        return $r . $s;
    }
}

if (!function_exists('csc_read_der_integer')) {
    function csc_read_der_integer($der, &$pos) {
        if (ord($der[$pos++]) !== 0x02) return '';
        $len = ord($der[$pos++]);
        $val = substr($der, $pos, $len);
        $pos += $len;
        return $val;
    }
}

if (!function_exists('csc_vapid_pem_private')) {
    function csc_vapid_pem_private() {
        $priv_b64 = get_option('csc_vapid_private_key');
        if (!$priv_b64) return '';

        $raw = base64_decode($priv_b64);
        if (!$raw || strlen($raw) !== 32) return '';

        $asn1_prefix = "\x30\x77\x02\x01\x01\x04\x20";
        $oid_prime256v1 = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $sec1_no_pub = $asn1_prefix . $raw . $oid_prime256v1;
        $pem_no_pub = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($sec1_no_pub), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";

        $key_res = openssl_pkey_get_private($pem_no_pub);
        if ($key_res) {
            $details = openssl_pkey_get_details($key_res);
            if ($details && !empty($details['key'])) {
                $exported = '';
                if (openssl_pkey_export($key_res, $exported)) {
                    return $exported;
                }
            }
        }

        return $pem_no_pub;
    }
}

if (!function_exists('csc_pem_to_raw_public_key_from_private')) {
    function csc_pem_to_raw_public_key_from_private($priv_pem) {
        $key = openssl_pkey_get_private($priv_pem);
        if (!$key) return '';

        $details = openssl_pkey_get_details($key);
        if (!$details || empty($details['key'])) return '';

        $pem = $details['key'];
        $b64 = preg_replace('/^-+.*-+$/m', '', $pem);
        $b64 = str_replace(["\r", "\n"], '', $b64);
        $der = base64_decode($b64);
        if ($der === false || strlen($der) < 65) return '';

        return substr($der, -65);
    }
}

if (!function_exists('csc_raw_public_key_to_pem')) {
    function csc_raw_public_key_to_pem($raw_key) {
        $asn1_prefix = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
        $der = $asn1_prefix . $raw_key;
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}

if (!function_exists('csc_pem_to_raw_public_key')) {
    function csc_pem_to_raw_public_key($pem) {
        $b64 = preg_replace('/^-+.*-+$/m', '', $pem);
        $b64 = str_replace(["\r", "\n"], '', $b64);
        $der = base64_decode($b64);
        if ($der === false || strlen($der) < 65) return '';
        return substr($der, -65);
    }
}

if (!function_exists('csc_webpush_encrypt')) {
    function csc_webpush_encrypt($plaintext, $user_public_key_b64, $user_auth_b64, $server_priv_pem) {
        // Проверка доступности функции (PHP 7.4+)
        if (!function_exists('openssl_pkey_derive')) {
            return false;
        }

        $user_public_key = csc_base64url_decode($user_public_key_b64);
        $user_auth       = csc_base64url_decode($user_auth_b64);

        if (strlen($user_public_key) !== 65 || ord($user_public_key[0]) !== 0x04) {
            return false;
        }

        $server_priv = openssl_pkey_get_private($server_priv_pem);
        if (!$server_priv) return false;

        $server_pub_pem = csc_raw_public_key_to_pem($user_public_key);
        $peer_key = openssl_pkey_get_public($server_pub_pem);
        if (!$peer_key) return false;

        $shared_secret = '';
        if (!openssl_pkey_derive($peer_key, $server_priv, $shared_secret, 32)) {
            return false;
        }

        $salt = random_bytes(16);

        $server_details = openssl_pkey_get_details($server_priv);
        if (!$server_details || empty($server_details['key'])) return false;
        $server_pub_raw = csc_pem_to_raw_public_key($server_details['key']);

        $auth_info = "WebPush-AESGCM\x00" . $server_pub_raw;
        $auth_secret = hash_hkdf('sha256', $shared_secret, 32, $auth_info, $user_auth);

        $cek = hash_hkdf('sha256', $auth_secret, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $auth_secret, 12, "Content-Encoding: nonce\x00", $salt);

        $padded = $plaintext . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $nonce,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) return false;

        $record_size = strlen($padded) + 16;
        $header_block = $salt
            . pack('N', $record_size)
            . chr(strlen($server_pub_raw))
            . $server_pub_raw;

        $encrypted_body = $header_block . $ciphertext . $tag;

        return [
            'body' => $encrypted_body,
            'headers' => [
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL'              => '86400',
                'Urgency'          => 'high'
            ]
        ];
    }
}

if (!function_exists('csc_send_web_push_notification')) {
    function csc_send_web_push_notification($subscription_json, $title, $message, $order_number, $recipient_type, $recipient_id) {
        
        // 0. Создаем запись в логе СРАЗУ (статус pending), чтобы было что обновлять
        $log_id = csc_log_notification($order_number, $recipient_type, $recipient_id, 'web_push', $title, $message);
        if (!$log_id) return false;

        // 1. Проверка VAPID ключей
        $private_key_pem = function_exists('csc_vapid_pem_private') ? csc_vapid_pem_private() : get_option('csc_vapid_private_key');
        $subject         = get_option('csc_vapid_subject');

        if (empty($private_key_pem) || empty($subject)) {
            csc_update_notification_status($log_id, 'failed', 'VAPID keys not configured');
            return false;
        }

        // 2. Парсинг подписки
        $sub = is_array($subscription_json) ? $subscription_json : json_decode(stripslashes($subscription_json), true);
        
        if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            csc_update_notification_status($log_id, 'failed', 'Invalid subscription data');
            return false;
        }

        // 3. Проверка поддержки шифрования (PHP 7.4+)
        if (!function_exists('openssl_pkey_derive')) {
            csc_update_notification_status($log_id, 'failed', 'openssl_pkey_derive not available');
            return false;
        }

        // ИСПРАВЛЕНО (кликабельные push): URL перехода. Если вызывающий код
        // передал ссылку через фильтр — используем её; иначе строим стандартную:
        // мастеру — вход в кабинет с одноразовым креденшелом + open_order,
        // клиенту — страница заявки в ЛК по номеру.
        $click_url = '';
        if (!empty($order_number)) {
            if ($recipient_type === 'master' && function_exists('csc_issue_master_login_token')) {
                $login_token = csc_issue_master_login_token($recipient_id);
                if ($login_token !== '') {
                    $click_url = add_query_arg([
                        'csc_master_dashboard' => '1',
                        'mid'                  => $recipient_id,
                        'token'                => $login_token,
                        'open_order'           => $order_number,
                    ], home_url('/'));
                }
            } elseif ($recipient_type === 'customer' || $recipient_type === 'admin') {
                // Админ просматривает заявки в админке; клиент — в ЛК по параметру.
                if ($recipient_type === 'admin' && current_user_can('manage_options')) {
                    $click_url = add_query_arg(['page' => 'csc_orders_admin', 'csc_open_order' => $order_number], admin_url('options-general.php'));
                } else {
                    $click_url = add_query_arg(['csc_open_order' => $order_number], home_url('/'));
                }
            }
        }
        $click_url = apply_filters('csc_push_notification_url', $click_url, $order_number, $recipient_type, $recipient_id);

        // 4. Формирование payload
        $csc_plugin_url = defined('CSC_PLUGIN_URL') ? CSC_PLUGIN_URL : WP_PLUGIN_URL . '/custom-service-calculator/';
        $payload_data = [
            'title'        => $title,
            'body'         => $message,
            'message'      => $message,
            'order_number' => $order_number,
            'user_type'    => $recipient_type,
            'open_order'   => $order_number,
            // ИСПРАВЛЕНО (Push-иконки): фолбэки — полные URL к файлам плагина
            // (раньше '/icon-192.png' указывал в корень сайта, где файлов нет → 404).
            'icon'         => get_site_icon_url(192, $csc_plugin_url . 'assets/icon-192.png'),
            'badge'        => get_site_icon_url(72, $csc_plugin_url . 'assets/badge-72.png'),
            'tag'          => $order_number,
            'url'          => $click_url,
            'data'         => ['order' => $order_number, 'url' => $click_url]
        ];
        $payload_json = wp_json_encode($payload_data);

        // 5. Шифрование
        $encrypted = csc_webpush_encrypt($payload_json, $sub['keys']['p256dh'], $sub['keys']['auth'], $private_key_pem);
        if (empty($encrypted) || !is_array($encrypted) || empty($encrypted['body'])) {
            csc_update_notification_status($log_id, 'failed', 'Payload encryption failed');
            return false;
        }

        $payload_to_send = $encrypted['body'];
        $crypto_headers = $encrypted['headers'] ?? [];

        // 6. VAPID JWT
        $jwt = csc_vapid_generate_jwt($sub['endpoint'], $subject, $private_key_pem);
        if (empty($jwt)) {
            csc_update_notification_status($log_id, 'failed', 'JWT generation failed');
            return false;
        }

        // 7. Сборка итоговых HTTP-заголовков
        $pub_raw = csc_pem_to_raw_public_key_from_private($private_key_pem);
        $vapid_header = 'vapid t=' . $jwt . ',k=' . csc_base64url_encode($pub_raw);

        $headers = [
            'Authorization'    => $vapid_header,
            'TTL'              => '86400',
            'Content-Type'     => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
        ];
        
        if (!empty($crypto_headers)) {
            $headers = array_merge($headers, $crypto_headers);
        }

        // 8. Отправка HTTP запроса (cURL + fallback на wp_remote_post)
        $http_code = 0;
        $response_body = '';
        $curl_error = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($sub['endpoint']);
            $curl_headers = [];
            foreach ($headers as $key => $value) {
                $curl_headers[] = "$key: $value";
            }
            
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $curl_headers,
                CURLOPT_POSTFIELDS     => $payload_to_send,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                csc_update_notification_status($log_id, 'failed', 'cURL error: ' . $curl_error);
                return false;
            }
        } else {
            $response = wp_remote_post($sub['endpoint'], [
                'headers'   => $headers,
                'body'      => $payload_to_send,
                'timeout'   => 15,
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                csc_update_notification_status($log_id, 'failed', 'wp_remote error: ' . $response->get_error_message());
                return false;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
        }

        // 9. Обработка ответа Push-сервиса
        if ($http_code >= 200 && $http_code < 300) {
            csc_update_notification_status($log_id, 'delivered', 'HTTP ' . $http_code);
            return true;
        }

        // 404 или 410 (Gone) — подписка больше не действительна
        if ($http_code === 404 || $http_code === 410) {
            if (function_exists('csc_webpush_remove_invalid_subscription')) {
                csc_webpush_remove_invalid_subscription($sub['endpoint'], $recipient_type, $recipient_id);
            }
            csc_update_notification_status($log_id, 'failed', "Subscription removed by server (HTTP {$http_code})");
            return false;
        }

        // Все остальные ошибки
        $error_detail = !empty($response_body) ? " - {$response_body}" : '';
        csc_update_notification_status($log_id, 'failed', "HTTP error {$http_code}{$error_detail}");
        return false;
    }
}

if (!function_exists('csc_webpush_remove_invalid_subscription')) {
    function csc_webpush_remove_invalid_subscription($endpoint, $user_type, $user_id) {
        $option_key  = "csc_push_subs_{$user_type}_{$user_id}";
        $subscriptions = get_option($option_key, []);
        if (!is_array($subscriptions)) return;

        $cleaned = [];
        foreach ($subscriptions as $sub_json) {
            $dec = json_decode($sub_json, true);
            if (is_array($dec) && ($dec['endpoint'] ?? '') !== $endpoint) {
                $cleaned[] = $sub_json;
            }
        }

        if (count($cleaned) !== count($subscriptions)) {
            if (empty($cleaned)) {
                delete_option($option_key);
            } else {
                update_option($option_key, $cleaned, false);
            }
        }
    }
}
