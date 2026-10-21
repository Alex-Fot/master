<?php
/**
 * Файл: csc-analytics.php
 * Описание: Production-дашборд аналитики с кэшированием (Transients), защитой от XSS и DDoS.
 * ВАЖНО: Обработчик главной аналитики (csc_get_analytics_data) находится в другом файле плагина!
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ: Валидация дат и защита от инъекций
// ============================================================
function csc_validate_date_range($start, $end) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        return false;
    }
    if (strtotime($start) > strtotime($end)) {
        return false;
    }
    // Ограничиваем максимальный период 5 годами, чтобы предотвратить зависание PHP
    if ((strtotime($end) - strtotime($start)) > (5 * 365 * 24 * 60 * 60)) {
        return false;
    }
    return true;
}

// ============================================================
// 2. AJAX ОБРАБОТЧИК ДЛЯ ТАБЛИЦЫ КОНВЕРСИИ МАСТЕРОВ (С КЭШЕМ)
// ============================================================
add_action('wp_ajax_csc_get_conversion_data', 'csc_get_conversion_data_handler');

function csc_get_conversion_data_handler() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
    csc_verify_ajax_nonce('csc_analytics_nonce', '_ajax_nonce');

    $start_date = sanitize_text_field($_POST['start_date'] ?? date('Y-m-01'));
    $end_date = sanitize_text_field($_POST['end_date'] ?? date('Y-m-t'));

    if (!csc_validate_date_range($start_date, $end_date)) {
        wp_send_json_error(['message' => 'Некорректный период дат']);
    }

    // [PRODUCTION] Кэширование через Transients (ускорение в 100+ раз при повторных запросах)
    $cache_key = 'csc_conv_' . md5($start_date . $end_date);
    $cached_result = get_transient($cache_key);
    if ($cached_result !== false) {
        wp_send_json_success($cached_result);
    }

    // get_option('csc_all_orders', []) возвращает пустоту после миграции в таблицу
    $all_orders = function_exists('csc_get_all_orders')
        ? csc_get_all_orders()
        : get_option('csc_all_orders', []);
    $masters_list = function_exists('csc_get_masters') ? csc_get_masters() : [];

    $stats = [];
    foreach ($masters_list as $mid => $m) {
        if ($mid === 'master_default') continue;
        $stats[$mid] = ['name' => $m['name'], 'offered' => 0, 'accepted' => 0, 'rejected' => 0];
    }

    foreach ($all_orders as $order) {
        $o_date = $order['date'] ?? '';
        if (!$o_date || $o_date < $start_date || $o_date > $end_date) continue;

        $accepted_by = $order['accepted_by'] ?? null;
        $rejected_by = $order['rejected_by'] ?? [];

        if ($accepted_by && is_scalar($accepted_by) && isset($stats[(string)$accepted_by])) {
            $mid = (string)$accepted_by;
            $stats[$mid]['accepted']++;
            $stats[$mid]['offered']++;
        }

        if (is_array($rejected_by)) {
            foreach ($rejected_by as $rmid) {
                if (is_scalar($rmid) && isset($stats[(string)$rmid])) {
                    $mid = (string)$rmid;
                    $stats[$mid]['rejected']++;
                    $stats[$mid]['offered']++;
                }
            }
        }
    }

    $result = [];
    foreach ($stats as $mid => $data) {
        if ($data['offered'] == 0) continue;
        $data['conversion'] = round(($data['accepted'] / $data['offered']) * 100, 1);
        $result[] = $data;
    }

    usort($result, function($a, $b) { return $b['conversion'] <=> $a['conversion']; });
    
    // Сохраняем в кэш на 15 минут
    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    wp_send_json_success($result);
}

// ============================================================
// 3. AJAX: ДАННЫЕ ДЛЯ КАРТЫ ПОКРЫТИЯ ЗОН (С КЭШЕМ)
// ============================================================
add_action('wp_ajax_csc_get_zones_coverage_data', 'csc_get_zones_coverage_data_handler');

function csc_get_zones_coverage_data_handler() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Доступ запрещен']);
    csc_verify_ajax_nonce('csc_analytics_nonce', '_ajax_nonce');
    if (!function_exists('csc_get_cities')) wp_send_json_error(['message' => 'Функция csc_get_cities не найдена']);
    
    // Кэш карты на 1 час (данные городов меняются редко)
    $map_cache_key = 'csc_map_coverage_v2';
    $cached_map = get_transient($map_cache_key);
    if ($cached_map !== false) {
        wp_send_json_success($cached_map);
    }

    $cities = csc_get_cities();
    $coverage = [];
    
    foreach ($cities as $city_key => $city) {
        $lat = $city['lat'] ?? null;
        $lng = $city['lng'] ?? null;
        if ($lat === null || $lng === null) continue;
        
        $zones = [];
        $active_zones_count = 0;
        
        if (isset($city['zones']) && is_array($city['zones'])) {
            foreach ($city['zones'] as $zone_id => $zone) {
                $is_active = !empty($zone['active']);
                if ($is_active) $active_zones_count++;
                $zones[] = [
                    'id' => $zone_id, 'name' => $zone['name'] ?? '',
                    'price_multiplier' => floatval($zone['price_multiplier'] ?? 1.0),
                    'active' => $is_active, 'sort_order' => $zone['sort_order'] ?? 0,
                ];
            }
            usort($zones, function($a, $b) { return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0); });
        }
        // ИСПРАВЛЕНО: Добавляем смещение для зон, чтобы они не рисовались в одной точке
        // Каждая зона получает небольшое смещение от центра города
        $zone_index = 0;
        foreach ($city['zones'] as $zone_id => $zone) {
            $zone_lat = $lat;
            $zone_lng = $lng;

            // Смещение для визуального разделения зон на карте
            // Используем детерминированное смещение на основе индекса зоны
            $zone_index++;
            $offset_angle = ($zone_index - 1) * (2 * M_PI / max(1, count($city['zones'])));
            $offset_radius = 0.01 * $zone_index; // ~1 км на зону
            $zone_lat = $lat + ($offset_radius * cos($offset_angle));
            $zone_lng = $lng + ($offset_radius * sin($offset_angle));

            $coverage[] = [
                'key' => $city_key,
                'name' => $city['name'] ?? '',
                'subdomain' => $city['subdomain'] ?? '',
                'timezone' => $city['timezone'] ?? '',
                'active' => !empty($city['active']),
                'zone_limit' => intval($city['zone_limit'] ?? 0),
                'lat' => floatval($zone_lat),
                'lng' => floatval($zone_lng),
                'zones' => [
                    [
                        'id' => $zone_id,
                        'name' => $zone['name'] ?? '',
                        'price_multiplier' => floatval($zone['price_multiplier'] ?? 1.0),
                        'active' => !empty($zone['active']),
                        'sort_order' => $zone['sort_order'] ?? 0,
                    ]
                ],
                'total_zones' => 1,
                'active_zones' => !empty($zone['active']) ? 1 : 0,
            ];
        }

        // Если зон нет, добавляем город без зон
        if (empty($city['zones'])) {
            $coverage[] = [
                'key' => $city_key,
                'name' => $city['name'] ?? '',
                'subdomain' => $city['subdomain'] ?? '',
                'timezone' => $city['timezone'] ?? '',
                'active' => !empty($city['active']),
                'zone_limit' => intval($city['zone_limit'] ?? 0),
                'lat' => floatval($lat),
                'lng' => floatval($lng),
                'zones' => [],
                'total_zones' => 0,
                'active_zones' => 0,
            ];
        }
	}
    
    $response_data = ['cities' => $coverage, 'total_cities' => count($coverage)];
    set_transient($map_cache_key, $response_data, HOUR_IN_SECONDS);
    wp_send_json_success($response_data);
}
// ============================================================
// 3. ГЛАВНАЯ ФУНКЦИЯ ОТРИСОВКИ СТРАНИЦЫ
// ============================================================
function csc_render_analytics_page() {
    // Подключение внешних библиотек
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
    wp_enqueue_script('sheetjs', 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', [], '0.18.5', true);
    wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
    wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

    $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
    $default_start = date('Y-m-01');
    $default_end = date('Y-m-t');

    // Формируем карту мастеров и передаем в JS
    $masters_map = [];
    foreach ($masters as $mid => $m) {
        $masters_map[$mid] = $m['name'];
    }

    // Конфигурация для JS (nonce, admin_url, маппинг имен)
    echo '<script>window.CSC_ANALYTICS_CONFIG={"masters_map":' . wp_json_encode($masters_map) . ',"admin_url":"' . esc_url(admin_url('admin-ajax.php')) . '","nonce":"' . esc_js(wp_create_nonce('csc_analytics_nonce')) . '"};</script>';
?>
<style>
/* ============================================================
CSS VARIABLES & BASE
============================================================ */
:root {
    --csc-dash-bg: #f3f4f6; --csc-dash-card: #ffffff; --csc-dash-text: #1e293b;
    --csc-dash-text-muted: #64748b; --csc-dash-border: #e2e8f0; --csc-dash-primary: #3b82f6;
    --csc-dash-success: #10b981; --csc-dash-warning: #f59e0b; --csc-dash-danger: #ef4444;
    --csc-dash-radius: 12px;
    --csc-dash-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}
