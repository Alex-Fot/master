<?php
if (!defined('ABSPATH')) exit;

function csc_render_reviews_admin_page() {
    // Обработка удаления отзыва
    if (isset($_GET['action']) && $_GET['action'] === 'delete_review' && isset($_GET['order_number'])) {
        $order_num = sanitize_text_field($_GET['order_number']);
        if (check_admin_referer('csc_delete_review_' . $order_num)) {
            $all_orders = csc_get_all_orders();
            if (isset($all_orders[$order_num])) {
                unset($all_orders[$order_num]['review']);
                csc_update_order($order_num, $all_orders[$order_num]);
                csc_cache_delete_orders();
                if (function_exists('csc_invalidate_ratings_cache')) csc_invalidate_ratings_cache();
                echo '<div class="notice notice-success is-dismissible"><p>Отзыв успешно удален.</p></div>';
            }
        }
    }

    // Фильтры
    $search = sanitize_text_field($_GET['s'] ?? '');
    $filter_rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
    $filter_master = sanitize_text_field($_GET['master_id'] ?? '');
    $filter_has_photos = !empty($_GET['has_photos']);
    $date_from = sanitize_text_field($_GET['date_from'] ?? '');
    $date_to = sanitize_text_field($_GET['date_to'] ?? '');
    
    $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
    $all_orders = csc_get_all_orders();
    
    $reviews_list = [];
    foreach ($all_orders as $order_num => $order) {
        if (empty($order['review']) || empty($order['review']['rating'])) continue;
        
        $review = $order['review'];
        $rating = intval($review['rating']);
        $comment = $review['comment'] ?? '';
        $date = $review['date'] ?? $order['completed_at'] ?? $order['created_at'] ?? '';
        $photos = $review['photos'] ?? [];
        $master_id = $order['master_id'] ?? '';
        $client_name = $order['name'] ?? '';
        $client_phone = $order['phone'] ?? '';
        
        if ($filter_rating > 0 && $rating !== $filter_rating) continue;
        if ($filter_master && $master_id !== $filter_master) continue;
        if ($filter_has_photos && empty($photos)) continue;
        if ($date_from && $date && strtotime($date) < strtotime($date_from)) continue;
        if ($date_to && $date && strtotime($date) > strtotime($date_to . ' 23:59:59')) continue;
        
        if ($search) {
            $search_lower = mb_strtolower($search);
            $haystack = mb_strtolower($order_num . ' ' . $client_name . ' ' . $client_phone . ' ' . $comment);
            if (mb_strpos($haystack, $search_lower) === false) continue;
        }
        
        $reviews_list[] = [
            'order_number' => $order_num,
            'order' => $order,
            'review' => $review,
            'master_id' => $master_id,
            'client_name' => $client_name,
            'client_phone' => $client_phone,
            'date_ts' => strtotime($date) ?: 0
        ];
    }
    
    usort($reviews_list, function($a, $b) { return $b['date_ts'] - $a['date_ts']; });
    
    $per_page = 20;
    $current_page = max(1, intval($_GET['paged'] ?? 1));
    $total_items = count($reviews_list);
    $total_pages = max(1, ceil($total_items / $per_page));
    if ($current_page > $total_pages) $current_page = $total_pages;
    $offset = ($current_page - 1) * $per_page;
    $reviews_on_page = array_slice($reviews_list, $offset, $per_page, true);
    
    $base_url = esc_url(add_query_arg(['page' => 'csc_reviews_admin'], admin_url('options-general.php')));
    ?>
    <div class="wrap">
        <h1>⭐ Управление отзывами <span class="title-count" style="color:#64748b; font-size:14px; font-weight:normal;">(<?php echo esc_html($total_items); ?> всего)</span></h1>
        
        <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e2e8f0;">
            <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="page" value="csc_reviews_admin">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Поиск (№, имя, телефон, текст)</label>
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" style="width: 200px;" placeholder="Поиск...">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Оценка</label>
                    <select name="rating" style="width: 120px;">
                        <option value="0">Все</option>
                        <?php for ($i=5; $i>=1; $i--): ?>
                            <option value="<?php echo $i; ?>" <?php selected($filter_rating, $i); ?>><?php echo $i; ?> ⭐</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Мастер</label>
                    <select name="master_id" style="width: 180px;">
                        <option value="">Все мастера</option>
                        <?php foreach ($masters as $mid => $m): ?>
                            <option value="<?php echo esc_attr($mid); ?>" <?php selected($filter_master, $mid); ?>><?php echo esc_html($m['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Дата с</label>
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Дата по</label>
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                </div>
                <div style="display:flex; align-items:center; gap:5px; padding-bottom:4px;">
                    <label><input type="checkbox" name="has_photos" value="1" <?php checked($filter_has_photos); ?>> Только с фото</label>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="button button-primary">Фильтровать</button>
                    <a href="<?php echo $base_url; ?>" class="button">Сбросить</a>
                </div>
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 130px;">Дата / Заказ</th>
                    <th style="width: 15%;">Клиент</th>
                    <th style="width: 15%;">Мастер</th>
                    <th style="width: 80px;">Оценка</th>
                    <th>Комментарий и Фото</th>
                    <th style="width: 120px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reviews_on_page)): ?>
                    <?php foreach ($reviews_on_page as $item): 
                        $r = $item['review'];
                        $o = $item['order'];
                        $m_name = isset($masters[$item['master_id']]) ? $masters[$item['master_id']]['name'] : 'Не назначен';
                        $stars = str_repeat('⭐', intval($r['rating']));
                        $photos = $r['photos'] ?? [];
                    ?>
                    <tr>
                        <td>
                            <strong>#<?php echo esc_html($item['order_number']); ?></strong><br>
                            <small><?php echo esc_html(date('d.m.Y H:i', $item['date_ts'])); ?></small>
                        </td>
                        <td>
                            <?php echo esc_html($item['client_name']); ?><br>
                            <small style="color:#64748b;"><?php echo esc_html($item['client_phone']); ?></small>
                        </td>
                        <td><?php echo esc_html($m_name); ?></td>
                        <td style="font-size:14px; letter-spacing:1px;"><?php echo $stars; ?></td>
                        <td>
                            <div style="margin-bottom:8px; color:#334155;"><?php echo esc_html($r['comment'] ?? ''); ?></div>
                            <?php if (!empty($photos)): ?>
                                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                    <?php foreach ($photos as $pIdx => $p): 
                                        $url = is_array($p) ? ($p['url'] ?? '') : $p;
                                        // Формируем массив URL для JS
                                        $urls_js = wp_json_encode(array_map(function($x){ return is_array($x) ? ($x['url'] ?? '') : $x; }, $photos));
                                    ?>
                                        <img src="<?php echo esc_url($url); ?>" class="csc-review-photo-thumb" 
                                             onclick="window.CSC_Lightbox.open(<?php echo esc_attr($urls_js); ?>, <?php echo $pIdx; ?>)" alt="Фото">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('options-general.php?page=csc_orders_admin&view=list')); ?>" class="button button-small" style="margin-bottom:4px; width:100%;">К заказу</a>
                            <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'delete_review', 'order_number' => $item['order_number']], $base_url), 'csc_delete_review_' . $item['order_number']); ?>" 
                               class="button button-small button-link-delete" 
                               onclick="return confirm('Удалить этот отзыв? Это действие необратимо.');" 
                               style="width:100%; text-align:center; color:#dc3232;">Удалить</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">Отзывы не найдены.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav" style="margin-top: 20px;">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo esc_html($total_items); ?> элемент(ов)</span>
                    <?php
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%', $base_url),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'prev_text' => '« Назад',
                        'next_text' => 'Вперёд »',
                        'type'      => 'plain',
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}