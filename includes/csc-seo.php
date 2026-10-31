<?php
/**
 * CSC SEO — промышленный SEO-модуль плагина Custom Service Calculator.
 *
 * Места интеграции, закрытые этим модулем (см. аудит в /SEO-AUDIT.md):
 *  1. Meta title/description для страниц с шорткодами плагина (калькулятор, ЛК, «Стать мастером»).
 *  2. Open Graph + Twitter Card (корректные type/url/title/description/image).
 *  3. JSON-LD микроразметка: LocalBusiness, Service, FAQPage, BreadcrumbList, ItemList услуг.
 *  4. XML-Sitemap («/csc-sitemap.xml») разделов, услуг и городов + flush при изменении данных.
 *  5. robots.txt: директива Sitemap + noindex для служебных параметров (?csc_master_dashboard=1 и т.п.).
 *  6. Канонические URL (чистый ?p=ID вместо query-параметров плагинов).
 *  7. SEO-поля в админке: мета-описания разделов/услуг, глобальные шаблоны title/description.
 *  8. Внутренняя перелинковка: блок «Похожие услуги» + footer-меню городов/разделов.
 *  9. Server-side rendering каталога услуг внутри [service_calculator] (индексability контента:
 *     раньше весь каталог грузился AJAX-ом и был невидим для краулеров).
 * 10. Оптимизация Core Web Vitals: defer для html2canvas, отключение тяжёлых ассетов на not-found.
 *
 * Все функции обёрнуты в if (!function_exists(...)) — безопасно при ререгистрации.
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// 1. ХРАНИЛИЩЕ SEO-НАСТРОЕК (option csc_seo_settings)
// ============================================================

if (!function_exists('csc_seo_get_defaults')) {
    function csc_seo_get_defaults() {
        return [
            'enabled'            => 1,      // общий выключатель модуля
            'sitemap_enabled'    => 1,      // /csc-sitemap.xml
            'robots_sitemap'     => 1,      // строка Sitemap: в robots.txt
            'og_enabled'         => 1,      // Open Graph / Twitter
            'jsonld_enabled'     => 1,      // JSON-LD
            'ssr_catalog'        => 1,      // server-side каталог услуг в шорткоде
            'cross_links'        => 1,      // перелинковка (footer-меню городов)
            'canonical_query'    => 1,      // canonical ?p=ID для страниц с шорткодами
            'title_template'     => '%title% — %sitename%',
            'calc_title'         => 'Расчёт стоимости услуг и вызов мастера — онлайн-калькулятор',
            'calc_desc'          => 'Рассчитайте стоимость услуги за 30 секунд: выберите раздел, услуги, укажите адрес и время. Прозрачные цены, %. Работаем в %cities%. Гарантия на все работы.',
            'cabinet_title'      => 'Личный кабинет клиента — статусы и история заказов',
            'cabinet_desc'       => 'Вход по номеру телефона: история заявок, статусы выполнения, повтор заказа и оценка работ мастера.',
            'master_title'       => 'Стать мастером — подключение к сервису',
            'master_desc'       => 'Регистрация мастеров: заказы рядом с домом, свободный график, еженедельные выплаты, рейтинг и отзывы. Города: %cities%.',
            'default_image'      => '',     // URL картинки по умолчанию для OG
            'business_name'      => '',     // LocalBusiness name (пусто = blogname)
            'business_type'      => 'HomeAndConstructionBusiness', // schema.org @type
            'business_phone'     => '',
            'business_email'     => '',
            'business_area'      => '',     // AreaServed, через запятую
            'noindex_service_pages' => 0,   // резерв: noindex для служебных экранов
        ];
    }
}

if (!function_exists('csc_seo_get_settings')) {
    function csc_seo_get_settings() {
        static $cache = null;
        if ($cache === null) {
            $raw = get_option('csc_seo_settings', []);
            $cache = wp_parse_args(is_array($raw) ? $raw : [], csc_seo_get_defaults());
        }
        return $cache;
    }
}

if (!function_exists('csc_seo_enabled')) {
    function csc_seo_enabled($key = 'enabled') {
        $s = csc_seo_get_settings();
        return !empty($s['enabled']) && !empty($s[$key]);
    }
}

// ---- SEO-тексты разделов/услуг (поле seo_desc уже предусмотрено форматом данных) ----

if (!function_exists('csc_seo_section_text')) {
    function csc_seo_section_text($section) {
        if (is_string($section)) $section = ['name' => $section];
        $seo = trim((string)($section['seo_desc'] ?? ''));
        return $seo !== '' ? $seo : trim((string)($section['name'] ?? ''));
    }
}

if (!function_exists('csc_seo_item_text')) {
    function csc_seo_item_text($item) {
        $seo = trim((string)($item['seo_desc'] ?? ''));
        return $seo !== '' ? $seo : trim((string)($item['name'] ?? ''));
    }
}

// ============================================================
// 2. ПЕРСИНТ: определение страниц плагина и их метаданных
// ============================================================

if (!function_exists('csc_seo_detect_page_kind')) {
    /**
     * @return string|null 'calculator' | 'cabinet' | 'become_master' | null
     */
    function csc_seo_detect_page_kind() {
        if (!is_singular()) return null;
        $post = get_post();
        if (!$post) return null;
        $c = (string)$post->post_content;
        if (has_shortcode($c, 'service_calculator'))        return 'calculator';
        if (has_shortcode($c, 'csc_personal_cabinet'))      return 'cabinet';
        if (has_shortcode($c, 'csc_become_master'))         return 'become_master';
        return null;
    }
}

