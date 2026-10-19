<?php
/**
 * Файл: csc-master-ratings.php
 * Описание: Расчёт рейтингов мастеров, вывод в таблицу админки и умная сортировка заявок.
 * Версия: 3.1 (добавлены csc_get_master_rating и csc_get_master_reviews)
 *
 * ИСПРАВЛЕНО:
 * - csc_get_master_ratings_data использует csc_get_all_orders() вместо get_option
 * - Добавлено кэширование рейтингов через транзиент (5 минут)
 * - Инвалидация кэша при добавлении отзыва (хук csc_invalidate_ratings_cache)
 * - wp_json_encode вместо json_encode для безопасности
 *
 * ДОБАВЛЕНО:
 * - csc_get_master_rating($mid) — рейтинг одного мастера
 * - csc_get_master_reviews($mid) — отзывы одного мастера
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. ПОДСЧЁТ СРЕДНИХ ОЦЕНОК МАСТЕРОВ (С КЭШИРОВАНИЕМ)
// ============================================================
if (!function_exists('csc_get_master_ratings_data')) {
    function csc_get_master_ratings_data($force_refresh = false) {
        $cache_key = 'csc_master_ratings_cache';
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $all_orders = csc_get_all_orders();
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        $ratings_data = [];
        foreach ($masters as $mid => $m) {
            $ratings_data[$mid] = ['total_score' => 0, 'reviews_count' => 0, 'avg' => 0];
        }
        foreach ($all_orders as $order) {
            $mid = $order['master_id'] ?? null;
            if ($mid && isset($order['review']['rating']) && isset($ratings_data[$mid])) {
                $ratings_data[$mid]['total_score'] += (int)$order['review']['rating'];
                $ratings_data[$mid]['reviews_count'] += 1;
            }
        }
        foreach ($ratings_data as $mid => &$data) {
            if ($data['reviews_count'] > 0) {
                $data['avg'] = round($data['total_score'] / $data['reviews_count'], 1);
            }
        }
        unset($data);
        set_transient($cache_key, $ratings_data, 5 * MINUTE_IN_SECONDS);
        return $ratings_data;
    }
}

// ============================================================
// 1.1 РЕЙТИНГ ОДНОГО МАСТЕРА
// ============================================================
/**
 * Получить рейтинг одного мастера.
 *
 * @param string $mid ID мастера
 * @return array ['avg' => float, 'count' => int, 'total_score' => int]
 */
if (!function_exists('csc_get_master_rating')) {
    function csc_get_master_rating($mid) {
        if (empty($mid)) {
            return ['avg' => 0, 'count' => 0, 'total_score' => 0];
        }
        $ratings = csc_get_master_ratings_data();
        if (isset($ratings[$mid])) {
            return [
                'avg'         => $ratings[$mid]['avg'] ?? 0,
                'count'       => $ratings[$mid]['reviews_count'] ?? 0,
                'total_score' => $ratings[$mid]['total_score'] ?? 0,
            ];
        }
        return ['avg' => 0, 'count' => 0, 'total_score' => 0];
    }
}

// ============================================================
// 1.2 ОТЗЫВЫ ОДНОГО МАСТЕРА
// ============================================================
/**
 * Получить отзывы одного мастера из заказов.
 *
 * @param string $mid ID мастера
 * @param int    $limit Максимум отзывов
 * @return array Массив отзывов
 */