.csc-analytics-wrapper { margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--csc-dash-text); }
.csc-analytics-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.csc-analytics-title { font-size: 24px; font-weight: 700; margin: 0; }

/* ============================================================
FILTERS PANEL
============================================================ */
.csc-filters-panel { background: var(--csc-dash-card); border-radius: var(--csc-dash-radius); padding: 20px; box-shadow: var(--csc-dash-shadow); margin-bottom: 24px; display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; border: 1px solid var(--csc-dash-border); }
.csc-filter-group { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 180px; }
.csc-filter-label { font-size: 13px; font-weight: 600; color: var(--csc-dash-text-muted); text-transform: uppercase; }
.csc-filter-input, .csc-coverage-map-controls select, .csc-coverage-map-controls input[type="text"], .csc-export-modal-input { padding: 10px 12px; border: 1px solid var(--csc-dash-border); border-radius: 6px; font-size: 14px; color: var(--csc-dash-text); background: #f8fafc; transition: all 0.2s; width: 100%; box-sizing: border-box; }
.csc-filter-input:focus, .csc-coverage-map-controls select:focus, .csc-coverage-map-controls input[type="text"]:focus, .csc-export-modal-input:focus { outline: none; border-color: var(--csc-dash-primary); background: #fff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
.csc-coverage-map-controls select { width: auto; min-width: 140px; }
.csc-coverage-map-controls input[type="text"] { width: 180px; }

.csc-btn-apply, .csc-btn-apply:disabled { background: var(--csc-dash-primary); color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; font-size: 14px; cursor: pointer; transition: background 0.2s, opacity 0.2s; height: 40px; display: flex; align-items: center; justify-content: center; min-width: 120px; }
.csc-btn-apply:hover:not(:disabled) { background: #2563eb; }
.csc-btn-apply:disabled { opacity: 0.6; cursor: not-allowed; }

/* ============================================================
EXPORT BUTTON
============================================================ */
.csc-btn-export-excel { background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; cursor: pointer; height: 40px; display: flex; align-items: center; gap: 8px; transition: all 0.2s; }
.csc-btn-export-excel:hover:not(:disabled) { background: linear-gradient(135deg, #059669, #047857); transform: translateY(-1px); box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3); }
.csc-btn-export-excel:active { transform: translateY(0); }
.csc-btn-export-excel:disabled { opacity: 0.6; cursor: not-allowed; }

/* ============================================================
KPI CARDS
============================================================ */
.csc-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px; }
.csc-kpi-card { background: var(--csc-dash-card); border-radius: var(--csc-dash-radius); padding: 24px; box-shadow: var(--csc-dash-shadow); border: 1px solid var(--csc-dash-border); position: relative; overflow: hidden; }
.csc-kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--csc-dash-primary); }
.csc-kpi-card.success::before { background: var(--csc-dash-success); }
.csc-kpi-card.warning::before { background: var(--csc-dash-warning); }
.csc-kpi-card.danger::before { background: var(--csc-dash-danger); }
.csc-kpi-title { font-size: 14px; font-weight: 600; color: var(--csc-dash-text-muted); margin-bottom: 8px; }
.csc-kpi-value { font-size: 32px; font-weight: 800; color: var(--csc-dash-text); line-height: 1.2; }
.csc-kpi-subtext { font-size: 13px; color: var(--csc-dash-text-muted); margin-top: 4px; }

/* ============================================================
CHARTS GRID
============================================================ */
.csc-charts-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-bottom: 24px; }
.csc-chart-card { background: var(--csc-dash-card); border-radius: var(--csc-dash-radius); padding: 24px; box-shadow: var(--csc-dash-shadow); border: 1px solid var(--csc-dash-border); min-height: 350px; position: relative; }
.csc-chart-card.full-width { grid-column: 1 / -1; }
.csc-chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--csc-dash-border); padding-bottom: 12px; }
.csc-chart-title { font-size: 16px; font-weight: 700; margin: 0; }
.csc-chart-container { position: relative; height: 300px; width: 100%; }

