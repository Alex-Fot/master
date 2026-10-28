<?php
/**
* Файл: csc-order-routing.php
* Описание: Логика распределения заявок между мастерами и отслеживание таймаутов.
* Версия: 3.3 (исправлены часовые пояса, расчет минут, экранирование HTML)
*
* ИСПРАВЛЕНО В 3.3:
* - Критический баг часовых поясов: переход на DateTime + wp_timezone()
* - Критический баг минут: десятичные часы при проверке расписания
* - Оптимизация O(N*M) в csc_calculate_master_priority
* - Экранирование HTML для Telegram API
*/
// 1. САМАЯ ПЕРВАЯ СТРОКА: Защита от прямого доступа
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'csc-notifications.php';

// ============================================================
// ХЕЛПЕРЫ СБРОСА КЭША (ЕДИНЫЕ ДЛЯ ВСЕХ МОДУЛЕЙ)
// ============================================================
if (!function_exists('csc_cache_delete_orders')) {
    /**
    * ЕДИНАЯ ТОЧКА ИНВАЛИДАЦИИ КЭША ЗАКАЗОВ (Кэш заказов).
    * Удаляет ключ объектного кэша, который питает csc_get_all_orders(),
    * а также сбрасывает трансиентный снапшот для Telegram-бота.
    * Должна вызываться ПОСЛЕ ЛЮБОЙ записи в хранилище заказов
    * (прямые $wpdb->update()/insert() мимо csc_update_order — тоже здесь).
    */
    function csc_cache_delete_orders() {
        wp_cache_delete('csc_all_orders_v2');
        wp_cache_delete('csc_all_orders_tg_v2');
        // Совместимость: старый трансиентный снапшот списка заказов
        delete_transient('csc_all_orders_tg_v2');
    }
}

if (!function_exists('csc_cache_delete_masters')) {
    function csc_cache_delete_masters() {
        wp_cache_delete('csc_masters_list_v2');
        wp_cache_delete('csc_masters_tg_v2');
        wp_cache_delete('csc_masters_ratings_v2');
    }
}

// ============================================================
// 1. КОНСТАНТЫ И НАСТРОЙКИ (из админки)
// ============================================================
$settings = get_option('csc_main_settings', []);
$routing_timeout = intval($settings['routing_timeout'] ?? 180);
$routing_max_attempts = intval($settings['routing_max_attempts'] ?? 3);
$routing_log_enabled = !empty($settings['routing_log_enabled']);

if (!defined('CSC_ROUTING_TIMEOUT')) define('CSC_ROUTING_TIMEOUT', $routing_timeout);
if (!defined('CSC_ROUTING_MAX_ATTEMPTS')) define('CSC_ROUTING_MAX_ATTEMPTS', $routing_max_attempts);
if (!defined('CSC_ROUTING_LOG_ENABLED')) define('CSC_ROUTING_LOG_ENABLED', $routing_log_enabled);
if (!defined('CSC_ROUTING_GLOBAL_TIMEOUT')) define('CSC_ROUTING_GLOBAL_TIMEOUT', 1800);

// ============================================================
// X. РЕЗЕРВНОЕ КОПИРОВАНИЕ ПРИ СБОЕ РОУТИНГА
// ============================================================
if (!function_exists('csc_backup_order_on_routing_failure')) {
    function csc_backup_order_on_routing_failure($order_number, $order, $failure_reason) {
        $backup_queue = get_option('csc_routing_backup_queue', []);
        $backup_entry = [
            'order_number' => $order_number,
            'original_order_data' => $order,
            'failure_reason' => $failure_reason,
            'timestamp' => time(),
            'attempts_count' => 1,
            'last_attempt' => time()
        ];
        $backup_queue[$order_number] = $backup_entry;
        update_option('csc_routing_backup_queue', $backup_queue, false);
        csc_log("Заказ {$order_number} помещен в резервную очередь из-за: {$failure_reason}", 'error', 'ROUTING');
    }
}

add_action('csc_routing_cron_hook', 'csc_check_routing_backup_queue');

