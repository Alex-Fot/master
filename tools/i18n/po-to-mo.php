<?php
/**
 * CSC: компилятор .po -> .mo (без зависимостей от gettext/msgfmt).
 *
 * Использование:
 *   php tools/i18n/po-to-mo.php languages/csc-ru_RU.po
 *   -> создаёт languages/csc-ru_RU.mo (рядом с исходником)
 *
 * Поддерживаются: msgid/msgstr, msgid_plural/msgstr[N], msgctx,
 * многострочные записи, escape-последовательности (\n, \t, \" , \\).
 * Выход — стандартный MO revision 0 (little-endian), совместимый с
 * WP_Scripts/Gettext (MOParser WordPress).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Only CLI usage.\n");
    exit(1);
}

$src = isset($argv[1]) ? $argv[1] : null;
if (!$src || !is_file($src)) {
    fwrite(STDERR, "Usage: php po-to-mo.php <file.po> [out.mo]\n");
    exit(1);
}
$out = isset($argv[2]) ? $argv[2] : preg_replace('/\.po$/i', '', $src) . '.mo';

// ---------- Парсер PO ----------
function csc_po_unescape($s) {
    return str_replace(
        ['\\n', '\\t', '\\r', '\\"', '\\\\'],
        ["\n", "\t", "\r", '"', '\\'],
        $s
    );
}

$entries   = []; // list of ['ctx'=>, 'id'=>, 'id2'=>, 'tr'=>[], 'fuzzy'=>bool]
$cur       = null;
$field     = null; // текущее поле для continuation-строк
$line_no   = 0;

function csc_flush_entry(&$entries, &$cur) {
    if ($cur !== null && $cur['id'] !== null) {
        $entries[] = $cur;
    }
    $cur = null;
}

foreach (file($src, FILE_IGNORE_NEW_LINES) as $line) {
    $line_no++;
    $t = trim($line);
    if ($t === '' ) { $field = null; continue; }
    if (strpos($t, '#') === 0) { continue; } // комментарии (fuzzy обрабатываем ниже отдельно)

    if (preg_match('/^msgctxt\s+"(.*)"$/', $t, $m)) {
        csc_flush_entry($entries, $cur);
        $cur = ['ctx' => csc_po_unescape($m[1]), 'id' => null, 'id2' => null, 'tr' => [], 'fuzzy' => false];
        $field = 'ctx';
    } elseif (preg_match('/^msgid_plural\s+"(.*)"$/', $t, $m)) {
        if ($cur === null) { throw new Exception("msgid_plural without msgid at line $line_no"); }
        $cur['id2'] = csc_po_unescape($m[1]);
        $field = 'id2';
    } elseif (preg_match('/^msgid\s+"(.*)"$/', $t, $m)) {
        csc_flush_entry($entries, $cur);
        $cur = ['ctx' => null, 'id' => csc_po_unescape($m[1]), 'id2' => null, 'tr' => [], 'fuzzy' => false];
        $field = 'id';
    } elseif (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $t, $m)) {
        if ($cur === null) { throw new Exception("msgstr[N] without msgid at line $line_no"); }
        $cur['tr'][intval($m[1])] = csc_po_unescape($m[2]);
        $field = 'tr' . intval($m[1]);
    } elseif (preg_match('/^msgstr\s+"(.*)"$/', $t, $m)) {
        if ($cur === null) { throw new Exception("msgstr without msgid at line $line_no"); }
        $cur['tr'][0] = csc_po_unescape($m[1]);
        $field = 'tr0';
    } elseif (preg_match('/^"(.*)"$/', $t, $m)) {
        // continuation предыдущего поля
        $piece = csc_po_unescape($m[1]);
        if ($field === 'id')         $cur['id'] .= $piece;
        elseif ($field === 'id2')    $cur['id2'] .= $piece;
        elseif ($field === 'ctx')    $cur['ctx'] .= $piece;
        elseif ($field === 'tr0')    $cur['tr'][0] .= $piece;
        elseif (strncmp($field, 'tr', 2) === 0) { $idx = intval(substr($field, 2)); $cur['tr'][$idx] .= $piece; }
    } else {
        fwrite(STDERR, "WARN: unparsed line $line_no: $t\n");
    }
}
csc_flush_entry($entries, $cur);

// ---------- Сборка MO ----------
$messages = []; // key => translation (key = ctx\x04id или id, плюралы: id\x00id2 => tr0\x00tr1...)
$header_done = false;
foreach ($entries as $e) {
    if ($e['id'] === '') {
        if ($header_done) continue; // дублирующийся заголовок пропускаем
        $header_done = true;
        // нормализуем Project-Id-Version домена
        $messages[''] = $e['tr'][0] ?? '';
        continue;
    }
    if (empty($e['tr'][0])) {
        continue; // пустой перевод — в MO не кладём (gettext вернёт оригинал)
    }
    $key = ($e['ctx'] !== null && $e['ctx'] !== '') ? ($e['ctx'] . "\x04" . $e['id']) : $e['id'];
    if ($e['id2'] !== null) {
        $key .= "\x00" . $e['id2'];
        ksort($e['tr']);
        $val = implode("\x00", array_values($e['tr']));
    } else {
        $val = $e['tr'][0];
    }
    $messages[$key] = $val;
}

ksort($messages, SORT_STRING);

$n = count($messages);
$offset_trans_table = 7 * 4 + $n * 8;          // таблица оригиналов сразу после заголовка
$offset_data        = $offset_trans_table + $n * 8; // затем таблица переводов, потом данные

$keys   = [];
$vals   = [];
$orig_off = [];
$trans_off = [];
$data_buf = '';
$pos = $offset_data;
foreach ($messages as $k => $v) {
    $keys[] = $k;
    $len = strlen($k);
    $orig_off[] = [$len, $pos];
    $data_buf .= $k . "\x00";
    $pos += $len + 1;
    $vals[] = $v;
    $len = strlen($v);
    $trans_off[] = [$len, $pos];
    $data_buf .= $v . "\x00";
    $pos += $len + 1;
}

$mo  = pack('V', 0x950412de);           // magic LE
$mo .= pack('V', 0);                    // revision
$mo .= pack('V', $n);                   // число записей
$mo .= pack('V', 7 * 4);                // смещение таблицы оригиналов (сразу после заголовка)
$mo .= pack('V', $offset_trans_table);  // смещение таблицы переводов
$mo .= pack('V', 0);                    // hash size (не используем)
$mo .= pack('V', 0);                    // hash offset

foreach ($orig_off as $o)  $mo .= pack('VV', $o[0], $o[1]);
foreach ($trans_off as $o) $mo .= pack('VV', $o[0], $o[1]);
$mo .= $data_buf;

if (file_put_contents($out, $mo) === false) {
    fwrite(STDERR, "Failed to write $out\n");
    exit(1);
}

fwrite(STDOUT, "OK: $out (" . filesize($out) . " bytes, $n entries)\n");

// ---------- Самопроверка: прочитаем обратно как MO ----------
$data = file_get_contents($out);
if (substr($data, 0, 4) !== "\xde\x12\x04\x95") {
    fwrite(STDERR, "Self-check failed: bad magic\n");
    exit(1);
}
$h = unpack('Vmagic/Vrev/Vn/VoOff/VtOff', substr($data, 0, 20));
for ($i = 0; $i < $h['n']; $i++) {
    $o = unpack('Vlen/Voff', substr($data, $h['oOff'] + $i * 8, 8));
    $t = unpack('Vlen/Voff', substr($data, $h['tOff'] + $i * 8, 8));
    $k = substr($data, $o['off'], $o['len']);
    $v = substr($data, $t['off'], $t['len']);
    if (!array_key_exists($k, $messages) || $messages[$k] !== $v) {
        fwrite(STDERR, "Self-check failed at entry $i (key=" . substr($k, 0, 40) . ")\n");
        exit(1);
    }
}
fwrite(STDOUT, "Self-check OK: MO parses back consistently.\n");
exit(0);