/* ============================================================
TABLE & BADGES
============================================================ */
.csc-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
.csc-table th { padding: 12px 16px; border-bottom: 2px solid var(--csc-dash-border); color: var(--csc-dash-text-muted); font-weight: 600; }
.csc-table td { padding: 14px 16px; border-bottom: 1px solid var(--csc-dash-border); vertical-align: middle; }
.csc-table tbody tr:hover { background-color: #f8fafc; }
.csc-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; display: inline-block; }
.csc-badge.green { background: #d1fae5; color: #065f46; }
.csc-badge.yellow { background: #fef3c7; color: #92400e; }
.csc-badge.red { background: #fee2e2; color: #991b1b; }
.csc-table-empty { text-align: center; padding: 30px; color: var(--csc-dash-text-muted); }

/* ============================================================
LOADERS & SPINNERS
============================================================ */
.csc-loader-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255, 255, 255, 0.95); display: flex; justify-content: center; align-items: center; z-index: 10; border-radius: inherit; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
@supports ((-webkit-backdrop-filter: blur(2px)) or (backdrop-filter: blur(2px))) { .csc-loader-overlay { background: rgba(255, 255, 255, 0.8); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px); } }
.csc-loader-overlay.active { opacity: 1; pointer-events: all; }
.csc-spinner { width: 40px; height: 40px; border: 4px solid var(--csc-dash-border); border-top: 4px solid var(--csc-dash-primary); border-radius: 50%; animation: csc-spin 1s linear infinite; }
@keyframes csc-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

/* ============================================================
TOAST NOTIFICATIONS
============================================================ */
.csc-toast { position: fixed; bottom: 24px; right: 24px; background: #1e293b; color: #fff; padding: 14px 20px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); z-index: 9999; font-size: 14px; max-width: 400px; opacity: 0; transform: translateY(10px); transition: opacity 0.3s, transform 0.3s; pointer-events: none; }
.csc-toast.show { opacity: 1; transform: translateY(0); pointer-events: all; }
.csc-toast.error { background: #dc2626; }
.csc-toast.success { background: #059669; }

/* ============================================================
COVERAGE MAP
============================================================ */
.csc-coverage-map-section { background: var(--csc-dash-card); border-radius: var(--csc-dash-radius); padding: 24px; box-shadow: var(--csc-dash-shadow); border: 1px solid var(--csc-dash-border); margin-bottom: 24px; position: relative; }
.csc-coverage-map-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--csc-dash-border); flex-wrap: wrap; gap: 12px; }
.csc-coverage-map-title { font-size: 18px; font-weight: 700; margin: 0; }
.csc-coverage-map-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.csc-coverage-map-btn { background: var(--csc-dash-primary); color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.csc-coverage-map-btn:hover { background: #2563eb; }
.csc-coverage-map-btn.secondary { background: #f1f5f9; color: var(--csc-dash-text); border: 1px solid var(--csc-dash-border); }
.csc-coverage-map-btn.secondary:hover { background: #e2e8f0; }
.csc-coverage-map-wrapper { position: relative; border-radius: 10px; overflow: hidden; border: 2px solid var(--csc-dash-border); }
#csc-coverage-map { width: 100%; height: 600px; z-index: 1; }
.csc-coverage-legend { margin-top: 16px; padding: 12px 16px; background: #f8fafc; border-radius: 8px; border: 1px solid var(--csc-dash-border); display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
.csc-coverage-legend-title { font-size: 13px; font-weight: 600; color: var(--csc-dash-text-muted); margin-right: 8px; }
.csc-coverage-legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; padding: 4px 8px; border-radius: 4px; }
.csc-coverage-legend-item.dimmed { opacity: 0.4; }
.csc-coverage-legend-color { width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.1); }
.csc-coverage-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-top: 16px; }
.csc-coverage-stat { background: #f8fafc; border: 1px solid var(--csc-dash-border); border-radius: 8px; padding: 12px; text-align: center; }
.csc-coverage-stat-value { font-size: 22px; font-weight: 700; color: var(--csc-dash-text); }
.csc-coverage-stat-label { font-size: 12px; color: var(--csc-dash-text-muted); margin-top: 2px; }
.csc-coverage-popup-title { font-size: 15px; font-weight: 700; margin: 0 0 6px 0; color: #1e293b; }
.csc-coverage-popup-meta { font-size: 12px; color: #64748b; margin: 3px 0; }

/* ============================================================
EXPORT MODAL (УМНЫЙ ЭКСПОРТ)
============================================================ */
.csc-export-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 10000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.25s; }
.csc-export-modal-overlay.active { display: flex; opacity: 1; }
.csc-export-modal { background: var(--csc-dash-card); border-radius: var(--csc-dash-radius); padding: 32px; max-width: 540px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); transform: translateY(20px); transition: transform 0.3s; }
.csc-export-modal-overlay.active .csc-export-modal { transform: translateY(0); }
.csc-export-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--csc-dash-border); padding-bottom: 16px; }
.csc-export-modal-title { font-size: 20px; font-weight: 700; margin: 0; }
.csc-export-modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--csc-dash-text-muted); padding: 4px; line-height: 1; }
.csc-export-modal-close:hover { color: var(--csc-dash-text); }

.csc-export-modal-section { margin-bottom: 20px; }
.csc-export-modal-label { display: block; font-size: 13px; font-weight: 600; color: var(--csc-dash-text-muted); text-transform: uppercase; margin-bottom: 10px; }
.csc-export-period-options { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.csc-export-period-option { position: relative; }
.csc-export-period-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.csc-export-period-option label { display: block; padding: 14px 16px; border: 2px solid var(--csc-dash-border); border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; text-align: center; transition: all 0.2s; background: #f8fafc; }
.csc-export-period-option label:hover { border-color: var(--csc-dash-primary); background: #eff6ff; }
.csc-export-period-option input[type="radio"]:checked + label { border-color: var(--csc-dash-primary); background: #dbeafe; color: #1e40af; font-weight: 700; box-shadow: 0 2px 6px rgba(59, 130, 246, 0.2); }

.csc-export-custom-range { display: none; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--csc-dash-border); }
.csc-export-custom-range.active { display: grid; }
.csc-export-modal-checkboxes { display: flex; flex-direction: column; gap: 10px; }
.csc-export-modal-checkbox { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; }
.csc-export-modal-checkbox input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: var(--csc-dash-primary); }

.csc-export-modal-footer { display: flex; gap: 12px; justify-content: flex-end; margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--csc-dash-border); }
.csc-export-modal-btn { padding: 10px 24px; border-radius: 6px; font-weight: 600; font-size: 14px; cursor: pointer; border: none; transition: all 0.2s; height: 40px; }
.csc-export-modal-btn.cancel { background: #f1f5f9; color: var(--csc-dash-text); border: 1px solid var(--csc-dash-border); }
.csc-export-modal-btn.cancel:hover { background: #e2e8f0; }
.csc-export-modal-btn.confirm { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
.csc-export-modal-btn.confirm:hover { background: linear-gradient(135deg, #059669, #047857); }
.csc-export-modal-btn.confirm:disabled { opacity: 0.6; cursor: not-allowed; }

/* ============================================================
RESPONSIVE
============================================================ */
@media (max-width: 1024px) { .csc-charts-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) { 
    .csc-filters-panel { flex-direction: column; align-items: stretch; } 
    .csc-btn-apply { width: 100%; } 
    .csc-export-period-options { grid-template-columns: 1fr; }
    .csc-export-modal { padding: 24px 20px; }
}
</style>

<!-- Toast контейнер для уведомлений -->
<div class="csc-toast" id="csc-toast"></div>

<!-- МОДАЛЬНОЕ ОКНО ЭКСПОРТА -->
<div class="csc-export-modal-overlay" id="csc-export-modal-overlay">
    <div class="csc-export-modal">
        <div class="csc-export-modal-header">
            <h2 class="csc-export-modal-title">📥 Экспорт отчета в Excel</h2>
            <button type="button" class="csc-export-modal-close" id="csc-export-modal-close-btn">&times;</button>
        </div>

        <div class="csc-export-modal-section">
            <label class="csc-export-modal-label">Выберите период отчета</label>
            <div class="csc-export-period-options">
                <div class="csc-export-period-option">
                    <input type="radio" name="export-period" id="export-period-30" value="30">
                    <label for="export-period-30">Последние 30 дней</label>
                </div>
                <div class="csc-export-period-option">
                    <input type="radio" name="export-period" id="export-period-90" value="90">
                    <label for="export-period-90">Последние 3 месяца</label>
                </div>
                <div class="csc-export-period-option">
                    <input type="radio" name="export-period" id="export-period-180" value="180">
                    <label for="export-period-180">Последние 6 месяцев</label>
                </div>
                <div class="csc-export-period-option">
                    <input type="radio" name="export-period" id="export-period-365" value="365">
                    <label for="export-period-365">Последний год</label>
                </div>
                <div class="csc-export-period-option">
                    <input type="radio" name="export-period" id="export-period-all" value="all" checked>
                    <label for="export-period-all">Всё время</label>
                </div>
                <div class="csc-export-period-option">
                    <input type="radio" name="export-period" id="export-period-custom" value="custom">
                    <label for="export-period-custom">Свой диапазон</label>
                </div>
            </div>

            <div class="csc-export-custom-range" id="csc-export-custom-range">
                <div>
                    <label class="csc-filter-label" style="margin-bottom: 6px; display: block;">С даты</label>
                    <input type="date" id="export-custom-start" class="csc-export-modal-input">
                </div>
                <div>
                    <label class="csc-filter-label" style="margin-bottom: 6px; display: block;">По дату</label>
                    <input type="date" id="export-custom-end" class="csc-export-modal-input">
                </div>
            </div>
        </div>

        <div class="csc-export-modal-section">
            <label class="csc-export-modal-label">Дополнительные фильтры</label>
            <div class="csc-export-modal-checkboxes">
                <label class="csc-export-modal-checkbox">
                    <input type="checkbox" id="export-filter-active-masters">
                    <span>Только активные мастера (с заказами)</span>
                </label>
                <label class="csc-export-modal-checkbox">
                    <input type="checkbox" id="export-filter-completed-only">
                    <span>Только выполненные заказы</span>
                </label>
                <label class="csc-export-modal-checkbox">
                    <input type="checkbox" id="export-include-details" checked>
                    <span>Детализация по дням (увеличивает размер файла)</span>
                </label>
            </div>
        </div>

        <div class="csc-export-modal-footer">
            <button type="button" class="csc-export-modal-btn cancel" id="csc-export-cancel-btn">Отмена</button>
            <button type="button" class="csc-export-modal-btn confirm" id="csc-export-confirm-btn">
                <span id="csc-export-confirm-label">📊 Сформировать отчет</span>
            </button>
        </div>
    </div>
</div>

<div class="csc-analytics-wrapper">
    <!-- ШАПКА -->
    <div class="csc-analytics-header">
        <h1 class="csc-analytics-title">📊 Аналитика и Отчеты</h1>
        <button type="button" class="csc-btn-export-excel" id="csc-export-excel-btn" title="Открыть настройки экспорта">
            <span>📥</span>
            <span>Экспорт в Excel</span>
        </button>
    </div>

    <!-- ПАНЕЛЬ ФИЛЬТРОВ -->
    <div class="csc-filters-panel" id="csc-filters">
        <div class="csc-filter-group">
            <label class="csc-filter-label" for="filter-date-start">Период ОТ</label>
            <input type="date" id="filter-date-start" class="csc-filter-input" value="<?php echo esc_attr($default_start); ?>">
        </div>
        <div class="csc-filter-group">
            <label class="csc-filter-label" for="filter-date-end">Период ДО</label>
            <input type="date" id="filter-date-end" class="csc-filter-input" value="<?php echo esc_attr($default_end); ?>">
        </div>
        <div class="csc-filter-group">
            <label class="csc-filter-label" for="filter-master">Мастер</label>
            <select id="filter-master" class="csc-filter-input">
                <option value="all">Все мастера</option>
                <?php foreach ($masters as $mid => $m): ?>
                    <option value="<?php echo esc_attr($mid); ?>"><?php echo esc_html($m['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="csc-filter-group">
            <label class="csc-filter-label" for="filter-status">Статус заказа</label>
            <select id="filter-status" class="csc-filter-input">
                <option value="all">Все статусы (кроме отмененных)</option>
                <option value="выполнено">Только Выполненные (Фактическая выручка)</option>
                <option value="в работе">В работе</option>
                <option value="ожидает">Ожидают подтверждения / оплаты</option>
                <option value="отменено">Отмененные</option>
            </select>
        </div>
        <button type="button" class="csc-btn-apply" id="csc-apply-filters-btn">Применить</button>
    </div>

    <!-- KPI КАРТОЧКИ -->
    <div class="csc-kpi-grid">
        <div class="csc-kpi-card success"><div class="csc-loader-overlay" id="loader-kpi-1"><div class="csc-spinner"></div></div><div class="csc-kpi-title">Общая Выручка</div><div class="csc-kpi-value" id="kpi-revenue">0 ₽</div><div class="csc-kpi-subtext" id="kpi-revenue-sub">За выбранный период</div></div>
        <div class="csc-kpi-card"><div class="csc-loader-overlay" id="loader-kpi-2"><div class="csc-spinner"></div></div><div class="csc-kpi-title">Количество Заказов</div><div class="csc-kpi-value" id="kpi-orders">0</div><div class="csc-kpi-subtext">Успешных заявок</div></div>
        <div class="csc-kpi-card warning"><div class="csc-loader-overlay" id="loader-kpi-3"><div class="csc-spinner"></div></div><div class="csc-kpi-title">Средний Чек</div><div class="csc-kpi-value" id="kpi-avg-receipt">0 ₽</div><div class="csc-kpi-subtext">Сумма / Кол-во заказов</div></div>
        <div class="csc-kpi-card danger"><div class="csc-loader-overlay" id="loader-kpi-4"><div class="csc-spinner"></div></div><div class="csc-kpi-title">Отменено заказов</div><div class="csc-kpi-value" id="kpi-cancelled">0</div><div class="csc-kpi-subtext">Потерянная выгода: <span id="kpi-lost-revenue">0 ₽</span></div></div>
        <div class="csc-kpi-card" style="border-left-color: #8b5cf6;"><div class="csc-loader-overlay" id="loader-kpi-5"><div class="csc-spinner"></div></div><div class="csc-kpi-title">Средний Рейтинг</div><div class="csc-kpi-value" id="kpi-rating">0.0 ⭐</div><div class="csc-kpi-subtext">По отзывам клиентов</div></div>
    </div>

    <!-- ТАБЛИЦА КОНВЕРСИИ -->
    <div class="csc-chart-card full-width" style="height: auto; min-height: 200px; margin-bottom: 24px;">
        <div class="csc-loader-overlay" id="loader-conversion"><div class="csc-spinner"></div></div>
        <div class="csc-chart-header"><h3 class="csc-chart-title">🎯 Эффективность диспетчеризации (Конверсия мастеров)</h3></div>
        <div style="overflow-x: auto;"><table class="csc-table"><thead><tr><th>Сотрудник</th><th style="text-align: center;">Предложено заявок</th><th style="text-align: center;">Принято (✅)</th><th style="text-align: center;">Отклонено (❌)</th><th style="text-align: center;">% Конверсии</th></tr></thead><tbody id="conversion-table-body"><tr><td colspan="5" class="csc-table-empty">Загрузка данных...</td></tr></tbody></table></div>
    </div>

    <!-- СЕТКА ГРАФИКОВ -->
    <div class="csc-charts-grid">
        <div class="csc-chart-card full-width"><div class="csc-loader-overlay" id="loader-chart-trend"><div class="csc-spinner"></div></div><div class="csc-chart-header"><h3 class="csc-chart-title">📈 Динамика Выручки и Заказов (по дням)</h3></div><div class="csc-chart-container"><canvas id="cscTrendChart"></canvas></div></div>
        <div class="csc-chart-card"><div class="csc-loader-overlay" id="loader-chart-masters"><div class="csc-spinner"></div></div><div class="csc-chart-header"><h3 class="csc-chart-title">🧑‍🔧 Доли Мастеров (по выручке)</h3></div><div class="csc-chart-container"><canvas id="cscMasterChart"></canvas></div></div>
        <div class="csc-chart-card"><div class="csc-loader-overlay" id="loader-chart-services"><div class="csc-spinner"></div></div><div class="csc-chart-header"><h3 class="csc-chart-title">🔥 Топ-10 Популярных услуг (по количеству)</h3></div><div class="csc-chart-container"><canvas id="cscServicesChart"></canvas></div></div>
        <div class="csc-chart-card"><div class="csc-loader-overlay" id="loader-chart-days"><div class="csc-spinner"></div></div><div class="csc-chart-header"><h3 class="csc-chart-title">📅 Активность заказчиков (Дни недели)</h3></div><div class="csc-chart-container"><canvas id="cscDaysChart"></canvas></div></div>
        <div class="csc-chart-card"><div class="csc-loader-overlay" id="loader-chart-time"><div class="csc-spinner"></div></div><div class="csc-chart-header"><h3 class="csc-chart-title">⏰ Пики нагрузки (Время записи)</h3></div><div class="csc-chart-container"><canvas id="cscTimeChart"></canvas></div></div>
        <div class="csc-chart-card"><div class="csc-loader-overlay" id="loader-chart-ratings"><div class="csc-spinner"></div></div><div class="csc-chart-header"><h3 class="csc-chart-title">⭐ Рейтинг мастеров</h3></div><div class="csc-chart-container"><canvas id="cscRatingsChart"></canvas></div></div>
    </div>

    <!-- КАРТА ПОКРЫТИЯ -->
    <div class="csc-coverage-map-section">
        <div class="csc-loader-overlay" id="loader-coverage-map"><div class="csc-spinner"></div></div>
        <div class="csc-coverage-map-header">
            <h3 class="csc-coverage-map-title">🗺️ Карта покрытия зон обслуживания</h3>
            <div class="csc-coverage-map-controls">
                <select id="csc-coverage-filter-status" title="Фильтр по статусу"><option value="all">Все зоны</option><option value="active">Только активные</option><option value="inactive">Только неактивные</option></select>
                <input type="text" id="csc-coverage-search" placeholder="🔍 Поиск города или зоны...">
                <button type="button" class="csc-coverage-map-btn secondary" id="csc-coverage-fit-all" title="Показать все зоны">📐 Показать все</button>
                <button type="button" class="csc-coverage-map-btn secondary" id="csc-coverage-reset" title="Сбросить вид">🎯 Сброс</button>
            </div>
        </div>
        <div class="csc-coverage-map-wrapper"><div id="csc-coverage-map"></div></div>
        <div class="csc-coverage-legend" id="csc-coverage-legend"><span class="csc-coverage-legend-title">Города:</span></div>
        <div class="csc-coverage-stats">
            <div class="csc-coverage-stat"><div class="csc-coverage-stat-value" id="csc-stat-cities">0</div><div class="csc-coverage-stat-label">Городов с координатами</div></div>
            <div class="csc-coverage-stat"><div class="csc-coverage-stat-value" id="csc-stat-zones-total">0</div><div class="csc-coverage-stat-label">Всего зон</div></div>
            <div class="csc-coverage-stat"><div class="csc-coverage-stat-value" id="csc-stat-zones-active" style="color:#10b981;">0</div><div class="csc-coverage-stat-label">Активных зон</div></div>
            <div class="csc-coverage-stat"><div class="csc-coverage-stat-value" id="csc-stat-zones-inactive" style="color:#6b7280;">0</div><div class="csc-coverage-stat-label">Неактивных зон</div></div>
        </div>
    </div>
</div>
<script>
// ============================================================
// ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ И КОНФИГУРАЦИЯ
// ============================================================
let charts = { trend: null, masters: null, services: null, days: null, time: null, ratings: null };
let cachedAnalyticsData = null;
let cachedConversionData = null;
let cachedFilters = { start: '', end: '', master: '', status: '' };
let cscIsFetching = false;
let cscAbortController = null;

let cscCoverageMap = null;
let cscCoverageMarkersLayer = null;
let cscCoverageCirclesLayer = null;
let cscCoverageData = [];
let cscCoverageMarkers = {};
let cscCoverageCircles = {};
let cscCoverageCityColors = {};
let cscCoverageHiddenCities = new Set();

const cscCoverageColorPalette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#f97316', '#14b8a6', '#6366f1', '#84cc16', '#a855f7', '#0ea5e9', '#eab308', '#dc2626', '#059669', '#7c3aed', '#0891b2', '#c026d3', '#ea580c'];
const BASE_CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#f97316', '#14b8a6', '#6366f1'];

const CSC_CONFIG = window.CSC_ANALYTICS_CONFIG || {
    masters_map: {},
    admin_url: (window.location.href.indexOf('wp-admin') !== -1 ? window.location.href.split('wp-admin')[0] + 'wp-admin/admin-ajax.php' : '/wp-admin/admin-ajax.php'),
    nonce: ''
};

// ============================================================
// УТИЛИТЫ И ЗАЩИТА
// ============================================================
function cscEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function cscShowToast(message, type) {
    type = type || 'error';
    const toast = document.getElementById('csc-toast');
    if (!toast) { console.error('Toast element not found:', message); return; }
    toast.textContent = message;
    toast.className = 'csc-toast ' + type;
    void toast.offsetWidth;
    toast.classList.add('show');
    setTimeout(function() { toast.classList.remove('show'); }, 5000);
}

function formatCurrency(value) {
    const num = Number(value) || 0;
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(num);
}

function generateChartColors(count) {
    if (count <= BASE_CHART_COLORS.length) return BASE_CHART_COLORS.slice(0, count);
    const colors = [];
    for (let i = 0; i < count; i++) {
        const hue = Math.round((i * 360) / count);
        colors.push('hsl(' + hue + ', 70%, 60%)');
    }
    return colors;
}

function toggleLoaders(isActive, isConversion) {
    const selector = isConversion ? '#loader-conversion' : '.csc-loader-overlay:not(#loader-conversion):not(#loader-coverage-map)';
    const loaders = document.querySelectorAll(selector);
    loaders.forEach(function(l) {
        if (isActive) l.classList.add('active');
        else l.classList.remove('active');
    });
}

function updateKPIs(kpiData) {
    if (!kpiData) return;
    const revenueEl = document.getElementById('kpi-revenue');
    const ordersEl = document.getElementById('kpi-orders');
    const avgEl = document.getElementById('kpi-avg-receipt');
    const cancelledEl = document.getElementById('kpi-cancelled');
    const lostEl = document.getElementById('kpi-lost-revenue');
    const ratingEl = document.getElementById('kpi-rating');

    if (revenueEl) revenueEl.textContent = formatCurrency(kpiData.total_revenue);
    if (ordersEl) ordersEl.textContent = kpiData.total_orders || 0;
    if (avgEl) avgEl.textContent = formatCurrency(kpiData.avg_receipt);
    if (cancelledEl) cancelledEl.textContent = kpiData.cancelled_count || 0;
    if (lostEl) lostEl.textContent = formatCurrency(kpiData.lost_revenue);
    if (ratingEl) ratingEl.textContent = (Number(kpiData.avg_rating) || 0).toFixed(1) + ' ⭐';
}

// ============================================================
// МОДАЛЬНОЕ ОКНО ЭКСПОРТА
// ============================================================
function cscOpenExportModal() {
    const overlay = document.getElementById('csc-export-modal-overlay');
    if (overlay) overlay.classList.add('active');
}

function cscCloseExportModal() {
    const overlay = document.getElementById('csc-export-modal-overlay');
    if (overlay) overlay.classList.remove('active');
}

function cscToggleCustomRange() {
    const customSelected = document.getElementById('export-period-custom') && document.getElementById('export-period-custom').checked;
    const customRange = document.getElementById('csc-export-custom-range');
    if (customRange) {
        if (customSelected) customRange.classList.add('active');
        else customRange.classList.remove('active');
    }
}

// ============================================================
// ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ СТРАНИЦЫ
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const applyBtn = document.getElementById('csc-apply-filters-btn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function() { cscFetchAllData(); });
    }

    const exportBtn = document.getElementById('csc-export-excel-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', cscOpenExportModal);
    }

    const modalClose = document.getElementById('csc-export-modal-close-btn');
    const modalCancel = document.getElementById('csc-export-cancel-btn');
    const modalOverlay = document.getElementById('csc-export-modal-overlay');
    if (modalClose) modalClose.addEventListener('click', cscCloseExportModal);
    if (modalCancel) modalCancel.addEventListener('click', cscCloseExportModal);
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) cscCloseExportModal();
        });
    }

    const periodRadios = document.querySelectorAll('input[name="export-period"]');
    periodRadios.forEach(function(radio) {
        radio.addEventListener('change', cscToggleCustomRange);
    });

    const dateInputs = document.querySelectorAll('#filter-date-start, #filter-date-end');
    dateInputs.forEach(function(input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); cscFetchAllData(); }
        });
    });

    cscFetchAllData();
    cscInitCoverageMap();
    cscLoadCoverageData();
});