if (!function_exists('csc_check_routing_backup_queue')) {
    function csc_check_routing_backup_queue() {
        $backup_queue = get_option('csc_routing_backup_queue', []);
        if (empty($backup_queue)) return;

        $retry_interval_seconds = 300;
        $max_attempts = 5;
        $now = time();
        $all_orders = csc_get_all_orders(true);

        foreach ($backup_queue as $num => $entry) {
            $order_exists = isset($all_orders[$num]) && ($all_orders[$num]['status'] ?? '') !== CSC_STATUS_CANCELLED;
            $should_retry = ($now - $entry['last_attempt']) >= $retry_interval_seconds;
            $under_max_attempts = $entry['attempts_count'] < $max_attempts;

            if ($order_exists && $should_retry && $under_max_attempts) {
                csc_log("Повторная попытка маршрутизации для заказа {$num} из резервной очереди.", 'debug', 'ROUTING');
                $backup_queue[$num]['attempts_count']++;
                $backup_queue[$num]['last_attempt'] = time();

                if (isset($all_orders[$num])) {
                    $all_orders[$num]['routing_status'] = 'searching';
                    $all_orders[$num]['rejected_by'] = [];
                    $all_orders[$num]['current_candidate'] = null;
                    $all_orders[$num]['offer_time'] = null;
                    $all_orders[$num]['tg_message_id'] = null;
                    $all_orders[$num]['routing_attempts'] = 0;
                    $all_orders[$num]['routing_started_at'] = time();
                    csc_update_order($num, $all_orders[$num]);
                    csc_cache_delete_orders();

                    if (function_exists('csc_route_order_to_next')) {
                        csc_route_order_to_next($num, 'backup_queue_retry');
                    } else {
                        csc_log("ERROR: csc_route_order_to_next не найдена при повторной попытке для {$num}", 'error', 'ROUTING');
                    }
                } else {
                    unset($backup_queue[$num]);
                    csc_log("Заказ {$num} не найден в основной очереди, удален из резервной.", 'warning', 'ROUTING');
                }
            } elseif (!$order_exists || $entry['attempts_count'] >= $max_attempts) {
                unset($backup_queue[$num]);
                csc_log("Заказ {$num} удален из резервной очереди (превышено число попыток или отменен).", 'debug', 'ROUTING');
            }
        }
        update_option('csc_routing_backup_queue', $backup_queue, false);
    }
}

// ============================================================
// 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================
if (!function_exists('csc_routing_log')) {
    function csc_routing_log($order_number, $event, $data = []) {
        if (!defined('CSC_ROUTING_LOG_ENABLED') || !CSC_ROUTING_LOG_ENABLED) return;
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'order' => $order_number,
            'event' => $event,
            'data' => $data
        ];
        $logs = get_option('csc_routing_logs', []);
        $logs[] = $log_entry;
        $logs = array_slice($logs, -100);
        update_option('csc_routing_logs', $logs, false);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            csc_log(json_encode($log_entry, JSON_UNESCAPED_UNICODE), 'debug', 'ROUTING');
        }
    }
}

if (!function_exists('csc_get_masters_cached')) {
    function csc_get_masters_cached($force_refresh = false) {
        $cache_key = 'csc_masters_list_v2';
        $masters = wp_cache_get($cache_key);
        if ($force_refresh || false === $masters) {
            $masters = function_exists('csc_get_masters_sorted_by_rating')
                ? csc_get_masters_sorted_by_rating()
                : (function_exists('csc_get_masters') ? csc_get_masters() : []);
            wp_cache_set($cache_key, $masters, '', HOUR_IN_SECONDS);
        }
        return $masters;
    }
}

if (!function_exists('csc_get_orders_cached')) {
    function csc_get_orders_cached($force_refresh = false) {
        return csc_get_all_orders($force_refresh);
    }
}

if (!function_exists('csc_tg_send_with_retry')) {
    function csc_tg_send_with_retry($endpoint, $body, $max_attempts = CSC_ROUTING_MAX_ATTEMPTS) {
        $s = get_option('csc_main_settings', []);
        // ИСПРАВЛЕНО: расшифровка токена через csc_get_telegram_token()
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        if (!$tg_token) return false;

        $api_url = "https://api.telegram.org/bot{$tg_token}/{$endpoint}";
        $attempt = 0;
        $delay = 1;

        while ($attempt < $max_attempts) {
            $response = wp_remote_post($api_url, ['body' => $body, 'timeout' => 15]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $result = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($result['ok'])) {
                    if ($endpoint === 'sendMessage') {
                        return isset($result['result']['message_id']) ? (int)$result['result']['message_id'] : false;
                    }
                    return true;
                }
            }
            $attempt++;
            if ($attempt < $max_attempts) {
                usleep($delay * 1000000);
                $delay *= 2;
            }
        }
        csc_routing_log('system', 'tg_send_failed', ['endpoint' => $endpoint, 'attempts' => $max_attempts]);
        return false;
    }
}

