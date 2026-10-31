<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// Быстрое меню плагина (⋮) — «три точки» в контуре на страницах
// калькулятора и личного кабинета.
//
// Поведение:
//  - клик по ⋮ раздвигает контур влево или вправо (сторона зависит от
//    положения кнопки: правее центра экрана — растёт влево, левее — вправо)
//    и показывает два значка:
//      1) «мастер с гаечным ключом» → Стать мастером (подсказка при наведении)
//      2) «человечек»               → Личный кабинет (подсказка при наведении)
//  - стили берут CSS-переменные темы страницы (--csc-card/--csc-text/
//    --csc-border/--csc-primary), тёмная тема наследуется автоматически;
//  - удержание кнопки (pointer drag) перемещает её по горизонтали вдоль
//    верхней кромки страницы; позиция сохраняется в localStorage и
//    восстанавливается при повторном входе.
// ============================================================

if (!function_exists('csc_quick_menu_urls')) {
    /**
     * URL пунктов меню: страница с шорткодом [csc_become_master] и страница ЛК.
     */
    function csc_quick_menu_urls() {
        $find_shortcode_page = function ($shortcode_tag) {
            $pages = get_pages([
                'post_status' => 'publish',
                'numberposts' => -1,
            ]);
            foreach ($pages as $p) {
                if (has_shortcode((string) $p->post_content, $shortcode_tag)) {
                    return get_permalink($p);
                }
            }
            return '';
        };

        // Мастер: страница с [csc_become_master] → главная (фолбэк).
        $master_url = $find_shortcode_page('csc_become_master');
        $master_url = $master_url ? esc_url($master_url) : esc_url(home_url('/'));

        // Кабинет: опция csc_cabinet_page_id (используется и в push deep-link)
        // → страница с [csc_personal_cabinet] → главная (фолбэк).
        $cab_id  = absint(get_option('csc_cabinet_page_id', 0));
        $cab_url = ($cab_id && get_permalink($cab_id)) ? esc_url(get_permalink($cab_id)) : '';
        if (!$cab_url) {
            $found = $find_shortcode_page('csc_personal_cabinet');
            $cab_url = $found ? esc_url($found) : esc_url(home_url('/'));
        }

        return [
            'master'  => $master_url,
            'cabinet' => $cab_url,
            'labels'  => [
                'menu'    => __('Меню', 'csc'),
                'master'  => __('Стать мастером', 'csc'),
                'cabinet' => __('Личный кабинет', 'csc'),
            ],
        ];
    }
}

