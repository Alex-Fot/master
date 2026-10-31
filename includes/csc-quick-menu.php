<?php
/**
 * БЫСТРОЕ МЕНЮ «⋮» (csc-quick-menu) — версия 9.1
 * ============================================================
 * Компактная плашка с тремя точками вверху страниц плагина
 * (главная страница калькулятора + личный кабинет).
 *
 * Поведение:
 *  - клик по «⋮» растягивает контур ВЛЕВО или ВПРАВО (в зависимости от
 *    того, где стоит плашка относительно центра экрана — чтобы не вылезать
 *    за край) и показывает два значка:
 *      1) «человечек с гаечным ключом» — Стать мастером (title при наведении),
 *      2) «человечек»                  — Личный кабинет (title при наведении);
 *  - РАЗДЕЛ, НА КОТОРОМ НАХОДИТСЯ ПОСЕТИТЕЛЬ, НЕ АКТИВЕН: его значок
 *    заменяется значком перехода на страницу КАЛЬКУЛЯТОРА («🧮 Рассчитать услугу»);
 *  - стили полностью наследуют переменные темы контейнера
 *    ([data-theme="dark"] / светлая тема страницы);
 *  - при УДЕРЖАНИИ значка «⋮» (pointerdown > 300ms) плашку можно перемещать
 *    по горизонтали на всю ширину экрана; позиция сохраняется в localStorage
 *    и восстанавливается при повторном входе.
 *
 * Хуки/фильтры:
 *  - csc_quick_menu_urls($urls) — переопределение ссылок меню извне.
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// 1. Построитель ссылок меню (единственный источник правды для
//    PHP-разметки и JS-конфига — ссылки не расходятся).
// ============================================================
if (!function_exists('csc_quick_menu_build_urls')) {
    function csc_quick_menu_build_urls() {
        $current_id = get_queried_object_id();

        // Страница калькулятора: текущая, если на ней рендерится [service_calculator],
        // иначе — первая опубликованная страница с шорткодом.
        $calc_url = '';
        // [9.1.3] приоритет — настройка csc_main_settings['calculator_page_id'] (если есть),
        // затем факт рендера шорткода на текущей странице, затем глобальный поиск.
        $s = get_option('csc_main_settings', []);
        $calc_page = absint($s['calculator_page_id'] ?? 0);
        if ($calc_page && get_permalink($calc_page)) {
            $calc_url = get_permalink($calc_page);
        } elseif ($current_id && has_shortcode((string) get_post_field('post_content', $current_id), 'service_calculator')) {
            $calc_url = get_permalink($current_id);
        } else {
            $found = get_posts([
                'post_type' => 'any', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'ASC',
                'meta_key'  => 'csc_calc_page_flag', 'meta_value' => '1',
            ]);
            if (!$found) {
                $found = get_posts([
                    'post_type' => 'any', 'posts_per_page' => 1, 's' => '[service_calculator]',
                    'exclude' => array_filter([$current_id]),
                ]);
            }
            $calc_url = $found ? get_permalink($found[0]->ID) : home_url('/');
        }

        // Страница личного кабинета: настройка csc_cabinet_page_id (используется
        // и в csc_build_push_click_url), затем поиск страницы с шорткодом.
        $cab_url  = '';
        $cab_page = absint(get_option('csc_cabinet_page_id', 0));
        if ($cab_page && get_permalink($cab_page)) {
            $cab_url = get_permalink($cab_page);
        } else {
            $found = get_posts([
                'post_type' => 'page', 'posts_per_page' => 1,
                'meta_key'  => 'csc_cabinet_page_flag', 'meta_value' => '1',
            ]);
            if (!$found) {
                $found = get_posts([
                    'post_type' => 'page', 'posts_per_page' => 1, 's' => '[csc_personal_cabinet]',
                    'exclude'   => array_filter([$current_id]),
                ]);
            }
            $cab_url = $found ? get_permalink($found[0]->ID) : '';
        }
        if (!$cab_url) $cab_url = home_url('/'); // нет страницы ЛК — ссылка на главную

        // Форма «стать мастером»: страница с [csc_become_master] или админ-страница.
        $master_url = '';
        if ($current_id && (has_shortcode((string) get_post_field('post_content', $current_id), 'csc_become_master')
             || csc_quick_menu_find_shortcode_page('csc_become_master') === $current_id)) {
            $master_url = get_permalink($current_id);
        } else {
            $found = get_posts([
                'post_type' => 'any', 'posts_per_page' => 1,
                'meta_key'  => 'csc_master_reg_page_flag', 'meta_value' => '1',
            ]);
            if (!$found) {
                $found = get_posts([
                    'post_type' => 'any', 'posts_per_page' => 1, 's' => '[csc_become_master]',
                    'exclude'   => array_filter([$current_id]),
                ]);
            }
            $master_url = $found ? get_permalink($found[0]->ID) : '';
        }
        if (!$master_url) {
            $master_url = add_query_arg('csc_action', 'become_master', home_url('/'));
        }

        $urls = [
            'calc'   => $calc_url ?: home_url('/'),
            'cabinet'=> $cab_url,
            'master' => $master_url,
            // Текущий раздел определяется по page_id (надёжнее строкового сравнения URL:
            // без www/http-vs-https/слэша на конце permalink может «не совпасть»).
            'page_id'=> (int) $current_id,
        ];
        return apply_filters('csc_quick_menu_urls', $urls);
    }
}

// ============================================================
// 2. Печать CSS+JS (один раз на страницу, даже если меню
//    рендерится несколько раз). Инлайн-вывод гарантирует, что
//    активы попадают на страницу независимо от очереди
//    wp_enqueue_* конкретной темы (шорткод может исполняться
//    после wp_head уже отрендеренного буфера).
// ============================================================
if (!function_exists('csc_quick_menu_print_assets')) {
    function csc_quick_menu_print_assets() {
        // [9.1.4] Активы всегда доставляются через wp_head/wp_footer (см.
        // csc_quick_menu_bootstrap()). Инлайн-дубликат внутри буфера шорткода
        // убран: фильтры контента (Elementor/кэш-плагины) вырезали <style> и
        // <script> из post_content — на боевой странице CSS отсутствовал
        // (document.body.innerHTML.includes('csc_quick_menu_css') === false),
        // а значит кнопка не имела pointer-events и клик «не работал».
        csc_quick_menu_register_hooks();
    }
}

if (!function_exists('csc_quick_menu_register_hooks')) {
    function csc_quick_menu_register_hooks() {
        static $hooked = false;
        if ($hooked || !function_exists('add_action')) return;
        $hooked = true;
        add_action('wp_head', 'csc_quick_menu_print_css_hook', 5);
        add_action('wp_footer', 'csc_quick_menu_print_js_hook', 50);
    }
}
if (!function_exists('csc_quick_menu_print_css_hook')) {
    function csc_quick_menu_print_css_hook() { echo csc_quick_menu_css_markup(); }
}
if (!function_exists('csc_quick_menu_print_js_hook')) {
    function csc_quick_menu_print_js_hook() { echo csc_quick_menu_js_markup(); }
}

// ============================================================
// 2a. [9.1.4] Глобальная доставка меню — НЕ зависит от исполнения
//     шорткода. Раньше меню рендерилось только внутри буфера
//     шорткода ([service_calculator] и т.п.). Если страница отдаётся
//     из page-кэша / конструктора, который не прогоняет the_content
//     через шорткод-фильтр, разметка меню отсутствовала в HTML, а JS
//     (из wp_footer) молчал — «ничего не происходит». Теперь:
//      - автодетект страниц плагина по ID настроек и meta-флагам;
//      - меню принудительно вставляется в <body> через wp_footer,
//        если в отрендеренном HTML его ещё нет (идемпотентно).
// ============================================================
if (!function_exists('csc_quick_menu_target_page_ids')) {
    function csc_quick_menu_target_page_ids() {
        $ids   = [];
        $map   = []; // id => section
        $s     = get_option('csc_main_settings', []);
        $pairs = [
            ['calc'    => absint($s['calculator_page_id'] ?? 0)],
            ['cabinet' => absint(get_option('csc_cabinet_page_id', 0))],
            ['master'  => absint(get_option('csc_master_reg_page_id', 0)) ?: absint($s['master_page_id'] ?? 0)],
        ];
        foreach ($pairs as $p) { foreach ($p as $sec => $id) { if ($id && !isset($map[$id])) { $map[$id] = $sec; $ids[] = $id; } } }
        $meta_map = [
            'csc_calc_page_flag'       => 'calculator',
            'csc_cabinet_page_flag'    => 'cabinet',
            'csc_master_reg_page_flag' => 'master',
        ];
        $q = get_posts([
            'post_type' => ['page', 'landing-page'], 'posts_per_page' => 20,
            'meta_query' => [[ 'relation' => 'OR' ] + array_map(function ($k) {
                return ['key' => $k, 'value' => '1'];
            }, array_keys($meta_map))],
            'fields' => 'ids',
        ]);
        foreach ($q as $pid) {
            if (isset($map[$pid])) continue;
            foreach ($meta_map as $k => $sec) {
                if (get_post_meta($pid, $k, true) === '1') { $map[$pid] = $sec; break; }
            }
            $ids[] = $pid;
        }
        return ['ids' => array_values(array_unique($ids)), 'map' => $map];
    }
}

if (!function_exists('csc_quick_menu_current_section')) {
    /** Определяет раздел текущей страницы: data-атрибут > настройки ID > поиск шорткода. */
    function csc_quick_menu_current_section() {
        $pid = get_queried_object_id();
        if (!$pid) return null;
        $t = csc_quick_menu_target_page_ids();
        if (isset($t['map'][$pid])) return $t['map'][$pid];
        // Фолбэк: контент страницы содержит наш шорткод?
        $content = (string) get_post_field('post_content', $pid);
        if (has_shortcode($content, 'service_calculator') || strpos($content, '[service_calculator') !== false) return 'calculator';
        if (has_shortcode($content, 'csc_personal_cabinet') || strpos($content, '[csc_personal_cabinet') !== false) return 'cabinet';
        if (has_shortcode($content, 'csc_become_master') || strpos($content, '[csc_become_master') !== false) return 'master';
        return null;
    }
}