// ============================================================
// ПРОВЕРКА СВОБОДНОСТИ МАСТЕРА (С УЧЁТОМ ДНЯ НЕДЕЛИ И РАСПИСАНИЯ)
// ============================================================
if (!function_exists('csc_is_master_free_at')) {
    function csc_is_master_free_at($master_id, $date, $start_time, $duration_minutes) {
        if (empty($date) || empty($start_time)) return true;

        $s = get_option('csc_main_settings', []);
        $travel_time = intval($s['travel_time'] ?? 0);
        $total_duration = $duration_minutes + $travel_time;

        $all_orders = csc_get_all_orders();
        // ИСПРАВЛЕНО: Использование часового пояса WordPress
        $tz = wp_timezone();

        try {
            $dt_start = new DateTime("$date $start_time", $tz);
            $requested_start = $dt_start->getTimestamp();
        } catch (Exception $e) {
            return true;
        }
        $requested_end = $requested_start + ($total_duration * 60);

        $masters_list = function_exists('csc_get_masters') ? csc_get_masters() : [];
        $master_data = isset($masters_list[$master_id]) ? $masters_list[$master_id] : [];

        // ИСПРАВЛЕНО: wp_date вместо date для учета TZ
        $req_day_of_week = (int)wp_date('N', $requested_start);
        if (!empty($master_data['work_days']) && is_array($master_data['work_days'])) {
            $work_days_int = array_map('intval', $master_data['work_days']);
            if (!in_array($req_day_of_week, $work_days_int)) {
                return false;
            }
        }

        // ИСПРАВЛЕНО: Учет минут начала заказа (десятичные часы)
        $slot_hour = (int)wp_date('H', $requested_start);
        $slot_minute = (int)wp_date('i', $requested_start);
        $slot_decimal = $slot_hour + ($slot_minute / 60);

        $day_api_keys = [1=>'monday', 2=>'tuesday', 3=>'wednesday', 4=>'thursday', 5=>'friday', 6=>'saturday', 7=>'sunday'];
        if (isset($master_data['schedule']) && is_array($master_data['schedule'])) {
            $day_key = $day_api_keys[$req_day_of_week] ?? '';
            $day_schedule = $master_data['schedule'][$day_key] ?? null;

            if ($day_schedule !== null) {
                if (empty($day_schedule['enabled'])) {
                    return false;
                }
                $from_parts = explode(':', $day_schedule['from'] ?? '09:00');
                $to_parts = explode(':', $day_schedule['to'] ?? '18:00');
                
                $from_decimal = intval($from_parts[0]) + (intval($from_parts[1] ?? 0) / 60);
                $to_decimal = intval($to_parts[0]) + (intval($to_parts[1] ?? 0) / 60);

                if ($slot_decimal < $from_decimal || $slot_decimal >= $to_decimal) {
                    return false;
                }

                $req_end_decimal = $slot_decimal + ($total_duration / 60);
                if ($req_end_decimal > $to_decimal) {
                    return false;
                }
            }
        }
        elseif (isset($master_data['work_hours_start']) && isset($master_data['work_hours_end'])) {
            $m_start_h = intval($master_data['work_hours_start']);
            $m_end_h = intval($master_data['work_hours_end']);
            
            $m_start_decimal = $m_start_h;
            $m_end_decimal = $m_end_h;

            if ($slot_decimal < $m_start_decimal || $slot_decimal >= $m_end_decimal) {
                return false;
            }
            $req_end_decimal = $slot_decimal + ($total_duration / 60);
            if ($req_end_decimal > $m_end_decimal) {
                return false;
            }
        }

        foreach ($all_orders as $order) {
            if (($order['status'] ?? '') === CSC_STATUS_CANCELLED) continue;
            if (($order['master_id'] ?? '') !== $master_id) continue;
            if (($order['date'] ?? '') !== $date) continue;

            $o_time_str = ($order['date'] ?? '') . ' ' . ($order['time'] ?? '00:00');
            try {
                $dt_o = new DateTime($o_time_str, $tz);
                $order_start = $dt_o->getTimestamp();
            } catch (Exception $e) {
                continue;
            }

            $order_duration = intval($order['duration'] ?? 0) + $travel_time;
            $order_end = $order_start + ($order_duration * 60);

            if ($requested_start < $order_end && $requested_end > $order_start) {
                return false;
            }
        }

        return true;
    }
}

// ============================================================
// РАСЧЁТ ПРИОРИТЕТА МАСТЕРА (С БОНУСОМ ЗА ГОРОД И ЗОНУ)
// ============================================================
if (!function_exists('csc_calculate_master_priority')) {
    function csc_calculate_master_priority($master_id, $order, $masters) {
        $priority = 0;
        $m = $masters[$master_id] ?? [];

        if (empty($m['active']) || empty($m['tg_chat_id'])) return -1;

        $priority += (float)($m['avg_rating'] ?? $m['rating'] ?? 3) * 10;

        // ИСПРАВЛЕНО: Статический кэш для подсчета заказов за сегодня (O(1) вместо O(N))
        static $today_orders_cache = null;
        if ($today_orders_cache === null) {
            $today_orders_cache = [];
            $today = wp_date('Y-m-d');
            $all_orders = csc_get_all_orders();
            foreach ($all_orders as $o) {
                if (($o['date'] ?? '') === $today) {
                    $mid = $o['master_id'] ?? '';
                    if ($mid) {
                        $today_orders_cache[$mid] = ($today_orders_cache[$mid] ?? 0) + 1;
                    }
                }
            }
        }
        $today_orders = $today_orders_cache[$master_id] ?? 0;
        $priority += max(0, 10 - $today_orders) * 5;

        if (!empty($m['specializations']) && is_array($m['specializations'])) {
            $order_services = $order['services'] ?? [];
            if (!is_array($order_services)) $order_services = [$order_services];
            $matches = 0;
            foreach ($order_services as $svc) {
                foreach ($m['specializations'] as $spec) {
                    if (is_string($svc) && is_string($spec) && stripos($svc, $spec) !== false) $matches++;
                }
            }
            $priority += $matches * 15;
        }

        if (!empty($m['avg_accept_time']) && $m['avg_accept_time'] < 120) {
            $priority += 20;
        }

        $total_offers = (int)($m['total_offers'] ?? 0);
        $total_rejects = (int)($m['total_rejects'] ?? 0);
        if ($total_offers > 0) {
            $reject_rate = $total_rejects / $total_offers;
            $priority -= $reject_rate * 30;
        }

        if (!empty($m['city']) && !empty($order['city']) && $m['city'] === $order['city']) {
            $priority += 20;
        }

        if (!empty($m['zones']) && is_array($m['zones']) && !empty($order['zone'])) {
            if (in_array($order['zone'], $m['zones'])) {
                $priority += 15;
            }
        }

        $order_has_geo = !empty($order['client_lat']) && !empty($order['client_lng']);
        $master_has_geo = !empty($m['service_lat']) && !empty($m['service_lng']);
        
        if ($order_has_geo && $master_has_geo && function_exists('csc_haversine_km')) {
            $distance_km = csc_haversine_km(
                $order['client_lat'], $order['client_lng'],
                $m['service_lat'], $m['service_lng']
            );
            $master_radius = max(1, intval($m['service_radius'] ?? 10));
            $proximity_bonus = max(0, 40 * (1 - $distance_km / $master_radius));
            $priority += $proximity_bonus;
        }

        return round($priority);
    }
}

