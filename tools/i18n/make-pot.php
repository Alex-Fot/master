<?php
/**
 * CSC i18n extractor: сканирует PHP-файлы плагина через token_get_all()
 * и перегенерирует languages/csc.pot (строки текстового домена 'csc').
 *
 * Поддерживаемые функции: __(), _e(), _x(), esc_html__(), esc_html_e(),
 * esc_attr__(), esc_attr_e(), _n(), _nx().
 *
 * Запуск:  php tools/i18n/make-pot.php [путь_к_корню_плагина]
 */

$root = realpath($argv[1] ?? dirname(__DIR__, 2));
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Root not found\n");
    exit(1);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $path = str_replace('\\', '/', $f->getPathname());
        if (strpos($path, '/languages/') !== false) continue;   // языковые каталоги
        if (strpos($path, '/tools/i18n/') !== false) continue;  // сам экстрактор
        $files[] = $f->getPathname();
    }
}
sort($files);

$single   = [];  // msgid => true
$plurals  = [];  // msgid => msgid_plural
$contexts = [];  // msgid => context

$I18N_FUNCS = [
    '__' => 'single', '_e' => 'single', 'esc_html__' => 'single', 'esc_html_e' => 'single',
    'esc_attr__' => 'single', 'esc_attr_e' => 'single',
    '_x' => 'context', '_ex' => 'context',
    '_n' => 'plural', '_nx' => 'plural_context',
];

/**
 * Разбирает аргументы вызова i18n-функции из потока токенов.
 * Аргументы возвращаются как: строка (литерал) | null (выражение с переменными).
 */
function csc_parse_args(array $tokens, int &$i): array
{
    $args = [];
    $cur = '';
    $cur_pure = true; // только литералы без переменных
    $cur_has_lit = false; // в текущем аргументе был хотя бы один строковый литерал
    $depth = 0;
    $n = count($tokens);

    $flush = function () use (&$cur, &$cur_pure, &$cur_has_lit, &$args) {
        $val = trim($cur);
        if ($val === '' || !$cur_has_lit) {
            $args[] = null; // пусто или выражение без литерала (переменные и т.п.)
        } elseif ($cur_pure) {
            $args[] = $val; // чистый литерал (в т.ч. склеенный конкатенацией, с %s и знаками препинания)
        } else {
            $args[] = null; // смешанное выражение — не извлекаем
        }
        $cur = '';
        $cur_pure = true;
        $cur_has_lit = false;
    };

    // Отладка: CSC_POT_DEBUG=<подстрока msgid> — печатает разобранные аргументы вызовов,
    // содержащих эту подстроку (для диагностики пропущенных строк).
    $dbg = getenv('CSC_POT_DEBUG');

    // Вызывающий код передал позицию сразу за открывающей скобкой '('.
    for (; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $str = substr($t[1], 1, -1);
                if ($t[1][0] === "'") {
                    $str = str_replace(["\\'", "\\\\"], ["'", "\\"], $str);
                } else {
                    $str = stripcslashes($str);
                }
                $cur .= $str;
                $cur_has_lit = true;
            } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $cur .= $t[1];
            } else {
                $cur_pure = false;
                if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $cur .= $t[1];
                }
            }
        } else {
            if ($t === '(') { $depth++; $cur .= $t; $cur_pure = false; }
            elseif ($t === ')') {
                if ($depth === 0) { $flush(); break; }
                $depth--;
                $cur .= $t;
            } elseif ($t === '[' || $t === ']') {
                // скобки массивов внутри аргумента (например, ['message' => __('...','csc')]) —
                // не прерывают разбор литерала и НЕ помечают его «нечистым»
                $cur .= $t;
            } elseif ($t === '{' || $t === '}') {
                $cur .= $t;
                $cur_pure = false;
            } elseif ($t === ',' && $depth === 0) {
                $flush();
            } else {
                if ($t === '.') {
                    // конкатенация: склеивает части литерала; переменные уже пометили cur_pure=false
                    continue;
                }
                if ($t === '"' || $t === "'" || $t === '`') {
                    // маркеры строк/наследия от heredoc-разборов — игнорируем как шум
                    continue;
                }
                $cur .= $t;
                $cur_pure = false;
            }
        }
    }
    if ($dbg !== false && $dbg !== '' && strpos(json_encode($args, JSON_UNESCAPED_UNICODE), $dbg) !== false) {
        fwrite(STDERR, "DEBUG parse_args at start: " . json_encode($args, JSON_UNESCAPED_UNICODE) . "\n");
    }
    return $args;
}