// ============================================================
// ЗАГРУЗКА ДАННЫХ (FETCH С ABORTCONTROLLER)
// ============================================================
function cscFetchAllData() {
    if (cscIsFetching) return;
    cscIsFetching = true;

    const applyBtn = document.getElementById('csc-apply-filters-btn');
    if (applyBtn) applyBtn.disabled = true;

    if (cscAbortController) cscAbortController.abort();
    cscAbortController = new AbortController();

    cachedFilters = {
        start: document.getElementById('filter-date-start').value,
        end: document.getElementById('filter-date-end').value,
        master: document.getElementById('filter-master').value,
        status: document.getElementById('filter-status').value
    };

    Promise.all([
        cscFetchAnalytics(cscAbortController.signal),
        cscFetchConversionTable(cscAbortController.signal)
    ]).catch(function(err) {
        if (err.name !== 'AbortError') {
            cscShowToast('Ошибка загрузки данных: ' + (err.message || 'Неизвестная ошибка'), 'error');
            console.error('CSC Analytics fetch error:', err);
        }
    }).finally(function() {
        cscIsFetching = false;
        if (applyBtn) applyBtn.disabled = false;
    });
}

function cscFetchAnalytics(signal) {
    toggleLoaders(true, false);

    const params = new URLSearchParams({
        action: 'csc_get_analytics_data',
        _ajax_nonce: CSC_CONFIG.nonce,
        start_date: cachedFilters.start,
        end_date: cachedFilters.end,
        master_id: cachedFilters.master,
        status: cachedFilters.status
    });

    return fetch(CSC_CONFIG.admin_url, {
        method: 'POST',
        body: params,
        credentials: 'same-origin',
        signal: signal
    })
    .then(function(response) {
        if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        return response.json();
    })
    .then(function(res) {
        if (!res.success) throw new Error(res.data && res.data.message ? res.data.message : 'Сервер вернул ошибку');
        if (!res.data) throw new Error('Пустой ответ от сервера');
        if (!res.data.kpi) throw new Error('Отсутствуют KPI данные');
        if (!res.data.charts) throw new Error('Отсутствуют данные графиков');

        cachedAnalyticsData = res.data;
        updateKPIs(res.data.kpi);
        
        renderTrendChart(res.data.charts.trend);
        renderMastersChart(res.data.charts.masters);
        renderServicesChart(res.data.charts.services);
        renderDaysChart(res.data.charts.days);
        renderTimeChart(res.data.charts.time);
        renderRatingsChart(res.data.charts.ratings);
    })
    .catch(function(err) {
        if (err.name !== 'AbortError') {
            cscShowToast('Ошибка загрузки аналитики: ' + err.message, 'error');
            console.error('cscFetchAnalytics error:', err);
        }
        throw err;
    })
    .finally(function() {
        toggleLoaders(false, false);
    });
}