// ИСПРАВЛЕНО: удалён дублирующий интервал 'every_minute'.
// В custom-service-calculator.php зарегистрирован 'csc_every_minute' (60 сек),
// и крон 'csc_routing_cron_hook' планируется именно с ним при активации.
// Два разных имени интервала для одного хука создавали конфликт планирования.
add_action('init', 'csc_ensure_routing_cron_scheduled');
function csc_ensure_routing_cron_scheduled() {
if (get_transient('csc_cron_check_throttle')) return;
set_transient('csc_cron_check_throttle', 1, HOUR_IN_SECONDS);
if (!wp_next_scheduled('csc_routing_cron_hook')) {
wp_schedule_event(time(), 'csc_every_minute', 'csc_routing_cron_hook');
}
}
add_action('csc_routing_cron_hook', 'csc_check_routing_timeouts');

// ============================================================
// 4. ЛОГИКА МАРШРУТИЗАЦИИ (С ТРЕКИНГОМ УВЕДОМЛЕНИЙ)
// ============================================================
if (!function_exists('csc_start_order_routing')) {
    function csc_start_order_routing($order_number) {
        $all_orders = csc_get_all_orders(true);
        if (!isset($all_orders[$order_number])) {
            csc_routing_log($order_number, 'start_failed', ['reason' => 'order_not_found']);
            return;
        }

        $all_orders[$order_number]['routing_status'] = 'searching';
        $all_orders[$order_number]['routing_circle'] = 1; // КРУГ 1: только мастера в радиусе
        $all_orders[$order_number]['rejected_by'] = [];
        $all_orders[$order_number]['current_candidate'] = null;
        $all_orders[$order_number]['offer_time'] = null;
        $all_orders[$order_number]['tg_message_id'] = null;
        $all_orders[$order_number]['routing_attempts'] = 0;
        $all_orders[$order_number]['routing_started_at'] = time();

        csc_update_order($order_number, $all_orders[$order_number]);
        csc_cache_delete_orders();

        csc_routing_log($order_number, 'routing_started');
        csc_log_notification($order_number, 'system', 'router', 'web', '🔍 Поиск мастера', 'Маршрутизация запущена. Ожидание свободных кандидатов.', ['status' => 'routing_started']);

        csc_route_order_to_next($order_number);
    }
}