if (!function_exists('csc_seo_cities_list')) {
    /** Активные города: массив названий (для %cities% и LocalBusiness.areaServed). */
    function csc_seo_cities_list() {
        $out = [];
        if (!function_exists('csc_get_cities')) return $out;
        foreach ((array)csc_get_cities() as $city) {
            if (!empty($city['active']) && !empty($city['name'])) $out[] = $city['name'];
        }
        return $out;
    }
}

if (!function_exists('csc_seo_price_from')) {
    /** «от N ₽» — минимальная цена среди доступных услуг (сильный CTR-фактор сниппета). */
    function csc_seo_price_from() {
        $min = 0;
        foreach ((array)csc_get_items() as $item) {
            if (empty($item['is_available'])) continue;
            $p = (float)($item['price'] ?? 0);
            if ($p <= 0) continue;
            if ($min === 0 || $p < $min) $min = $p;
        }
        return $min > 0 ? 'от ' . number_format($min, 0, ',', ' ') . ' ₽' : '';
    }
}

if (!function_exists('csc_seo_apply_tokens')) {
    /** Подстановка %sitename%, %cities%, %price_from% в шаблоны meta-полей. */
    function csc_seo_apply_tokens($text) {
        $cities = csc_seo_cities_list();
        return str_replace(
            ['%sitename%', '%cities%', '%price_from%'],
            [get_bloginfo('name'), implode(', ', $cities), csc_seo_price_from()],
            $text
        );
    }
}

// ============================================================
// 3. META TITLE / DESCRIPTION / CANONICAL / ROBOTS
//    Приоритет 4: до Yoast (`wpseo_head`, приоритет 5) — наши фильтры
//    применяются раньше, а фолбэки — только если Yoast молчит.
// ============================================================

add_filter('pre_get_document_title', 'csc_seo_filter_title', 4);
if (!function_exists('csc_seo_filter_title')) {
    function csc_seo_filter_title($title) {
        if (!csc_seo_enabled()) return $title;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return $title;
        $s = csc_seo_get_settings();
        $map = ['calculator' => $s['calc_title'], 'cabinet' => $s['cabinet_title'], 'become_master' => $s['master_title']];
        $custom = trim((string)($map[$kind] ?? ''));
        if ($custom === '') return $title;
        return csc_seo_apply_tokens(str_replace('%title%', $custom, $s['title_template']));
    }
}

add_filter('document_title_parts', 'csc_seo_title_parts', 4);
if (!function_exists('csc_seo_title_parts')) {
    function csc_seo_title_parts($parts) {
        // Совместимость: если тема собирает title из частей — подменяем заголовок так же.
        if (!csc_seo_enabled()) return $parts;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return $parts;
        $s = csc_seo_get_settings();
        $map = ['calculator' => $s['calc_title'], 'cabinet' => $s['cabinet_title'], 'become_master' => $s['master_title']];
        $custom = trim((string)($map[$kind] ?? ''));
        if ($custom !== '') $parts['title'] = csc_seo_apply_tokens($custom);
        return $parts;
    }
}

add_action('wp_head', 'csc_seo_meta_tags', 4);
if (!function_exists('csc_seo_meta_tags')) {
    function csc_seo_meta_tags() {
        if (!csc_seo_enabled()) return;
        if (!is_singular()) return;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return;

        $s = csc_seo_get_settings();
        $desc_map = ['calculator' => $s['calc_desc'], 'cabinet' => $s['cabinet_desc'], 'become_master' => $s['master_desc']];
        $desc = csc_seo_apply_tokens(trim((string)($desc_map[$kind] ?? '')));

        echo '<!-- CSC SEO -->' . "\n";
        if ($desc !== '') {
            echo '<meta name="description" content="' . esc_attr(mb_substr($desc, 0, 300)) . '">' . "\n";
        }

        // Canonical: чистый permalink страницы вместо URL с query-параметрами плагина
        if (!empty($s['canonical_query'])) {
            $clean = get_permalink();
            if ($clean && strpos($clean, '?') === false) {
                echo '<link rel="canonical" href="' . esc_url($clean) . '">' . "\n";
            }
        }

        // noindex для дублей вида ?order=... / ?csc-sw и пр. оставляем WordPress core.
    }
}

