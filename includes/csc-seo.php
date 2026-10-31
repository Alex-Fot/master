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
            'ssr_after_shortcode'   => 1,   // вставка сразу после контейнера калькулятора

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

if (!function_exists('csc_seo_log')) {
    /** П0 debug_log: трассировка решений модуля в общий логгер CSC. */
    function csc_seo_log($message) {
        $s = csc_seo_get_settings();
        if (empty($s['debug_log']) || empty($s['enabled'])) return;
        if (function_exists('csc_log_debug')) {
            csc_log_debug((string)$message, 'seo');
        } elseif (function_exists('csc_logger')) {
            csc_logger('SEO', (string)$message);
        } elseif (function_exists('csc_log')) {
            csc_log((string)$message, 'debug', 'seo');
        }
    }
}

if (!function_exists('csc_seo_find_shortcode_page_url')) {
    /** URL первой страницы с указанным шорткодом (кэш в трансиенте 12 ч). */
    function csc_seo_find_shortcode_page_url($shortcode) {
        $key = 'csc_seo_page_' . md5($shortcode);
        $url = get_transient($key);
        if ($url !== false) return $url ?: null;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 1",
            '%' . $wpdb->esc_like('[' . $shortcode) . '%'
        ));
        $url = $ids ? (string)get_permalink((int)$ids[0]) : '';
        set_transient($key, $url, 12 * HOUR_IN_SECONDS);
        return $url ?: null;
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
            ['%sitename%', '%cities%', '%price_from%', '%year%', '%tagline%'],
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
        echo '<!-- CSC SEO -->' . "
