<?php
/**
 * Файл: csc-import-export.php
 * Описание: Импорт услуг из CSV и экспорт всех услуг в CSV.
 * Версия: 3.4 (автоконвертация Windows-1251 → UTF-8, лечение уже импортированных услуг)
 *
 * ИСПРАВЛЕНО в 3.4:
 * - ПРИЧИНА БАГА: Excel сохраняет CSV в кодировке ANSI (Windows-1251).
 *   Добавлена автоконвертация CP1251 → UTF-8 для всех полей при импорте.
 * - Добавлена кнопка «Исправить кодировку импортированных услуг» —
 *   лечит уже сохранённые записи с именами в CP1251.
 * - sanitize_text_field применяется ПОСЛЕ конвертации кодировки.
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// 0. УТИЛИТА: ПРИВЕДЕНИЕ СТРОКИ К UTF-8
// ============================================================

if (!function_exists('csc_to_utf8')) {
    function csc_to_utf8($str) {
        if (!is_string($str) || $str === '') return $str;
        // Убираем BOM (UTF-8 и CP1251-варианты)
        $str = preg_replace('/^\xEF\xBB\xBF/', '', $str);
        $str = preg_replace('/^\xFF\xFE/', '', $str);
        // Уже валидный UTF-8 — не трогаем
        if (mb_check_encoding($str, 'UTF-8')) return $str;
        // Конвертируем из Windows-1251
        if (function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($str, 'UTF-8', 'Windows-1251');
            if ($conv !== false && $conv !== null && mb_check_encoding($conv, 'UTF-8')) return $conv;
        }
        if (function_exists('iconv')) {
            $conv = @iconv('Windows-1251', 'UTF-8//IGNORE', $str);
            if ($conv !== false) return $conv;
        }
        return $str;
    }
}

// ============================================================
// 1. СТРАНИЦА ИМПОРТА / ЭКСПОРТА
// ============================================================

if (!function_exists('csc_import_export_page')) {
    function csc_import_export_page() {
        // Обработка Импорта
        if ($_POST && isset($_POST['csc_import_csv']) && wp_verify_nonce($_POST['_wpnonce'], 'csc_import_action')) {
            csc_handle_import();
        }
        // Обработка лечения кодировки
        if ($_POST && isset($_POST['csc_fix_encoding']) && wp_verify_nonce($_POST['_wpnonce'], 'csc_fix_encoding_action')) {
            csc_fix_items_encoding();
        }

        $sections = csc_get_sections();
        ?>
        <div class="wrap">
            <h1>Импорт и Экспорт (Excel / CSV)</h1>
            <div style="display:flex; gap: 20px; flex-wrap:wrap;">

                <div style="flex:1; min-width:300px; background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <h2>📥 Импорт услуг</h2>
                    <p class="description">
                        Загрузите CSV файл (формат Excel).<br>
                        Поддерживаются кодировки UTF-8 и Windows-1251 (ANSI) — определяются автоматически.<br>
                        <strong>Структура столбцов:</strong><br>
                        1. Наименование услуги<br>
                        2. Стоимость (число)<br>
                        3. Время в минутах (если пусто — будет 0)
                    </p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('csc_import_action'); ?>
                        <table class="form-table">
                            <tr>
                                <th>Куда загрузить:</th>
                                <td>
                                    <select name="target_section_id" required style="width:100%">
                                        <option value="">-- Выберите раздел --</option>
                                        <?php foreach ($sections as $id => $sec): ?>
                                            <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($sec['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Файл (CSV):</th>
                                <td><input type="file" name="import_file" accept=".csv" required></td>
                            </tr>
                            <tr>
                                <th>Настройки:</th>
                                <td>
                                    <label><input type="checkbox" name="skip_header" checked> Пропустить первую строку (заголовки)</label><br>
                                    <label><input type="checkbox" name="debug_mode"> Режим отладки (показать детали)</label>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('Импортировать', 'primary', 'csc_import_csv'); ?>
                    </form>
                </div>

                <div style="flex:1; min-width:300px; background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <h2>📤 Экспорт всех услуг</h2>
                    <p>Скачать полный список всех услуг и разделов в формате CSV (для Excel).</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="csc_export_csv">
                        <?php wp_nonce_field('csc_export_action'); ?>
                        <?php submit_button('Скачать таблицу (.csv)', 'secondary'); ?>
                    </form>

                    <h2>🔧 Лечение кодировки</h2>
                    <p class="description">
                        Если услуги импортировались пустыми/невидимыми (файл был в ANSI/Windows-1251),
                        нажмите кнопку — имена существующих услуг будут сконвертированы в UTF-8 без переимпорта.
                    </p>
                    <form method="post">
                        <?php wp_nonce_field('csc_fix_encoding_action'); ?>
                        <?php submit_button('Исправить кодировку импортированных услуг', 'secondary', 'csc_fix_encoding'); ?>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }
}

// ============================================================
// 2. ЛОГИКА ИМПОРТА
// ============================================================

if (!function_exists('csc_handle_import')) {
    function csc_handle_import() {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Доступ запрещен.</p></div>';
            return;
        }
        if (!check_admin_referer('csc_import_action')) {
            echo '<div class="notice notice-error"><p>Ошибка безопасности (nonce).</p></div>';
            return;
        }
        if (empty($_FILES['import_file']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>Файл не выбран.</p></div>';
            return;
        }
        if (!is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>Ошибка безопасности: файл не был загружен через форму.</p></div>';
            return;
        }
        if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>Ошибка загрузки файла. Код ошибки: ' . intval($_FILES['import_file']['error']) . '</p></div>';
            return;
        }

        $file_check = wp_check_filetype_and_ext(
            $_FILES['import_file']['tmp_name'],
            $_FILES['import_file']['name'],
            ['csv' => 'text/csv', 'txt' => 'text/plain']
        );
        if (empty($file_check['ext']) || !in_array($file_check['ext'], ['csv', 'txt'])) {
            echo '<div class="notice notice-error"><p>Неверный формат файла. Ожидается .csv</p></div>';
            return;
        }

        $section_id = sanitize_text_field($_POST['target_section_id']);
        $skip_header = !empty($_POST['skip_header']);
        $debug_mode = !empty($_POST['debug_mode']);
        $sections = csc_get_sections();

        if (!isset($sections[$section_id])) {
            echo '<div class="notice notice-error"><p>Ошибка: Раздел не найден.</p></div>';
            return;
        }

        $handle = fopen($_FILES['import_file']['tmp_name'], 'r');
        if ($handle === false) {
            echo '<div class="notice notice-error"><p>Не удалось открыть файл.</p></div>';
            return;
        }

        $items = csc_get_items();
        $imported_count = 0;
        $skipped_count = 0;
        $converted_count = 0;
        $row_index = 0;
        $delimiter = csc_detect_csv_delimiter($_FILES['import_file']['tmp_name']);

        $debug_log = [];
        if ($debug_mode) {
            $debug_log[] = "Определенный разделитель: " . json_encode($delimiter);
            $debug_log[] = "Пропуск заголовка: " . ($skip_header ? 'да' : 'нет');
        }

        while (($data = fgetcsv($handle, 0, $delimiter, '"', "\0")) !== false) {
            $row_index++;

            // ИСПРАВЛЕНО: конвертация всех полей в UTF-8 (CP1251 → UTF-8 при необходимости)
            $before = $data;
            $data = array_map('csc_to_utf8', $data);
            if ($debug_mode && $row_index <= 3 && $before != $data) {
                $converted_count++;
            }

            if ($skip_header && $row_index === 1) {
                if ($debug_mode) {
                    $debug_log[] = "Строка $row_index (заголовок): пропущена";
                }
                continue;
            }

            if ($debug_mode && $row_index <= 5) {
                $debug_log[] = "Строка $row_index: полей=" . count($data) . ", данные=" . json_encode($data, JSON_UNESCAPED_UNICODE);
            }

            // "Расшивка" бракованных строк CSV
            if (count($data) === 1 && !empty($data[0])) {
                $raw_line = trim($data[0], "\"'");
                if (strpos($raw_line, ';') !== false) {
                    $data = array_map('csc_to_utf8', str_getcsv($raw_line, ';', '"', "\0"));
                } elseif (strpos($raw_line, ',') !== false) {
                    $data = array_map('csc_to_utf8', str_getcsv($raw_line, ',', '"', "\0"));
                } elseif (strpos($raw_line, "\t") !== false) {
                    $data = array_map('csc_to_utf8', explode("\t", $raw_line));
                }
            }

            if (count($data) < 2) {
                $skipped_count++;
                continue;
            }

            // sanitize_text_field безопасен: строка уже валидный UTF-8
            $name = sanitize_text_field($data[0] ?? '');
            if ($name === '') {
                if ($debug_mode) {
                    $debug_log[] = "  → Пропущена: пустое наименование";
                }
                $skipped_count++;
                continue;
            }

            $price_raw = str_replace([',', ' '], ['.', ''], $data[1] ?? '0');
            $price = floatval($price_raw);

            $duration_raw = trim($data[2] ?? '');
            $duration = ($duration_raw === '') ? 0 : intval($duration_raw);

            $new_id = 'item_' . uniqid('', true) . '_' . wp_rand(100000, 999999) . '_' . $row_index;

            $items[$new_id] = [
                'section_id'   => $section_id,
                'name'         => $name,
                'seo_desc'     => '',
                'price'        => $price,
                'duration'     => $duration,
                'is_available' => true
            ];
            $imported_count++;

            if ($debug_mode && $row_index <= 5) {
                $debug_log[] = "  → Импортирована: $name, цена=$price, время=$duration";
            }
        }
        fclose($handle);

        csc_save_items($items);

        $message = "Успешно импортировано услуг: $imported_count";
        if ($skipped_count > 0) {
            $message .= ", пропущено: $skipped_count";
        }
        echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';

        if ($debug_mode && !empty($debug_log)) {
            echo '<div class="notice notice-info"><h3>Отладочная информация:</h3><pre style="max-height:400px;overflow:auto;">';
            foreach ($debug_log as $line) {
                echo esc_html($line) . "\n";
            }
            echo '</pre></div>';
        }
    }
}

// ============================================================
// 2.1. ЛЕЧЕНИЕ КОДИРОВКИ УЖЕ ИМПОРТИРОВАННЫХ УСЛУГ
// ============================================================

if (!function_exists('csc_fix_items_encoding')) {
    function csc_fix_items_encoding() {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Доступ запрещен.</p></div>';
            return;
        }
        $items = csc_get_items();
        $fixed = 0;
        foreach ($items as $id => $item) {
            if (!is_array($item)) continue;
            $changed = false;
            foreach (['name', 'seo_desc'] as $field) {
                if (isset($item[$field]) && is_string($item[$field]) && !mb_check_encoding($item[$field], 'UTF-8')) {
                    $items[$id][$field] = csc_to_utf8($item[$field]);
                    $changed = true;
                }
            }
            if ($changed) $fixed++;
        }
        if ($fixed > 0) {
            csc_save_items($items);
        }
        echo '<div class="notice notice-success"><p>Исправлено услуг с неверной кодировкой: ' . intval($fixed) . '</p></div>';
    }
}

// ============================================================
// 3. ОПРЕДЕЛЕНИЕ РАЗДЕЛИТЕЛЯ CSV
// ============================================================

if (!function_exists('csc_detect_csv_delimiter')) {
    function csc_detect_csv_delimiter($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) return ';';

        $first_line = fgets($handle, 4096);
        fclose($handle);

        if ($first_line === false) return ';';

        $tabs = substr_count($first_line, "\t");
        $semicolons = substr_count($first_line, ';');
        $commas = substr_count($first_line, ',');

        if ($tabs > $semicolons && $tabs > $commas) return "\t";
        if ($semicolons > $commas && $semicolons > $tabs) return ';';
        return ',';
    }
}

// ============================================================
// 4. ЛОГИКА ЭКСПОРТА (ADMIN-POST)
// ============================================================

add_action('admin_post_csc_export_csv', 'csc_export_csv_handler');

if (!function_exists('csc_export_csv_handler')) {
    function csc_export_csv_handler() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещен');
        }
        if (!check_admin_referer('csc_export_action')) {
            wp_die('Ошибка безопасности (nonce)');
        }

        $sections = csc_get_sections();
        $items = csc_get_items();
        $filename = 'csc-services-export-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['Раздел', 'Наименование услуги', 'Стоимость', 'Время (мин)', 'ID Раздела'], ';', '"', "\0");

        foreach ($items as $item) {
            if (!isset($item['section_id'])) continue;
            $sec_name = $sections[$item['section_id']]['name'] ?? 'Без раздела';
            fputcsv($output, [
                $sec_name,
                csc_to_utf8($item['name']),
                $item['price'],
                $item['duration'],
                $item['section_id']
            ], ';', '"', "\0");
        }

        fclose($output);
        exit;
    }
}