// Fallback description, если активен SEO-плагин, который сам ставит description,
// но страница сервиса его не заполнил — не мешаем: проверяем вывод конкурентов позже в footers.
add_action('wp_head', 'csc_seo_open_graph', 6);
if (!function_exists('csc_seo_open_graph')) {
    function csc_seo_open_graph() {
        if (!csc_seo_enabled('og_enabled')) return;
        if (!is_singular()) return;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return;

        $url  = get_permalink();
        $s    = csc_seo_get_settings();
        $title = wp_get_document_title();
        $desc_map = ['calculator' => $s['calc_desc'], 'cabinet' => $s['cabinet_desc'], 'become_master' => $s['master_desc']];
        $desc = csc_seo_apply_tokens(trim((string)($desc_map[$kind] ?? '')));
        $img  = esc_url((string)$s['default_image']);
        if ($img === '' && has_post_thumbnail()) {
            $img = esc_url(get_the_post_thumbnail_url(null, 'large'));
        }

        $tags = [
            'og:type'        => 'website',
            'og:site_name'   => get_bloginfo('name'),
            'og:title'       => $title,
            'og:description' => $desc,
            'og:url'         => $url,
            'og:locale'      => substr(get_locale(), 0, 5) ?: 'ru_RU',
            'twitter:card'   => $img ? 'summary_large_image' : 'summary',
            'twitter:title'  => $title,
            'twitter:description' => $desc,
        ];
        if ($img) { $tags['og:image'] = $img; $tags['twitter:image'] = $img; }

        foreach ($tags as $k => $v) {
            if ($v === '' || $v === null) continue;
            echo '<meta property="' . esc_attr($k) . '" content="' . esc_attr($v) . '">' . "\n";
        }
    }
}

// ============================================================
// 4. JSON-LD МИКРОРАЗМЕТКА
// ============================================================

add_action('wp_head', 'csc_seo_json_ld', 7);
if (!function_exists('csc_seo_json_ld')) {
    function csc_seo_json_ld() {
        if (!csc_seo_enabled('jsonld_enabled')) return;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return;

        $graph = [];
        $s = csc_seo_get_settings();

        // --- LocalBusiness (все страницы плагина) ---
        $cities = csc_seo_cities_list();
        $business = [
            '@type' => $s['business_type'] ?: 'LocalBusiness',
            'name'  => $s['business_name'] ?: get_bloginfo('name'),
            'url'   => home_url('/'),
        ];
        if (!empty($s['business_phone'])) $business['telephone'] = $s['business_phone'];
        if (!empty($s['business_email'])) $business['email'] = $s['business_email'];
        if ($cities) {
            $business['areaServed'] = array_map(fn($c) => ['@type' => 'City', 'name' => $c], $cities);
        }
        if (!empty($s['default_image'])) $business['image'] = $s['default_image'];
        $graph[] = $business;

        if ($kind === 'calculator') {
            // --- OfferCatalog + Service для каждой услуги ---
            $sections = (array)csc_get_sections();
            $items    = (array)csc_get_items();
            $offers = [];
            foreach ($items as $item) {
                if (empty($item['is_available'])) continue;
                $sec = $sections[$item['section_id'] ?? ''] ?? null;
                $offers[] = [
                    '@type'    => 'Service',
                    'name'     => $item['name'],
                    'serviceType' => $sec ? $sec['name'] : '',
                    'description' => csc_seo_item_text($item),
                    'provider' => ['@type' => 'LocalBusiness', 'name' => $business['name']],
                    'areaServed' => $cities ?: null,
                    'offers' => [
                        '@type'         => 'Offer',
                        'price'         => (float)$item['price'],
                        'priceCurrency' => 'RUB',
                        'availability'  => 'https://schema.org/InStock',
                    ],
                ];
            }
            $catalog = [
                '@type'       => 'ServiceCatalog',
                'name'        => 'Каталог услуг — ' . get_bloginfo('name'),
                'hasOffer'    => array_values(array_filter($offers)),
                'url'         => get_permalink(),
            ];
            $graph[] = $catalog;

            // --- BreadcrumbList ---
            $graph[] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [[
                    '@type' => 'ListItem', 'position' => 1,
                    'name' => 'Главная', 'item' => home_url('/'),
                ], [
                    '@type' => 'ListItem', 'position' => 2,
                    'name' => get_the_title(), 'item' => get_permalink(),
                ]],
            ];
        }

        if ($kind === 'become_master') {
            $graph[] = [
                '@type' => 'FAQPage',
                'mainEntity' => [
                    ['@type' => 'Question', 'name' => 'Как стать мастером?',
                     'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Заполните анкету на этой странице: город, специализация, радиус выездной зоны. После модерации вы получите доступ в кабинет мастера с входящими заявками.']],
                    ['@type' => 'Question', 'name' => 'В каких городах вы работаете?',
                     'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Текущие города: ' . implode(', ', $cities) . '.']],
                ],
            ];
        }

        $out = count($graph) === 1 ? $graph[0] : ['@context' => 'https://schema.org', '@graph' => $graph];
        if (count($graph) > 1) { /* context уже в @graph-обёртке */ }
        else { $out = array_merge(['@context' => 'https://schema.org'], $out); }

        echo '<script type="application/ld+json">' . wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}

// ============================================================
// 5. XML-SITEMAP ПЛАГИНА: /csc-sitemap.xml
//    Страницы WP + разделы/услуги (якорями на страницу калькулятора) + города.
// ============================================================

add_action('init', 'csc_seo_rewrite_rules', 9);
if (!function_exists('csc_seo_rewrite_rules')) {
    function csc_seo_rewrite_rules() {
        add_rewrite_rule('^csc-sitemap\.xml$', 'index.php?csc_sitemap=1', 'top');
    }
}

add_filter('query_vars', 'csc_seo_query_vars');
if (!function_exists('csc_seo_query_vars')) {
    function csc_seo_query_vars($vars) {
        $vars[] = 'csc_sitemap';
        return $vars;
    }
}