if (!function_exists('csc_route_order_to_next')) {
    function csc_route_order_to_next($order_number, $reason = 'timeout') {
        $all_orders = csc_get_all_orders(true);
        if (!isset($all_orders[$order_number])) return;

        $order = $all_orders[$order_number];

        if (($order['routing_status'] ?? '') !== 'searching') {
            csc_routing_log($order_number, 'route_skipped', ['reason' => 'status_not_searching', 'current_status' => $order['routing_status'] ?? 'unknown']);
            return;
        }

        $routing_started = (int)($order['routing_started_at'] ?? time());
        if (time() - $routing_started > CSC_ROUTING_GLOBAL_TIMEOUT) {
            $all_orders[$order_number]['routing_status'] = 'timeout_global';
            $all_orders[$order_number]['status'] = CSC_STATUS_ROUTING_TIMEOUT;
            csc_update_order($order_number, $all_orders[$order_number]);
            csc_cache_delete_orders();

            csc_routing_log($order_number, 'routing_global_timeout');

            $masters = csc_get_masters_cached();
            $rejected = (array)($order['rejected_by'] ?? []);
            $current_candidate = $order['current_candidate'] ?? null;
            if ($current_candidate && !in_array($current_candidate, $rejected)) {
                $rejected[] = $current_candidate;
            }

            csc_backup_order_on_routing_failure($order_number, $order, 'global_timeout');
            csc_notify_admin_no_masters($order_number, $order, $rejected, $masters);
            csc_log_notification($order_number, 'admin', 'admin', 'web', '⚠️ Таймаут маршрутизации', 'Поиск мастера превысил 30 минут.', ['status' => 'failed', 'reason' => 'global_timeout']);
            return;
        }

        $masters = csc_get_masters_cached();
        $rejected = (array)($order['rejected_by'] ?? []);
        $current_candidate = $order['current_candidate'] ?? null;

        if ($current_candidate && !in_array($current_candidate, $rejected)) {
            $rejected[] = $current_candidate;
            $all_orders[$order_number]['rejected_by'] = $rejected;
        
            if (isset($masters[$current_candidate])) {
                $masters[$current_candidate]['total_rejects'] = ($masters[$current_candidate]['total_rejects'] ?? 0) + 1;
                update_option('csc_masters_list', $masters, false);
                csc_cache_delete_masters();
            }
        
            // НОВОЕ: штраф за отказ (только если причина — явный отказ мастера,
            // а не таймаут — за таймаут штраф не начисляется)
            if ($reason === 'rejected' && function_exists('csc_apply_rejection_penalty')) {
                csc_apply_rejection_penalty($order_number, $current_candidate);
            }
        }

        $routing_circle = intval($order['routing_circle'] ?? 1);
        $candidates = [];
        
        foreach ($masters as $mid => $m) {
            if (empty($m['active']) || empty($m['tg_chat_id'])) continue;
            if (in_array($mid, $rejected)) continue;
        
            $order_has_geo = !empty($order['client_lat']) && !empty($order['client_lng']);
            $master_has_geo = !empty($m['service_lat']) && !empty($m['service_lng']);
            $distance_km = null;
        
            if ($order_has_geo && $master_has_geo && function_exists('csc_haversine_km')) {
                $distance_km = csc_haversine_km(
                    $order['client_lat'], $order['client_lng'],
                    $m['service_lat'], $m['service_lng']
                );
                $master_radius = max(1, intval($m['service_radius'] ?? 10));
        
                // КРУГ 1: только мастера строго в своём радиусе
                // КРУГ 2: мастера в радиусе + все, кто ближе 2х радиусов (расширенная зона)
                if ($routing_circle === 1) {
                    if ($distance_km > $master_radius) {
                        csc_routing_log($order_number, 'candidate_out_of_radius', [
                            'master_id' => $mid,
                            'distance_km' => round($distance_km, 2),
                            'radius_km' => $master_radius,
                            'circle' => 1,
                        ]);
                        continue;
                    }
                } else {
                    $expanded_radius = $master_radius * 2;
                    if ($distance_km > $expanded_radius) {
                        csc_routing_log($order_number, 'candidate_out_of_expanded_radius', [
                            'master_id' => $mid,
                            'distance_km' => round($distance_km, 2),
                            'expanded_radius_km' => $expanded_radius,
                            'circle' => 2,
                        ]);
                        continue;
                    }
                }
            }
        
            if (!empty($order['city']) && !empty($m['city']) && $m['city'] !== $order['city']) {
                csc_routing_log($order_number, 'candidate_city_mismatch', ['master_id' => $mid]);
                continue;
            }
        
            if (!csc_is_master_free_at($mid, $order['date'] ?? '', $order['time'] ?? '', intval($order['duration'] ?? 60))) {
                csc_routing_log($order_number, 'candidate_busy', ['master_id' => $mid]);
                continue;
            }
        
            $priority = csc_calculate_master_priority($mid, $order, $masters);
            if ($priority >= 0) {
                // На круге 2 штрафуем приоритет по расстоянию — чем дальше, тем ниже приоритет
                if ($routing_circle === 2 && $distance_km !== null) {
                    $priority -= round($distance_km * 2);
                }
                $candidates[$mid] = $priority;
            }
        }

        arsort($candidates);
        $candidates = apply_filters('csc_routing_candidates', $candidates, $order_number, $order);

        $next_master_id = !empty($candidates) ? array_key_first($candidates) : null;

        if ($next_master_id) {
            $all_orders[$order_number]['current_candidate'] = $next_master_id;
            $all_orders[$order_number]['offer_time'] = time();
            $all_orders[$order_number]['routing_attempts'] = ($order['routing_attempts'] ?? 0) + 1;

            $services_text = '';
            if (!empty($order['services_for_master'])) {
                $services_text = is_array($order['services_for_master']) ? implode(', ', $order['services_for_master']) : $order['services_for_master'];
            } elseif (!empty($order['services'])) {
                $services_text = is_array($order['services']) ? implode(', ', $order['services']) : $order['services'];
            }
            // ИСПРАВЛЕНО: Экранирование HTML для Telegram
            $services_text = esc_html($services_text);

            $msg = "🆕 <b>Новая заявка #{$order_number}</b>\n";
            $msg .= "Услуги: " . $services_text . "\n";
            $msg .= "Дата: " . esc_html($order['date'] ?? 'Не указана') . " в " . esc_html($order['time'] ?? 'Не указано') . "\n";
            $msg .= "Сумма: " . ($order['total'] ?? 0) . " руб.";

            $dash_url = function_exists('csc_tg_get_dashboard_link')
                ? csc_tg_get_dashboard_link($next_master_id, $masters)
                : esc_url(home_url('/'));

            $msg .= "\n📲 <a href=\"" . esc_url($dash_url) . "\">Перейти в кабинет мастера</a>";

            $keyboard = json_encode([
                'inline_keyboard' => [[
                    ['text' => '✅ Принять', 'callback_data' => 'acc_' . $order_number],
                    ['text' => '❌ Отклонить', 'callback_data' => 'rej_' . $order_number]
                ]]
            ]);

            $log_id = csc_log_notification($order_number, 'master', $next_master_id, 'telegram', '🆕 Предложение мастеру', $msg, ['keyboard' => 'accept_reject']);
            csc_update_notification_status($log_id, 'sent');

            $msg_id = csc_tg_send_with_retry('sendMessage', [
                'chat_id' => $masters[$next_master_id]['tg_chat_id'],
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);

            if ($msg_id && is_numeric($msg_id)) {
                csc_update_notification_status($log_id, 'delivered', $msg_id);
            } else {
                csc_update_notification_status($log_id, 'failed');
                $msg_id = null; // ИСПРАВЛЕНО: не сохраняем false в БД
            }

            $all_orders[$order_number]['tg_message_id'] = $msg_id;

            $masters[$next_master_id]['total_offers'] = ($masters[$next_master_id]['total_offers'] ?? 0) + 1;
            update_option('csc_masters_list', $masters, false);
            csc_cache_delete_masters();

            csc_update_order($order_number, $all_orders[$order_number]);
            csc_cache_delete_orders();

            csc_routing_log($order_number, 'offer_sent', ['master_id' => $next_master_id, 'message_id' => $msg_id]);
            do_action('csc_routing_offer_sent', $order_number, $next_master_id, $msg_id);
        } else {
            if ($routing_circle === 1) {
                // ============================================================
                // КРУГ 2: в радиусе мастеров не осталось — расширяем зону.
                // Ищем ближайших активных мастеров за пределами радиуса.
                // ============================================================
                $all_orders[$order_number]['routing_circle'] = 2;
                $all_orders[$order_number]['rejected_by'] = []; // сбрасываем список отказов для нового круга
                $all_orders[$order_number]['routing_started_at'] = time();
                csc_update_order($order_number, $all_orders[$order_number]);
                csc_cache_delete_orders();
                csc_routing_log($order_number, 'expanding_to_circle_2', [
                    'reason' => 'no_candidates_in_radius',
                    'rejected_in_circle_1' => $rejected,
                ]);
                // Рекурсивный запуск второго круга
                csc_route_order_to_next($order_number, 'circle_2_expand');
                return;
            }
        
            // Оба круга исчерпаны — уведомляем администратора
            $all_orders[$order_number]['routing_status'] = 'no_masters';
            $all_orders[$order_number]['status'] = CSC_STATUS_NO_MASTERS;
            csc_update_order($order_number, $all_orders[$order_number]);
            csc_cache_delete_orders();
        
            csc_notify_admin_no_masters($order_number, $order, $rejected, $masters);
            csc_routing_log($order_number, 'no_masters_available', ['rejected_count' => count($rejected), 'circles_used' => 2]);
            csc_log_notification($order_number, 'admin', 'admin', 'web', '🚫 Нет мастеров', 'Все кандидаты в обоих кругах отклонили заказ или заняты.', ['status' => 'failed', 'rejected_count' => count($rejected)]);
            do_action('csc_routing_no_masters', $order_number, $order, $rejected);
        }
	}
}

if (!function_exists('csc_notify_admin_no_masters')) {
    function csc_notify_admin_no_masters($order_number, $order, $rejected, $masters = []) {
        $s = get_option('csc_main_settings', []);
        $tg_token = function_exists('csc_get_telegram_token')
            ? csc_get_telegram_token()
            : ($s['telegram_token'] ?? '');
        $admin_chat = $s['telegram_chat_id'] ?? '';
        if (!$tg_token || !$admin_chat) return;

        $reject_names = [];
        foreach ((array)$rejected as $rm_id) {
            if (!isset($masters[$rm_id])) continue;
            $name = esc_html($masters[$rm_id]['name'] ?? 'Неизвестно');
            $phone = esc_html($masters[$rm_id]['phone'] ?? '-');
            $reject_names[] = "$name ($phone)";
        }

        $reject_text = empty($reject_names) ? "Никто из мастеров не доступен." : "Отказались/пропустили:\n- " . implode("\n- ", $reject_names);

        $adm_msg = "⚠️ <b>ВНИМАНИЕ! Нет мастеров на заявку #{$order_number}</b>\n";
        $adm_msg .= "Клиент: " . esc_html($order['name'] ?? '') . " (+" . esc_html($order['phone'] ?? '') . ")\n";
        $adm_msg .= "Дата: " . esc_html($order['date'] ?? '') . " в " . esc_html($order['time'] ?? '') . "\n";
        $adm_msg .= "Сумма: " . ($order['total'] ?? 0) . " руб.\n";
        $adm_msg .= $reject_text;

        $log_id = csc_log_notification($order_number, 'admin', 'admin', 'telegram', '⚠️ Критическое: нет мастеров', $adm_msg);
        csc_update_notification_status($log_id, 'sent');

        $sent = csc_tg_send_with_retry('sendMessage', ['chat_id' => $admin_chat, 'text' => $adm_msg, 'parse_mode' => 'HTML']);
        if ($sent) csc_update_notification_status($log_id, 'delivered');
    }
}

// ============================================================
// 5. ПРОВЕРКА ТАЙМАУТОВ (ВЫЗЫВАЕТСЯ CRON-ОМ + ПРИ ЗАГРУЗКЕ АДМИНКИ)
// ============================================================
if (!function_exists('csc_check_routing_timeouts')) {
    function csc_check_routing_timeouts() {
        $lock_key = 'csc_routing_timeout_lock';
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 30);

        $all_orders = csc_get_all_orders();
        $now = time();
        $updated = false;

        foreach ($all_orders as $order_number => $order) {
            if (($order['routing_status'] ?? '') !== 'searching') continue;

            $offer_time = (int)($order['offer_time'] ?? 0);
            if ($offer_time <= 0) continue;

            if ($now - $offer_time >= CSC_ROUTING_TIMEOUT) {
                $tg_token = function_exists('csc_get_telegram_token')
                    ? csc_get_telegram_token()
                    : ($s['telegram_token'] ?? '');
                $masters = csc_get_masters_cached();

                $current_candidate_id = $order['current_candidate'] ?? null;
                $chat_id = ($current_candidate_id && isset($masters[$current_candidate_id])) ? $masters[$current_candidate_id]['tg_chat_id'] : null;
                $msg_id = $order['tg_message_id'] ?? null;

                // ИСПРАВЛЕНО: Проверка is_numeric для защиты от false
                if ($tg_token && $chat_id && $msg_id && is_numeric($msg_id)) {
                    csc_tg_send_with_retry('editMessageText', [
                        'chat_id' => $chat_id,
                        'message_id' => $msg_id,
                        'text' => "⏱ <b>Время вышло.</b> Заказ передан другому мастеру.",
                        'parse_mode' => 'HTML'
                    ]);
                    csc_log_notification($order_number, 'master', $current_candidate_id, 'telegram', '⏱ Таймаут', 'Время на принятие истекло.', ['status' => 'timeout']);
                }

                csc_route_order_to_next($order_number, 'timeout');
                $updated = true;
            }
        }

        if ($updated) {
            csc_cache_delete_orders();
        }

        delete_transient($lock_key);
    }
}