if (!function_exists('csc_quick_menu_should_render')) {
    function csc_quick_menu_should_render() {
        if (is_admin()) return false;
        // [9.1.4] По умолчанию меню рендерится на ВСЕХ фронтенд-страницах —
        // раньше оно зависело от исполнения шорткода/автодетекта, и на боевых
        // страницах (Elementor + кэш) разметка отсутствовала полностью.
        // Отключить: add_filter('csc_quick_menu_force_all', '__return_false');
        if (!apply_filters('csc_quick_menu_force_all', true)) {
            return csc_quick_menu_current_section() !== null;
        }
        return true;
    }
}

if (!function_exists('csc_quick_menu_footer_markup')) {
    function csc_quick_menu_footer_markup() {
        if (!csc_quick_menu_should_render()) return;
        // Разметка с data-csc-page — для точного определения раздела на клиенте.
        $section = (string) (csc_quick_menu_current_section() ?: 'calculator');
        echo '<div class="csc-qm-wrap" data-csc-page="' . esc_attr($section) . '">' . csc_quick_menu_inner_markup($section) . '</div>';
    }
}

if (!function_exists('csc_quick_menu_bootstrap')) {
    function csc_quick_menu_bootstrap() {
        if (!function_exists('add_action')) return;
        // Дубль CSS в wp_head идемпотентен: <style id="csc-quick-menu-css"> — браузер
        // применит второй такой же блок так же, вреда нет. Приоритет 5 — раньше
        // оптимизаторов, вырезающих вывод из wp_head/wp_footer.
        add_action('wp_head',   'csc_quick_menu_print_css_hook', 5);
        add_action('wp_footer', 'csc_quick_menu_print_js_hook', 50);
        add_action('wp_footer', 'csc_quick_menu_force_markup_hook', 60);
    }
}
if (!function_exists('csc_quick_menu_force_markup_hook')) {
    /**
     * [9.1.4] Принудительная вставка меню в конец <body>, если shortcode-рендер
     * не состоялся (кэш страницы/конструктор). Идемпотентность обеспечивает JS:
     * дубликаты .csc-qm схлопываются, обработчики навешиваются один раз.
     */
    function csc_quick_menu_force_markup_hook() {
        if (!csc_quick_menu_should_render()) return;
        global $csc_quick_menu_rendered_by_shortcode;
        if (!empty($csc_quick_menu_rendered_by_shortcode)) return; // шорткод уже отдал разметку
        csc_quick_menu_footer_markup();
    }
}