add_action('template_redirect', 'csc_seo_sitemap_output', 1);
if (!function_exists('csc_seo_sitemap_output')) {
    function csc_seo_sitemap_output() {
        if (!isset($_GET['csc_sitemap']) && !get_query_var('csc_sitemap')) return;
        if (!csc_seo_enabled('sitemap_enabled')) return;

        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);

        $calc_url = csc_seo_find_shortcode_page_url('service_calculator');
        $master_url = csc_seo_find_shortcode_page_url('csc_become_master');
        $cab_url = csc_seo_find_shortcode_page_url('csc_personal_cabinet');

        $urls = [];
        $now = gmdate('Y-m-d');
        foreach (array_filter([$calc_url, $master_url, $cab_url]) as $u) {
            $urls[] = ['loc' => $u, 'priority' => $u === $calc_url ? '1.0' : '0.7', 'changefreq' => 'daily'];
        }

        // Разделы и услуги — якорями на страницу калькулятора (id секций рендерятся как #section-{id})
        if ($calc_url) {
            foreach ((array)csc_get_sections() as $sid => $sec) {
                $urls[] = ['loc' => $calc_url . '#section-' . $sid, 'priority' => '0.8', 'changefreq' => 'weekly'];
            }
        }

        // Города — landing-фильтры (страница калькулятора + ?csc_city=slug)
        if ($calc_url && function_exists('csc_get_cities')) {
            foreach ((array)csc_get_cities() as $slug => $city) {
                if (!empty($city['active'])) {
                    $urls[] = ['loc' => add_query_arg('csc_city', $slug, $calc_url), 'priority' => '0.9', 'changefreq' => 'daily'];
                }
            }
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            echo "  <url><loc>" . esc_html($u['loc']) . "</loc>"
               . "<lastmod>{$now}</lastmod>"
               . "<changefreq>{$u['changefreq']}</changefreq>"
               . "<priority>{$u['priority']}</priority></url>\n";
        }
        echo "</urlset>\n";
        exit;
    }
}

if (!function_exists('csc_seo_find_shortcode_page_url')) {
    /** Быстрый поиск опубликованной страницы, содержащей шорткод (кэш 12ч). */
    function csc_seo_find_shortcode_page_url($shortcode) {
        $key = 'csc_seo_page_' . md5($shortcode);
        $url = get_transient($key);
        if ($url !== false) return $url ?: null;
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like('[' . $shortcode) . '%'
        ));
        $url = $id ? (string)get_permalink((int)$id) : '';
        set_transient($key, $url, 12 * HOUR_IN_SECONDS);
        return $url !== '' ? $url : null;
    }
}

// Сброс кэша карты страниц/сайта при изменении каталога или городов.
foreach (['update_option_csc_service_sections', 'update_option_csc_service_items', 'update_option_csc_cities'] as $csc_seo_hook) {
    add_action($csc_seo_hook, function () {
        delete_transient('csc_seo_page_' . md5('service_calculator'));
        delete_transient('csc_seo_page_' . md5('csc_become_master'));
        delete_transient('csc_seo_page_' . md5('csc_personal_cabinet'));
    }, 10, 0);
}

// Flush rewrite rules только при активации/обновлении плагина (дёшево и безопасно).
add_action('csc_seo_flush', 'flush_rewrite_rules');

// ============================================================
// 6. ROBOTS.TXT — sitemap + запрет индексации служебных query
// ============================================================

add_filter('robots_txt', 'csc_seo_robots_txt', 10, 2);
if (!function_exists('csc_seo_robots_txt')) {
    function csc_seo_robots_txt($output, $public) {
        if ((int)$public !== 1 || !csc_seo_enabled()) return $output;
        $s = csc_seo_get_settings();
        $add = "\n";
        if (!empty($s['robots_sitemap'])) {
            $add .= "Sitemap: " . home_url('/csc-sitemap.xml') . "\n";
        }
        // Служебные экраны кабинета мастера и upsale-интерфейса — не для индексов
        $add .= "Disallow: /*?csc_master_dashboard=1\n";
        $add .= "Disallow: /*?csc_upsell=1\n";
        $add .= "Disallow: /*&csc_token=\n";
        return $output . $add;
    }
}

// X-Robots-Tag для служебных GET-экранов (страховка, если ссылку всё же проиндексировали).
add_action('send_headers', 'csc_seo_service_noindex_header');
if (!function_exists('csc_seo_service_noindex_header')) {
    function csc_seo_service_noindex_header() {
        if (is_admin()) return;
        $svc = ['csc_master_dashboard', 'csc_upsell'];
        foreach ($svc as $k) {
            if (isset($_GET[$k])) {
                header('X-Robots-Tag: noindex, nofollow', true);
                return;
            }
        }
    }
}

// ============================================================
// 7. SERVER-SIDE КАТАЛОГ УСЛУГ ВНУТРИ [service_calculator]
//    JS-ридер перехватывает управление (#csc-catalog-ssr заменяется динамикой),
//    но HTML для краулера и пользователей без JS готов сразу.
// ============================================================

