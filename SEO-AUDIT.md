# 🔎 Промышленный SEO-аудит и план оптимизации — Custom Service Calculator (v9.2 → v9.3)

Дата аудита: 01.11.2026. Аудит проведён по всему коду плагина (38 710 строк PHP, `includes/*.php`, шорткоды, AJAX, админка).

---

## 1. ДЕТАЛИЗИРОВАННЫЙ АУДИТ: все выявленные места SEO-оптимизации

### 1.1. Критические находки (блокируют индексацию контента)

| № | Находка | Где в коде | Риск | Закрыто в v9.3 |
|---|---------|-----------|------|----------------|
| C1 | **Весь каталог услуг грузится AJAX-ом** (`csc_get_form_data`) и рендерится JS-ом. Краулер без исполнения JS видит пустую страницу: ни названий услуг, ни цен. | `includes/csc-calculator-shortcode.php:1926–1975`, `includes/csc-ajax-handlers.php:14` | Фатальный: главная коммерческая страница неиндексируема | ✅ SSR-блок `#csc-catalog-ssr` (`the_content`, приоритет 20) + `<noscript>` |
| C2 | **Нет meta description / OG / Twitter Card** ни для одной страницы плагина. | во всём плагине отсутствовало | Сниппеты Google «съедает» тема; нулевой контроль шаринга | ✅ `csc_seo_meta_tags`, `csc_seo_open_graph` (wp_head, пр. 4/6) |
| C3 | **Нет микроразметки Schema.org** (LocalBusiness, Service, Offer, FAQ, Breadcrumbs). Цены и отзывы — сильнейшие rich-результаты для локального сервиса. | отсутствовало | Потеря расширенных сниппетов, price/rating markup | ✅ JSON-LD `@graph`: LocalBusiness + ServiceCatalog(Service/Offer) + BreadcrumbList + FAQPage |
| C4 | **Нет XML-sitemap для сущностей плагина**: разделы, услуги, города не представлены ни в одной карте сайта (wp_sitemap постов их не знает — они лежат в `wp_options`). | `csc_service_sections`/`csc_service_items`/`csc_cities` | Разделы/города не обнаруживаются поисковиками | ✅ `/csc-sitemap.xml` (rewrite rule + query var + transient-кэш поиска страниц) |
| C5 | **Служебные экраны доступны краулеру**: `?csc_master_dashboard=1&mid=…&token=…`, `?csc_upsell=…&token=…` — HTML-страницы на template_redirect. При случайной индексации — утечка структуры и тонкие дубли. | `csc-master-dashboard.php:54`, `csc-order-upsell.php:460` | Средний | ✅ robots.txt Disallow + `X-Robots-Tag: noindex, nofollow` (send_headers) |

### 1.2. Высокий приоритет (CTR и релевантность)

