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
        if ($current_id && has_shortcode((string) get_post_field('post_content', $current_id), 'service_calculator')) {
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
        if ($current_id && has_shortcode((string) get_post_field('post_content', $current_id), 'csc_become_master')) {
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
        static $printed = false;
        if ($printed) return;
        $printed = true;

        // [9.1.2] Диагностика: window.cscQmDbg фиксирует загрузку скрипта и факт boot'а.
        // Если на странице cscQmDbg.booted=false — JS до меню не дошёл (конфликт/обрезка).
        ?>
<style id="csc-quick-menu-css">
/* ===== Быстрое меню «⋮» — цвета наследуют тему контейнера ===== */
/* [9.1.2] display:flex убран: без него обёртка — normal-flow блок нулевой высоты,
   absolute-плашка в нём становилась «призраком» (0×0), клики по ⋮ не попадали
   в кнопку → «ничего не происходит», drag невозможен. .csc-qm позиционируется
   абсолютно сам; pointer-events:none на обёртке больше не нужен. */
.csc-qm-wrap{position:absolute;top:14px;left:0;right:0;z-index:99998;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
.csc-qm{--qm-bg:rgba(255,255,255,.78);--qm-fg:#1e293b;--qm-br:rgba(15,23,42,.14);--qm-ring:rgba(59,130,246,.35);--qm-accent:#3b82f6;--qm-hover:rgba(59,130,246,.10);position:absolute;left:50%;transform:translateX(-50%);top:0;pointer-events:auto;background:var(--qm-bg);border:1px solid var(--qm-br);border-radius:999px;box-shadow:0 6px 18px rgba(15,23,42,.10);display:flex;align-items:center;height:44px;padding:0 4px;backdrop-filter:blur(12px) saturate(160%);-webkit-backdrop-filter:blur(12px) saturate(160%);transition:box-shadow .25s ease,border-color .25s ease;max-width:min(94vw,520px)}
/* [9.1.2] после place()/drag JS переводит в left+translate:none — transform больше не мешает */
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
<script id="csc-quick-menu-js">
(function(){
    'use strict';
    window.cscQmDbg = { loaded: true, booted: false, err: null }; // [9.1.2] диагностика boot'а
    try {
    if (window.CSC_QUICK_MENU_BOOTED) return;
    window.CSC_QUICK_MENU_BOOTED = true;

    var LS_POS  = 'csc_qm_pos_pct';      // горизонтальная позиция (процент от ширины окна)
    var HOLD_MS = 300;                   // порог «удержания» для начала перетаскивания
    var MOVE_TOL = 6;                    // px, ниже которых клик не считается перемещением

    function each(sel, fn){ Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }

    // [9.1.2] place() задаёт qm.style.left в пикселях; без снятия transform из CSS
    // сдвиг translateX(-50%) ломал бы hit-test и расчёт позиции — плашка «уезжала».
    function markPlaced(qm){ qm.classList.add('qm-placed'); }

    each('.csc-qm', function(qm){
        var wrap   = qm.parentNode;                       // .csc-qm-wrap
        var toggle = qm.querySelector('.csc-qm-toggle');
        var items  = qm.querySelector('.csc-qm-items');
        if (!toggle || !items) return;

        /* ---------- восстановление сохранённой позиции ---------- */
        function place(){
            var vw = Math.max(document.documentElement.clientWidth, 320);
            var pct = parseFloat(localStorage.getItem(LS_POS) || '');
            if (isNaN(pct)) pct = 50;                     // по умолчанию — центр
            pct = Math.min(97, Math.max(3, pct));
            var w = qm.offsetWidth || 52;
            var left = Math.round(vw * pct / 100 - w / 2);
            left = Math.max(4, Math.min(left, vw - w - 4));
            qm.style.position = 'absolute';
            markPlaced(qm);                               // [9.1.2] гасим CSS translateX(-50%)
            qm.style.left = left + 'px';
            qm.style.right = 'auto';
            updateSide(left + w / 2, vw);
        }
        /* сторона раскрытия: правая половина экрана → тянемся ВЛЕВО и наоборот */
        function updateSide(centerX, vw){
            vw = vw || Math.max(document.documentElement.clientWidth, 320);
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
            var vw = Math.max(document.documentElement.clientWidth, 320);
            var w  = qm.offsetWidth || 52;
            var left = Math.max(4, Math.min(drag.left0 + dx, vw - w - 4));
            markPlaced(qm);                               // [9.1.2] translateX(-50%) погашен
            qm.style.left = left + 'px';
            updateSide(left + w / 2, vw);
        });
        function endDrag(){
            if (!drag) return;
            clearTimeout(drag.hold);
            if (drag.active) {
                var vw = Math.max(document.documentElement.clientWidth, 320);
                var w  = qm.offsetWidth || 52;
                var center = (parseFloat(qm.style.left) || qm.offsetLeft) + w / 2;
                localStorage.setItem(LS_POS, String(Math.round(Math.min(97, Math.max(3, center * 100 / vw)))));
                if (drag.moved) qm.dataset.justDragged = '1';
                qm.classList.remove('dragging');
            }
            drag = null;
        }
        toggle.addEventListener('pointerup', endDrag);
        toggle.addEventListener('pointercancel', endDrag);
        window.addEventListener('resize', function(){ place(); });

        /* ---------- логика активного раздела ----------
           Раздел, на котором находится посетитель, НЕ активен: его значок
           заменён на переход к странице калькулятора. Подмена выполнена
           уже НА СЕРВЕРЕ (PHP): current-элемент приходит с href=калькулятор,
           data-current="1", иконкой-калькулятором и data-tip="Рассчитать услугу".
           Здесь — только accessibility-атрибуты и защита от перехода
           по ссылке на текущую страницу (без перезагрузки). */
        each('.csc-qm-item', function(it){
            if (it.getAttribute('data-current') !== '1') return;
            it.classList.add('current');
            it.setAttribute('aria-disabled', 'false'); // ссылка рабочая — ведёт на калькулятор
            it.addEventListener('click', function(e){
                var u;
                try { u = new URL(it.getAttribute('href') || '', location.origin); } catch (_) { return; }
                if (u.pathname.replace(/\/+$/, '') === location.pathname.replace(/\/+$/, '')) {
                    e.preventDefault(); // уже на этой странице — не перезагружать
                }
            });
        });

        place();
    });

    window.cscQmDbg.booted = true;                        // [9.1.2] boot завершён успешно
    } catch (err) {
        // [9.1.2] не глушим ошибку молча: фиксируем в диагностике и console
        try { window.cscQmDbg.err = String(err && err.stack || err); } catch (_) {}
        if (window.console && console.warn) console.warn('[csc-quick-menu] boot error:', err);
    }
})();
</script>
        <?php
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

if (!function_exists('csc_render_quick_menu')) {
    /**
     * $section = 'calculator' | 'cabinet' — на каком разделе идёт рендер.
     * Значок текущего раздела НЕ АКТИВЕН как раздел: он подменён (на сервере)
     * на переход к странице калькулятора («Рассчитать услугу»).
     */
    function csc_render_quick_menu($section = 'calculator') {
        $urls  = csc_quick_menu_build_urls();
        $items = [];

        // 1) Стать мастером
        $items[] = [
            'key'   => 'master',
            'href'  => $urls['master'],
            'tip'   => 'Стать мастером',
            'icon'  => 'master',
            'cur'   => ($section === 'master'),
        ];
        // 2) Личный кабинет
        $items[] = [
            'key'   => 'cabinet',
            'href'  => $urls['cabinet'],
            'tip'   => 'Личный кабинет',
            'icon'  => 'cabinet',
            'cur'   => ($section === 'cabinet'),
        ];

        ob_start();
        csc_quick_menu_print_assets();
        ?>
<div class="csc-qm-wrap">
    <div class="csc-qm csc-qm-side-right" data-page-id="<?php echo (int) $urls['page_id']; ?>" role="navigation" aria-label="Быстрое меню">
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
</div>
        <?php
        return ob_get_clean();
    }
}