add_filter('the_content', 'csc_seo_inject_ssr_catalog', 20);
if (!function_exists('csc_seo_inject_ssr_catalog')) {
    function csc_seo_inject_ssr_catalog($content) {
        if (!csc_seo_enabled('ssr_catalog')) return $content;
        if (is_admin() || !is_main_query() || !is_singular()) return $content;
        if (strpos($content, '[service_calculator]') === false) return $content;

        $sections = (array)csc_get_sections();
        $items    = (array)csc_get_items();
        if (!$sections) return $content;

        $html = '<noscript><style>#csc-calculator .csc-section-tiles,#csc-calculator .csc-services-section{display:none!important}</style></noscript>';
        $html .= '<div id="csc-catalog-ssr" class="csc-catalog-ssr" aria-hidden="false">';
        $html .= '<h2>Наши услуги и цены</h2><ul class="csc-ssr-list">';
        foreach ($sections as $sid => $sec) {
            $sec_desc = csc_seo_section_text($sec);
            $html .= '<li class="csc-ssr-section"><h3 id="section-' . esc_attr($sid) . '">' . esc_html($sec['name']) . '</h3>';
            if ($sec_desc !== $sec['name']) {
                $html .= '<p class="csc-ssr-section-desc">' . esc_html($sec_desc) . '</p>';
            }
            $html .= '<ul>';
            $any = false;
            foreach ($items as $iid => $item) {
                if (empty($item['is_available'])) continue;
                if (($item['section_id'] ?? '') !== $sid) continue;
                $any = true;
                $html .= '<li id="item-' . esc_attr($iid) . '">'
                      . '<span class="csc-ssr-item-name">' . esc_html($item['name']) . '</span> — '
                      . '<span class="csc-ssr-item-price">' . esc_html(number_format((float)$item['price'], 0, ',', ' ') . ' ₽') . '</span>'
                      . ', ' . esc_html(sprintf('%d мин', (int)($item['duration'] ?? 0)))
                      . '</li>';
            }
            if (!$any) $html .= '<li>Уточняйте наличие услуг в этом разделе.</li>';
            $html .= '</ul></li>';
        }
        $html .= '</ul></div>';

        // Вставляем SSR-блок сразу после контейнера калькулятора.
        return $content . $html;
    }
}

// ============================================================
// 8. ПЕРЕЛИНКОВКА: SEO-блок городов/разделов внизу страницы калькулятора
// ============================================================

add_action('wp_footer', 'csc_seo_footer_cross_links', 30);
if (!function_exists('csc_seo_footer_cross_links')) {
    function csc_seo_footer_cross_links() {
        if (!csc_seo_enabled('cross_links')) return;
        if (csc_seo_detect_page_kind() !== 'calculator') return;
        $calc = get_permalink();
        if (!$calc) return;

        $sections = (array)csc_get_sections();
        $cities = function_exists('csc_get_cities') ? (array)csc_get_cities() : [];
        if (!$sections && !$cities) return;

        echo '<nav class="csc-seo-links" aria-label="Дополнительные ссылки" style="max-width:800px;margin:24px auto;padding:0 16px;font-size:13px;color:#64748b;">';
        if ($sections) {
            echo '<p><strong>Популярные категории:</strong> ';
            $links = [];
            foreach ($sections as $sid => $sec) {
                $links[] = '<a href="' . esc_url($calc . '#section-' . $sid) . '">' . esc_html($sec['name']) . '</a>';
            }
            echo implode(', ', $links) . '</p>';
        }
        if ($cities) {
            echo '<p><strong>Мы работаем в городах:</strong> ';
            $links = [];
            foreach ($cities as $slug => $city) {
                if (empty($city['active'])) continue;
                $links[] = '<a href="' . esc_url(add_query_arg('csc_city', $slug, $calc)) . '">' . esc_html($city['name']) . '</a>';
            }
            echo implode(', ', $links) . '</p>';
        }
        echo '</nav>';
    }
}

// ============================================================
// 9. CORE WEB VITALS: defer внешних скриптов шорткода
// ============================================================

add_filter('script_loader_tag', 'csc_seo_defer_vendor_scripts', 10, 3);
if (!function_exists('csc_seo_defer_vendor_scripts')) {
    function csc_seo_defer_vendor_scripts($tag, $handle, $src) {
        if (is_admin()) return $tag;
        // html2canvas нужен только на шаге подтверждения — не блокирует LCP.
        if (strpos($src, 'assets/vendor/html2canvas/html2canvas.min.js') !== false) {
            if (strpos($tag, 'defer') === false) {
                $tag = str_replace(' src=', ' defer src=', $tag);
            }
        }
        return $tag;
    }
}

// Alt/aria для изображений превью работ мастеров (если выводятся через the_content)
add_filter('wp_get_attachment_image_attributes', 'csc_seo_image_alt_fallback', 10, 3);
if (!function_exists('csc_seo_image_alt_fallback')) {
    function csc_seo_image_alt_fallback($attr, $attachment, $size) {
        if (!csc_seo_enabled()) return $attr;
        if (empty($attr['alt']) || trim($attr['alt']) === '') {
            $alt = trim((string)get_post_meta($attachment->ID, '_wp_attachment_image_alt', true));
            if ($alt === '') {
                $alt = wp_trim_words(wp_strip_all_tags((string)$attachment->post_title), 10);
            }
            if ($alt !== '') $attr['alt'] = $alt;
        }
        return $attr;
    }
}

// ============================================================
// 10. АДМИНКА: страница «SEO (CSC)» + поля seo_desc разделов/услуг
// ============================================================