if (!function_exists('csc_quick_menu_css_markup')) {
    function csc_quick_menu_css_markup() {
        ob_start();
        ?>
<style id="csc-quick-menu-css">
/* ===== Быстрое меню «⋮» — цвета наследуют тему контейнера ===== */
/* [9.1.4] Обёртка — position:fixed: не зависит от position:relative
   родительского контейнера темы/конструктора (Elementor-секции, flex-контейнеры
   страницы регистрации и т.п.) — меню гарантированно вверху окна. */
.csc-qm-wrap{position:fixed;top:14px;left:0;right:0;height:0;z-index:99998;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;pointer-events:none}
.csc-qm{--qm-bg:rgba(255,255,255,.78);--qm-fg:#1e293b;--qm-br:rgba(15,23,42,.14);--qm-ring:rgba(59,130,246,.35);--qm-accent:#3b82f6;--qm-hover:rgba(59,130,246,.10);position:absolute;left:50%;transform:translateX(-50%);top:0;pointer-events:auto;background:var(--qm-bg);border:1px solid var(--qm-br);border-radius:999px;box-shadow:0 6px 18px rgba(15,23,42,.10);display:flex;align-items:center;height:44px;padding:0 4px;backdrop-filter:blur(12px) saturate(160%);-webkit-backdrop-filter:blur(12px) saturate(160%);transition:box-shadow .25s ease,border-color .25s ease;max-width:min(94vw,520px)}
.csc-qm.qm-placed{left:0;transform:none}
[data-theme="dark"] .csc-qm,.csc-qm[data-theme="dark"]{--qm-bg:rgba(15,23,42,.72);--qm-fg:#f1f5f9;--qm-br:rgba(255,255,255,.16);--qm-ring:rgba(96,165,250,.35);--qm-accent:#60a5fa;--qm-hover:rgba(96,165,250,.14);box-shadow:0 6px 18px rgba(0,0,0,.45)}
.csc-qm.open{border-color:var(--qm-accent);box-shadow:0 0 0 var(--qm-ring),0 8px 24px rgba(15,23,42,.16)}
.csc-qm-toggle{flex:0 0 auto;width:40px;height:40px;border-radius:50%;border:none;background:transparent;color:var(--qm-fg);font-size:20px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;touch-action:none;user-select:none;-webkit-user-select:none;outline:none;transition:background .2s ease,transform .2s ease}
.csc-qm-toggle:hover{background:var(--qm-hover)}
.csc-qm-toggle:focus-visible{box-shadow:0 0 0 3px var(--qm-ring)}
.csc-qm.dragging{cursor:grabbing}
.csc-qm.dragging .csc-qm-toggle{cursor:grabbing}
.csc-qm-items{display:flex;align-items:center;overflow:hidden;max-width:0;opacity:0;transition:max-width .35s cubic-bezier(.22,.9,.35,1),opacity .3s ease}
.csc-qm.open .csc-qm-items{max-width:220px;opacity:1}
.csc-qm-side-left .csc-qm-items{order:-1;padding-left:6px}
.csc-qm-side-right .csc-qm-items{padding-right:6px}
.csc-qm-item{flex:0 0 auto;width:38px;height:38px;margin:0 2px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--qm-fg);text-decoration:none;position:relative;transition:background .2s ease,color .2s ease}
.csc-qm-item:hover{background:var(--qm-hover);color:var(--qm-accent)}
.csc-qm-item.current{pointer-events:auto}
.csc-qm-item svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
/* tooltip при наведении */
.csc-qm-item::after{content:attr(data-tip);position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%) scale(.95);background:var(--qm-fg);color:var(--qm-bg);font-size:12px;line-height:1;padding:6px 9px;border-radius:8px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .15s ease,transform .15s ease}
.csc-qm-item:hover::after,.csc-qm-item:focus-visible::after{opacity:.95;transform:translateX(-50%) scale(1)}
@media (prefers-reduced-motion:reduce){.csc-qm,.csc-qm-items,.csc-qm-item,.csc-qm-item::after{transition:none!important}}
</style>
<?php
        return ob_get_clean();
    }
}