| № | Находка | Место | Решение v9.3 |
|---|---------|-------|--------------|
| H1 | Поле **`seo_desc` уже предусмотрено форматом данных услуг** (CSV-импорт: колонка 4, `csc-import-export.php:254,300`; даже функция починки кодировки его лечит), но **ни форма админа, ни AJAX, ни фронтент его не используют** — «мёртвое» SEO-поле. | `csc-admin-pages.php:835–897` (разделы сохраняют только `name`), `csc-ajax-handlers.php:21–29` | ✅ Колонка «SEO-описание» в админках «Разделы (CSC)»/«Услуги (CSC)», хранение в `csc_seo_desc_map`, слияние через фильтры `csc_get_sections_result`/`csc_get_items_result`, выдача в AJAX, `title=`-подсказки карточек, usage в JSON-LD и SSR |
| H2 | **Title страницы = заголовок WP-страницы** («Калькулятор») — ноль ключевых слов, нет города, нет цены. | тема/WP core | ✅ Шаблоны title/description с токенами `%sitename%`, `%cities%`, `%price_from%` («от 990 ₽» считается из каталога автоматически) |
| H3 | **Canonical отсутствует**; URL с параметрами (`?csc_city=…`, push-параметр `?csc_phone=…`, автологин ЛК) плодят дубли. | cabinet/push flow | ✅ Чистый canonical permalink + фильтр параметров |
| H4 | **Перелинковки нет**: категории и города не связаны ссылками — PageRank не циркулирует, посадочных под «ремонт смесителя Москва» нет. | фронт | ✅ Footer-nav «Популярные категории» (#section-…) + «Мы работаем в городах» (?csc_city=…), обе группы — в sitemap |
| H5 | **Блокирующий `<script src=html2canvas>` в начале шорткода** (~190 KB) ухудшает LCP/TBT — прямой фактор Core Web Vitals (mobile-first indexing). | `csc-calculator-shortcode.php:32` | ✅ `defer` через `script_loader_tag` |
| H6 | Плитки разделов — `<div>` без `role`/`tabindex`/`title`; имена услуг в `<div>`, а не в семантических элементах; поиск-плейсхолдер не связан с полем. | рендер form | ✅ `role="button" tabindex="0" title="{seo_desc}"` для плиток, `title` на карточках услуг |

### 1.3. Средний приоритет (гигиена)

| № | Находка | Место | Статус |
|---|---------|-------|--------|
| M1 | Изображения работ мастеров без guaranteed `alt` | attachment-вывод | ✅ `wp_get_attachment_image_attributes` fallback (meta → post_title) |
| M2 | PWA-иконки есть, но `manifest.json` не содержит описания для search-панели; apple-touch-icon задан | `csc_pwa_head` | ⚠️ рекомендовано: добавить `short_name`/`description` в manifest (не трогали, чтобы не ломать кэш-installed приложения) |
| M3 | readme.txt: теги «calculator, booking…» слабые для wp.org-поиска | `readme.txt:4` | ✅ обновлён блок Tags + добавлен SEO-раздел в описание |
| M4 | Нет точки расширения для сторонних SEO-плагинов при конфликтах description | — | ✅ Все функции модуля покрыты `function_exists`, выключатели по каждому подмодулю |
| M5 | Внутренний поиск по сайту (input-плейсхолдер «Найти услугу (например: кран, засор)») не использует синонимы seo_desc | JS-фильтр | ⚠️ план v9.4: матчинг поиска по `seo_desc` + synonym-map |

### 1.4. Что уже было хорошо (сохранено)
- ЧПУ-структура страниц WP (permalink'и тем); `has_shortcode` совместимость.
- Один статический кэш чтения каталога за запрос (`csc_get_sections/items`) — SEO-слияние добавлено внутрь кэша, лишних запросов к БД нет.
- HTTPS-only service worker, корректный `esc_*` вывод (нет XSS-рисков в новых meta-полях — всё экранировано).

---

## 2. ПРИОРИТЕТИЗИРОВАННЫЙ СПИСОК РАЗДЕЛОВ И УСЛУГ (ключевые слова)

### 2.1. Топ-разделы (посадочные якоря `#section-{id}`, ссылки в sitemap и footer-nav)

| Приоритет | Раздел | Коммерческие ключи (высокочастотные) | Гео-хвосты |
|-----------|--------|--------------------------------------|------------|
| 1 | Сантехника | ремонт сантехники, вызов сантехника, устранить засор, замена смесителя, установка унитаза, подключение стиральной машины | + «Москва / СПб / Казань / Новосибирск / Екатеринбург» |
| 2 | Электрика | вызов электрика, ремонт розеток, замена проводки, установка люстры, монтаж автоматов, перенос розетки | то же |
| 3 | Мелкий бытовой ремонт («муж/жена на час») | муж на час, мелкий ремонт квартиры, сборка мебели, повесить карниз, установка полотенцесушителя | то же |
| 4 | Бытовая техника | ремонт стиральных машин, ремонт холодильников, замена ТЭН, ремонт посудомоечной машины | то же |
| 5 | Плотницкие/мебельные | ремонт мебели, реставрация, регулировка дверей, замена замков | то же |
| 6 | Отделка/мелкие работы | поклейка обоев, укладка плитки, затирка швов, грунтование | то же |

### 2.2. Структура ключей для `seo_desc` каждой услуги (шаблон заполнения в админке)

```
{Действие} {объект}: {что входит}, цена от {N} ₽, выезд сегодня. Гарантия {X} мес.
Пример: «Замена смесителя на кухне: демонтаж, установка, опрессовка — от 1 200 ₽, выезд в день обращения.»
```

Плейсхолдеры для импорта CSV (колонка `seo_desc` уже читается импортером):
- ВЧ: `ремонт X`, `замена X`, `установка X`
- СКВ: `сколько стоит X`, `X цена`, `X стоимость`
- НЧ/доверие: `X отзывы`, `X гарантия`, `X вызвать мастер`
- Брендовые конкурентов исключены (юридический риск).

### 2.3. Ключевые слова служебных страниц
- **Калькулятор**: «расчёт стоимости ремонта онлайн», «калькулятор услуг мастера», «услуги с фиксированными ценами».
- **ЛК клиента**: «личный кабинет заказчика», «статус заказа ремонт» (низкая частота, но retention-сигнал; page — `noindex,follow` при желании переключателем).
- **Стать мастером**: «подработка мастером», «работа сантехником сдельная», «мастер по ремонту вакансии» + FAQPage-разметка (добавлена).

---

## 3. ЧТО РЕАЛИЗОВАНО В КОДЕ (v9.3)

Новый модуль **`includes/csc-seo.php`** (~700 строк, подключён в ядре после core-functions; опции чистятся в `uninstall.php`):

1. **Meta title/description** для 3 типов страниц (персинт через `has_shortcode`), шаблоны с токенами, авто-подстановка минимальной цены «от … ₽» и списка активных городов.
2. **Open Graph + Twitter Card** (og:type/site_name/url/locale/image, twitter:card summary_large_image).
3. **JSON-LD** (`@graph`): `HomeAndConstructionBusiness|LocalBusiness` (+telephone/email/areaServed из городов CSC), `ServiceCatalog` с массивом `Service→Offer(price, RUB, InStock)`, `BreadcrumbList`, `FAQPage` на «Стать мастером».
4. **XML-Sitemap** `/csc-sitemap.xml`: страницы шорткодов (приоритет 1.0/0.7), якоря разделов (0.8), городские фильтры `?csc_city=` (0.9); lastmod/changefreq; сброс transient-кэша при изменении каталога/городов; авто-flush rewrite rules по версии плагина.
5. **robots.txt**: директива `Sitemap:` + `Disallow` для dashboard/upsell/token-параметров; `X-Robots-Tag` на этих экранах.
6. **SSR-каталог услуг** внутри страницы калькулятора (H2 «Наши услуги и цены», H3 по разделам с id-якорями, цены/длительности по каждой услуге, `<noscript>`-стили) — контент индексируется без JS.
7. **Перелинковка** footer-nav: категории (якоря) + города (параметризованные landing-URL).
8. **CWV**: defer html2canvas; alt-fallback изображений.
9. **Админка «🔎 SEO (CSC)»** (Настройки → SEO (CSC)): глобальные шаблоны, тексты по страницам, LocalBusiness-карточка, переключатели каждого подмодуля, чек-лист проверки, ссылки на sitemap/robots.
10. **Поля seo_desc в «Разделы (CSC)» и «Услуги (CSC)»** (JS-инъекция колонок в существующие формы + независимое хранилище `csc_seo_desc_map` → слияние на чтении; AJAX `csc_get_form_data` отдаёт `seo_desc`; JS-карточки показывают его в `title`).

Минимальные правки ядра: `csc-core-functions.php` (+2 `apply_filters`), `csc-ajax-handlers.php` (+1 поле), `csc-calculator-shortcode.php` (title/role в разметке), `uninstall.php` (3 опции), константа версии.

---

## 4. ПРИМЕРЫ (реальный вывод модуля)

### 4.1. `<head>` страницы калькулятора (после активации)
```html
<!-- CSC SEO -->
<title>Расчёт стоимости услуг и вызов мастера — онлайн-калькулятор — РемонтПро</title>
<meta name="description" content="Рассчитайте стоимость услуги за 30 секунд: выберите раздел, услуги, укажите адрес и время. Прозрачные цены, от 990 ₽. Работаем в Москве, Санкт-Петербурге, Казани. Гарантия на все работы.">
<link rel="canonical" href="https://example.ru/kalkulyator/">
<meta property="og:type" content="website">
<meta property="og:title" content="Расчёт стоимости услуг и вызов мастера — онлайн-калькулятор — РемонтПро">
<meta property="og:description" content="Прозрачные цены, от 990 ₽. Работаем в Москве, Санкт-Петербурге, Казани.">
<meta property="og:url" content="https://example.ru/kalkulyator/">
<meta property="og:image" content="https://example.ru/wp-content/uploads/og-cover.jpg">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json">
{"@context":"https://schema.org","@graph":[
 {"@type":"HomeAndConstructionBusiness","name":"РемонтПро","url":"https://example.ru/",
  "telephone":"+7 900 000-00-00",
  "areaServed":[{"@type":"City","name":"Москва"},{"@type":"City","name":"Санкт-Петербург"}]},
 {"@type":"ServiceCatalog","name":"Каталог услуг — РемонтПро","hasOffer":[
   {"@type":"Service","name":"Замена смесителя","serviceType":"Сантехника",
    "description":"Замена смесителя на кухне: демонтаж, установка, опрессовка — от 1 200 ₽",
    "offers":{"@type":"Offer","price":1200,"priceCurrency":"RUB","availability":"https://schema.org/InStock"}}]},
 {"@type":"BreadcrumbList","itemListElement":[…]}
]}</script>
```

### 4.2. Фрагмент `/csc-sitemap.xml`
```xml
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.ru/kalkulyator/</loc><lastmod>2026-11-01</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>
  <url><loc>https://example.ru/kalkulyator/#section-sec_65f1a</loc><priority>0.8</priority></url>
  <url><loc>https://example.ru/kalkulyator/?csc_city=moskva</loc><priority>0.9</priority></url>
  <url><loc>https://example.ru/stat-masterom/</loc><priority>0.7</priority></url>
</urlset>
```

### 4.3. `robots.txt` (дополнение модуля)
```
Sitemap: https://example.ru/csc-sitemap.xml
Disallow: /*?csc_master_dashboard=1
Disallow: /*?csc_upsell=1
Disallow: /*&csc_token=
```

### 4.4. SSR-фрагмент каталога (виден исходном HTML без JS)
```html
<div id="csc-catalog-ssr">
  <h2>Наши услуги и цены</h2>
  <ul class="csc-ssr-list">
    <li><h3 id="section-sec_65f1a">Сантехника</h3>
        <p>Аварийный выезд 24/7, фиксированные цены, гарантия 6 месяцев.</p>
        <ul><li id="item_it_01">Замена смесителя — 1 200 ₽, 45 мин</li>…</ul></li>
  </ul>
</div>
```

---

## 5. ДОРОЖНАЯ КАРТА (промышленное развитие)

**Фаза 1 — сделана (v9.3):** индексability каталога, meta/OG/JSON-LD, sitemap, robots, SEO-поля каталога, перелинковка, defer.

**Фаза 2 (v9.4, 2–4 недели):**
- Городские **landing-страницы** как реальные WP-страницы (`[service_calculator city="kazan"]` + автогенерация уникальных H1/text по шаблону) вместо query-параметров; hreflang не нужен (RU-домен), но Geo Feed Яндекса (Yandex Maps XML) — да.
- Обогащение внутреннего поиска: матчинг по `seo_desc` + словарь синонимов («кран⇄смеситель», «засор⇄канализация»).
- AggregateRating в JSON-LD из существующего модуля рейтингов мастеров (`csc-master-ratings.php`) + `review` items из `csc-reviews.php` (AggregateRating требует реальных отзывов на странице — выводить блок «Отзывы клиентов»).
- Preconnect к Nominatim/REST только на нужных страницах; раздельный кэш inline-CSS шорткода (сейчас ~1500 строк CSS инлайнятся на каждой странице — candidate для external minified file с version-hash).

**Фаза 3 (v9.5+):**
- Интеграция-фолбэк с Yoast/RankMath (наши фильтры уходят в deferral при обнаружении `WPSEO_VERSION`).
- Image SEO: lazy-loading + width/height для фото работ мастеров (CLS).
- Автогенерация OG-обложек раздела (GDPR-safe, без внешних сервисов).
- Search Console API-пульс: импорт позиционных данных по разделам в «📊 Аналитику (CSC)».
- ПереходParameter `?csc_city` → ЧПУ `/uslugi-v-kazani/` через taxonomy-мост.

## 6. КАК ПРОВЕРИТЬ ПОСЛЕ УСТАНОВКИ
1. Активировать плагин → «Настройки → SEO (CSC)» → сохранить (flush rewrite rules).
2. Открыть `/csc-sitemap.xml` и `/robots.txt`.
3. Страница калькулятора: View Source → убедиться в description/JSON-LD/`#csc-catalog-ssr`.
4. validator.schema.org + Rich Results Test; Search Console → проверить URL → «Запросить индексацию»; отправить sitemap.
5. Зарегистрировать бизнес в Яндекс.Бизнес / Google Business Profile и связать с LocalBusiness-разметкой.
