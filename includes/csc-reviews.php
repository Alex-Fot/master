<?php
/**
* Файл: csc-reviews.php
* Описание: Модуль оценки и отзывов мастеров клиентами.
* Версия: 3.0 (единая работа с заказами через таблицу, инвалидация кэша рейтингов)
*
* ИСПРАВЛЕНО:
* - Все операции с заказами через csc_get_all_orders() / csc_update_order()
* - После сохранения отзыва вызывается do_action('csc_invalidate_ratings_cache')
* - После обновления рейтинга мастера вызывается csc_cache_delete_masters()
* - Telegram-уведомления через csc_send_tg_notification() с фолбэком
* - Все функции обёрнуты в function_exists
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// ОТКЛЮЧАЕМ СТАРЫЕ ДУБЛИКАТЫ ФУНКЦИЙ (если остались)
// ============================================================
remove_action('wp_ajax_csc_submit_review', 'csc_submit_review_handler', 10);
remove_action('wp_ajax_nopriv_csc_submit_review', 'csc_submit_review_handler', 10);

// Подключаем новый обработчик с приоритетом 1
add_action('wp_ajax_csc_submit_review', 'csc_submit_review_handler_pro', 1);
add_action('wp_ajax_nopriv_csc_submit_review', 'csc_submit_review_handler_pro', 1);

// ============================================================
// 0. FALLBACK-ФУНКЦИЯ НОРМАЛИЗАЦИИ ТЕЛЕФОНА
// ============================================================
if (!function_exists('csc_normalize_phone_fallback')) {
function csc_normalize_phone_fallback($phone) {
if (empty($phone)) return '';
$clean = preg_replace('/[^0-9]/', '', $phone);
if (strlen($clean) === 11 && ($clean[0] === '8' || $clean[0] === '7')) {
return '7' . substr($clean, 1);
}
return $clean;
}
}

// ============================================================
// 1a. ЗАЩИТА ОТ ПЕРЕБОРА (BRUTE-FORCE) ПОПЫТОК ОЦЕНИТЬ ЗАКАЗ
// ============================================================
/**
 * Не более 10 неудачных попыток отправки отзыва на один телефон за 15 минут.
 * Позволяет закрыть уязвимость перебора номеров заказов чужими телефонами.
 *
 * @param string $phone Нормализованный телефон
 * @return bool true — попытка разрешена, false — лимит исчерпан
 */
if (!function_exists('csc_check_review_rate_limit')) {
    function csc_check_review_rate_limit($phone) {
        if (empty($phone)) return false;
        $key = 'csc_review_attempts_' . md5($phone);
        $data = get_transient($key);

        $max_attempts = 10;
        $window = 15 * MINUTE_IN_SECONDS;

        if (!$data) {
            set_transient($key, ['attempts' => 1, 'start_time' => time()], $window);
            return true;
        }

        $attempts = (int)($data['attempts'] ?? 0);
        if ($attempts >= $max_attempts) {
            return false;
        }

        set_transient($key, ['attempts' => $attempts + 1, 'start_time' => $data['start_time'] ?? time()], $window);
        return true;
    }
}

/**
 * Сброс счётчика неудачных попыток после успешной отправки отзыва.
 */
if (!function_exists('csc_reset_review_rate_limit')) {
    function csc_reset_review_rate_limit($phone) {
        if (empty($phone)) return;
        delete_transient('csc_review_attempts_' . md5($phone));
    }
}