add_action('admin_init', 'csc_check_routing_timeouts_on_admin_load', 20);

if (!function_exists('csc_check_routing_timeouts_on_admin_load')) {
    function csc_check_routing_timeouts_on_admin_load() {
        $throttle_key = 'csc_routing_admin_check_throttle';
        if (get_transient($throttle_key)) return;
        set_transient($throttle_key, 1, 60);

        $all_orders = csc_get_all_orders();
        $has_searching = false;
        foreach ($all_orders as $order) {
            if (($order['routing_status'] ?? '') === 'searching') {
                $has_searching = true;
                break;
            }
        }
        if (!$has_searching) return;

        csc_check_routing_timeouts();
    }
}

// ============================================================
// 7. ФУНКЦИИ УПРАВЛЕНИЯ И ОТЛАДКИ (ADMIN)
// ============================================================
if (!function_exists('csc_admin_restart_routing')) {
    function csc_admin_restart_routing($order_number) {
        if (!current_user_can('manage_options')) {
            return false;
        }

        if (empty($order_number)) {
            return false;
        }

        $all_orders = csc_get_all_orders(true);
        if (!isset($all_orders[$order_number])) {
            return false;
        }

        $all_orders[$order_number]['routing_status'] = 'searching';
        $all_orders[$order_number]['routing_circle'] = 1;   // новый круг всегда с 1
        $all_orders[$order_number]['rejected_by'] = [];
        $all_orders[$order_number]['current_candidate'] = null;
        $all_orders[$order_number]['offer_time'] = null;
        $all_orders[$order_number]['tg_message_id'] = null;
        $all_orders[$order_number]['routing_started_at'] = time();
        $all_orders[$order_number]['routing_attempts'] = 0;
        $all_orders[$order_number]['status'] = CSC_STATUS_PENDING;   // возвращаем в «Ожидают» мастеру

        csc_update_order($order_number, $all_orders[$order_number]);

        if (function_exists('csc_cache_delete_orders')) {
            csc_cache_delete_orders();
        }

        if (function_exists('csc_routing_log')) {
            csc_routing_log($order_number, 'admin_restart_routing');
        }

        if (function_exists('csc_log_notification')) {
            csc_log_notification(
                $order_number,
                'admin',
                (string) get_current_user_id(),
                'web',
                '🔄 Перезапуск маршрутизации',
                'Администратор вручную перезапустил поиск мастера.',
                [
                    'status' => 'sent',
                    'routing_circle' => 1
                ]
            );
        }

        // Запускаем первый круг заново
        if (function_exists('csc_route_order_to_next')) {
            csc_route_order_to_next($order_number, 'admin_restart');
        }

        return true;
    }
}