foreach ($files as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) continue;
        $fname = $t[1];
        if (!isset($I18N_FUNCS[$fname])) continue;

        // пропуск методов ->__() / ::__()
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k--;
        if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) continue;

        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
        if ($j >= $n || $tokens[$j] !== '(') continue;

        $i = $j + 1;
        $args = csc_parse_args($tokens, $i);
        $kind = $I18N_FUNCS[$fname];

        // определение домена
        if ($kind === 'plural')             $domain = $args[3] ?? null;
        elseif ($kind === 'plural_context') $domain = $args[4] ?? null;
        elseif ($kind === 'context')        $domain = $args[2] ?? null;
        else                                $domain = $args[1] ?? null;
        if ($domain !== 'csc') continue;

        $msgid  = $args[0] ?? null;
        $plural = $args[1] ?? null;
        $ctx    = ($kind === 'context') ? ($args[1] ?? null) : (($kind === 'plural_context') ? ($args[3] ?? null) : null);

        if (!is_string($msgid) || $msgid === '' || !preg_match('/\p{L}/u', $msgid)) continue;

        if ($kind === 'plural' || $kind === 'plural_context') {
            if (is_string($plural) && $plural !== '') {
                $single[$msgid] = true;
                $plurals[$msgid] = $plural;
            }
        } else {
            $single[$msgid] = true;
        }
        if (is_string($ctx) && $ctx !== '') $contexts[$msgid] = $ctx;
    }
}

ksort($single, SORT_NATURAL | SORT_FLAG_CASE);

$out  = "# Copyright (C) 2026 Custom Service Calculator\n";
$out .= "# This file is distributed under the GPLv2 or GPLv3.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Custom Service Calculator 9.0\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/custom-service-calculator\\n\"\n";
$out .= "\"POT-Creation-Date: " . gmdate('Y-m-d\TH:i:s+00:00') . "\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"X-Generator: CSC i18n extractor (token_get_all)\\n\"\n";
$out .= "\"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\\n\"\n";
$out .= "\"X-Domain: csc\\n\"\n\n";

function csc_po_escape(string $s): string {
    return str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\\"', '\\n', '\\t'], $s);
}

$count_single = 0; $count_plural = 0;
foreach (array_keys($single) as $msgid) {
    $e = csc_po_escape($msgid);
    if (isset($contexts[$msgid])) {
        $out .= "msgctxt \"" . csc_po_escape($contexts[$msgid]) . "\"\n";
    }
    if (isset($plurals[$msgid])) {
        $out .= "msgid \"$e\"\n";
        $out .= "msgid_plural \"" . csc_po_escape($plurals[$msgid]) . "\"\n";
        $out .= "msgstr[0] \"\"\nmsgstr[1] \"\"\nmsgstr[2] \"\"\n\n";
        $count_plural++;
    } else {
        $out .= "msgid \"$e\"\nmsgstr \"\"\n\n";
        $count_single++;
    }
}

$pot_path = $root . '/languages/csc.pot';
if (!is_dir(dirname($pot_path))) mkdir(dirname($pot_path), 0755, true);
file_put_contents($pot_path, $out);
echo "Wrote $pot_path: $count_single singular, $count_plural plural entries (" . count($single) . " unique msgids)\n";