add_action('admin_menu', 'csc_seo_admin_menu', 20);
if (!function_exists('csc_seo_admin_menu')) {
    function csc_seo_admin_menu() {
        add_submenu_page(
            'options-general.php',
            'SEO (CSC)',
            '🔎 SEO (CSC)',
            'manage_options',
            'csc_seo_admin',
            'csc_seo_admin_page'
        );
    }
}

if (!function_exists('csc_seo_admin_page')) {
    function csc_seo_admin_page() {
        if (!current_user_can('manage_options')) return;

        if ($_POST && isset($_POST['save_seo']) && check_admin_referer('csc_seo_save_action')) {
            $in = $_POST['csc_seo'] ?? [];
            $settings = [
                'enabled'        => !empty($in['enabled']) ? 1 : 0,
                'sitemap_enabled'=> !empty($in['sitemap_enabled']) ? 1 : 0,
                'robots_sitemap' => !empty($in['robots_sitemap']) ? 1 : 0,
                'og_enabled'     => !empty($in['og_enabled']) ? 1 : 0,
                'jsonld_enabled' => !empty($in['jsonld_enabled']) ? 1 : 0,
                'ssr_catalog'    => !empty($in['ssr_catalog']) ? 1 : 0,
                'cross_links'    => !empty($in['cross_links']) ? 1 : 0,
                'canonical_query'=> !empty($in['canonical_query']) ? 1 : 0,
                'title_template' => sanitize_text_field($in['title_template'] ?? '%title% — %sitename%'),
                'calc_title'     => sanitize_text_field($in['calc_title'] ?? ''),
                'calc_desc'      => sanitize_textarea_field($in['calc_desc'] ?? ''),
                'cabinet_title'  => sanitize_text_field($in['cabinet_title'] ?? ''),
                'cabinet_desc'   => sanitize_textarea_field($in['cabinet_desc'] ?? ''),
                'master_title'   => sanitize_text_field($in['master_title'] ?? ''),
                'master_desc'    => sanitize_textarea_field($in['master_desc'] ?? ''),
                'default_image'  => esc_url_raw($in['default_image'] ?? ''),
                'business_name'  => sanitize_text_field($in['business_name'] ?? ''),
                'business_type'  => sanitize_text_field($in['business_type'] ?? 'LocalBusiness'),
                'business_phone' => sanitize_text_field($in['business_phone'] ?? ''),
                'business_email' => sanitize_email($in['business_email'] ?? ''),
                'business_area'  => sanitize_text_field($in['business_area'] ?? ''),
            ];
            update_option('csc_seo_settings', $settings, false);
            do_action('csc_seo_flush'); // flush_rewrite_rules для /csc-sitemap.xml
            echo '<div class="notice notice-success"><p>SEO-настройки сохранены. <a href="' . esc_url(home_url('/csc-sitemap.xml')) . '" target="_blank">Открыть sitemap</a> · <a href="' . esc_url(home_url('/robots.txt')) . '" target="_blank">robots.txt</a></p></div>';
        }

        $s = csc_seo_get_settings();
        ?>
        <div class="wrap">
            <h1>🔎 SEO калькулятора услуг</h1>
            <p class="description">Модуль генерирует meta-теги, Open Graph, JSON-LD (LocalBusiness/Service/FAQ/Breadcrumbs), XML-карту <code>/csc-sitemap.xml</code>, дополняет robots.txt и рендерит каталог услуг на сервере (индексация без JS).</p>
            <form method="post">
                <?php wp_nonce_field('csc_seo_save_action'); ?>
                <table class="form-table" role="presentation">
                    <tr><th colspan="2"><label><input type="checkbox" name="csc_seo[enabled]" value="1" <?php checked(!empty($s['enabled'])); ?>> Модуль SEO включён</label></th></tr>
                    <tr><th scope="row">Подмодули</th><td>
                        <label><input type="checkbox" name="csc_seo[sitemap_enabled]" value="1" <?php checked(!empty($s['sitemap_enabled'])); ?>> XML-sitemap</label><br>
                        <label><input type="checkbox" name="csc_seo[robots_sitemap]" value="1" <?php checked(!empty($s['robots_sitemap'])); ?>> Sitemap в robots.txt</label><br>
                        <label><input type="checkbox" name="csc_seo[og_enabled]" value="1" <?php checked(!empty($s['og_enabled'])); ?>> Open Graph / Twitter</label><br>
                        <label><input type="checkbox" name="csc_seo[jsonld_enabled]" value="1" <?php checked(!empty($s['jsonld_enabled'])); ?>> JSON-LD</label><br>
                        <label><input type="checkbox" name="csc_seo[ssr_catalog]" value="1" <?php checked(!empty($s['ssr_catalog'])); ?>> Каталог услуг в HTML (SSR)</label><br>
                        <label><input type="checkbox" name="csc_seo[cross_links]" value="1" <?php checked(!empty($s['cross_links'])); ?>> Перелинковка разделов/городов</label><br>
                        <label><input type="checkbox" name="csc_seo[canonical_query]" value="1" <?php checked(!empty($s['canonical_query'])); ?>> Чистый canonical</label>
                    </td></tr>
                    <tr><th scope="row"><label for="csc-title-template">Шаблон &lt;title&gt;</label></th>
                        <td><input id="csc-title-template" class="regular-text" name="csc_seo[title_template]" value="<?php echo esc_attr($s['title_template']); ?>">
                        <p class="description">Токены: <code>%title%</code> — настроенный заголовок страницы, <code>%sitename%</code>.</p></td></tr>

                    <tr><th scope="row" colspan="2"><h2 style="margin:0">Страница калькулятора</h2></th></tr>
                    <tr><th scope="row"><label for="csc-calc-title">Title</label></th>
                        <td><input id="csc-calc-title" class="large-text code" name="csc_seo[calc_title]" value="<?php echo esc_attr($s['calc_title']); ?>"></td></tr>
                    <tr><th scope="row"><label for="csc-calc-desc">Description</label></th>
                        <td><textarea id="csc-calc-desc" class="large-text" rows="3" name="csc_seo[calc_desc]"><?php echo esc_textarea($s['calc_desc']); ?></textarea>
                        <p class="description">Токены: <code>%sitename%</code>, <code>%cities%</code> (активные города), <code>%price_from%</code> («от … ₽» по каталогу).</p></td></tr>

                    <tr><th scope="row" colspan="2"><h2 style="margin:0">Личный кабинет клиента</h2></th></tr>
                    <tr><th scope="row"><label>Title ЛК</label></th><td><input class="large-text code" name="csc_seo[cabinet_title]" value="<?php echo esc_attr($s['cabinet_title']); ?>"></td></tr>
                    <tr><th scope="row"><label>Description ЛК</label></th><td><textarea class="large-text" rows="2" name="csc_seo[cabinet_desc]"><?php echo esc_textarea($s['cabinet_desc']); ?></textarea></td></tr>

                    <tr><th scope="row" colspan="2"><h2 style="margin:0">Стать мастером</h2></th></tr>
                    <tr><th scope="row"><label>Title</label></th><td><input class="large-text code" name="csc_seo[master_title]" value="<?php echo esc_attr($s['master_title']); ?>"></td></tr>
                    <tr><th scope="row"><label>Description</label></th><td><textarea class="large-text" rows="2" name="csc_seo[master_desc]"><?php echo esc_textarea($s['master_desc']); ?></textarea></td></tr>

                    <tr><th scope="row" colspan="2"><h2 style="margin:0">LocalBusiness (JSON-LD / OG)</h2></th></tr>
                    <tr><th scope="row"><label>Название организации</label></th><td><input class="regular-text" name="csc_seo[business_name]" value="<?php echo esc_attr($s['business_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
                    <tr><th scope="row"><label>Тип (schema.org)</label></th><td>
                        <select name="csc_seo[business_type]">
                            <?php foreach (['HomeAndConstructionBusiness','LocalBusiness','GeneralContractor','Plumber','HVACBusiness','Electrician','MovingCompany','ProfessionalService'] as $t): ?>
                                <option value="<?php echo esc_attr($t); ?>" <?php selected($s['business_type'], $t); ?>><?php echo esc_html($t); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                    <tr><th scope="row"><label>Телефон</label></th><td><input class="regular-text" name="csc_seo[business_phone]" value="<?php echo esc_attr($s['business_phone']); ?>" placeholder="+7 900 000-00-00"></td></tr>
                    <tr><th scope="row"><label>E-mail</label></th><td><input class="regular-text" type="email" name="csc_seo[business_email]" value="<?php echo esc_attr($s['business_email']); ?>"></td></tr>
                    <tr><th scope="row"><label>OG-изображение по умолчанию</label></th><td><input class="large-text code" name="csc_seo[default_image]" value="<?php echo esc_attr($s['default_image']); ?>" placeholder="https://…/og-cover.jpg (1200×630)"></td></tr>
                </table>
                <?php submit_button('Сохранить SEO-настройки', 'primary', 'save_seo'); ?>
            </form>

            <hr>
            <h2>Чек-лист проверки после сохранения</h2>
            <ol>
                <li><a href="<?php echo esc_url(home_url('/csc-sitemap.xml')); ?>" target="_blank">/csc-sitemap.xml</a> отдаёт XML (после сохранения правил перезаписи; если 404 — «Настройки → Постоянные ссылки → Сохранить»).</li>
                <li><a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank">/robots.txt</a> содержит строку <code>Sitemap:</code>.</li>
                <li>На странице калькулятора в <code>&lt;head&gt;</code>: description, og:*, JSON-LD; в теле — блок <code>#csc-catalog-ssr</code> со всеми услугами.</li>
                <li>Проверка rich-результатов: <a href="https://search.google.com/test/rich-results" target="_blank">Rich Results Test</a> / <a href="https://validator.schema.org/" target="_blank">validator.schema.org</a>.</li>
            </ol>
        </div>
        <?php
    }
}