if (!function_exists('csc_get_routing_history')) {
    function csc_get_routing_history($order_number) {
        if (!current_user_can('manage_options')) return [];
        $logs = get_option('csc_routing_logs', []);
        return array_filter($logs, function($l) use ($order_number) {
            return ($l['order'] ?? '') === $order_number;
        });
    }
}

// ============================================================
// 6. ХУКИ ДЛЯ РАСШИРЕНИЯ (ДОБАВЛЕНО)
// ============================================================
if (!function_exists('csc_routing_filter_candidates')) {
    function csc_routing_filter_candidates($candidates, $order_number, $order) {
        return apply_filters('csc_routing_candidates', $candidates, $order_number, $order);
    }
}

if (!function_exists('csc_routing_after_offer')) {
    function csc_routing_after_offer($order_number, $master_id, $message_id) {
        do_action('csc_routing_offer_sent', $order_number, $master_id, $message_id);
    }
}

if (!function_exists('csc_routing_no_masters')) {
    function csc_routing_no_masters($order_number, $order, $rejected) {
        do_action('csc_routing_no_masters', $order_number, $order, $rejected);
    }
}

if (!function_exists('csc_routing_accept_order')) {
    function csc_routing_accept_order($order_number, $master_id) {
        $all_orders = csc_get_all_orders(true);
        if (!isset($all_orders[$order_number])) return false;

        $all_orders[$order_number]['routing_status'] = 'assigned';
        $all_orders[$order_number]['status'] = CSC_STATUS_PROCESSING;
        $all_orders[$order_number]['accepted_by'] = $master_id;
        $all_orders[$order_number]['accepted_at'] = current_time('mysql');
        $all_orders[$order_number]['rejected_by'] = [];

        csc_update_order($order_number, $all_orders[$order_number]);
        csc_cache_delete_orders();

        csc_routing_log($order_number, 'order_accepted', ['master_id' => $master_id]);
        return true;
    }
}