// ============================================================
// 1b. ОБРАБОТЧИК ОТПРАВКИ ОТЗЫВА
// ============================================================
if (!function_exists('csc_submit_review_handler_pro')) {
    function csc_submit_review_handler_pro() {
        // УСИЛЕНО (2.5): nonce обязателен всегда, запрос без токена отклоняется
        if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'csc_cabinet_nonce')) {
            wp_send_json_error(['message' => 'Сессия устарела. Обновите страницу и попробуйте снова.'], 403);
            return;
        }

        // Очистка ключей массива $_POST от HTML-сущностей WordPress (&amp; → &)
        $clean_post = [];
        foreach ($_POST as $key => $value) {
            $clean_key = str_replace('&amp;', '&', $key);
            $clean_post[$clean_key] = $value;
        }

        // Дополнительная проверка на случай передачи через JSON
        $raw_post = file_get_contents('php://input');
        if (empty($clean_post) && !empty($raw_post)) {
            $decoded = json_decode($raw_post, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $clean_key = str_replace('&amp;', '&', $key);
                    $clean_post[$clean_key] = $value;
                }
            }
        }

        // 1. Получение и нормализация данных из очищенного массива
        $raw_phone = sanitize_text_field($clean_post['phone'] ?? '');
        $phone = function_exists('csc_normalize_phone') ? csc_normalize_phone($raw_phone) : csc_normalize_phone_fallback($raw_phone);
        $order_number = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)($clean_post['order_number'] ?? ''));
        $rating = intval($clean_post['rating'] ?? 0);
        $comment = mb_substr(trim(sanitize_textarea_field($clean_post['comment'] ?? '')), 0, 1000);

        // Проверка обязательных данных
        if (!$phone || !$order_number) {
            error_log('[CSC_REVIEWS] Review submit failed: missing phone or order_number.');
            wp_send_json_error(['message' => 'Сбой передачи данных. Обновите страницу и попробуйте снова.']);
            return;
        }

        // УСИЛЕНО (2.5): лимит попыток — защита от перебора чужих заказов
        if (!csc_check_review_rate_limit($phone)) {
            error_log('[CSC_REVIEWS] Review rate limit exceeded for phone hash: ' . md5($phone));
            wp_send_json_error(['message' => 'Слишком много попыток. Подождите 15 минут и попробуйте снова.'], 429);
            return;
        }

        if ($rating < 1 || $rating > 5) {
            wp_send_json_error(['message' => 'Пожалуйста, выберите оценку (от 1 до 5 звезд)']);
            return;
        }

        // 2. ИСПРАВЛЕНО: единая функция чтения заказов
        $all_orders = csc_get_all_orders();
        if (!isset($all_orders[$order_number])) {
            wp_send_json_error(['message' => "Заказ {$order_number} не найден в базе данных"]);
            return;
        }

        $order = $all_orders[$order_number];

        // 3. Сравнение нормализованных телефонов
        $order_phone = function_exists('csc_normalize_phone') ? csc_normalize_phone($order['phone'] ?? '') : csc_normalize_phone_fallback($order['phone'] ?? '');
        if ($order_phone !== $phone) {
            wp_send_json_error(['message' => "Доступ запрещен. Телефоны не совпадают (В заказе: {$order_phone}, Ваш: {$phone})"]);
            return;
        }

        if (($order['status'] ?? '') !== 'выполнено') {
            wp_send_json_error(['message' => 'Оценить можно только завершенный заказ']);
            return;
        }

        if (isset($order['review'])) {
            wp_send_json_error(['message' => 'Вы уже оставили отзыв к этому заказу']);
            return;
        }

        // 4. ИСПРАВЛЕНО: сохранение отзыва через единую функцию
        $review_data = [
            'rating' => $rating,
            'comment' => $comment,
            'date' => current_time('mysql')
        ];

        // === ОБРАБОТКА ПРИКРЕПЛЁННЫХ ФОТО ===
        if (!empty($_FILES['review_photos']) && is_array($_FILES['review_photos']['name'])) {
            $photos = [];
            $upload_dir = wp_upload_dir();
            $review_dir = $upload_dir['basedir'] . '/csc-uploads/reviews/' . $order_number;

            if (!file_exists($review_dir)) {
                wp_mkdir_p($review_dir);
                @file_put_contents($review_dir . '/index.php', '<?php // Silence is golden.');
            }

            if (!function_exists('wp_handle_upload')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }

            $file_count = count($_FILES['review_photos']['name']);
            $max_photos = 10;
            $max_size = 5 * 1024 * 1024; // 5 МБ
            $allowed_types = ['jpg', 'jpeg', 'png', 'heic', 'webp'];
            // УСИЛЕНО (2.5): реальные MIME-типы изображений, которым соответствуют разрешённые расширения
            $allowed_mimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/octet-stream'];

            for ($i = 0; $i < $file_count; $i++) {
                if ($i >= $max_photos) break;
                if ($_FILES['review_photos']['error'][$i] !== UPLOAD_ERR_OK) continue;

                $filename = $_FILES['review_photos']['name'][$i];
                $tmp_name = $_FILES['review_photos']['tmp_name'][$i];
                $file_size = $_FILES['review_photos']['size'][$i];

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_types)) continue;
                if ($file_size > $max_size) continue;
                if ($file_size <= 0 || !is_uploaded_file($tmp_name)) continue;

                // УСИЛЕНО (2.5): проверка содержимого файла, а не только расширения
                $detected_mime = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $detected_mime = (string)finfo_file($finfo, $tmp_name);
                        finfo_close($finfo);
                    }
                } elseif (function_exists('mime_content_type')) {
                    $detected_mime = (string)@mime_content_type($tmp_name);
                }
                if ($detected_mime && !in_array($detected_mime, $allowed_mimes, true)) continue;

                // jpg/png/webp дополнительно проверяем через getimagesize (защита от поддельных картинок)
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $img_info = @getimagesize($tmp_name);
                    if ($img_info === false || empty($img_info[0]) || empty($img_info[1])) continue;
                }

                $unique_name = 'review_' . time() . '_' . $i . '_' . wp_generate_password(8, false) . '.' . $ext;
                $dest_path = $review_dir . '/' . $unique_name;

                if (move_uploaded_file($tmp_name, $dest_path)) {
                    @chmod($dest_path, 0644);
                    $photos[] = [
                        'url' => $upload_dir['baseurl'] . '/csc-uploads/reviews/' . $order_number . '/' . $unique_name,
                        'name' => $unique_name,
                        'size' => $file_size,
                        'uploaded' => current_time('mysql')
                    ];
                }
            }

            if (!empty($photos)) {
                $review_data['photos'] = $photos;
            }
        }

        $all_orders[$order_number]['review'] = $review_data;
        csc_update_order($order_number, $all_orders[$order_number]);
        csc_cache_delete_orders();

        // УСИЛЕНО (2.5): успех — сбрасываем счётчик неудачных попыток для телефона
        csc_reset_review_rate_limit($phone);

        // === ДОБАВЛЕН ХУК ПОСЛЕ СОХРАНЕНИЯ ОТЗЫВА ===
        do_action('csc_review_submitted', $order_number, [
            'rating'  => $rating,
            'comment' => $comment,
            'photos'  => $photos ?? [], // Используем ?? [] для защиты от Undefined variable, если фото не загружались
        ], $order);

        // 5. ИСПРАВЛЕНО: обновление рейтинга мастера + инвалидация кэшей
        $master_id = $order['master_id'] ?? '';
        if ($master_id && function_exists('csc_get_masters')) {
            $masters = csc_get_masters();
            if (isset($masters[$master_id])) {
                // Собираем все отзывы для этого мастера из обновлённого списка заказов
                $reviews = [];
                $all_orders_fresh = csc_get_all_orders(true); // Перечитываем после обновления
                foreach ($all_orders_fresh as $o) {
                    if (($o['master_id'] ?? '') === $master_id && isset($o['review']['rating'])) {
                        $reviews[] = $o['review']['rating'];
                    }
                }
                if (!empty($reviews)) {
                    $avg_rating = round(array_sum($reviews) / count($reviews), 1);
                    $masters[$master_id]['rating'] = $avg_rating;
                    $masters[$master_id]['total_reviews'] = count($reviews);
                    update_option('csc_masters_list', $masters, false);
                    // ИСПРАВЛЕНО: сброс кэша мастеров после обновления рейтинга
                    if (function_exists('csc_cache_delete_masters')) {
                        csc_cache_delete_masters();
                    } else {
                        wp_cache_delete('csc_masters_list_v2');
                        wp_cache_delete('csc_masters_tg_v2');
                        wp_cache_delete('csc_masters_ratings_v2');
                    }
                }
            }
        }

        // 6. ИСПРАВЛЕНО: ИНВАЛИДАЦИЯ КЭША РЕЙТИНГОВ
        // Вызывает csc_invalidate_ratings_cache() из csc-master-ratings.php
        do_action('csc_invalidate_ratings_cache');

        // 7. Логирование через централизованную систему
        if (function_exists('csc_log_notification')) {
            $stars = str_repeat('⭐', $rating);
            $log_msg = "Клиент оценил заказ #{$order_number} на {$stars} ({$rating}/5)";
            if ($comment) {
                $log_msg .= "\nКомментарий: {$comment}";
            }
            csc_log_notification($order_number, 'customer', $phone, 'web', '🌟 Новый отзыв', $log_msg, ['rating' => $rating]);
        }

        // 8. Уведомление в Telegram
        if (function_exists('csc_send_review_telegram_notification')) {
            csc_send_review_telegram_notification($order, $rating, $comment);
        }

        wp_send_json_success(['message' => 'Ваш отзыв успешно сохранен!']);
    }
}