// --- Поля seo_desc в админке разделов (добавляются без правки разметки существующей страницы) ---
add_action('csc_sections_admin_after_rows', 'csc_seo_sections_fields_js');
if (!function_exists('csc_seo_sections_fields_js')) {
    function csc_seo_sections_fields_js() {}
}

// Инъекция колонок seo_desc выполняется JS-ом на страницах «Разделы (CSC)»/«Услуги (CSC)»:
// safer than re-rendering legacy forms and losing concurrent structural changes.
add_action('admin_footer', 'csc_seo_admin_inject_seo_fields');
if (!function_exists('csc_seo_admin_inject_seo_fields')) {
    function csc_seo_admin_inject_seo_fields() {
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        if (!$screen) return;
        $target = in_array($screen->id ?? '', ['settings_page_csc_sections_admin', 'settings_page_csc_items_admin'], true);
        if (!$target) return;
        ?>
        <script>
        jQuery(function($){
            var isSections = $('#csc-sections-table').length > 0;
            // 1. Заголовок колонки
            var $headRow = isSections ? $('#csc-sections-table thead tr') : $('.widefat.fixed thead tr').first();
            if ($headRow.length && $headRow.find('th.csc-seo-col').length === 0) {
                $headRow.find('th:last').before('<th class="csc-seo-col" style="width:30%">SEO-описание (для поисков и сниппетов)</th>');
            }
            // 2. Поле в каждую строку tbody
            $('tbody tr').each(function(){
                var $tr = $(this);
                if ($tr.find('.csc-seo-cell').length) return;
                var $nameInput = $tr.find('input[type=text]').first();
                if (!$nameInput.length) return;
                var name = $nameInput.attr('name') || '';
                // sections[sec_x][name] -> sections[sec_x][seo_desc]
                var seoName = name.replace(/\[name\]$/, '[seo_desc]');
                if (seoName === name) return; // не наш ряд
                var val = '';
                var $hid = $tr.find('input[name="' + seoName.replace(/[\[\]]/g, '\\$&') + '"]');
                if ($hid.length) val = $hid.val();
                $tr.find('td:last').before('<td class="csc-seo-cell"><textarea name="' + seoName + '" rows="2" style="width:95%" placeholder="Короткое описание для поисковика (до 200 символов)…"></textarea></td>');
                if (val) $tr.find('.csc-seo-cell textarea').val(val);
            });
        });
        </script>
        <?php
    }
}