// ============================================================
// 2c. [9.1.3] Определение текущего раздела по факту рендера шорткода.
//     has_shortcode(post_content,...) НЕ работает для блоков Gutenberg
//     (шорткод лежит в HTML-комментарях блока) и для шаблонов конструкторов —
//     поэтому страница калькулятора ошибочно считалась «не текущей», а ссылки
//     вели на home_url('/'). Теперь раздел фиксируется в момент реального
//     вызова рендера меню (см. csc_render_quick_menu).
// ============================================================
if (!function_exists('csc_quick_menu_find_shortcode_page')) {
    function csc_quick_menu_find_shortcode_page($shortcode) {
        static $cache = [];
        if (isset($cache[$shortcode])) return $cache[$shortcode];
        $current_id = get_queried_object_id();
        // 1) прямая проверка post_content (классические страницы с шорткодом)
        if ($current_id && has_shortcode((string) get_post_field('post_content', $current_id), $shortcode)) {
            return $cache[$shortcode] = $current_id;
        }
        // 2) поиск по опубликованным страницам: post_content ИЛИ raw-block HTML
        $found = 0;
        $pages = get_posts([
            'post_type' => ['page', 'landing-page'], 'posts_per_page' => 200,
            'orderby' => 'ID', 'order' => 'ASC', 'exclude' => array_filter([$current_id]),
        ]);
        foreach ($pages as $p) {
            $content = (string) $p->post_content;
            if (has_shortcode($content, $shortcode) || strpos($content, '[' . $shortcode) !== false) {
                $found = $p->ID; break;
            }
        }
        // 3) meta-флаги установщика/страниц настроек
        if (!$found) {
            $meta_map = [
                'service_calculator'   => 'csc_calc_page_flag',
                'csc_personal_cabinet' => 'csc_cabinet_page_flag',
                'csc_become_master'    => 'csc_master_reg_page_flag',
            ];
            if (!empty($meta_map[$shortcode])) {
                $q = get_posts([
                    'post_type' => 'any', 'posts_per_page' => 1,
                    'meta_key' => $meta_map[$shortcode], 'meta_value' => '1',
                ]);
                if ($q) $found = $q[0]->ID;
            }
        }
        return $cache[$shortcode] = $found;
    }
}