// ============================================================
// НОВОЕ: ШТРАФ ЗА ОТКАЗ МАСТЕРА ОТ ЗАЯВКИ В РАБОЧЕЕ ВРЕМЯ
// Вызывается при отказе через Telegram-кнопку или кабинет мастера.
// Настройки штрафа берутся из 'Регистрация мастеров' (csc-master-registration.php)
// ============================================================
if (!function_exists('csc_apply_rejection_penalty')) {
    function csc_apply_rejection_penalty($order_number, $master_id) {
        if (empty($master_id) || empty($order_number)) return false;

        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        if (empty($masters[$master_id])) return false;

        $order = function_exists('csc_get_order') ? csc_get_order($order_number) : null;
        if (!$order) return false;

        // Получаем настройки штрафов из модуля регистрации
        $reg_settings = function_exists('csc_get_registration_settings')
            ? csc_get_registration_settings()
            : ['penalty_rejection_amount' => 0, 'penalty_rejection_type' => 'fixed', 'penalty_rejection_percent' => 0];

        $penalty_type = $reg_settings['penalty_rejection_type'] ?? 'fixed';
        $order_total = floatval($order['total'] ?? 0);

        if ($penalty_type === 'percent') {
            $penalty_amount = $order_total * (floatval($reg_settings['penalty_rejection_percent'] ?? 0) / 100);
        } else {
            $penalty_amount = floatval($reg_settings['penalty_rejection_amount'] ?? 0);
        }

        if ($penalty_amount <= 0) {
            return false; // штраф не настроен
        }

        // Проверка: не превышен ли лимит отказов до блокировки
        $max_rejections = intval($reg_settings['max_rejections_before_block'] ?? 5);
        $current_rejects = intval($masters[$master_id]['total_rejects'] ?? 0);

        // Начисляем штраф через финансовый модуль
        if (function_exists('csc_finance_on_penalty')) {
            csc_finance_on_penalty(
                $master_id,
                $penalty_amount,
                sprintf('Отказ от заявки #%s в рабочее время', $order_number),
                $order_number
            );
        } else {
            do_action('csc_finance_penalty', $master_id, $penalty_amount, 'Отказ от заявки #' . $order_number, $order_number);
        }

        csc_routing_log($order_number, 'rejection_penalty_applied', [
            'master_id' => $master_id,
            'amount' => $penalty_amount,
            'type' => $penalty_type,
        ]);

        // Уведомление мастеру о штрафе (внутрикабинетное + push через триггер)
        if (function_exists('csc_notify_master')) {
            csc_notify_master(
                $master_id,
                '⚠️ Штраф за отказ',
                sprintf(
                    'За отказ от заявки #%s начислен штраф %s ₽',
                    $order_number,
                    number_format($penalty_amount, 0, '.', ' ')
                ),
                $order_number,
                'penalty',
                '⚠️'
            );
        }

        // Проверка на блокировку по лимиту отказов
        if ($max_rejections > 0 && $current_rejects + 1 >= $max_rejections) {
            csc_block_master_for_rejections($master_id, $masters);
        }

        return true;
    }
}

/**
* Блокировка мастера при превышении лимита отказов
*/
if (!function_exists('csc_block_master_for_rejections')) {
    function csc_block_master_for_rejections($master_id, $masters = null) {
        if (!$masters) {
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        }
        if (empty($masters[$master_id])) return;

        $masters[$master_id]['status'] = 'blocked';
        $masters[$master_id]['is_online'] = false;
        $masters[$master_id]['block_reason'] = 'Превышен лимит отказов от заявок';
        update_option('csc_masters_list', $masters, false);
        if (function_exists('csc_cache_delete_masters')) csc_cache_delete_masters();

        if (function_exists('csc_notify_master')) {
            csc_notify_master(
                $master_id,
                '🚫 Аккаунт заблокирован',
                'Ваш аккаунт заблокирован из-за превышения лимита отказов от заявок. Обратитесь к администрации.',
                '',
                'block',
                '🚫'
            );
        }
    }
}