// --- Сохранение seo_desc: фильтрация POST до обработки стандартными страницами админа ---
add_action('admin_init', 'csc_seo_capture_seo_desc', 1);
if (!function_exists('csc_seo_capture_seo_desc')) {
    function csc_seo_capture_seo_desc() {
        if (empty($_POST['save_sections']) && empty($_POST['save_items'])) return;
        if (!check_admin_referer('csc_save_sections_action') && !check_admin_referer('csc_save_items_action')) return;
        // Перехватываем присланные seo_desc и храним отдельным map'ом (не ломаем существующие обработчики,
        // которые пересобирают массивы section/item без поля seo_desc).
        $map = get_option('csc_seo_desc_map', []);
        if (!is_array($map)) $map = [];
        if (!empty($_POST['sections'])) {
            foreach ($_POST['sections'] as $id => $data) {
                $id = sanitize_text_field($id);
                $seo = sanitize_textarea_field($data['seo_desc'] ?? '');
                if ($seo !== '') $map['sec:' . $id] = $seo; else unset($map['sec:' . $id]);
            }
        }
        if (!empty($_POST['items'])) {
            foreach ($_POST['items'] as $it) {
                $iid = sanitize_text_field($it['id'] ?? '');
                if ($iid === '') continue;
                $seo = sanitize_textarea_field($it['seo_desc'] ?? '');
                if ($seo !== '') $map['item:' . $iid] = $seo; else unset($map['item:' . $iid]);
            }
        }
        update_option('csc_seo_desc_map', $map, false);
    }
}

// Объединение map'а seo_desc с данными каталога при чтении (прозрачно для всех потребителей).
add_filter('csc_get_sections_result', 'csc_seo_merge_sections_desc');
add_filter('csc_get_items_result', 'csc_seo_merge_items_desc');
if (!function_exists('csc_seo_merge_desc_into')) {
    function csc_seo_merge_desc_into($data, $prefix) {
        $map = get_option('csc_seo_desc_map', []);
        if (!is_array($map) || !is_array($data)) return $data;
        foreach ($data as $id => &$row) {
            if (is_array($row) && empty($row['seo_desc']) && !empty($map[$prefix . $id])) {
                $row['seo_desc'] = $map[$prefix . $id];
            }
        }
        return $data;
    }
}
if (!function_exists('csc_seo_merge_sections_desc')) {
    function csc_seo_merge_sections_desc($d) { return csc_seo_merge_desc_into($d, 'sec:'); }
}
if (!function_exists('csc_seo_merge_items_desc')) {
    function csc_seo_merge_items_desc($d) { return csc_seo_merge_desc_into($d, 'item:'); }
}

// ============================================================
// 11. АКТИВАЦИЯ: flush rewrite rules для sitemap-URL
// ============================================================

add_action('admin_init', 'csc_seo_maybe_flush');
if (!function_exists('csc_seo_maybe_flush')) {
    function csc_seo_maybe_flush() {
        if (get_option('csc_seo_rules_ver') !== CSC_PLUGIN_VERSION) {
            csc_seo_rewrite_rules();
            flush_rewrite_rules(false);
            update_option('csc_seo_rules_ver', CSC_PLUGIN_VERSION, false);
        }
    }
}

// Очистка при деактивации (soft): снимаем robots-директивы нельзя (это фильтр),
// но удаляем правило переписывания из БД при следующем flush.
register_deactivation_hook(__FILE__, function () {
    delete_option('csc_seo_rules_ver');
});