// ============================================================
// 2d. [9.1.3] JS меню — вынесен в функцию и печатается И инлайн, И через
//     wp_footer. Улучшения boot:
//       - запуск сразу + повтор на DOMContentLoaded (защита от раннего парсинга);
//       - MutationObserver: если разметка меню появилась/обновилась после
//         кэша-плагина или динамической подстановки — кликеры навешиваются;
//       - диагностика window.cscQmDbg (loaded/booted/handlers/err).
// ============================================================
if (!function_exists('csc_quick_menu_js_markup')) {
    function csc_quick_menu_js_markup() {
        // [9.1.3] КРИТИЧЕСКИЙ FIX: JS печатается PHP-строкой (NOWDOC) — см. комментарий ниже.
        $js = <<<'JS'
<script id="csc-quick-menu-js">
(function(){
    'use strict';
    var DBG = window.cscQmDbg = window.cscQmDbg || {};
    DBG.loaded = true; DBG.booted = false; DBG.handlers = DBG.handlers || 0; DBG.err = DBG.err || null;
    try {
    if (window.CSC_QUICK_MENU_BOOTED) return;
    window.CSC_QUICK_MENU_BOOTED = true;

    var LS_POS  = 'csc_qm_pos_pct';      // горизонтальная позиция (процент от ширины окна)
    var HOLD_MS = 300;                   // порог «удержания» для начала перетаскивания
    var MOVE_TOL = 6;                    // px, ниже которых клик не считается перемещением

    // [9.1.3] Ширина вьюпорта: documentElement.clientWidth может быть undefined в
    // нестандартных окружениях → NaN в расчётах и плашка уезжала/не двигалась.
    function vwpx(){
        var d = document.documentElement || {}, b = document.body || {};
        return Math.max(d.clientWidth || 0, window.innerWidth || 0, b.clientWidth || 0, 320);
    }

    function each(sel, fn){ Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }

    function markPlaced(qm){ qm.classList.add('qm-placed'); }

    function initMenu(qm){
        if (qm.dataset.qmReady === '1') return;          // идемпотентность (двойная доставка)
        var wrap   = qm.parentNode;                       // .csc-qm-wrap
        var toggle = qm.querySelector('.csc-qm-toggle');
        var items  = qm.querySelector('.csc-qm-items');
        if (!toggle || !items) return;
        qm.dataset.qmReady = '1';
        DBG.handlers = (DBG.handlers || 0) + 1;

        /* ---------- восстановление сохранённой позиции ---------- */
        function place(){
            var vw = vwpx();
            var pct = parseFloat(localStorage.getItem(LS_POS) || '');
            if (isNaN(pct)) pct = 50;                     // по умолчанию — центр
            pct = Math.min(97, Math.max(3, pct));
            var w = qm.offsetWidth || 52;
            var left = Math.round(vw * pct / 100 - w / 2);
            left = Math.max(4, Math.min(left, vw - w - 4));
            qm.style.position = 'absolute';
            markPlaced(qm);                               // гасим CSS translateX(-50%)
            qm.style.left = left + 'px';
            qm.style.right = 'auto';
            updateSide(left + w / 2, vw);
        }
        /* сторона раскрытия: правая половина экрана → тянемся ВЛЕВО и наоборот */
        function updateSide(centerX, vw){
            vw = vw || vwpx();
            var openLeft = centerX > vw / 2;
            qm.classList.toggle('csc-qm-side-left', openLeft);
            qm.classList.toggle('csc-qm-side-right', !openLeft);
        }

        /* ---------- раскрытие/сворачивание ---------- */
        function setOpen(open){
            qm.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        toggle.addEventListener('click', function(e){
            if (qm.dataset.justDragged === '1') { e.preventDefault(); delete qm.dataset.justDragged; return; }
            setOpen(!qm.classList.contains('open'));
        });
        document.addEventListener('click', function(e){
            if (!qm.contains(e.target)) setOpen(false);
        }, true);
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') setOpen(false);
        });

        /* ---------- удержание ⇒ перетаскивание по горизонтали ---------- */
        var drag = null;
        toggle.addEventListener('pointerdown', function(e){
            // ВАЖНО: здесь НЕ вызываем preventDefault — иначе браузер не
            // генерирует click и раскрытие меню «не работает». Отмена
            // скролла/выделения делается через CSS touch-action:none на кнопке.
            if (e.pointerType === 'mouse' && e.button !== 0) return; // drag только ЛКМ
            var left0 = parseFloat(qm.style.left);
            if (isNaN(left0)) left0 = qm.offsetLeft;      // фиксируем до первого move
            drag = { x0: e.clientX, left0: left0, moved: false, active: false, pointerId: e.pointerId,
                     hold: setTimeout(function(){
                if (drag) { drag.active = true; qm.classList.add('dragging'); setOpen(true); try{ toggle.setPointerCapture(e.pointerId); }catch(_){} }
            }, HOLD_MS) };
        });
        toggle.addEventListener('pointermove', function(e){
            if (!drag) return;
            var dx = e.clientX - drag.x0;
            if (!drag.active) {
                if (Math.abs(dx) > MOVE_TOL) { clearTimeout(drag.hold); drag = null; } // короткий свайп ≠ перетаскивание
                return;
            }
            drag.moved = true;
            var vw = vwpx();
            var w  = qm.offsetWidth || 52;
            var left = Math.max(4, Math.min(drag.left0 + dx, vw - w - 4));
            markPlaced(qm);
            qm.style.left = left + 'px';
            updateSide(left + w / 2, vw);
        });
        function endDrag(){
            if (!drag) return;
            clearTimeout(drag.hold);
            if (drag.active) {
                var vw = vwpx();
                var w  = qm.offsetWidth || 52;
                var center = (parseFloat(qm.style.left) || qm.offsetLeft || 0) + w / 2;
                localStorage.setItem(LS_POS, String(Math.round(Math.min(97, Math.max(3, center * 100 / vw)))));
                if (drag.moved) qm.dataset.justDragged = '1';
                qm.classList.remove('dragging');
            }
            drag = null;
        }
        toggle.addEventListener('pointerup', endDrag);
        toggle.addEventListener('pointercancel', endDrag);
        window.addEventListener('resize', function(){ place(); });

        /* ---------- [9.1.4] Поправка подсветки по data-csc-page обёртки ----------
           Если сервер не смог определить раздел (конструктор/пустые настройки ID),
           inner markup помечен data-section="calculator"; обёртка шорткода несёт
           точное значение — переклеиваем current-состояние на клиенте. */
        var wsec = wrap && wrap.getAttribute ? wrap.getAttribute('data-csc-page') : null;
        if (wsec && qm.getAttribute('data-section') !== wsec) {
            qm.setAttribute('data-section', wsec);
            each('.csc-qm-item', function(it){
                var k = it.getAttribute('data-key');
                var isCur = (k === 'master' && wsec === 'master') || (k === 'cabinet' && wsec === 'cabinet');
                if (isCur) it.setAttribute('data-current', '1'); else it.removeAttribute('data-current');
            });
        }

        /* ---------- логика активного раздела ----------
           Раздел посетителя НЕ активен: его значок заменён НА СЕРВЕРЕ на
           переход к калькулятору (href=калькулятор, data-current="1").
           Здесь — защита от перезагрузки текущей страницы. */
        each('.csc-qm-item', function(it){
            if (it.getAttribute('data-current') !== '1') return;
            it.classList.add('current');
            it.addEventListener('click', function(e){
                var u;
                try { u = new URL(it.getAttribute('href') || '', location.origin); } catch (_) { return; }
                if (u.pathname.replace(/\/+$/, '') === location.pathname.replace(/\/+$/, '')) {
                    e.preventDefault(); // уже на этой странице — не перезагружать
                }
            });
        });

        place();
    }

    function boot(){
        // [9.1.4] Схлопывание дубликатов: разметка меню может прийти И из
        // шорткода, И из принудительной вставки wp_footer — оставляем один
        // экземпляр (последний, footer-ный), остальные удаляем вместе с их
        // обёртками .csc-qm-wrap.
        var all = document.querySelectorAll('.csc-qm');
        if (all.length > 1) {
            for (var i = 0; i < all.length - 1; i++) {
                var w = all[i].parentNode;
                all[i].remove();
                // если обёртка опустела — убираем и её
                if (w && w.classList && w.classList.contains('csc-qm-wrap') && !w.querySelector('.csc-qm')) w.remove();
            }
            DBG.deduped = (DBG.deduped || 0) + (all.length - 1);
        }
        each('.csc-qm', initMenu);
        DBG.booted = true;
    }

    // [9.1.3] Boot в трёх фазах: немедленно (скрипт в конце body / после разметки),
    // на DOMContentLoaded (скрипт из wp_head опережает разметку) и по MutationObserver
    // (разметка шорткода могла появиться позже — AJAX-подгрузка, конструктор страниц).
    boot();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    }
    try {
        var mo = new MutationObserver(function(){ boot(); });
        mo.observe(document.documentElement, { childList: true, subtree: true });
        setTimeout(function(){ mo.disconnect(); }, 10000); // observer живёт 10 с — хватит на рендер
    } catch (_) {}

    } catch (err) {
        try { DBG.err = String(err && err.stack || err); } catch (_) {}
        if (window.console && console.warn) console.warn('[csc-quick-menu] boot error:', err);
    }
})();
</script>
JS;
        return $js;
    }
}