";
        if ($desc !== '') {
            echo '<meta name="description" content="' . esc_attr(mb_substr($desc, 0, $dmax)) . '">' . "
";
            csc_seo_log('description выведен: ' . $kind . ' (' . mb_strlen(mb_substr($desc, 0, $dmax)) . ' симв.)');
        } else {
            csc_seo_log('description пропущен: пусто для ' . $kind);
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
                echo '<link rel="canonical" href="' . esc_url($clean) . '">' . "
";
                csc_seo_log('canonical: ' . $clean);
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
// 9. CORE WEB VITALS: defer внешних скриптов, lazy-load и alt
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

// Lazy-loading изображений внутри HTML плагина (img в the_content/SSR-блоках).
add_filter('csc_html_output', 'csc_seo_html_lazyloading', 5);
add_filter('the_content', 'csc_seo_html_lazyloading', 5);
if (!function_exists('csc_seo_html_lazyloading')) {
    function csc_seo_html_lazyloading($html) {
        if (is_admin() || !csc_seo_enabled('cwv_enabled') || !csc_seo_enabled('img_lazyloading')) return $html;
        if (stripos((string)$html, '<img') === false) return $html;
        return preg_replace_callback('/<img\b(?![^>]*loading=)([^>]*)>/i', function ($m) {
            return '<img loading="lazy" decoding="async"' . $m[1] . '>';
        }, $html);
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
// 9b. РЕДИРЕКТЫ 301/302 (П9): обрабатываются на template_redirect
//     Список управляется админкой («SEO (CSC)» → «Редиректы»).
// ============================================================

add_action('template_redirect', 'csc_seo_handle_redirects', 0);
if (!function_exists('csc_seo_handle_redirects')) {
    function csc_seo_handle_redirects() {
        if (is_admin() || !csc_seo_enabled('redirects_enabled')) return;
        $list = csc_seo_get_settings()['redirects_list'] ?? [];
        if (!is_array($list) || !$list) return;

        // Нормализуем текущий путь: /path/ без query, в нижнем регистре для сопоставления.
        $req_path = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $req_path = strtok($req_path, '?');
        if ($req_path === null || $req_path === '') return;

        foreach ($list as $r) {
            $from = (string)($r['from'] ?? '');
            $to   = (string)($r['to'] ?? '');
            $code = in_array((int)($r['code'] ?? 301), [301, 302, 307, 308], true) ? (int)$r['code'] : 301;
            if ($from === '' || $to === '' || $from === $to) continue;

            // from может быть полным URL другого домена или относительным путём.
            if (preg_match('#^https?://#i', $from)) {
                $from_path = wp_parse_url($from, PHP_URL_PATH);
                if (!$from_path) continue;
                $match = strcasecmp($req_path, $from_path) === 0 || strcasecmp($req_path, rtrim($from_path, '/')) === 0;
            } else {
                $match = strcasecmp($req_path, rtrim($from, '/')) === 0 || strcasecmp($req_path, $from) === 0;
            }
            if (!$match) continue;

            // Защита от петли: не редиректим страницу на неё же (после нормализации home_url).
            $target = preg_match('#^https?://#i', $to) ? $to : home_url($to);
            $tpath  = wp_parse_url($target, PHP_URL_PATH);
            if ($tpath && strcasecmp((string)$tpath, $req_path) === 0) continue;
            csc_seo_log('Redirect ' . $code . ': ' . $req_path . ' -> ' . $target);
            wp_redirect(esc_url_raw($target), $code);
            exit;
        }
    }
}

// ============================================================
// 10. АДМИНКА: модуль настроек «SEO (CSC)» — декларативная схема
//     КАЖДЫЙ оптимизационный пункт аудита представлен отдельным
//     полем/переключателем. Схема = источник истины для рендера,
//     санитайзинга и значений по умолчанию (нет рассинхрона UI/кода).
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

/**
 * Полная админская схема модуля: вкладки → секции → поля.
 * Типы полей: checkbox | text | textarea | number | url | email | select | multiline | redirect_rows | token_help.
 * Ключ каждого поля совпадает с ключом в csc_seo_get_defaults()/csc_seo_settings.
 */
if (!function_exists('csc_seo_admin_fields_schema')) {
    function csc_seo_admin_fields_schema() {
        $business_types = ['HomeAndConstructionBusiness','LocalBusiness','GeneralContractor','Plumber','Electrician','HVACBusiness','MovingCompany','Locksmith','ProfessionalService'];
        $og_types       = ['website','article','business.business','service'];
        $compat         = ['auto' => 'Авто — уступать внешнему SEO-плагину, если он заполнил страницу', 'force' => 'Всегда включать наши теги (перехват управления)', 'off' => 'Выключено — meta/OG не выводим'];
        $positions      = ['footer' => 'В footer страницы (wp_footer)', 'content_end' => 'В конце контента страницы (the_content)'];
        $headings       = ['h2' => 'H2 (рекомендуется)', 'h3' => 'H3', 'h4' => 'H4'];

        $tabs = [];

        $tabs['general'] = [
            'title' => '⚙️ Общие',
            'desc'  => 'Главный выключатель модуля и диагностика.',
            'sections' => [
                '' => [
                    ['k' => 'enabled', 't' => 'checkbox', 'l' => 'SEO-модуль включён (все подмодули работают только при активном главном переключателе)'],
                    ['k' => 'debug_log', 't' => 'checkbox', 'l' => 'Журналировать решения хуков (что выведено/пропущено и почему) в CSC Logger'],
                    ['t' => 'token_help'],
                ],
            ],
        ];

        $tabs['meta'] = [
            'title' => '📝 Title / Description',
            'desc'  => 'П1. Meta-теги страниц с шорткодами плагина. Пустое поле = не трогаем title/description страницы.',
            'sections' => [
                'Глобальные параметры' => [
                    ['k' => 'meta_enabled', 't' => 'checkbox', 'l' => 'Подмодуль meta title/description включён'],
                    ['k' => 'title_template', 't' => 'text', 'l' => 'Шаблон &lt;title&gt;. Токены: <code>%title%</code> — заголовок ниже по вкладке, <code>%sitename%</code>, <code>%tagline%</code>, <code>%year%</code>.', 'cls' => 'large-text code'],
                    ['k' => 'title_max', 't' => 'number', 'l' => 'Макс. длина &lt;title&gt;, символов (0 — не обрезать; рекомендуется 55–65)', 'min' => 0, 'max' => 180],
                    ['k' => 'desc_max', 't' => 'number', 'l' => 'Жёсткий лимит description, символов (рекомендуется 160–300; Google обрезает ~160)', 'min' => 50, 'max' => 500],
                ],
                'Страница калькулятора [service_calculator]' => [
                    ['k' => 'calc_title', 't' => 'text', 'l' => 'Title', 'cls' => 'large-text code'],
                    ['k' => 'calc_desc', 't' => 'textarea', 'l' => 'Description. Токены: <code>%cities%</code> — активные города, <code>%price_from%</code> — «от N ₽» из каталога, <code>%sitename%</code>.', 'rows' => 3],
                ],
                'Личный кабинет [csc_personal_cabinet]' => [
                    ['k' => 'cabinet_title', 't' => 'text', 'l' => 'Title', 'cls' => 'large-text code'],
                    ['k' => 'cabinet_desc', 't' => 'textarea', 'l' => 'Description', 'rows' => 2],
                ],
                'Стать мастером [csc_become_master]' => [
                    ['k' => 'master_title', 't' => 'text', 'l' => 'Title', 'cls' => 'large-text code'],
                    ['k' => 'master_desc', 't' => 'textarea', 'l' => 'Description', 'rows' => 2],
                ],
            ],
        ];

        $tabs['canonical'] = [
            'title' => '🔗 Canonical / Robots',
            'desc'  => 'П2. Управление каноническими URL и директивами индексации для служебных экранов.',
            'sections' => [
                'Canonical' => [
                    ['k' => 'canonical_enabled', 't' => 'checkbox', 'l' => 'Выводить чистый <code>&lt;link rel="canonical"&gt;</code> страниц плагина (без query-параметров)'],
                    ['k' => 'canonical_force_ssl', 't' => 'checkbox', 'l' => 'Принудительно https в canonical'],
                    ['k' => 'canonical_trailing_slash', 't' => 'checkbox', 'l' => 'Единый формат: завершающий слэш (иначе — без слэша)'],
                ],
                'X-Robots-Tag (HTTP-заголовки)' => [
                    ['k' => 'noindex_404', 't' => 'checkbox', 'l' => 'noindex,follow для страниц 404'],
                    ['k' => 'noindex_search', 't' => 'checkbox', 'l' => 'noindex,follow для результатов поиска <code>?s=</code> (защита от мусорных кластеров)'],
                    ['k' => 'service_noindex', 't' => 'checkbox', 'l' => 'noindex,nofollow для служебных GET-экранов (<code>?csc_master_dashboard=1</code>, <code>?csc_upsell=…</code>)'],
                ],
            ],
        ];

        $tabs['social'] = [
            'title' => '🌐 Open Graph / Twitter',
            'desc'  => 'П3. Сниппеты соцсетей для страниц плагина.',
            'sections' => [
                'Open Graph' => [
                    ['k' => 'og_enabled', 't' => 'checkbox', 'l' => 'Выводить og:* теги'],
                    ['k' => 'og_site_name', 't' => 'text', 'l' => 'og:site_name (пусто = название сайта)'],
                    ['k' => 'og_locale', 't' => 'text', 'l' => 'og:locale (например ru_RU)'],
                    ['k' => 'og_type_calc', 't' => 'select', 'l' => 'og:type — калькулятор', 'opts' => array_combine($og_types, $og_types)],
                    ['k' => 'og_type_cabinet', 't' => 'select', 'l' => 'og:type — личный кабинет', 'opts' => array_combine($og_types, $og_types)],
                    ['k' => 'og_type_master', 't' => 'select', 'l' => 'og:type — стать мастером', 'opts' => array_combine($og_types, $og_types)],
                ],
                'Twitter Card' => [
                    ['k' => 'twitter_enabled', 't' => 'checkbox', 'l' => 'Выводить twitter:* теги'],
                    ['k' => 'twitter_site', 't' => 'text', 'l' => 'twitter:site — @username аккаунта'],
                ],
                'Изображение по умолчанию' => [
                    ['k' => 'default_image', 't' => 'url', 'l' => 'OG-обложка 1200×630 (пусто = миниатюра записи)', 'ph' => 'https://example.com/og-cover.jpg'],
                    ['k' => 'default_image_w', 't' => 'number', 'l' => 'Ширина', 'min' => 0, 'max' => 4096],
                    ['k' => 'default_image_h', 't' => 'number', 'l' => 'Высота', 'min' => 0, 'max' => 4096],
                ],
            ],
        ];

        $tabs['jsonld'] = [
            'title' => '📦 Микроразметка JSON-LD',
            'desc'  => 'П4. Schema.org: каждый тип узла — отдельный переключатель.',
            'sections' => [
                'Общее' => [
                    ['k' => 'jsonld_enabled', 't' => 'checkbox', 'l' => 'Выводить JSON-LD'],
                    ['k' => 'jsonld_graph', 't' => 'checkbox', 'l' => 'Объединять узлы в <code>@graph</code> (иначе — отдельные блоки script)'],
                ],
                'LocalBusiness' => [
                    ['k' => 'jsonld_localbusiness', 't' => 'checkbox', 'l' => 'Узел LocalBusiness'],
                    ['k' => 'business_name', 't' => 'text', 'l' => 'Название организации (пусто = название сайта)'],
                    ['k' => 'business_type', 't' => 'select', 'l' => '@type по schema.org', 'opts' => array_combine($business_types, $business_types)],
                    ['k' => 'business_phone', 't' => 'text', 'l' => 'Телефон', 'ph' => '+7 900 000-00-00'],
                    ['k' => 'business_email', 't' => 'email', 'l' => 'E-mail'],
                    ['k' => 'business_area', 't' => 'text', 'l' => 'areaServed вручную, через запятую (пусто = список активных городов)'],
                    ['k' => 'geo_lat', 't' => 'text', 'l' => 'geo: latitude', 'ph' => '55.751244'],
                    ['k' => 'geo_lng', 't' => 'text', 'l' => 'geo: longitude', 'ph' => '37.618423'],
                    ['k' => 'opening_hours', 't' => 'text', 'l' => 'openingHours (формата schema.org)', 'ph' => 'Mo-Su 08:00-22:00'],
                    ['k' => 'price_currency', 't' => 'text', 'l' => 'Валюта Offer\'ов (ISO-4217)', 'ph' => 'RUB'],
                    ['k' => 'jsonld_reviews', 't' => 'checkbox', 'l' => 'aggregateRating из данных рейтингов мастеров (при ≥3 отзывах)'],
                    ['k' => 'jsonld_social', 't' => 'checkbox', 'l' => 'sameAs (соцсети ниже)'],
                ],
                'WebSite / ServiceCatalog / Breadcrumb / FAQ' => [
                    ['k' => 'jsonld_website', 't' => 'checkbox', 'l' => 'WebSite + SearchAction (поиск по сайту)'],
                    ['k' => 'jsonld_service_catalog', 't' => 'checkbox', 'l' => 'ServiceCatalog с Offer по каждой услуге'],
                    ['k' => 'jsonld_service_max', 't' => 'number', 'l' => 'Лимит Service-узлов в каталоге (размер блока)', 'min' => 1, 'max' => 500],
                    ['k' => 'jsonld_breadcrumbs', 't' => 'checkbox', 'l' => 'BreadcrumbList'],
                    ['k' => 'jsonld_faq', 't' => 'checkbox', 'l' => 'FAQPage (общий выключатель)'],
                    ['k' => 'jsonld_faq_calc', 't' => 'checkbox', 'l' => '— FAQ на странице калькулятора'],
                    ['k' => 'jsonld_faq_master', 't' => 'checkbox', 'l' => '— FAQ на «Стать мастером»'],
                ],
                'Соцсети (sameAs)' => [
                    ['k' => 'social_facebook', 't' => 'url', 'l' => 'Facebook'],
                    ['k' => 'social_instagram', 't' => 'url', 'l' => 'Instagram'],
                    ['k' => 'social_vk', 't' => 'url', 'l' => 'VK'],
                    ['k' => 'social_telegram', 't' => 'url', 'l' => 'Telegram'],
                    ['k' => 'social_youtube', 't' => 'url', 'l' => 'YouTube'],
                ],
            ],
        ];

        $tabs['sitemap'] = [
            'title' => '🗺️ Sitemap / robots.txt',
            'desc'  => 'П5. Собственная XML-карта и директивы robots. Состав карты настраивается по типам URL.',
            'sections' => [
                'XML-Sitemap' => [
                    ['k' => 'sitemap_enabled', 't' => 'checkbox', 'l' => 'Отдавать XML-карту по пути ниже (по умолчанию <code>/csc-sitemap.xml</code>)'],
                    ['k' => 'sitemap_path', 't' => 'text', 'l' => 'Путь карты (без ведущего слэша)', 'ph' => 'csc-sitemap.xml'],
                    ['k' => 'sitemap_wp_pages', 't' => 'checkbox', 'l' => 'Страницы с шорткодами плагина'],
                    ['k' => 'sitemap_sections', 't' => 'checkbox', 'l' => 'Якоря разделов <code>#section-{id}</code>'],
                    ['k' => 'sitemap_items', 't' => 'checkbox', 'l' => 'Якоря услуг <code>#item-{id}</code> (может раздуть карту — включайте осознанно)'],
                    ['k' => 'sitemap_cities', 't' => 'checkbox', 'l' => 'Городские фильтры <code>?csc_city=slug</code>'],
                    ['k' => 'sitemap_lastmod', 't' => 'checkbox', 'l' => 'Элемент lastmod'],
                    ['k' => 'sitemap_changefreq', 't' => 'checkbox', 'l' => 'Элемент changefreq'],
                    ['k' => 'sitemap_priority', 't' => 'checkbox', 'l' => 'Элемент priority'],
                    ['k' => 'sitemap_max_urls', 't' => 'number', 'l' => 'Лимит URL в карте', 'min' => 100, 'max' => 50000],
                    ['k' => 'sitemap_cache_ttl', 't' => 'number', 'l' => 'Кэш XML, секунд (0 = всегда перегенерировать)', 'min' => 0, 'max' => 86400],
                ],
                'robots.txt' => [
                    ['k' => 'robots_sitemap', 't' => 'checkbox', 'l' => 'Директива Sitemap:'],
                    ['k' => 'robots_disallow_service', 't' => 'checkbox', 'l' => 'Disallow для служебных query-параметров и /csc-sw.js'],
                    ['k' => 'robots_extra_lines', 't' => 'multiline', 'l' => 'Произвольные директивы (разрешены User-agent/Sitemap/Disallow/Allow/Crawl-delay/Host; по одной на строку)', 'rows' => 4],
                ],
            ],
        ];

        $tabs['ssr'] = [
            'title' => '🖥️ SSR-каталог',
            'desc'  => 'П6. Каталог услуг в исходном HTML (индексация без JS). Краулер видит H2/H3, названия, цены и описания.',
            'sections' => [
                '' => [
                    ['k' => 'ssr_catalog', 't' => 'checkbox', 'l' => 'Встраивать блок <code>#csc-catalog-ssr</code> в страницы с [service_calculator]'],
                    ['k' => 'ssr_headings_level', 't' => 'select', 'l' => 'Уровень главного заголовка', 'opts' => $headings],
                    ['k' => 'ssr_show_prices', 't' => 'checkbox', 'l' => 'Показывать цены услуг'],
                    ['k' => 'ssr_show_duration', 't' => 'checkbox', 'l' => 'Показывать длительность (мин)'],
                    ['k' => 'ssr_show_desc', 't' => 'checkbox', 'l' => 'Выводить seo_desc разделов/услуг текстом'],
                    ['k' => 'ssr_only_available', 't' => 'checkbox', 'l' => 'Только доступные услуги (совпадает с Offer availability)'],
                    ['k' => 'ssr_hide_noscript', 't' => 'checkbox', 'l' => 'noscript-скрытие JS-дублей плиток (чистый DOM для краулера)'],
                    ['k' => 'ssr_after_shortcode', 't' => 'checkbox', 'l' => 'Вставлять сразу после контейнера калькулятора (иначе — в конец контента)'],
                ],
            ],
        ];

        $tabs['links'] = [
            'title' => '🧭 Перелинковка',
            'desc'  => 'П7. SEO-навигация разделов/городов внизу страницы калькулятора.',
            'sections' => [
                '' => [
                    ['k' => 'cross_links', 't' => 'checkbox', 'l' => 'Блок перелинковки включён'],
                    ['k' => 'cross_sections', 't' => 'checkbox', 'l' => 'Ссылки на разделы (#section-id)'],
                    ['k' => 'cross_cities', 't' => 'checkbox', 'l' => 'Ссылки на города (?csc_city=slug)'],
                    ['k' => 'cross_sections_label', 't' => 'text', 'l' => 'Заголовок блока разделов'],
                    ['k' => 'cross_cities_label', 't' => 'text', 'l' => 'Заголовок блока городов'],
                    ['k' => 'cross_max_sections', 't' => 'number', 'l' => 'Макс. ссылок на разделы', 'min' => 1, 'max' => 100],
                    ['k' => 'cross_max_cities', 't' => 'number', 'l' => 'Макс. ссылок на города', 'min' => 1, 'max' => 100],
                    ['k' => 'cross_position', 't' => 'select', 'l' => 'Размещение блока', 'opts' => $positions],
                    ['k' => 'cross_nofollow', 't' => 'checkbox', 'l' => 'rel="nofollow" для городских фильтров'],
                ],
            ],
        ];

        $tabs['cwv'] = [
            'title' => '⚡ Core Web Vitals',
            'desc'  => 'П8. Оптимизация загрузки ассетов и изображений.',
            'sections' => [
                '' => [
                    ['k' => 'cwv_enabled', 't' => 'checkbox', 'l' => 'Подмодуль CWV включён'],
                    ['k' => 'defer_vendor_scripts', 't' => 'checkbox', 'l' => 'defer для html2canvas (~190 KB, нужен только на шаге подтверждения)'],
                    ['k' => 'img_lazyloading', 't' => 'checkbox', 'l' => 'loading="lazy" для изображений в контенте плагина'],
                    ['k' => 'img_alt_fallback', 't' => 'checkbox', 'l' => 'alt из названия вложения, если alt пустой'],
                ],
            ],
        ];

        $tabs['redirects'] = [
            'title' => '↪️ Редиректы 301/302',
            'desc'  => 'П9. Управление переадресациями (например, старые URL кампаний → страница калькулятора).',
            'sections' => [
                '' => [
                    ['k' => 'redirects_enabled', 't' => 'checkbox', 'l' => 'Обработчик редиректов включён'],
                    ['k' => 'redirects_list', 't' => 'redirect_rows', 'l' => 'Список: путь откуда (относительный или полный URL) → куда → код'],
                ],
            ],
        ];

        $tabs['integrations'] = [
            'title' => '🔌 Интеграции / верификация',
            'desc'  => 'П10. Поведение рядом с внешними SEO-плагинами и meta-подтверждения сервисов вебмастеров.',
            'sections' => [
                'Совместимость с SEO-плагинами' => [
                    ['k' => 'compat_mode', 't' => 'select', 'l' => 'Режим работы meta/OG при активных Yoast/Rank Math/AIOSEO/SEOPress/The SEO Framework', 'opts' => $compat],
                    ['t' => 'compat_detected'],
                ],
                'Верификация поисковых систем' => [
                    ['k' => 'gsc_verification', 't' => 'text', 'l' => 'Google Search Console — content meta google-site-verification'],
                    ['k' => 'yandex_verification', 't' => 'text', 'l' => 'Яндекс.Вебмастер — yandex-verification'],
                    ['k' => 'bing_verification', 't' => 'text', 'l' => 'Bing — msvalidate.01'],
                ],
                'Поиск по сайту (SearchAction)' => [
                    ['k' => 'search_param', 't' => 'text', 'l' => 'GET-параметр внутреннего поиска в urlTemplate', 'ph' => 'csc_q'],
                ],
                'Токены шаблонов' => [
                    ['k' => 'token_price_separator', 't' => 'text', 'l' => 'Префикс токена %price_from% (например «от » или «от » с NBSP)'],
                    ['k' => 'token_cities_limit', 't' => 'number', 'l' => 'Макс. городов в %cities%', 'min' => 1, 'max' => 50],
                ],
            ],
        ];

        return apply_filters('csc_seo_admin_fields_schema', $tabs);
    }
}

/** Санитайзинг одного поля по типу схемы. */
if (!function_exists('csc_seo_sanitize_field')) {
    function csc_seo_sanitize_field($type, $key, $raw, $cur) {
        switch ($type) {
            case 'checkbox':
                return !empty($raw) ? 1 : 0;
            case 'number':
                return is_numeric($raw) ? (int)$raw : (int)$cur;
            case 'url':
                return esc_url_raw((string)$raw);
            case 'email':
                return sanitize_email((string)$raw);
            case 'textarea':
            case 'multiline':
                return sanitize_textarea_field((string)$raw);
            case 'select':
                return sanitize_text_field((string)$raw);
            case 'redirect_rows':
                $out = [];
                if (is_array($raw)) {
                    foreach ($raw as $row) {
                        $from = trim((string)($row['from'] ?? ''));
                        $to   = trim((string)($row['to'] ?? ''));
                        $code = (int)($row['code'] ?? 301);
                        if ($from === '' || $to === '') continue;
                        if (!in_array($code, [301, 302, 307, 308], true)) $code = 301;
                        if (strpos($from, 'http') !== 0 && $from[0] !== '/') $from = '/' . $from;
                        $out[] = ['from' => $from, 'to' => $to, 'code' => $code];
                    }
                }
                return $out;
            case 'text':
            default:
                return sanitize_text_field((string)$raw);
        }
    }
}

/** Рендер одного поля формы. */
if (!function_exists('csc_seo_render_field')) {
    function csc_seo_render_field($f, $val) {
        $name = 'csc_seo[' . $f['k'] . ']';
        $id   = 'csc_seo_' . $f['k'];
        $lbl  = '<label for="' . esc_attr($id) . '">' . $f['l'] . '</label>'; // доверяем только внутренним меткам схемы
        $hint = !empty($f['d']) ? '<p class="description">' . $f['d'] . '</p>' : '';
        echo '<tr><th scope="row">' . $lbl . '</th><td>';
        switch ($f['t']) {
            case 'checkbox':
                echo '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . checked(!empty($val), true, false) . '>';
                break;
            case 'textarea':
            case 'multiline':
                echo '<textarea class="large-text code" rows="' . (int)($f['rows'] ?? 3) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">' . esc_textarea((string)$val) . '</textarea>';
                break;
            case 'number':
                echo '<input type="number" class="small-text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string)(int)$val) . '"'
                   . (isset($f['min']) ? ' min="' . (int)$f['min'] . '"' : '')
                   . (isset($f['max']) ? ' max="' . (int)$f['max'] . '"' : '') . '>';
                break;
            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                foreach ((array)$f['opts'] as $ov => $ol) {
                    echo '<option value="' . esc_attr((string)$ov) . '"' . selected((string)$val, (string)$ov, false) . '>' . esc_html((string)$ol) . '</option>';
                }
                echo '</select>';
                break;
            case 'redirect_rows':
                $rows = is_array($val) ? $val : [];
                echo '<table class="csc-seo-redirects widefat" id="csc-seo-redirects"><thead><tr><th>Откуда (path/URL)</th><th>Куда</th><th style="width:110px">Код</th><th style="width:40px"></th></tr></thead><tbody>';
                foreach ($rows as $i => $r) {
                    csc_seo_redirect_row((string)($r['from'] ?? ''), (string)($r['to'] ?? ''), (int)($r['code'] ?? 301));
                }
                echo '</tbody></table><button type="button" class="button" id="csc-seo-redirect-add">+ Добавить редирект</button>';
                break;
            case 'email':
                echo '<input type="email" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string)$val) . '"' . (isset($f['ph']) ? ' placeholder="' . esc_attr($f['ph']) . '"' : '') . '>';
                break;
            case 'url':
            case 'text':
            default:
                $cls = !empty($f['cls']) ? $f['cls'] : 'regular-text';
                echo '<input type="text" class="' . esc_attr($cls) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string)$val) . '"' . (isset($f['ph']) ? ' placeholder="' . esc_attr($f['ph']) . '"' : '') . '>';
        }
        echo $hint . '</td></tr>';
    }
}

if (!function_exists('csc_seo_redirect_row')) {
    function csc_seo_redirect_row($from, $to, $code) {
        printf('<tr><td><input type="text" class="large-text code" name="csc_seo[redirects_list][__IDX__][from]" value="%1$s" placeholder="/staraya-stranica/"></td>'
             . '<td><input type="text" class="large-text code" name="csc_seo[redirects_list][__IDX__][to]" value="%2$s" placeholder="/raschet-stoimosti/"></td>'
             . '<td><select name="csc_seo[redirects_list][__IDX__][code]">%3$s</select></td>'
             . '<td><button type="button" class="button-link-delete csc-seo-redirect-del" title="Удалить">✕</button></td></tr>',
            esc_attr($from), esc_attr($to),
            implode('', array_map(fn($c) => '<option value="' . $c . '"' . selected($code, $c, false) . '>' . $c . '</option>', [301, 302, 307, 308]))
        );
    }
}

if (!function_exists('csc_seo_admin_page')) {
    function csc_seo_admin_page() {
        if (!current_user_can('manage_options')) return;

        $saved = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_seo']) && check_admin_referer('csc_seo_save_action')) {
            $in  = isset($_POST['csc_seo']) && is_array($_POST['csc_seo']) ? wp_unslash($_POST['csc_seo']) : [];
            $cur = csc_seo_get_settings();
            $new = $cur;
            // Сохраняем ПОЛНУЮ схему настроек, переключая только поля текущей вкладки:
            // значения с других вкладок не теряются (их input'ов нет в этом POST).
            $tab_keys = [];
            foreach ($tabs_now = csc_seo_admin_fields_schema() as $t) {
                foreach ($t['sections'] as $fields) {
                    foreach ($fields as $f) {
                        if (!empty($f['k'])) $tab_keys[$f['k']] = $f['t'];
                    }
                }
            }
            $post_tab = isset($_POST['tab_anchor']) ? sanitize_key($_POST['tab_anchor']) : '';
            if ($post_tab !== '' && isset($tabs_now[$post_tab])) {
                foreach ($tabs_now[$post_tab]['sections'] as $fields) {
                    foreach ($fields as $f) {
                        if (empty($f['k'])) continue;
                        $new[$f['k']] = csc_seo_sanitize_field($f['t'], $f['k'], $in[$f['k']] ?? null, $cur[$f['k']] ?? null);
                    }
                }
            } else {
                foreach ($tab_keys as $k => $t) {
                    $new[$k] = csc_seo_sanitize_field($t, $k, $in[$k] ?? null, $cur[$k] ?? null);
                }
            }
            update_option('csc_seo_settings', $new, false);
            delete_transient('csc_seo_sitemap_xml');
            do_action('csc_seo_flush'); // flush_rewrite_rules для sitemap-пути
            $saved = true;
        }

        $s    = csc_seo_get_settings();
        $tabs = csc_seo_admin_fields_schema();
        $active = isset($_GET['tab']) && isset($tabs[sanitize_key($_GET['tab'])]) ? sanitize_key($_GET['tab']) : 'general';
        ?>
        <div class="wrap csc-seo-admin">
            <h1>🔎 SEO калькулятора услуг</h1>
            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>SEO-настройки сохранены.
                    <a href="<?php echo esc_url(home_url('/' . trim((string)$s['sitemap_path'], '/'))); ?>" target="_blank">Открыть sitemap</a> ·
                    <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank">robots.txt</a></p></div>
            <?php endif; ?>
            <?php if (!$s['enabled']): ?><div class="notice notice-warning"><p>Модуль SEO сейчас <b>выключен</b> (главный переключатель во вкладке «Общие»).</p></div><?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tk => $tab): ?>
                    <a class="nav-tab<?php echo $tk === $active ? ' nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg(['page' => 'csc_seo_admin', 'tab' => $tk])); ?>"><?php echo $tab['title']; /* внутренние метки схемы */ ?></a>
                <?php endforeach; ?>
            </h2>

            <form method="post">
                <?php wp_nonce_field('csc_seo_save_action'); ?>
                <input type="hidden" name="tab_anchor" value="<?php echo esc_attr($active); ?>">
                <?php $tab = $tabs[$active]; ?>
                <p class="description"><?php echo $tab['desc']; ?></p>
                <?php foreach ($tab['sections'] as $sec_title => $fields): ?>
                    <?php if ($sec_title !== ''): ?><h2><?php echo esc_html($sec_title); ?></h2><?php endif; ?>
                    <table class="form-table" role="presentation">
                        <?php foreach ($fields as $f):
                            if (($f['t'] ?? '') === 'token_help'): ?>
                                <tr><th scope="row">Токены шаблонов</th><td>
                                    <code>%sitename%</code> · <code>%tagline%</code> · <code>%cities%</code> · <code>%price_from%</code> · <code>%year%</code> · <code>%title%</code> (для title_template)<br>
                                    <span class="description">Текущие значения: cities = <b><?php echo esc_html(implode(', ', csc_seo_cities_list()) ?: '—'); ?></b>, price_from = <b><?php echo esc_html(csc_seo_price_from() ?: '—'); ?></b></span>
                                </td></tr>
                            <?php elseif (($f['t'] ?? '') === 'compat_detected'):
                                $det = csc_seo_external_seo_active(); ?>
                                <tr><th scope="row">Обнаруженные SEO-плагины</th><td>
                                    <?php echo $det ? esc_html(implode(', ', $det)) : '— нет —'; ?>
                                    <p class="description">В режиме «Авто» модуль уступает, если конкурент заполнил title/description конкретной страницы.</p>
                                </td></tr>
                            <?php else:
                                csc_seo_render_field($f, $s[$f['k']] ?? null);
                            endif;
                        endforeach; ?>
                    </table>
                <?php endforeach; ?>
                <?php submit_button('Сохранить SEO-настройки', 'primary', 'save_seo'); ?>
            </form>

            <hr>
            <h2>Чек-лист проверки после сохранения</h2>
            <ol>
                <li><a href="<?php echo esc_url(home_url('/' . trim((string)$s['sitemap_path'], '/'))); ?>" target="_blank">/<?php echo esc_html(trim((string)$s['sitemap_path'], '/')); ?></a> отдаёт XML (если 404 — «Настройки → Постоянные ссылки → Сохранить»).</li>
                <li><a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank">/robots.txt</a> содержит строку <code>Sitemap:</code>.</li>
                <li>На странице калькулятора в <code>&lt;head&gt;</code>: description, og:*, JSON-LD; в теле — блок <code>#csc-catalog-ssr</code> со всеми услугами.</li>
                <li>Проверка rich-результатов: <a href="https://search.google.com/test/rich-results" target="_blank">Rich Results Test</a> / <a href="https://validator.schema.org/" target="_blank">validator.schema.org</a>.</li>
            </ol>
        </div>
        <script>
        jQuery(function($){
            var tpl = $('<tr></tr>').html(<?php echo wp_json_encode(str_replace('__IDX__', '__IDX__', (function(){ ob_start(); csc_seo_redirect_row('','',301); return ob_get_clean(); })())); ?>);
            function reindex(){ $('#csc-seo-redirects tbody tr').each(function(i){ $(this).find('[name*="[from]"],[name*="[to]"],[name*="[code]"]').each(function(){ this.name = this.name.replace(/\[\d+\]/, '['+i+']'); }); }); }
            $('#csc-seo-redirect-add').on('click', function(){
                var i = $('#csc-seo-redirects tbody tr').length;
                $('#csc-seo-redirects tbody').append(tpl.html().replace(/__IDX__/g, i));
            });
            $('#csc-seo-redirects').on('click', '.csc-seo-redirect-del', function(){ $(this).closest('tr').remove(); reindex(); });
        });
        </script>
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
        // 1) Миграция настроек при обновлении плагина: дополняем сохранённый набор
        //    новыми ключами схемы, не затирая пользовательские значения.
        $ver = get_option('csc_seo_settings_ver', '0');
        if (defined('CSC_PLUGIN_VERSION') && $ver !== CSC_PLUGIN_VERSION) {
            $stored = get_option('csc_seo_settings', []);
            if (!is_array($stored)) $stored = [];
            update_option('csc_seo_settings', array_merge(csc_seo_get_defaults(), $stored), false);
            update_option('csc_seo_settings_ver', CSC_PLUGIN_VERSION, false);
            delete_transient('csc_seo_sitemap_xml');
        }
        // 2) Flush rewrite rules при смене версии или пути sitemap.
        $path_hash = md5(trim((string)(csc_seo_get_settings()['sitemap_path'] ?? '')));
        if (get_option('csc_seo_rules_ver') !== CSC_PLUGIN_VERSION . '|' . $path_hash) {
            csc_seo_rewrite_rules();
            flush_rewrite_rules(false);
            update_option('csc_seo_rules_ver', CSC_PLUGIN_VERSION . '|' . $path_hash, false);
        }
    }
}

// Очистка при деактивации (soft): снимаем robots-директивы нельзя (это фильтр),
// но удаляем правило переписывания из БД при следующем flush.
register_deactivation_hook(__FILE__, function () {
    delete_option('csc_seo_rules_ver');
});
