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
    /**
     * Полная схема настроек SEO-модуля. КАЖДЫЙ оптимизационный пункт аудита
     * имеет собственный переключатель/поле в админке («🔎 SEO (CSC)», вкладки).
     */
    function csc_seo_get_defaults() {
        return [
            // ── П0. Общие ──
            'enabled'               => 1,   // главный выключатель модуля
            'debug_log'             => 0,   // писать решения хуков в журнал CSC

            // ── П1. Meta title / description ──
            'meta_enabled'          => 1,
            'title_template'        => '%title% — %sitename%',
            'title_max'             => 60,  // обрезка <title> (0 = не обрезать)
            'desc_max'              => 300, // жёсткий лимит description
            'calc_title'            => 'Расчёт стоимости услуг и вызов мастера — онлайн-калькулятор',
            'calc_desc'             => 'Рассчитайте стоимость услуги за 30 секунд: выберите раздел, услуги, укажите адрес и время. Прозрачные цены, %price_from%. Работаем в %cities%. Гарантия на все работы.',
            'cabinet_title'         => 'Личный кабинет клиента — статусы и история заказов',
            'cabinet_desc'          => 'Вход по номеру телефона: история заявок, статусы выполнения, повтор заказа и оценка работ мастера.',
            'master_title'          => 'Стать мастером — подключение к сервису',
            'master_desc'           => 'Регистрация мастеров: заказы рядом с домом, свободный график, еженедельные выплаты, рейтинг и отзывы. Города: %cities%.',

            // ── П2. Canonical / robots-meta ──
            'canonical_enabled'     => 1,   // чистый canonical страниц плагина
            'canonical_force_ssl'   => 0,   // принудительно https в canonical
            'canonical_trailing_slash' => 1,// единый формат URL: со слэшем на конце
            'noindex_404'           => 1,   // X-Robots-Tag noindex на страницах 404
            'noindex_search'        => 1,   // noindex для результатов ?s= (защита от мусорных кластеров)
            'service_noindex'       => 1,   // noindex,follow для служебных GET-экранов (?csc_master_dashboard=1 и т.п.)

            // ── П3. Open Graph / Twitter Card ──
            'og_enabled'            => 1,
            'og_site_name'          => '',  // пусто = bloginfo('name')
            'og_locale'             => 'ru_RU',
            'og_type_calc'          => 'website',
            'og_type_master'        => 'website',
            'og_type_cabinet'       => 'website',
            'twitter_enabled'       => 1,
            'twitter_site'          => '',  // @username аккаунта сайта
            'default_image'         => '',  // OG-обложка 1200×630
            'default_image_w'       => 1200,
            'default_image_h'       => 630,

            // ── П4. JSON-LD микроразметка ──
            'jsonld_enabled'        => 1,
            'jsonld_graph'          => 1,   // объединять в @graph (иначе — отдельные блоки)
            'business_name'         => '',
            'business_type'         => 'HomeAndConstructionBusiness',
            'business_phone'        => '',
            'business_email'        => '',
            'business_area'         => '',  // AreaServed вручную (пусто = из списка городов)
            'geo_lat'               => '',
            'geo_lng'               => '',
            'opening_hours'         => 'Mo-Su 08:00-22:00',
            'price_currency'        => 'RUB',
            'jsonld_localbusiness'  => 1,
            'jsonld_website'        => 1,   // WebSite + SearchAction (поиск по сайту)
            'jsonld_service_catalog'=> 1,   // ServiceCatalog с Offer'ами услуг
            'jsonld_service_max'    => 60,  // лимит Service-узлов (размер блока)
            'jsonld_breadcrumbs'    => 1,
            'jsonld_faq'            => 1,
            'jsonld_faq_master'     => 1,   // FAQ на «Стать мастером»
            'jsonld_faq_calc'       => 1,   // FAQ на калькуляторе
            'jsonld_reviews'        => 1,   // AggregateRating из модуля отзывов
            'jsonld_social'         => 1,   // sameAs (соцсети ниже)
            'social_facebook'       => '',
            'social_instagram'      => '',
            'social_vk'             => '',
            'social_telegram'       => '',
            'social_youtube'        => '',

            // ── П5. XML-Sitemap и robots.txt ──
            'sitemap_enabled'       => 1,
            'sitemap_path'          => 'csc-sitemap.xml',
            'sitemap_wp_pages'      => 1,   // страницы с шорткодами плагина
            'sitemap_sections'      => 1,   // якоря разделов #section-{id}
            'sitemap_items'         => 0,   // якоря услуг #item-{id} (может раздуть карту)
            'sitemap_cities'        => 1,   // городские фильтры ?csc_city=slug
            'sitemap_lastmod'       => 1,
            'sitemap_changefreq'    => 1,
            'sitemap_priority'      => 1,
            'sitemap_max_urls'      => 5000,
            'sitemap_cache_ttl'     => 300, // сек; кэш XML в трансиенте
            'robots_sitemap'        => 1,   // строка Sitemap: в robots.txt
            'robots_disallow_service' => 1, // Disallow для служебных query-параметров
            'robots_extra_lines'    => '',  // произвольные директивы (по строке на директиву)

            // ── П6. SSR-каталог услуг (индексация контента без JS) ──
            'ssr_catalog'           => 1,
            'ssr_headings_level'    => 'h2',
            'ssr_show_prices'       => 1,
            'ssr_show_duration'     => 1,
            'ssr_show_desc'         => 1,   // seo_desc разделов/услуг текстом
            'ssr_only_available'    => 1,
            'ssr_hide_noscript'     => 1,   // noscript-скрытие JS-дублей плиток

            // ── П7. Перелинковка ──
            'cross_links'           => 1,
            'cross_sections'        => 1,
            'cross_cities'          => 1,
            'cross_sections_label'  => 'Популярные категории:',
            'cross_cities_label'    => 'Мы работаем в городах:',
            'cross_max_sections'    => 12,
            'cross_max_cities'      => 12,
            'cross_position'        => 'footer',  // footer | content_end
            'cross_nofollow'        => 0,         // rel="nofollow" для городских фильтров

            // ── П8. Core Web Vitals / ассеты ──
            'cwv_enabled'           => 1,
            'defer_vendor_scripts'  => 1,   // defer html2canvas
            'img_lazyloading'       => 1,   // loading="lazy" для изображений
            'img_alt_fallback'      => 1,   // alt из post_title, если пустой

            // ── П9. Редиректы 301/302 ──
            'redirects_enabled'     => 1,
            'redirects_list'        => [],  // [['from'=>'/old/','to'=>'/new/','code'=>301], …]

            // ── П10. Интеграции / верификация ──
            'compat_mode'           => 'auto', // auto|off|force — поведение рядом с Yoast/RankMath/AIOSEO/SEOPress
            'gsc_verification'      => '',     // meta-подтверждение Google Search Console
            'yandex_verification'   => '',     // Яндекс.Вебмастер
            'bing_verification'     => '',     // Bing Site Explorer

            // ── П11. Внутренний поиск (SearchAction) ──
            'search_param'          => 'csc_q',

            // ── П12. Токены шаблонов ──
            'token_price_separator' => 'от ',
            'token_cities_limit'    => 6,
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

if (!function_exists('csc_seo_meta_allowed')) {
    /**
     * П10 «Интеграции»: совместимость с внешними SEO-плагинами.
     *  compat_mode = 'off'  — наш модуль meta не мешает никому;
     *  compat_mode = 'force'— ставим теги всегда (даже при активном Yoast и т.п.);
     *  compat_mode = 'auto' — если определён конкурент, у которого заполнен title/description
     *                          страницы — молчим; иначе берём управление на себя.
     */
    function csc_seo_meta_allowed() {
        if (!csc_seo_enabled('meta_enabled')) return false;
        $mode = csc_seo_get_settings()['compat_mode'] ?? 'auto';
        if ($mode === 'off') return false;
        if ($mode === 'force') return true;
        // auto: проверяем, не закрыт ли пункт другим плагином именно на этой странице
        if (!csc_seo_external_seo_active()) return true;
        if (is_singular()) {
            $post = get_post();
            if ($post) {
                $meta = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);      // Yoast / АIOSEO legacy
                $rm  = get_post_meta($post->ID, 'rank_math_description', true);         // Rank Math
                $sp  = get_post_meta($post->ID, '_seopress_titles_desc', true);          // SEOPress
                if (trim((string)($meta ?: $rm ?: $sp)) !== '') return false;           // конкурент уже всё сделал
            }
        }
        return true; // конкурент молчит на страницах плагина — включаемся
    }
}

if (!function_exists('csc_seo_external_seo_active')) {
    /** Детект активных внешних SEO-плагинов (для админского аудита и compat). */
    function csc_seo_external_seo_active() {
        static $detected = null;
        if ($detected !== null) return $detected;
        $detected = [];
        if (defined('WPSEO_VERSION'))                        $detected[] = 'Yoast SEO';
        if (defined('RANK_MATH_VERSION'))                   $detected[] = 'Rank Math';
        if (class_exists('AIOSEO\Plugin\Loader'))          $detected[] = 'All in One SEO';
        if (defined('SEOPRESS_VERSION'))                    $detected[] = 'SEOPress';
        if (defined('THE_SEO_FRAMEWORK_VERSION'))           $detected[] = 'The SEO Framework';
        return $detected;
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
        $s = csc_seo_get_settings();
        $min = 0;
        foreach ((array)csc_get_items() as $item) {
            if (empty($item['is_available'])) continue;
            $p = (float)($item['price'] ?? 0);
            if ($p <= 0) continue;
            if ($min === 0 || $p < $min) $min = $p;
        }
        if ($min <= 0) return '';
        $sep   = (string)($s['token_price_separator'] ?? 'от ');
        $price = number_format($min, 0, ',', ' ') . ' ₽';
        return $sep !== '' ? $sep . $price : $price;
    }
}

if (!function_exists('csc_seo_apply_tokens')) {
    /** Подстановка %sitename%, %cities%, %price_from% в шаблоны meta-полей. */
    function csc_seo_apply_tokens($text) {
        $s = csc_seo_get_settings();
        $cities = csc_seo_cities_list();
        $limit = max(1, (int)($s['token_cities_limit'] ?? 6));
        if (count($cities) > $limit) {
            $cities = array_merge(array_slice($cities, 0, $limit), ['…']);
        }
        return str_replace(
            ['%sitename%', '%cities%'%, '%price_from%', '%year%', '%tagline%'],
            [get_bloginfo('name'), implode(', ', $cities), csc_seo_price_from(), gmdate('Y'), get_bloginfo('description')],
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
        if (!csc_seo_meta_allowed()) return $title;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return $title;
        $s = csc_seo_get_settings();
        $map = ['calculator' => $s['calc_title'], 'cabinet' => $s['cabinet_title'], 'become_master' => $s['master_title']];
        $custom = trim((string)($map[$kind] ?? ''));
        if ($custom === '') return $title;
        $out = csc_seo_apply_tokens(str_replace('%title%', $custom, $s['title_template']));
        $max = (int)($s['title_max'] ?? 60);
        if ($max > 0) $out = mb_substr($out, 0, $max);
        return $out;
    }
}

add_filter('document_title_parts', 'csc_seo_title_parts', 4);
if (!function_exists('csc_seo_title_parts')) {
    function csc_seo_title_parts($parts) {
        // Совместимость: если тема собирает title из частей — подменяем заголовок так же.
        if (!csc_seo_meta_allowed()) return $parts;
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
        if (!csc_seo_meta_allowed()) return;
        if (!is_singular()) return;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return;

        $s = csc_seo_get_settings();
        $desc_map = ['calculator' => $s['calc_desc'], 'cabinet' => $s['cabinet_desc'], 'become_master' => $s['master_desc']];
        $desc = csc_seo_apply_tokens(trim((string)($desc_map[$kind] ?? '')));

        $dmax = max(50, (int)($s['desc_max'] ?? 300));
        echo '<!-- CSC SEO -->' . "\n";
        if ($desc !== '') {
            echo '<meta name="description" content="' . esc_attr(mb_substr($desc, 0, $dmax)) . '">' . "\n";
        }

        // Canonical (П2): чистый permalink страницы вместо URL с query-параметрами плагина.
        if (!empty($s['canonical_enabled'])) {
            $clean = get_permalink();
            if ($clean && strpos($clean, '?') === false) {
                if (!empty($s['canonical_force_ssl'])) {
                    $clean = preg_replace('#^http://#', 'https://', $clean);
                }
                if (!empty($s['canonical_trailing_slash'])) {
                    $clean = rtrim($clean, '/') . '/';
                } else {
                    $clean = rtrim($clean, '/');
                }
                echo '<link rel="canonical" href="' . esc_url($clean) . '">' . "\n";
            }
        }

        // Meta-подтверждения сервисов вебмастеров (П10).
        foreach ([
            'google-site-verification'          => $s['gsc_verification'] ?? '',
            'yandex-verification'               => $s['yandex_verification'] ?? '',
            'msvalidate.01'                     => $s['bing_verification'] ?? '',
        ] as $name => $val) {
            $val = trim((string)$val);
            if ($val !== '') {
                echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($val) . '">' . "\n";
            }
        }
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

        $og_types = ['calculator' => $s['og_type_calc'], 'cabinet' => $s['og_type_cabinet'], 'become_master' => $s['og_type_master']];
        $tags = [
            'og:type'        => $og_types[$kind] ?? 'website',
            'og:site_name'   => ($s['og_site_name'] ?? '') !== '' ? $s['og_site_name'] : get_bloginfo('name'),
            'og:title'       => $title,
            'og:description' => $desc,
            'og:url'         => $url,
            'og:locale'      => !empty($s['og_locale']) ? $s['og_locale'] : (substr(get_locale(), 0, 5) ?: 'ru_RU'),
        ];
        if ($img) {
            $tags['og:image'] = $img;
            if (!empty($s['default_image_w'])) $tags['og:image:width']  = (int)$s['default_image_w'];
            if (!empty($s['default_image_h'])) $tags['og:image:height'] = (int)$s['default_image_h'];
            $tags['og:image:alt'] = $title;
        }
        if (!empty($s['twitter_enabled'])) {
            $tags['twitter:card'] = $img ? 'summary_large_image' : 'summary';
            $tags['twitter:title'] = $title;
            $tags['twitter:description'] = $desc;
            if ($img) $tags['twitter:image'] = $img;
            if (!empty($s['twitter_site'])) $tags['twitter:site'] = $s['twitter_site'];
        }

        foreach ($tags as $k => $v) {
            if ($v === '' || $v === null) continue;
            $prop = strpos($k, 'twitter:') === 0 ? 'name' : 'property';
            echo '<meta ' . $prop . '="' . esc_attr($k) . '" content="' . esc_attr((string)$v) . '">' . "\n";
        }
    }
}

// ============================================================
// 4. JSON-LD МИКРОРАЗМЕТКА
// ============================================================

add_action('wp_head', 'csc_seo_json_ld', 7);
if (!function_exists('csc_seo_json_ld')) {
    /**
     * П4. JSON-LD @graph: LocalBusiness (+geo/hours/rating/social), WebSite+SearchAction,
     * ServiceCatalog (Offer по каждой услуге), BreadcrumbList, FAQPage.
     * Каждый тип узла имеет отдельный переключатель в админке («SEO (CSC)» → вкладка «Микроразметка»).
     */
    function csc_seo_json_ld() {
        if (!csc_seo_enabled('jsonld_enabled')) return;
        $kind = csc_seo_detect_page_kind();
        if (!$kind) return;

        $s      = csc_seo_get_settings();
        $cities = csc_seo_cities_list();
        $graph  = [];

        // --- LocalBusiness ---
        if (!empty($s['jsonld_localbusiness'])) {
            $business = [
                '@type' => $s['business_type'] ?: 'LocalBusiness',
                'name'  => $s['business_name'] ?: get_bloginfo('name'),
                'url'   => home_url('/'),
            ];
            if (!empty($s['business_phone'])) $business['telephone'] = $s['business_phone'];
            if (!empty($s['business_email'])) $business['email'] = $s['business_email'];
            if (!empty($s['opening_hours'])) {
                $business['openingHours'] = $s['opening_hours'];
            }
            $area_src = trim((string)($s['business_area'] ?? ''));
            $area = $area_src !== '' ? array_map('trim', explode(',', $area_src)) : $cities;
            if ($area) {
                $business['areaServed'] = array_map(fn($c) => ['@type' => 'City', 'name' => $c], $area);
            }
            if (!empty($s['geo_lat']) && !empty($s['geo_lng'])) {
                $business['geo'] = [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => (float)$s['geo_lat'],
                    'longitude' => (float)$s['geo_lng'],
                ];
            }
            if (!empty($s['default_image'])) $business['image'] = $s['default_image'];
            if (!empty($s['jsonld_social'])) {
                $same = array_values(array_filter([
                    $s['social_facebook'] ?? '', $s['social_instagram'] ?? '',
                    $s['social_vk'] ?? '', $s['social_telegram'] ?? '', $s['social_youtube'] ?? '',
                ]));
                if ($same) $business['sameAs'] = $same;
            }
            // AggregateRating из данных модуля рейтингов мастеров (csc-master-ratings.php)
            if (!empty($s['jsonld_reviews']) && function_exists('csc_get_master_ratings_data')) {
                $sum = 0; $cnt = 0;
                foreach ((array)csc_get_master_ratings_data() as $r) {
                    if (!empty($r['reviews_count'])) {
                        $sum += (float)$r['avg'] * (int)$r['reviews_count'];
                        $cnt += (int)$r['reviews_count'];
                    }
                }
                if ($cnt >= 3) {
                    $business['aggregateRating'] = [
                        '@type'       => 'AggregateRating',
                        'ratingValue' => round($sum / $cnt, 1),
                        'reviewCount' => $cnt,
                        'bestRating'  => 5,
                    ];
                }
            }
            $graph[] = $business;
        }

        // --- WebSite + SearchAction (поиск по сайту) ---
        if (!empty($s['jsonld_website']) && $kind === 'calculator') {
            $calc_url = get_permalink();
            $ws = [
                '@type' => 'WebSite',
                'name'  => get_bloginfo('name'),
                'url'   => home_url('/'),
            ];
            if ($calc_url) {
                $param = rawurlencode($s['search_param'] ?: 'csc_q');
                $ws['potentialAction'] = [[
                    '@type'       => 'SearchAction',
                    'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $calc_url . '?' . $param . '={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ]];
            }
            $graph[] = $ws;
        }

        if ($kind === 'calculator') {
            // --- ServiceCatalog с Offer по каждой услуге ---
            if (!empty($s['jsonld_service_catalog'])) {
                $sections = (array)csc_get_sections();
                $items    = (array)csc_get_items();
                $offers   = [];
                $cap      = max(1, (int)($s['jsonld_service_max'] ?? 60));
                foreach ($items as $item) {
                    if (count($offers) >= $cap) break;
                    if (!empty($s['ssr_only_available']) && empty($item['is_available'])) continue;
                    $sec = $sections[$item['section_id'] ?? ''] ?? null;
                    $offers[] = [
                        '@type'       => 'Service',
                        'name'        => $item['name'],
                        'serviceType' => $sec ? $sec['name'] : '',
                        'description' => csc_seo_item_text($item),
                        'provider'    => ['@id' => rtrim(home_url('/'), '/') . '#business'],
                        'offers'      => [
                            '@type'         => 'Offer',
                            'price'         => (float)($item['price'] ?? 0),
                            'priceCurrency' => $s['price_currency'] ?: 'RUB',
                            'availability'  => 'https://schema.org/InStock',
                        ],
                    ];
                }
                if ($offers) {
                    $graph[] = [
                        '@type'    => 'ServiceCatalog',
                        'name'     => 'Каталог услуг — ' . get_bloginfo('name'),
                        'url'      => get_permalink(),
                        'hasOffer' => $offers,
                    ];
                }
            }

            // --- BreadcrumbList ---
            if (!empty($s['jsonld_breadcrumbs'])) {
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

            // --- FAQ на калькуляторе ---
            if (!empty($s['jsonld_faq']) && !empty($s['jsonld_faq_calc'])) {
                $faq = csc_seo_build_faq_nodes('calculator');
                if ($faq) $graph[] = $faq;
            }
        }

        if ($kind === 'become_master' && !empty($s['jsonld_faq']) && !empty($s['jsonld_faq_master'])) {
            $faq = csc_seo_build_faq_nodes('become_master');
            if ($faq) $graph[] = $faq;
        }

        if (!$graph) return;

        if (count($graph) > 1 && !empty($s['jsonld_graph'])) {
            $out = ['@context' => 'https://schema.org', '@graph' => $graph];
            echo '<script type="application/ld+json">' . wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            return;
        }
        // без @graph — несколько отдельных блоков script (валидный вариант)
        foreach ($graph as $node) {
            echo '<script type="application/ld+json">'
               . wp_json_encode(array_merge(['@context' => 'https://schema.org'], $node), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
               . '</script>' . "\n";
        }
    }
}

if (!function_exists('csc_seo_build_faq_nodes')) {
    /** FAQPage: типовые вопросы по ценам/городам/гарантии; редактируются фильтром csc_seo_faq_nodes. */
    function csc_seo_build_faq_nodes($kind) {
        $cities = csc_seo_cities_list();
        $price  = csc_seo_price_from();
        $pairs  = [];
        if ($kind === 'calculator') {
            $pairs[] = ['Как рассчитывается стоимость услуги?', 'Используйте онлайн-калькулятор на этой странице: выберите раздел и услуги — цена рассчитается мгновенно' . ($price ? '. Минимальный заказ ' . $price : '') . '.'];
            $pairs[] = ['В каких городах вы работаете?', 'Текущие города обслуживания: ' . implode(', ', $cities) . '.'];
            $pairs[] = ['Есть ли гарантия на работы?', 'Да, на все выполненные работы предоставляется гарантия. Условия гарантии указаны в заявке.'];
        } else {
            $pairs[] = ['Как стать мастером?', 'Заполните анкету на этой странице: город, специализация, радиус выездной зоны. После модерации вы получите доступ в кабинет мастера с входящими заявками.'];
            $pairs[] = ['В каких городах вы работаете?', 'Текущие города: ' . implode(', ', $cities) . '.'];
        }
        $nodes = [];
        foreach ($pairs as $p) {
            $nodes[] = ['@type' => 'Question', 'name' => $p[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p[1]]];
        }
        $nodes = apply_filters('csc_seo_faq_nodes', $nodes, $kind);
        return $nodes ? ['@type' => 'FAQPage', 'mainEntity' => $nodes] : null;
    }
}
// ============================================================
// 5. XML-SITEMAP ПЛАГИНА: /csc-sitemap.xml
//    Страницы WP + разделы/услуги (якорями на страницу калькулятора) + города.
// ============================================================

add_action('init', 'csc_seo_rewrite_rules', 9);
if (!function_exists('csc_seo_rewrite_rules')) {
    function csc_seo_rewrite_rules() {
        $s = csc_seo_get_settings();
        $path = trim((string)($s['sitemap_path'] ?? 'csc-sitemap.xml'), '/');
        if ($path === '') $path = 'csc-sitemap.xml';
        add_rewrite_rule('^' . preg_quote($path, '#') . '$', 'index.php?csc_sitemap=1', 'top');
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
if (!function_exists('csc_seo_build_sitemap_xml')) {
    /** Генерация XML-карты (П5): состав URL настраивается по типам в админке. */
    function csc_seo_build_sitemap_xml() {
        $s = csc_seo_get_settings();
        $calc_url   = csc_seo_find_shortcode_page_url('service_calculator');
        $master_url = csc_seo_find_shortcode_page_url('csc_become_master');
        $cab_url    = csc_seo_find_shortcode_page_url('csc_personal_cabinet');

        $urls = [];
        $now  = gmdate('Y-m-d');
        if (!empty($s['sitemap_wp_pages'])) {
            foreach (array_filter([$calc_url, $master_url, $cab_url]) as $u) {
                $urls[] = ['loc' => $u, 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => $u === $calc_url ? '1.0' : '0.7'];
            }
        }
        $max = max(100, (int)($s['sitemap_max_urls'] ?? 5000));

        if ($calc_url && !empty($s['sitemap_sections'])) {
            foreach ((array)csc_get_sections() as $sid => $sec) {
                if (count($urls) >= $max) break;
                $urls[] = ['loc' => $calc_url . '#section-' . $sid, 'lastmod' => $now, 'changefreq' => 'weekly', 'priority' => '0.8'];
            }
        }
        if ($calc_url && !empty($s['sitemap_items'])) {
            foreach ((array)csc_get_items() as $iid => $item) {
                if (count($urls) >= $max) break;
                if (!empty($s['ssr_only_available']) && empty($item['is_available'])) continue;
                $urls[] = ['loc' => $calc_url . '#item-' . $iid, 'lastmod' => $now, 'changefreq' => 'weekly', 'priority' => '0.6'];
            }
        }
        if ($calc_url && !empty($s['sitemap_cities']) && function_exists('csc_get_cities')) {
            foreach ((array)csc_get_cities() as $slug => $city) {
                if (count($urls) >= $max) break;
                if (!empty($city['active'])) {
                    $urls[] = ['loc' => add_query_arg('csc_city', $slug, $calc_url), 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => '0.9'];
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . esc_html($u['loc']) . '</loc>';
            if (!empty($s['sitemap_lastmod']))    $xml .= '<lastmod>' . $u['lastmod'] . '</lastmod>';
            if (!empty($s['sitemap_changefreq'])) $xml .= '<changefreq>' . $u['changefreq'] . '</changefreq>';
            if (!empty($s['sitemap_priority']))   $xml .= '<priority>' . $u['priority'] . '</priority>';
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    }
}

if (!function_exists('csc_seo_sitemap_output')) {
    function csc_seo_sitemap_output() {
        if (!isset($_GET['csc_sitemap']) && !get_query_var('csc_sitemap')) return;
        if (!csc_seo_enabled('sitemap_enabled')) return;

        $s   = csc_seo_get_settings();
        $ttl = max(60, (int)($s['sitemap_cache_ttl'] ?? 300));
        $xml = get_transient('csc_seo_sitemap_xml');
        if ($xml === false || $xml === '') {
            $xml = csc_seo_build_sitemap_xml();
            set_transient('csc_seo_sitemap_xml', $xml, $ttl);
        }

        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);
        header('Cache-Control: public, max-age=' . $ttl);
        echo $xml;
        exit;
    }
}

// Сброс кэша карты страниц/сайта при изменении каталога или городов.
foreach (['update_option_csc_service_sections', 'update_option_csc_service_items', 'update_option_csc_cities'] as $csc_seo_hook) {
    add_action($csc_seo_hook, function () {
        delete_transient('csc_seo_sitemap_xml');
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
        if (!empty($s['robots_disallow_service'])) {
            $add .= "Disallow: /*?csc_master_dashboard=1\n";
            $add .= "Disallow: /*?csc_upsell=1\n";
            $add .= "Disallow: /*&csc_token=\n";
            $add .= "Disallow: /csc-sw.js\n";
        }
        // Пользовательские директивы (админка → «Sitemap и robots»)
        $extra = trim((string)($s['robots_extra_lines'] ?? ''));
        if ($extra !== '') {
            foreach (preg_split('/\R/', $extra) as $line) {
                $line = trim($line);
                if ($line !== '' && preg_match('#^(Disallow|Allow|Crawl-delay|Sitemap|User-agent|Host):#i', $line)) {
                    $add .= $line . "\n";
                }
            }
        }
        return $output . $add;
    }
}

// X-Robots-Tag для служебных GET-экранов (страховка, если ссылку всё же проиндексировали).
add_action('send_headers', 'csc_seo_service_noindex_header');
if (!function_exists('csc_seo_service_noindex_header')) {
    function csc_seo_service_noindex_header() {
        if (is_admin() || !csc_seo_enabled()) return;
        $s = csc_seo_get_settings();
        if (!empty($s['service_noindex'])) {
            foreach (['csc_master_dashboard', 'csc_upsell'] as $k) {
                if (isset($_GET[$k])) {
                    header('X-Robots-Tag: noindex, nofollow', true);
                    return;
                }
            }
        }
        if (!empty($s['noindex_404']) && is_404()) {
            header('X-Robots-Tag: noindex, follow', true);
        }
        if (!empty($s['noindex_search']) && is_search()) {
            header('X-Robots-Tag: noindex, follow', true);
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
    /**
     * П6. Server-side каталог услуг внутри [service_calculator]: контент индексируется
     * без выполнения JS. Подпункты (заголовки/цены/длительность/описания/noscript) —
     * отдельные переключатели в админке на вкладке «SSR-каталог».
     */
    function csc_seo_inject_ssr_catalog($content) {
        if (!csc_seo_enabled('ssr_catalog')) return $content;
        if (is_admin() || !is_main_query() || !is_singular()) return $content;
        if (strpos($content, '[service_calculator]') === false) return $content;

        $s = csc_seo_get_settings();
        $sections = (array)csc_get_sections();
        $items    = (array)csc_get_items();
        if (!$sections) return $content;

        $lvl  = in_array($s['ssr_headings_level'] ?? 'h2', ['h2','h3','h4'], true) ? $s['ssr_headings_level'] : 'h2';
        $sub  = $lvl === 'h2' ? 'h3' : ($lvl === 'h3' ? 'h4' : 'h5');

        $html = '';
        if (!empty($s['ssr_hide_noscript'])) {
            $html .= '<noscript><style>#csc-calculator .csc-section-tiles,#csc-calculator .csc-services-section{display:none!important}</style></noscript>';
        }
        $html .= '<div id="csc-catalog-ssr" class="csc-catalog-ssr">';
        $html .= "<{$lvl}>Наши услуги и цены</{$lvl}><ul class=\"csc-ssr-list\">";
        foreach ($sections as $sid => $sec) {
            $sec_desc = csc_seo_section_text($sec);
            $html .= '<li class="csc-ssr-section">' . "<{$sub} id=\"section-" . esc_attr($sid) . '">' . esc_html($sec['name']) . "</{$sub}>";
            if (!empty($s['ssr_show_desc']) && $sec_desc !== $sec['name']) {
                $html .= '<p class="csc-ssr-section-desc">' . esc_html($sec_desc) . '</p>';
            }
            $html .= '<ul>';
            $any = false;
            foreach ($items as $iid => $item) {
                if (!empty($s['ssr_only_available']) && empty($item['is_available'])) continue;
                if (($item['section_id'] ?? '') !== $sid) continue;
                $any = true;
                $line = '<li id="item-' . esc_attr($iid) . '">' . esc_html($item['name']);
                if (!empty($s['ssr_show_prices'])) {
                    $line .= ' — <span class="csc-ssr-item-price">' . esc_html(number_format((float)$item['price'], 0, ',', ' ') . ' ₽') . '</span>';
                }
                if (!empty($s['ssr_show_duration']) && !empty($item['duration'])) {
                    $line .= ', ' . esc_html(sprintf('%d мин', (int)$item['duration']));
                }
                if (!empty($s['ssr_show_desc']) && !empty($item['seo_desc'])) {
                    $line .= '. ' . esc_html($item['seo_desc']);
                }
                $html .= $line . '</li>';
            }
            if (!$any) $html .= '<li>Уточняйте наличие услуг в этом разделе.</li>';
            $html .= '</ul></li>';
        }
        $html .= '</ul></div>';

        // Вставка сразу после контейнера калькулятора (если блок найден), иначе — в конец контента.
        if (!empty($s['ssr_after_shortcode'])) {
            $pos = strpos($content, 'class="csc-calculator"');
            if ($pos !== false) {
                // ищем закрывающий </div> первого уровня контейнера — эвристика: перед последним </div> блока шорткода
                $tail = substr($content, $pos);
                $close = strrpos($tail, '</div>');
                if ($close !== false) {
                    $insert_at = $pos + $close + strlen('</div>');
                    return substr($content, 0, $insert_at) . $html . substr($content, $insert_at);
                }
            }
        }
        return $content . $html;
    }
}

// ============================================================
// 8. ПЕРЕЛИНКОВКА: SEO-блок городов/разделов внизу страницы калькулятора
// ============================================================

add_action('wp_footer', 'csc_seo_footer_cross_links', 30);
if (!function_exists('csc_seo_render_cross_links')) {
    /** П7. HTML перелинковки; вызывается из wp_footer или the_content (position в настройках). */
    function csc_seo_render_cross_links($echo = true) {
        if (!csc_seo_enabled('cross_links')) return '';
        if (csc_seo_detect_page_kind() !== 'calculator') return '';
        $calc = get_permalink();
        if (!$calc) return '';

        $s = csc_seo_get_settings();
        $sections = (array)csc_get_sections();
        $cities   = function_exists('csc_get_cities') ? (array)csc_get_cities() : [];
        $show_sec = !empty($s['cross_sections']) && $sections;
        $show_cty = !empty($s['cross_cities']) && $cities;
        if (!$show_sec && !$show_cty) return '';

        $html = '<nav class="csc-seo-links" aria-label="Дополнительные ссылки" style="max-width:800px;margin:24px auto;padding:0 16px;font-size:13px;color:#64748b;">';
        if ($show_sec) {
            $cap = max(1, (int)($s['cross_max_sections'] ?? 12));
            $html .= '<p><strong>' . esc_html($s['cross_sections_label'] ?: 'Популярные категории:') . '</strong> ';
            $links = [];
            foreach (array_slice($sections, 0, $cap, true) as $sid => $sec) {
                $links[] = '<a href="' . esc_url($calc . '#section-' . $sid) . '">' . esc_html($sec['name']) . '</a>';
            }
            $html .= implode(', ', $links) . '</p>';
        }
        if ($show_cty) {
            $cap = max(1, (int)($s['cross_max_cities'] ?? 12));
            $html .= '<p><strong>' . esc_html($s['cross_cities_label'] ?: 'Мы работаем в городах:') . '</strong> ';
            $links = [];
            $i = 0;
            $rel = !empty($s['cross_nofollow']) ? ' rel="nofollow"' : '';
            foreach ($cities as $slug => $city) {
                if ($i >= $cap) break;
                if (empty($city['active'])) continue;
                $i++;
                $links[] = '<a href="' . esc_url(add_query_arg('csc_city', $slug, $calc)) . '"' . $rel . '>' . esc_html($city['name']) . '</a>';
            }
            $html .= implode(', ', $links) . '</p>';
        }
        $html .= '</nav>';

        if ($echo) echo $html;
        return $html;
    }
}
if (!function_exists('csc_seo_footer_cross_links')) {
    function csc_seo_footer_cross_links() {
        $s = csc_seo_get_settings();
        if (($s['cross_position'] ?? 'footer') !== 'footer') return;
        csc_seo_render_cross_links(true);
    }
}

// Вариант размещения «конец контента» — тот же блок, но до футера темы.
add_filter('the_content', 'csc_seo_content_end_cross_links', 30);
if (!function_exists('csc_seo_content_end_cross_links')) {
    function csc_seo_content_end_cross_links($content) {
        if (!csc_seo_enabled('cross_links')) return $content;
        $s = csc_seo_get_settings();
        if (($s['cross_position'] ?? 'footer') !== 'content_end') return $content;
        if (strpos((string)$content, '[service_calculator]') === false) return $content;
        return $content . csc_seo_render_cross_links(false);
    }
}

// ============================================================
// 9. CORE WEB VITALS: defer внешних скриптов шорткода
// ============================================================

add_filter('script_loader_tag', 'csc_seo_defer_vendor_scripts', 10, 3);
if (!function_exists('csc_seo_defer_vendor_scripts')) {
    function csc_seo_defer_vendor_scripts($tag, $handle, $src) {
        if (is_admin() || !csc_seo_enabled('cwv_enabled') || !csc_seo_enabled('defer_vendor_scripts')) return $tag;
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
        if (!csc_seo_enabled('cwv_enabled') || !csc_seo_enabled('img_alt_fallback')) return $attr;
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