function cscFetchConversionTable(signal) {
    const loader = document.getElementById('loader-conversion');
    if (loader) loader.classList.add('active');

    const params = new URLSearchParams({
        action: 'csc_get_conversion_data',
        _ajax_nonce: CSC_CONFIG.nonce,
        start_date: cachedFilters.start,
        end_date: cachedFilters.end
    });

    return fetch(CSC_CONFIG.admin_url, {
        method: 'POST',
        body: params,
        credentials: 'same-origin',
        signal: signal
    })
    .then(function(response) {
        if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        return response.json();
    })
    .then(function(res) {
        if (!res.success) throw new Error(res.data && res.data.message ? res.data.message : 'Ошибка конверсии');
        if (res.data) cachedConversionData = res.data;
        cscRenderConversionTable(res.data);
    })
    .catch(function(err) {
        if (err.name !== 'AbortError') {
            const tbody = document.getElementById('conversion-table-body');
            if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="csc-table-empty" style="color:#ef4444;">Ошибка: ' + cscEscapeHtml(err.message) + '</td></tr>';
            cscShowToast('Ошибка загрузки конверсии: ' + err.message, 'error');
            console.error('cscFetchConversionTable error:', err);
        }
        throw err;
    })
    .finally(function() {
        if (loader) loader.classList.remove('active');
    });
}

// ============================================================
// ОТРИСОВКА ТАБЛИЦЫ КОНВЕРСИИ
// ============================================================
function cscRenderConversionTable(data) {
    const tbody = document.getElementById('conversion-table-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="csc-table-empty">Нет данных о маршрутизации за выбранный период.</td></tr>';
        return;
    }

    const fragment = document.createDocumentFragment();
    data.forEach(function(row) {
        const tr = document.createElement('tr');
        
        const tdName = document.createElement('td');
        tdName.innerHTML = '<strong>' + cscEscapeHtml(row.name) + '</strong>';
        
        const tdOffered = document.createElement('td');
        tdOffered.style.textAlign = 'center';
        tdOffered.textContent = row.offered;
        
        const tdAccepted = document.createElement('td');
        tdAccepted.style.textAlign = 'center';
        tdAccepted.style.color = '#10b981';
        tdAccepted.style.fontWeight = 'bold';
        tdAccepted.textContent = row.accepted;
        
        const tdRejected = document.createElement('td');
        tdRejected.style.textAlign = 'center';
        tdRejected.style.color = '#ef4444';
        tdRejected.style.fontWeight = 'bold';
        tdRejected.textContent = row.rejected;
        
        const tdConv = document.createElement('td');
        tdConv.style.textAlign = 'center';
        
        let badgeClass = 'red';
        if (row.conversion >= 70) badgeClass = 'green';
        else if (row.conversion >= 40) badgeClass = 'yellow';
        
        const badge = document.createElement('span');
        badge.className = 'csc-badge ' + badgeClass;
        badge.textContent = row.conversion + '%';
        tdConv.appendChild(badge);

        tr.appendChild(tdName);
        tr.appendChild(tdOffered);
        tr.appendChild(tdAccepted);
        tr.appendChild(tdRejected);
        tr.appendChild(tdConv);
        fragment.appendChild(tr);
    });
    tbody.appendChild(fragment);
}

// ============================================================
// ОТРИСОВКА ГРАФИКОВ (CHART.JS)
// ============================================================
function renderTrendChart(data) {
    const ctx = document.getElementById('cscTrendChart');
    if (!ctx) return;
    if (charts.trend) charts.trend.destroy();

    const labels = (data && data.labels) ? data.labels : [];
    const revenue = (data && data.revenue) ? data.revenue : [];
    const orders = (data && data.orders) ? data.orders : [];

    charts.trend = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Выручка (₽)', data: revenue, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', borderWidth: 2, fill: true, tension: 0.4, yAxisID: 'y' },
                { label: 'Кол-во заказов', data: orders, borderColor: '#10b981', backgroundColor: '#10b981', borderWidth: 2, borderDash: [5, 5], tension: 0.4, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false } },
                y: { type: 'linear', display: true, position: 'left' },
                y1: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false } }
            },
            plugins: { legend: { position: 'top' } }
        }
    });
}