if (!function_exists('csc_quick_menu_assets')) {
    /**
     * Инлайн-CSS + JS быстрого меню. Выводится один раз за страницу
     * (статический флаг), независимо от того, сколько хост-шорткодов его запросили.
     */
    function csc_quick_menu_assets() {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        $cfg = csc_quick_menu_urls();
        ?>
<style id="csc-quick-menu-css">
/* ===== Быстрое меню плагина (.csc-qm) ===== */
/* Все цвета — через переменные темы страницы; fallback-значения — константы
   light-темы, чтобы меню выглядело корректно и вне калькулятора/кабинета. */
.csc-qm {
    position: fixed;
    top: 14px;
    z-index: 99998;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 7px;
    border-radius: 999px;
    border: 1px solid var(--csc-border, rgba(255,255,255,0.6));
    background: var(--csc-card, rgba(255,255,255,0.5));
    box-shadow: var(--csc-shadow, 0 4px 12px rgba(0,0,0,0.1));
    color: var(--csc-text, #1e293b);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    user-select: none;
    -webkit-user-select: none;
    touch-action: manipulation; /* жесты рисует сам обработчик pointer-drag */
}
@supports ((backdrop-filter: blur(10px)) or (-webkit-backdrop-filter: blur(10px))) {
    .csc-qm { backdrop-filter: blur(15px) saturate(180%); -webkit-backdrop-filter: blur(15px) saturate(180%); }
}
/* Тёмная тема. Источник переменных — ближайший предок с data-theme="dark":
   у калькулятора это #csc-calculator, у кабинета/страницы — body. Значения
   соответствуют dark-палитре обоих шорткодов. */
[data-theme="dark"] .csc-qm,
.csc-qm[data-theme="dark"] {
    --csc-card: rgba(30,41,59,0.72);
    --csc-text: #f1f5f9;
    --csc-border: rgba(255,255,255,0.14);
    --csc-shadow: 0 8px 32px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.15);
    --csc-ctl-ring: rgba(59,130,246,0.28);
}
[data-theme="dark"] .csc-qm .csc-qm-tip,
.csc-qm[data-theme="dark"] .csc-qm-tip {
    background: #f1f5f9;
    color: #0f172a;
}
.csc-qm-button {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid var(--csc-border, rgba(255,255,255,0.6));
    background: transparent;
    color: inherit;
    font-size: 20px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
    outline: none;
    flex: 0 0 auto;
    transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease;
}
.csc-qm-button:hover { transform: scale(1.08); border-color: var(--csc-primary, #3b82f6); }
.csc-qm-button:focus-visible { box-shadow: 0 0 0 3px var(--csc-ctl-ring, rgba(59,130,246,0.18)); }
.csc-qm-grip { font-size: 18px; }
/* Пункты: скрыты в свёрнутом контуре, растягиваются вместе с ним */
.csc-qm-item {
    width: 0;
    height: 40px;
    overflow: hidden;
    border-radius: 50%;
    border: 1px solid transparent;
    background: transparent;
    color: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
    opacity: 0;
    flex: 0 0 auto;
    text-decoration: none;
    white-space: nowrap;
    transition: width 0.28s ease, opacity 0.22s ease, border-color 0.2s ease, background 0.2s ease;
}
.csc-qm.open .csc-qm-item {
    width: 40px;
    opacity: 1;
    border-color: var(--csc-border, rgba(255,255,255,0.6));
}
.csc-qm-item:hover { border-color: var(--csc-primary, #3b82f6); background: var(--csc-ctl-ring, rgba(59,130,246,0.18)); }
.csc-qm-item svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: 0 0 auto; }
/* Подсказка при наведении */
.csc-qm-tip {
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) scale(0.95);
    background: var(--csc-text, #1e293b);
    color: var(--csc-card, #fff);
    font-size: 12px;
    line-height: 1.2;
    padding: 5px 10px;
    border-radius: 8px;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.csc-qm-item { position: relative; }
.csc-qm-item:hover .csc-qm-tip,
.csc-qm-item:focus-visible .csc-qm-tip { opacity: 0.95; transform: translateX(-50%) scale(1); }
/* Состояние перетаскивания */
.csc-qm.dragging { border-color: var(--csc-primary, #3b82f6); opacity: 0.95; cursor: ew-resize; }
.csc-qm.dragging .csc-qm-item { transition: none; }
@media (max-width: 768px) {
    .csc-qm { top: 10px; padding: 4px 6px; }
    .csc-qm-button, .csc-qm.open .csc-qm-item { width: 34px; height: 34px; }
    .csc-qm-item { height: 34px; }
    .csc-qm-button { font-size: 17px; }
    .csc-qm-item svg { width: 19px; height: 19px; }
}
@media (prefers-reduced-motion: reduce) {
    .csc-qm, .csc-qm * { transition-duration: 0.01ms !important; }
}
</style>
<script id="csc-quick-menu-js">
(function () {
    'use strict';
    if (window.cscQuickMenu) return; // один инстанс на страницу

    var CFG = <?php echo wp_json_encode($cfg); ?>;
    var LS_KEY = 'csc_qm_pos_x';   // горизонтальная позиция (px от левого края)
    var EDGE_GAP = 12;             // минимальный отступ от краёв экрана
    var HOLD_MS = 350;             // удержание = начало перетаскивания
    var MOVE_TOL = 6;              // px, после которых движение считается drag, а не click

    // Значок 1: человечек с гаечным ключом («Стать мастером»)
    var ICON_MASTER = '<svg viewBox="0 0 24 24" aria-hidden="true">' +
        '<circle cx="9.5" cy="7" r="3.2"/>' +
        '<path d="M3.8 19.5c0-3.4 2.6-5.6 5.7-5.6s5.7 2.2 5.7 5.6"/>' +
        '<path d="M18.9 10.6l-1.5 1.5-.9-.9-1.2.3-.3 1.2.9.9-1.5 1.5 1.7 1.7 1.5-1.5.9.9 1.2-.3.3-1.2-.9-.9 1.5-1.5z"/>' +
        '</svg>';
    // Значок 2: человечек («Личный кабинет»)
    var ICON_PERSON = '<svg viewBox="0 0 24 24" aria-hidden="true">' +
        '<circle cx="12" cy="7.5" r="3.6"/>' +
        '<path d="M5 20c0-3.8 3.1-6.2 7-6.2s7 2.4 7 6.2"/>' +
        '</svg>';

    function clamp(x, el) {
        var maxX = Math.max(EDGE_GAP, window.innerWidth - el.offsetWidth - EDGE_GAP);
        return Math.min(Math.max(x, EDGE_GAP), maxX);
    }

    function restorePosition(el) {
        var raw = null;
        try { raw = localStorage.getItem(LS_KEY); } catch (e) {}
        var x = raw === null ? null : parseFloat(raw);
        if (x === null || isNaN(x)) {
            // по умолчанию — справа вверху (рядом с кнопкой темы)
            el.style.left = 'auto';
            el.style.right = EDGE_GAP + 'px';
            return;
        }
        el.style.left = clamp(x, el) + 'px';
        el.style.right = 'auto';
    }

    function savePosition(el) {
        try { localStorage.setItem(LS_KEY, String(Math.round(el.getBoundingClientRect().left))); } catch (e) {}
    }

    function build() {
        if (document.querySelector('.csc-qm')) return;
        if (!document.body) return;

        // На странице ЛК меню появляется только после авторизации: до входа
        // оно перекрывало бы форму, а пункт «Личный кабинет» был бы циклической
        // ссылкой. Ждём класс .logged-in (навешивается в renderOrders).
        var cabinet = document.getElementById('csc-cabinet');
        if (cabinet && !cabinet.classList.contains('logged-in')) {
            var mo = new MutationObserver(function () {
                if (cabinet.classList.contains('logged-in')) {
                    mo.disconnect();
                    build();
                }
            });
            mo.observe(cabinet, { attributes: true, attributeFilter: ['class'] });
            return;
        }

        var el = document.createElement('div');
        el.className = 'csc-qm';
        el.setAttribute('role', 'toolbar');
        el.setAttribute('aria-label', CFG.labels.menu);

        // Тёмная тема: калькулятор держит data-theme на контейнере, а меню —
        // fixed и живёт в <body>. Копируем активную тему на сам элемент и
        // слушаем её изменения (переключатель темы, системная тема).
        function themeSource() {
            var c = document.getElementById('csc-calculator');
            if (c && c.getAttribute('data-theme')) return c.getAttribute('data-theme');
            if (document.body.getAttribute('data-theme')) return document.body.getAttribute('data-theme');
            try {
                var saved = localStorage.getItem('csc_calc_theme');
                if (saved) return saved;
            } catch (e) {}
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
            return 'light';
        }
        function syncTheme() {
            var t = themeSource();
            if (t === 'dark') el.setAttribute('data-theme', 'dark');
            else el.removeAttribute('data-theme');
        }
        syncTheme();
        new MutationObserver(syncTheme).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });
        var calcEl = document.getElementById('csc-calculator');
        if (calcEl) new MutationObserver(syncTheme).observe(calcEl, { attributes: true, attributeFilter: ['data-theme'] });
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            if (mq.addEventListener) mq.addEventListener('change', syncTheme);
            else if (mq.addListener) mq.addListener(syncTheme);
        }

        el.innerHTML =
            '<button type="button" class="csc-qm-button csc-qm-toggle" aria-expanded="false" aria-haspopup="true" title="' + CFG.labels.menu + '">' +
                '<span class="csc-qm-grip" aria-hidden="true">&#8942;</span>' +
            '</button>' +
            '<a class="csc-qm-item csc-qm-master" href="' + CFG.master + '" aria-label="' + CFG.labels.master + '">' +
                ICON_MASTER + '<span class="csc-qm-tip">' + CFG.labels.master + '</span></a>' +
            '<a class="csc-qm-item csc-qm-cabinet" href="' + CFG.cabinet + '" aria-label="' + CFG.labels.cabinet + '">' +
                ICON_PERSON + '<span class="csc-qm-tip">' + CFG.labels.cabinet + '</span></a>';
        document.body.appendChild(el);

        restorePosition(el);

        var toggle = el.querySelector('.csc-qm-toggle');

        // ---- раскрытие/сворачивание: контур растягивается в свободную сторону ----
        function setOpen(open) {
            var rect = el.getBoundingClientRect();
            var centerX = rect.left + rect.width / 2;
            // Кнопка в правой половине экрана — растём влево (row-reverse),
            // в левой — вправо (row).
            el.style.flexDirection = (open && centerX > window.innerWidth / 2)
                ? 'row-reverse' : 'row';
            el.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        toggle.addEventListener('click', function () {
            setOpen(!el.classList.contains('open'));
        });
        document.addEventListener('click', function (ev) {
            if (el.classList.contains('open') && !el.contains(ev.target)) setOpen(false);
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && el.classList.contains('open')) setOpen(false);
        });

        // ---- удержание = перетаскивание по горизонтали ----
        var pid = null, startX = 0, startLeft = 0, holdTimer = null, moved = false;

        toggle.addEventListener('pointerdown', function (ev) {
            if (ev.button !== undefined && ev.button !== 0) return;
            pid = ev.pointerId;
            startX = ev.clientX;
            startLeft = el.getBoundingClientRect().left;
            moved = false;
            // preventDefault только когдаdrag начался — обычный клик не ломаем
            holdTimer = setTimeout(function () {
                moved = true;
                el.classList.add('dragging');
                setOpen(false);
                try { toggle.setPointerCapture(pid); } catch (e) {}
            }, HOLD_MS);
        });

        toggle.addEventListener('pointermove', function (ev) {
            if (pid === null || ev.pointerId !== pid) return;
            var dx = ev.clientX - startX;
            if (!moved && Math.abs(dx) > MOVE_TOL) {
                clearTimeout(holdTimer);
                moved = true;
                el.classList.add('dragging');
                setOpen(false);
                try { toggle.setPointerCapture(pid); } catch (e) {}
            }
            if (moved) {
                el.style.left = clamp(startLeft + dx, el) + 'px';
                el.style.right = 'auto';
            }
        });

        function endDrag(ev) {
            if (pid === null || (ev && ev.pointerId !== pid)) return;
            clearTimeout(holdTimer);
            if (moved) {
                savePosition(el);
                // подавляем «клик», родившийся из перетаскивания
                var kill = function (ce) { ce.stopPropagation(); ce.preventDefault(); };
                toggle.addEventListener('click', kill, { capture: true, once: true });
                el.classList.remove('dragging');
            }
            pid = null;
            moved = false;
        }
        toggle.addEventListener('pointerup', endDrag);
        toggle.addEventListener('pointercancel', endDrag);

        // ---- пересчёт позиции при изменении размера окна ----
        var rzT = null;
        window.addEventListener('resize', function () {
            clearTimeout(rzT);
            rzT = setTimeout(function () {
                var x = el.getBoundingClientRect().left;
                el.style.left = clamp(x, el) + 'px';
                el.style.right = 'auto';
                savePosition(el);
            }, 150);
        });
    }

    window.cscQuickMenu = { mount: build };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
</script>
        <?php
    }
}