// ============================================================
// 2. ОТПРАВКА УВЕДОМЛЕНИЙ В TELEGRAM
// ============================================================
if (!function_exists('csc_send_review_telegram_notification')) {
function csc_send_review_telegram_notification($order, $rating, $comment) {
$s = get_option('csc_main_settings', []);
// ИСПРАВЛЕНО: расшифровка токена через csc_get_telegram_token()
$tg_token = function_exists('csc_get_telegram_token')
    ? csc_get_telegram_token()
    : ($s['telegram_token'] ?? '');
if (!$tg_token) return;

$admin_chat = $s['telegram_chat_id'] ?? '';
$master_id = $order['master_id'] ?? '';
$master_chat = '';
$master_name = 'Не назначен';

if ($master_id && function_exists('csc_get_masters')) {
$m_list = csc_get_masters();
if (isset($m_list[$master_id])) {
$master_chat = $m_list[$master_id]['tg_chat_id'] ?? '';
$master_name = $m_list[$master_id]['name'] ?? '';
}
}

$rating = max(1, min(5, intval($rating)));
$stars = str_repeat('⭐', $rating);
$order_number_safe = esc_html($order['order_number'] ?? '');
$master_name_safe = esc_html($master_name);
$comment_safe = trim((string)$comment);

$msg = "🌟 <b>НОВЫЙ ОТЗЫВ!</b>\n";
$msg .= "Заказ: #{$order_number_safe}\n";
$msg .= "Мастер: <b>{$master_name_safe}</b>\n";
$msg .= "Оценка: {$stars} ({$rating}/5)\n";

if ($comment_safe !== '') {
// ИСПРАВЛЕНО: правильное экранирование для Telegram HTML
$comment_tg_safe = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $comment_safe);
$msg .= "Комментарий: <i>{$comment_tg_safe}</i>\n";
}

// ИСПРАВЛЕНО: используем централизованную систему уведомлений с трекингом
if ($admin_chat && function_exists('csc_send_tg_notification')) {
csc_send_tg_notification(
$admin_chat,
'🌟 Новый отзыв',
$msg,
$order['order_number'] ?? '',
'admin',
'admin',
null
);
} elseif ($admin_chat) {
// Фолбэк: прямая отправка если модуль недоступен
wp_remote_post("https://api.telegram.org/bot{$tg_token}/sendMessage", [
'body' => ['chat_id' => $admin_chat, 'text' => $msg, 'parse_mode' => 'HTML']
]);
}

if ($master_chat && function_exists('csc_send_tg_notification')) {
csc_send_tg_notification(
$master_chat,
'🌟 Новый отзыв',
$msg,
$order['order_number'] ?? '',
'master',
$master_id,
null
);
} elseif ($master_chat) {
// Фолбэк
wp_remote_post("https://api.telegram.org/bot{$tg_token}/sendMessage", [
'body' => ['chat_id' => $master_chat, 'text' => $msg, 'parse_mode' => 'HTML']
]);
}
}
}