// ============================================================
// 3. SVG-иконки и рендер разметки.
// ============================================================
if (!function_exists('csc_quick_menu_icons')) {
    function csc_quick_menu_icons($name) {
        switch ($name) {
            case 'master': // человечек с гаечным ключом
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9.5" cy="6.5" r="3.2"/><path d="M4.5 20v-1.6c0-2.4 2.2-4.2 5-4.2 1.2 0 2.3.3 3.2.9"/><path d="M16.7 13.9l3.1-3.1a1.9 1.9 0 0 1 2.6 2.6l-3.1 3.1-1.3-.4-.4-1.3z"/><path d="M17.6 16.1l2.3 2.3"/></svg>';
            case 'cabinet': // человечек
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7.5" r="3.6"/><path d="M5 20.5c0-3.4 3.1-5.6 7-5.6s7 2.2 7 5.6"/></svg>';
            case 'calc': // калькулятор (замена неактивного значка)
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2.5"/><line x1="8" y1="7" x2="16" y2="7"/><circle cx="9" cy="12" r=".6"/><circle cx="12" cy="12" r=".6"/><circle cx="15" cy="12" r=".6"/><circle cx="9" cy="16" r=".6"/><circle cx="12" cy="16" r=".6"/><circle cx="15" cy="16" r=".6"/></svg>';
        }
        return '';
    }
}