if (!function_exists('csc_get_master_reviews')) {
    function csc_get_master_reviews($mid, $limit = 50) {
        if (empty($mid)) return [];

        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        $reviews = [];

        foreach ($all_orders as $order) {
            if (($order['master_id'] ?? '') !== $mid) continue;
            if (empty($order['review']) || !is_array($order['review'])) continue;

            $rating = intval($order['review']['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) continue;

            $reviews[] = [
                'order_number' => $order['order_number'] ?? '',
                'rating'       => $rating,
                'comment'      => $order['review']['comment'] ?? '',
                'date'         => $order['review']['date'] ?? $order['completed_at'] ?? $order['created_at'] ?? '',
                'client_name'  => $order['name'] ?? 'Клиент',
				'client_phone' => $order['phone'] ?? '',
                'total'        => floatval($order['total'] ?? 0),
                'services'     => $order['services_for_master'] ?? $order['services'] ?? [],
				'photos'       => $order['review']['photos'] ?? [],
            ];

            if (count($reviews) >= $limit) break;
        }

        // Новые сверху
        usort($reviews, function($a, $b) {
            return strtotime($b['date'] ?? '') - strtotime($a['date'] ?? '');
        });

        return $reviews;
    }
}

// ============================================================
// 2. ИНВАЛИДАЦИЯ КЭША
// ============================================================
if (!function_exists('csc_invalidate_ratings_cache')) {
    function csc_invalidate_ratings_cache() {
        delete_transient('csc_master_ratings_cache');
        if (function_exists('csc_cache_delete_masters')) {
            csc_cache_delete_masters();
        } else {
            wp_cache_delete('csc_masters_list_v2');
            wp_cache_delete('csc_masters_tg_v2');
            wp_cache_delete('csc_masters_ratings_v2');
        }
    }
}
add_action('csc_invalidate_ratings_cache', 'csc_invalidate_ratings_cache');

// ============================================================
// 3. СОРТИРОВКА МАСТЕРОВ ДЛЯ МАРШРУТИЗАЦИИ
// ============================================================
if (!function_exists('csc_get_masters_sorted_by_rating')) {
    function csc_get_masters_sorted_by_rating() {
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        if (empty($masters)) return [];
        $ratings = csc_get_master_ratings_data();
        foreach ($masters as $mid => &$m) {
            $m['avg_rating'] = $ratings[$mid]['avg'] ?? 0;
            $m['total_reviews'] = $ratings[$mid]['reviews_count'] ?? 0;
        }
        unset($m);
        uasort($masters, function($a, $b) {
            $rating_cmp = ($b['avg_rating'] ?? 0) <=> ($a['avg_rating'] ?? 0);
            if ($rating_cmp !== 0) return $rating_cmp;
            return ($b['total_reviews'] ?? 0) <=> ($a['total_reviews'] ?? 0);
        });
        return $masters;
    }
}

// ============================================================
// 4. ВНЕДРЕНИЕ СТОЛБЦА "РЕЙТИНГ" В ТАБЛИЦУ АДМИНКИ
// ============================================================
add_action('admin_footer', 'csc_inject_ratings_into_masters_table');
if (!function_exists('csc_inject_ratings_into_masters_table')) {
    function csc_inject_ratings_into_masters_table() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'csc_masters_admin') return;
        $ratings = csc_get_master_ratings_data();
        $ratings_json = wp_json_encode($ratings, JSON_UNESCAPED_UNICODE);
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ratingsData = <?php echo $ratings_json; ?>;
            var table = document.querySelector('.wp-list-table');
            if (!table) return;
            var theadTr = table.querySelector('thead tr');
            if (theadTr) {
                var th = document.createElement('th');
                th.style.width = '12%';
                th.textContent = '⭐ Рейтинг';
                if (theadTr.children.length > 4) {
                    theadTr.insertBefore(th, theadTr.children[4]);
                } else {
                    theadTr.appendChild(th);
                }
            }
            var tbodyTrs = table.querySelectorAll('tbody tr');
            tbodyTrs.forEach(function(tr) {
                var idInput = tr.querySelector('input[name*="[id]"]');
                if (idInput) {
                    var masterId = idInput.value;
                    var ratingHtml = '<span style="color:#94a3b8; font-size:12px;">Нет оценок</span>';
                    if (ratingsData[masterId] && ratingsData[masterId].reviews_count > 0) {
                        var avg = ratingsData[masterId].avg;
                        var count = ratingsData[masterId].reviews_count;
                        var color = avg >= 4.5 ? '#10b981' : (avg >= 3.5 ? '#f59e0b' : '#ef4444');
                        ratingHtml = '<strong style="color:' + color + '; font-size:15px;">' + avg + '</strong> <small>(' + count + ' отз.)</small>';
                    }
                    var td = document.createElement('td');
                    td.innerHTML = ratingHtml;
                    if (tr.children.length > 4) {
                        tr.insertBefore(td, tr.children[4]);
                    } else {
                        tr.appendChild(td);
                    }
                } else if (tr.children.length === 1) {
                    tr.children[0].colSpan = 9;
                }
            });
            var originalAddFn = window.cscAddNewMasterRow;
            if (typeof originalAddFn === 'function') {
                window.cscAddNewMasterRow = function() {
                    originalAddFn();
                    var newRow = table.querySelector('tbody').lastElementChild;
                    if (newRow) {
                        var td = document.createElement('td');
                        td.innerHTML = '<span style="color:#94a3b8; font-size:12px;">Новый</span>';
                        if (newRow.children.length > 4) {
                            newRow.insertBefore(td, newRow.children[4]);
                        } else {
                            newRow.appendChild(td);
                        }
                    }
                };
            }
        });
        </script>
        <?php
    }
}