function renderMastersChart(data) {
    const ctx = document.getElementById('cscMasterChart');
    if (!ctx) return;
    if (charts.masters) charts.masters.destroy();

    if (!data || !data.labels || data.labels.length === 0) {
        data = { labels: ['Нет данных'], revenue: [1] };
    }

    const colors = generateChartColors(data.labels.length);
    charts.masters = new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: data.labels,
            datasets: [{ data: data.revenue, backgroundColor: colors, borderWidth: 1 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '65%',
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            if (c.label === 'Нет данных') return 'Нет данных: 0 ₽';
                            return c.label + ': ' + formatCurrency(c.raw);
                        }
                    }
                }
            }
        }
    });
}

function renderServicesChart(data) {
    const ctx = document.getElementById('cscServicesChart');
    if (!ctx) return;
    if (charts.services) charts.services.destroy();

    const labels = (data && data.labels) ? data.labels : [];
    const counts = (data && data.counts) ? data.counts : [];

    charts.services = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{ label: 'Количество оказаний', data: counts, backgroundColor: '#3b82f6', borderRadius: 4 }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            scales: { x: { beginAtZero: true, grid: { display: false } }, y: { grid: { display: false } } },
            plugins: { legend: { display: false } }
        }
    });
}

function renderDaysChart(data) {
    const ctx = document.getElementById('cscDaysChart');
    if (!ctx) return;
    if (charts.days) charts.days.destroy();

    const counts = (data && data.counts) ? data.counts : [0, 0, 0, 0, 0, 0, 0];

    charts.days = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
            datasets: [{ label: 'Количество заказов', data: counts, backgroundColor: '#8b5cf6', borderRadius: 6 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } },
            plugins: { legend: { display: false } }
        }
    });
}

function renderTimeChart(data) {
    const ctx = document.getElementById('cscTimeChart');
    if (!ctx) return;
    if (charts.time) charts.time.destroy();

    const labels = (data && data.labels) ? data.labels : [];
    const counts = (data && data.counts) ? data.counts : [];

    charts.time = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{ label: 'Записей на это время', data: counts, backgroundColor: '#f59e0b', borderRadius: 4 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { grid: { display: false } } },
            plugins: { legend: { display: false } }
        }
    });
}