// ============================================================
// 3b. [9.1.4] Внутренняя разметка меню (без обёртки .csc-qm-wrap).
//     Используется и шорткод-рендером, и принудительной вставкой
//     в wp_footer. Позиционирование обёртки — fixed (не зависит от
//     position:relative родительского контейнера темы/конструктора).
// ============================================================
if (!function_exists('csc_quick_menu_inner_markup')) {
    function csc_quick_menu_inner_markup($section = '') {
        // [9.1.4] Пустая $section = «сервер не определил раздел» → фолбэк
        // 'calculator'; JS поправит подсветку по data-csc-page обёртки.
        $guessed = false;
        if (!is_string($section) || $section === '') { $section = 'calculator'; $guessed = true; }
        $urls = csc_quick_menu_build_urls();
        if (!$guessed && !empty($urls['page_id'])) {
            $pid      = (int) $urls['page_id'];
            $t        = csc_quick_menu_target_page_ids();
            $sec_byid = $t['map'][$pid] ?? null;
            if ($sec_byid) {
                $section = $sec_byid;
            } else {
                $cab_page = absint(get_option('csc_cabinet_page_id', 0));
                $calc_pid = csc_quick_menu_find_shortcode_page('service_calculator');
                $mas_pid  = csc_quick_menu_find_shortcode_page('csc_become_master');
                if ($cab_page && $cab_page === $pid)     $section = 'cabinet';
                elseif ($mas_pid && $mas_pid === $pid)   $section = 'master';
                elseif ($calc_pid && $calc_pid === $pid) $section = 'calculator';
            }
        }
        $items = [
            ['key' => 'master',  'href' => $urls['master'],  'tip' => 'Стать мастером',  'icon' => 'master',  'cur' => ($section === 'master')],
            ['key' => 'cabinet', 'href' => $urls['cabinet'], 'tip' => 'Личный кабинет', 'icon' => 'cabinet', 'cur' => ($section === 'cabinet')],
        ];
        ob_start();
        ?>
    <div class="csc-qm csc-qm-side-right" data-page-id="<?php echo (int) $urls['page_id']; ?>" data-section="<?php echo esc_attr($section); ?>" role="navigation" aria-label="Быстрое меню">
        <button type="button" class="csc-qm-toggle" aria-label="Быстрое меню" aria-expanded="false" title="Меню (клик — открыть, удержать и тянуть — переместить)">&#8942;</button>
        <div class="csc-qm-items">
            <?php foreach ($items as $it) :
                // Текущий раздел: значок подменяется на переход к калькулятору
                $href = $it['cur'] ? $urls['calc'] : $it['href'];
                $tip  = $it['cur'] ? 'Рассчитать услугу' : $it['tip'];
                $icon = $it['cur'] ? 'calc' : $it['icon'];
            ?>
            <a class="csc-qm-item<?php echo $it['cur'] ? ' current' : ''; ?>"
               href="<?php echo esc_url($href); ?>"
               data-key="<?php echo esc_attr($it['key']); ?>"
               <?php echo $it['cur'] ? 'data-current="1" ' : ''; ?>data-tip="<?php echo esc_attr($tip); ?>"
               title="<?php echo esc_attr($tip); ?>">
                <?php echo csc_quick_menu_icons($icon); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('csc_render_quick_menu')) {
    /**
     * $section = 'calculator' | 'cabinet' | 'master' — на каком разделе идёт рендер.
     * Значок текущего раздела НЕ АКТИВЕН как раздел: он подменён (на сервере)
     * на переход к странице калькулятора («Рассчитать услугу»).
     */
    function csc_render_quick_menu($section = 'calculator') {
        global $csc_quick_menu_rendered_by_shortcode;
        $csc_quick_menu_rendered_by_shortcode = true; // footer-фолбэк не нужен
        csc_quick_menu_print_assets();               // гарантирует регистрацию хуков активов
        // Разметка — только HTML-часть (стили/JS идут через wp_head/wp_footer).
        return '<div class="csc-qm-wrap" data-csc-page="' . esc_attr($section) . '">'
             . csc_quick_menu_inner_markup($section)
             . '</div>';
    }
}