function renderRatingsChart(data) {
    const ctx = document.getElementById('cscRatingsChart');
    if (!ctx) return;
    if (charts.ratings) charts.ratings.destroy();

    if (!data || !data.labels || data.labels.length === 0) {
        data = { labels: ['Нет данных'], scores: [0] };
    }

    charts.ratings = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{ label: 'Средняя оценка', data: data.scores, backgroundColor: '#f59e0b', borderRadius: 4 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });
}
</script>
<script>
// ============================================================
// КАРТА ПОКРЫТИЯ: ИНИЦИАЛИЗАЦИЯ
// ============================================================
function cscInitCoverageMap() {
    if (typeof L === 'undefined') {
        console.warn('Leaflet.js не загружен. Карта недоступна.');
        return;
    }
    var mapContainer = document.getElementById('csc-coverage-map');
    if (!mapContainer) {
        console.warn('Контейнер #csc-coverage-map не найден.');
        return;
    }

    cscCoverageMap = L.map('csc-coverage-map', {
        center: [61.524, 105.3188],
        zoom: 4,
        zoomControl: true,
        scrollWheelZoom: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(cscCoverageMap);

    cscCoverageCirclesLayer = L.layerGroup().addTo(cscCoverageMap);
    cscCoverageMarkersLayer = L.layerGroup().addTo(cscCoverageMap);
}

// ============================================================
// КАРТА ПОКРЫТИЯ: ЗАГРУЗКА ДАННЫХ
// ============================================================
function cscLoadCoverageData() {
    var loader = document.getElementById('loader-coverage-map');
    if (loader) loader.classList.add('active');

    var params = new URLSearchParams({
        action: 'csc_get_zones_coverage_data',
        _ajax_nonce: CSC_CONFIG.nonce
    });

    fetch(CSC_CONFIG.admin_url, {
        method: 'POST',
        body: params,
        credentials: 'same-origin'
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(res) {
        if (loader) loader.classList.remove('active');

        if (!res.success || !res.data || !res.data.cities) {
            var errMsg = (res.data && res.data.message) ? res.data.message : 'Неизвестная ошибка';
            cscShowToast('Ошибка карты: ' + errMsg, 'error');
            return;
        }

        cscCoverageData = res.data.cities;

        if (cscCoverageData.length === 0) {
            cscShowToast('Нет городов с координатами для отображения на карте', 'error');
            return;
        }

        cscCoverageData.forEach(function(city, idx) {
            cscCoverageCityColors[city.key] = cscCoverageColorPalette[idx % cscCoverageColorPalette.length];
        });

        cscRenderCoverageMap();
        cscRenderCoverageLegend();
        cscRenderCoverageStats();
        cscFitCoverageToAll();
    })
    .catch(function(err) {
        if (loader) loader.classList.remove('active');
        console.error('Ошибка загрузки данных карты:', err);
        cscShowToast('Ошибка загрузки карты: ' + err.message, 'error');
    });
}

// ============================================================
// КАРТА ПОКРЫТИЯ: ОТРИСОВКА ЗОН
// ============================================================
function cscRenderCoverageMap() {
    if (!cscCoverageCirclesLayer || !cscCoverageMarkersLayer) return;

    cscCoverageCirclesLayer.clearLayers();
    cscCoverageMarkersLayer.clearLayers();
    cscCoverageCircles = {};
    cscCoverageMarkers = {};

    var statusFilterEl = document.getElementById('csc-coverage-filter-status');
    var searchEl = document.getElementById('csc-coverage-search');
    var statusFilter = statusFilterEl ? statusFilterEl.value : 'all';
    var searchText = searchEl ? searchEl.value.trim().toLowerCase() : '';
    var hasVisibleZones = false;

    cscCoverageData.forEach(function(city) {
        if (cscCoverageHiddenCities.has(city.key)) return;

        var cityColor = cscCoverageCityColors[city.key] || '#3b82f6';

        city.zones.forEach(function(zone) {
            if (statusFilter === 'active' && !zone.active) return;
            if (statusFilter === 'inactive' && zone.active) return;

            if (searchText) {
                var nameMatch = (city.name || '').toLowerCase().indexOf(searchText) !== -1;
                var subdomainMatch = (city.subdomain || '').toLowerCase().indexOf(searchText) !== -1;
                var timezoneMatch = (city.timezone || '').toLowerCase().indexOf(searchText) !== -1;
                var zoneMatch = (zone.name || '').toLowerCase().indexOf(searchText) !== -1;
                if (!nameMatch && !subdomainMatch && !timezoneMatch && !zoneMatch) return;
            }

            hasVisibleZones = true;

            var baseRadius = 5000;
            var multiplier = parseFloat(zone.price_multiplier) || 1.0;
            var radius = Math.min(baseRadius * multiplier, 15000);
            var fillOpacity = zone.active ? 0.35 : 0.15;
            var weight = zone.active ? 2 : 1;

            var circle = L.circle([city.lat, city.lng], {
                radius: radius,
                color: cityColor,
                fillColor: cityColor,
                fillOpacity: fillOpacity,
                weight: weight,
                opacity: 0.8
            });

            var statusBadge = zone.active
                ? '<span class="csc-badge green" style="padding: 2px 8px; font-size: 10px;">🟢 Активна</span>'
                : '<span class="csc-badge red" style="padding: 2px 8px; font-size: 10px;">⚪ Неактивна</span>';

            var cityStatusBadge = city.active
                ? '<span class="csc-badge green" style="padding: 2px 8px; font-size: 10px;">🟢 Активен</span>'
                : '<span class="csc-badge red" style="padding: 2px 8px; font-size: 10px;">⚪ Неактивен</span>';

            var popupContent =
                '<div class="csc-coverage-popup-title">' + cscEscapeHtml(city.name) + ' ' + cityStatusBadge + '</div>' +
                '<div class="csc-coverage-popup-meta">🔗 <strong>' + cscEscapeHtml(city.subdomain || '—') + '</strong></div>' +
                '<div class="csc-coverage-popup-meta">🕐 ' + cscEscapeHtml(city.timezone || '—') + '</div>' +
                '<div style="border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 8px;">' +
                    '<strong>Зона:</strong> ' + cscEscapeHtml(zone.name) + ' ' + statusBadge + '<br>' +
                    '<span class="csc-coverage-popup-meta">Множитель: ×' + cscEscapeHtml(zone.price_multiplier) + '</span><br>' +
                    '<span class="csc-coverage-popup-meta">Радиус: ~' + (Math.round(radius / 1000 * 10) / 10) + ' км</span>' +
                '</div>';

            circle.bindPopup(popupContent, { maxWidth: 320, minWidth: 220 });
            cscCoverageCirclesLayer.addLayer(circle);

            var circleKey = city.key + '_' + zone.id;
            cscCoverageCircles[circleKey] = circle;
        });

        if (city.zones.length > 0) {
            var markerIcon = L.divIcon({
                className: 'csc-coverage-city-marker',
                html: '<div style="width: 14px; height: 14px; background: ' + cityColor + '; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 2px 6px rgba(0,0,0,0.4);"></div>',
                iconSize: [14, 14],
                iconAnchor: [7, 7]
            });

            var marker = L.marker([city.lat, city.lng], { icon: markerIcon });
            marker.bindTooltip(cscEscapeHtml(city.name), {
                permanent: false,
                direction: 'top',
                offset: [0, -12]
            });
            cscCoverageMarkersLayer.addLayer(marker);
            cscCoverageMarkers[city.key] = marker;
        }
    });

    if (!hasVisibleZones && cscCoverageData.length > 0) {
        cscShowToast('Нет зон, соответствующих текущим фильтрам', 'error');
    }
}

// ============================================================
// КАРТА ПОКРЫТИЯ: ЛЕГЕНДА С ПЕРЕКЛЮЧЕНИЕМ ВИДИМОСТИ
// ============================================================
function cscRenderCoverageLegend() {
    var legend = document.getElementById('csc-coverage-legend');
    if (!legend) return;

    var titleEl = legend.querySelector('.csc-coverage-legend-title');
    legend.innerHTML = '';
    if (titleEl) legend.appendChild(titleEl);

    cscCoverageData.forEach(function(city) {
        var color = cscCoverageCityColors[city.key] || '#3b82f6';
        var isHidden = cscCoverageHiddenCities.has(city.key);

        var item = document.createElement('div');
        item.className = 'csc-coverage-legend-item' + (isHidden ? ' dimmed' : '');
        item.innerHTML =
            '<span class="csc-coverage-legend-color" style="background: ' + color + ';"></span>' +
            '<span>' + cscEscapeHtml(city.name) + '</span>' +
            '<span style="color: #94a3b8; font-size: 11px;">(' + city.total_zones + ')</span>';

        item.addEventListener('click', function() {
            if (cscCoverageHiddenCities.has(city.key)) {
                cscCoverageHiddenCities.delete(city.key);
            } else {
                cscCoverageHiddenCities.add(city.key);
            }
            cscRenderCoverageLegend();
            cscRenderCoverageMap();
        });

        legend.appendChild(item);
    });
}

// ============================================================
// КАРТА ПОКРЫТИЯ: СТАТИСТИКА
// ============================================================
function cscRenderCoverageStats() {
    var totalZones = 0;
    var activeZones = 0;
    var inactiveZones = 0;

    cscCoverageData.forEach(function(city) {
        city.zones.forEach(function(zone) {
            totalZones++;
            if (zone.active) activeZones++;
            else inactiveZones++;
        });
    });

    var citiesEl = document.getElementById('csc-stat-cities');
    var totalEl = document.getElementById('csc-stat-zones-total');
    var activeEl = document.getElementById('csc-stat-zones-active');
    var inactiveEl = document.getElementById('csc-stat-zones-inactive');

    if (citiesEl) citiesEl.textContent = cscCoverageData.length;
    if (totalEl) totalEl.textContent = totalZones;
    if (activeEl) activeEl.textContent = activeZones;
    if (inactiveEl) inactiveEl.textContent = inactiveZones;
}

// ============================================================
// КАРТА ПОКРЫТИЯ: ПОДГОНКА ВИДА
// ============================================================
function cscFitCoverageToAll() {
    if (!cscCoverageMap || cscCoverageData.length === 0) return;

    var latLngs = cscCoverageData.map(function(c) { return [c.lat, c.lng]; });

    if (latLngs.length === 1) {
        cscCoverageMap.setView(latLngs[0], 11);
    } else {
        var bounds = L.latLngBounds(latLngs);
        cscCoverageMap.fitBounds(bounds, { padding: [60, 60], maxZoom: 12 });
    }
}

function cscResetCoverageView() {
    if (!cscCoverageMap) return;
    cscCoverageMap.setView([61.524, 105.3188], 4);
}

// ============================================================
// УМНЫЙ ЭКСПОРТ: РАСЧЕТ ДАТ ИЗ ВЫБРАННОГО ПЕРИОДА
// ============================================================
function cscCalculateExportDates(periodValue, customStart, customEnd) {
    var now = new Date();
    var start, end;

    var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
    var formatDate = function(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    };

    end = formatDate(now);

    if (periodValue === 'custom') {
        if (!customStart || !customEnd) {
            throw new Error('Укажите обе даты для своего диапазона');
        }
        if (customStart > customEnd) {
            throw new Error('Дата начала должна быть раньше даты окончания');
        }
        start = customStart;
        end = customEnd;
    } else if (periodValue === 'all') {
        start = '2020-01-01';
    } else {
        var days = parseInt(periodValue, 10);
        if (isNaN(days) || days <= 0) {
            throw new Error('Некорректный период');
        }
        var startDate = new Date(now);
        startDate.setDate(startDate.getDate() - days);
        start = formatDate(startDate);
    }

    return { start: start, end: end };
}

// ============================================================
// УМНЫЙ ЭКСПОРТ: ОБРАБОТЧИК ПОДТВЕРЖДЕНИЯ МОДАЛКИ
// ============================================================
function cscHandleExportConfirm() {
    if (typeof XLSX === 'undefined' || typeof XLSX.utils === 'undefined') {
        cscShowToast('Библиотека SheetJS не загружена. Проверьте интернет и обновите страницу.', 'error');
        return;
    }

    var periodRadio = document.querySelector('input[name="export-period"]:checked');
    if (!periodRadio) {
        cscShowToast('Выберите период отчета', 'error');
        return;
    }

    var periodValue = periodRadio.value;
    var customStart = document.getElementById('export-custom-start') ? document.getElementById('export-custom-start').value : '';
    var customEnd = document.getElementById('export-custom-end') ? document.getElementById('export-custom-end').value : '';

    var dates;
    try {
        dates = cscCalculateExportDates(periodValue, customStart, customEnd);
    } catch (e) {
        cscShowToast(e.message, 'error');
        return;
    }

    var onlyActiveMasters = document.getElementById('export-filter-active-masters') && document.getElementById('export-filter-active-masters').checked;
    var onlyCompleted = document.getElementById('export-filter-completed-only') && document.getElementById('export-filter-completed-only').checked;
    var includeDailyDetails = document.getElementById('export-include-details') && document.getElementById('export-include-details').checked;

    var confirmBtn = document.getElementById('csc-export-confirm-btn');
    var confirmLabel = document.getElementById('csc-export-confirm-label');
    if (confirmBtn) confirmBtn.disabled = true;
    if (confirmLabel) confirmLabel.textContent = '⏳ Формирование...';

    cscFetchDataForExport(dates.start, dates.end, onlyCompleted)
        .then(function(result) {
            cscGenerateExcel({
                analytics: result.analytics,
                conversion: result.conversion,
                dates: dates,
                onlyActiveMasters: onlyActiveMasters,
                onlyCompleted: onlyCompleted,
                includeDailyDetails: includeDailyDetails
            });
            cscCloseExportModal();
            cscShowToast('Отчет успешно сформирован и скачан', 'success');
        })
        .catch(function(err) {
            cscShowToast('Ошибка при формировании отчета: ' + (err.message || 'Неизвестная ошибка'), 'error');
            console.error('Export error:', err);
        })
        .finally(function() {
            if (confirmBtn) confirmBtn.disabled = false;
            if (confirmLabel) confirmLabel.textContent = '📊 Сформировать отчет';
        });
}

// ============================================================
// УМНЫЙ ЭКСПОРТ: ПОЛУЧЕНИЕ ДАННЫХ С СЕРВЕРА
// ============================================================
function cscFetchDataForExport(startDate, endDate, onlyCompleted) {
    var analyticsParams = new URLSearchParams({
        action: 'csc_get_analytics_data',
        _ajax_nonce: CSC_CONFIG.nonce,
        start_date: startDate,
        end_date: endDate,
        master_id: 'all',
        status: onlyCompleted ? 'выполнено' : 'all'
    });

    var conversionParams = new URLSearchParams({
        action: 'csc_get_conversion_data',
        _ajax_nonce: CSC_CONFIG.nonce,
        start_date: startDate,
        end_date: endDate
    });

    var fetchOpts = { method: 'POST', credentials: 'same-origin' };

    return Promise.all([
        fetch(CSC_CONFIG.admin_url, Object.assign({}, fetchOpts, { body: analyticsParams })).then(function(r) { return r.json(); }),
        fetch(CSC_CONFIG.admin_url, Object.assign({}, fetchOpts, { body: conversionParams })).then(function(r) { return r.json(); })
    ]).then(function(results) {
        var analyticsRes = results[0];
        var conversionRes = results[1];

        if (!analyticsRes.success || !analyticsRes.data) {
            throw new Error((analyticsRes.data && analyticsRes.data.message) || 'Не удалось получить аналитику');
        }
        if (!conversionRes.success || !conversionRes.data) {
            throw new Error((conversionRes.data && conversionRes.data.message) || 'Не удалось получить конверсию');
        }

        return {
            analytics: analyticsRes.data,
            conversion: conversionRes.data
        };
    });
}

// ============================================================
// УМНЫЙ ЭКСПОРТ: ГЕНЕРАЦИЯ EXCEL ФАЙЛА
// ============================================================
function cscGenerateExcel(opts) {
    var wb = XLSX.utils.book_new();
    var analytics = opts.analytics;
    var conversion = opts.conversion;
    var kpi = analytics.kpi;
    var charts = analytics.charts;

    var periodLabel = opts.dates.start + ' — ' + opts.dates.end;
    var statusLabel = opts.onlyCompleted ? 'Только выполненные' : 'Все статусы (кроме отмененных)';
    var generatedAt = cscGetLocalDateTime();

    // === ЛИСТ 1: СВОДКА (KPI) ===
    var summaryData = [
        ['Аналитический отчёт Custom Service Calculator'],
        [''],
        ['Параметры отчёта:'],
        ['Период:', periodLabel],
        ['Фильтр по статусу:', statusLabel],
        ['Фильтр по мастерам:', opts.onlyActiveMasters ? 'Только активные (с заказами)' : 'Все мастера'],
        ['Дата формирования:', generatedAt],
        [''],
        ['Показатель', 'Значение'],
        ['Общая выручка (₽)', Number(kpi.total_revenue) || 0],
        ['Количество заказов', Number(kpi.total_orders) || 0],
        ['Средний чек (₽)', Number(kpi.avg_receipt) || 0],
        ['Отменено заказов', Number(kpi.cancelled_count) || 0],
        ['Потерянная выгода (₽)', Number(kpi.lost_revenue) || 0],
        ['Средний рейтинг', Number(kpi.avg_rating) || 0]
    ];
    var wsSummary = XLSX.utils.aoa_to_sheet(summaryData);
    wsSummary['!cols'] = [{ wch: 35 }, { wch: 30 }];
    XLSX.utils.book_append_sheet(wb, wsSummary, 'Сводка');

    // === ЛИСТ 2: ДИНАМИКА ПО ДНЯМ (если включена детализация) ===
    if (opts.includeDailyDetails && charts.trend) {
        var trend = charts.trend;
        var trendData = [['Дата', 'Выручка (₽)', 'Количество заказов']];
        if (trend.labels && Array.isArray(trend.labels)) {
            for (var i = 0; i < trend.labels.length; i++) {
                trendData.push([
                    trend.labels[i] || '',
                    Number(trend.revenue[i]) || 0,
                    Number(trend.orders[i]) || 0
                ]);
            }
        }
        var wsTrend = XLSX.utils.aoa_to_sheet(trendData);
        wsTrend['!cols'] = [{ wch: 12 }, { wch: 18 }, { wch: 20 }];
        XLSX.utils.book_append_sheet(wb, wsTrend, 'Динамика по дням');
    }

    // === ЛИСТ 3: ВЫРУЧКА МАСТЕРОВ ===
    var mastersData = charts.masters;
    var mastersSheetData = [['Мастер', 'Выручка (₽)']];
    if (mastersData && mastersData.labels && Array.isArray(mastersData.labels)) {
        for (var j = 0; j < mastersData.labels.length; j++) {
            var masterName = mastersData.labels[j] || '';
            var masterRev = Number(mastersData.revenue[j]) || 0;
            if (opts.onlyActiveMasters && masterRev === 0) continue;
            mastersSheetData.push([masterName, masterRev]);
        }
    }
    var wsMasters = XLSX.utils.aoa_to_sheet(mastersSheetData);
    wsMasters['!cols'] = [{ wch: 35 }, { wch: 18 }];
    XLSX.utils.book_append_sheet(wb, wsMasters, 'Мастера');

    // === ЛИСТ 4: ПОПУЛЯРНЫЕ УСЛУГИ ===
    var servicesData = charts.services;
    var servicesSheetData = [['Услуга', 'Количество оказаний']];
    if (servicesData && servicesData.labels && Array.isArray(servicesData.labels)) {
        for (var k = 0; k < servicesData.labels.length; k++) {
            servicesSheetData.push([
                servicesData.labels[k] || '',
                Number(servicesData.counts[k]) || 0
            ]);
        }
    }
    var wsServices = XLSX.utils.aoa_to_sheet(servicesSheetData);
    wsServices['!cols'] = [{ wch: 40 }, { wch: 22 }];
    XLSX.utils.book_append_sheet(wb, wsServices, 'Услуги');

    // === ЛИСТ 5: КОНВЕРСИЯ МАСТЕРОВ ===
    var convSheetData = [['Сотрудник', 'Предложено заявок', 'Принято', 'Отклонено / Пропущено', '% Конверсии']];
    if (conversion && conversion.length > 0) {
        conversion.forEach(function(row) {
            if (opts.onlyActiveMasters && row.offered === 0) return;
            convSheetData.push([
                row.name || '',
                Number(row.offered) || 0,
                Number(row.accepted) || 0,
                Number(row.rejected) || 0,
                (Number(row.conversion) || 0) + '%'
            ]);
        });
    }
    var wsConv = XLSX.utils.aoa_to_sheet(convSheetData);
    wsConv['!cols'] = [{ wch: 30 }, { wch: 18 }, { wch: 12 }, { wch: 24 }, { wch: 14 }];
    XLSX.utils.book_append_sheet(wb, wsConv, 'Конверсия');

    // === ЛИСТ 6: АКТИВНОСТЬ ПО ДНЯМ НЕДЕЛИ ===
    var daysData = charts.days;
    var dayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
    var daysSheetData = [['День недели', 'Количество заказов']];
    if (daysData && daysData.counts && Array.isArray(daysData.counts)) {
        for (var d = 0; d < 7; d++) {
            daysSheetData.push([
                dayNames[d] || ('День ' + (d + 1)),
                Number(daysData.counts[d]) || 0
            ]);
        }
    }
    var wsDays = XLSX.utils.aoa_to_sheet(daysSheetData);
    wsDays['!cols'] = [{ wch: 18 }, { wch: 20 }];
    XLSX.utils.book_append_sheet(wb, wsDays, 'Дни недели');

    // === ЛИСТ 7: ПИКИ НАГРУЗКИ ПО ВРЕМЕНИ ===
    var timeData = charts.time;
    var timeSheetData = [['Время', 'Количество записей']];
    if (timeData && timeData.labels && Array.isArray(timeData.labels)) {
        for (var t = 0; t < timeData.labels.length; t++) {
            timeSheetData.push([
                timeData.labels[t] || '',
                Number(timeData.counts[t]) || 0
            ]);
        }
    }
    var wsTime = XLSX.utils.aoa_to_sheet(timeSheetData);
    wsTime['!cols'] = [{ wch: 10 }, { wch: 22 }];
    XLSX.utils.book_append_sheet(wb, wsTime, 'Время записи');

    // === ЛИСТ 8: РЕЙТИНГИ МАСТЕРОВ ===
    var ratingsData = charts.ratings;
    var ratingsSheetData = [['Мастер', 'Средняя оценка']];
    if (ratingsData && ratingsData.labels && Array.isArray(ratingsData.labels)) {
        for (var r = 0; r < ratingsData.labels.length; r++) {
            ratingsSheetData.push([
                ratingsData.labels[r] || '',
                Number(ratingsData.scores[r]) || 0
            ]);
        }
    }
    var wsRatings = XLSX.utils.aoa_to_sheet(ratingsSheetData);
    wsRatings['!cols'] = [{ wch: 35 }, { wch: 16 }];
    XLSX.utils.book_append_sheet(wb, wsRatings, 'Рейтинги');

    // === СКАЧИВАНИЕ ФАЙЛА ===
    var fileName = 'csc-analytics-' + opts.dates.start + '_to_' + opts.dates.end + '.xlsx';
    XLSX.writeFile(wb, fileName);
}

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДАТ
// ============================================================
function cscGetLocalDate() {
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function cscGetLocalDateTime() {
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var hours = String(now.getHours()).padStart(2, '0');
    var minutes = String(now.getMinutes()).padStart(2, '0');
    return day + '.' + month + '.' + year + ' ' + hours + ':' + minutes;
}

// ============================================================
// ИСПРАВЛЕНИЕ: Привязка обработчиков ПОСЛЕ определения функций
// ============================================================
(function() {
    function bindExportConfirmButton() {
        var confirmBtn = document.getElementById('csc-export-confirm-btn');
        if (confirmBtn && typeof cscHandleExportConfirm === 'function') {
            confirmBtn.addEventListener('click', cscHandleExportConfirm);
        }
    }

    function bindMapControls() {
        var filterStatus = document.getElementById('csc-coverage-filter-status');
        if (filterStatus) {
            filterStatus.addEventListener('change', function() {
                cscRenderCoverageMap();
            });
        }

        var searchInput = document.getElementById('csc-coverage-search');
        if (searchInput) {
            var debounceTimer;
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    cscRenderCoverageMap();
                }, 300);
            });
        }

        var fitBtn = document.getElementById('csc-coverage-fit-all');
        if (fitBtn) {
            fitBtn.addEventListener('click', function() {
                cscFitCoverageToAll();
            });
        }

        var resetBtn = document.getElementById('csc-coverage-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                cscResetCoverageView();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            bindExportConfirmButton();
            bindMapControls();
        });
    } else {
        bindExportConfirmButton();
        bindMapControls();
    }
})();
</script>
<?php
} // Конец функции csc_render_analytics_page()