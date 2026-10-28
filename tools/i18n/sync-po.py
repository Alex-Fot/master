#!/usr/bin/env python3
"""
Синхронизация languages/csc-ru_RU.po с актуальным csc.pot:
 - сохраняет существующие переводы (включая многострочные и плюральные);
 - новые msgid на кириллице заполняет идентичным переводом (ru — исходный язык);
 - порядок и состав записей = POT.

Запуск:  python3 tools/i18n/sync-po.py [корень_плагина]
Затем:   php tools/i18n/po-to-mo.php languages/csc-ru_RU.po languages/csc-ru_RU.mo
"""
import re, sys, datetime

root = sys.argv[1] if len(sys.argv) > 1 else '.'
POT = f'{root}/languages/csc.pot'
PO  = f'{root}/languages/csc-ru_RU.po'

def unesc(s):
    # корректная распаковка PO-экранирования в UTF-8
    return bytes(s, 'utf-8').decode('unicode_escape').encode('latin1').decode('utf-8')

def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')

def parse(path):
    """Разбор PO/POT в записи {ctx, msgid, plural, trans{}}.

    Реализация «блок на пустой строке»: корректно обрабатывает и заголовки
    (msgid "" + многострочный msgstr ""), и многострочные msgid/msgstr[],
    и вызовы без пробела после ключевого слова.
    """
    entries = []
    block = []          # строки текущего блока
    def add_block():
        nonlocal block
        if block:
            entries.append(parse_block(block))
            block = []
    with open(path, encoding='utf-8') as f:
        for raw in f:
            s = raw.strip()
            if s == '':
                add_block(); continue
            if s.startswith('#'):
                continue
            block.append(s)
        add_block()
    return [e for e in entries if e is not None]

def _lit(s):
    m = re.match(r'"((?:[^"\\]|\\.)*)"\s*$', s)
    return unesc(m.group(1)) if m else ''

def parse_block(lines):
    e = {'ctx': {}, 'msgid': {}, 'plural': None, 'trans': {}}
    field = None; key = 0
    for s in lines:
        m = re.match(r'msgctxt\s*(.*)', s)
        if m:
            field = 'ctx'; key = 0
            if m.group(1).strip(): e['ctx'][0] = _lit(m.group(1).strip())
            continue
        m = re.match(r'msgid_plural\s*(.*)', s)
        if m:
            field = 'plural'; key = 0
            e['plural'] = {}
            if m.group(1).strip(): e['plural'][0] = _lit(m.group(1).strip())
            continue
        m = re.match(r'msgid\s+(.*)', s)
        if m:
            field = 'msgid'; key = 0
            if m.group(1).strip(): e['msgid'][0] = _lit(m.group(1).strip())
            continue
        m = re.match(r'msgstr\[(\d+)\]\s*(.*)', s)
        if m:
            field = 'trans'; key = int(m.group(1))
            e['trans'].setdefault(key, '')
            if m.group(2).strip(): e['trans'][key] = _lit(m.group(2).strip())
            continue
        m = re.match(r'msgstr\s+(.*)', s)
        if m:
            field = 'trans'; key = 0
            e['trans'].setdefault(0, '')
            if m.group(1).strip(): e['trans'][0] = _lit(m.group(1).strip())
            continue
        if s.startswith('"') and field is not None:
            piece = _lit(s)
            if field == 'plural':
                e['plural'][0] = e['plural'].get(0, '') + piece
            else:
                e[field][key] = e[field].get(key, '') + piece
            continue
    if not e['msgid']:
        return None
    return e

pot_entries = parse(POT)
po_entries  = parse(PO)

old_map = {}
header_trans = ''
for e in po_entries:
    mid = e['msgid'].get(0, '')
    if mid == '':
        header_trans = e['trans'].get(0, '')
        continue
    old_map[(e['ctx'].get(0, ''), mid)] = e

now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S+00:00')
if header_trans:
    header_trans = re.sub(r'POT-Creation-Date: [^"]*', 'POT-Creation-Date: ' + now, header_trans)
    header_trans = re.sub(r'PO-Revision-Date: [^"]*', 'PO-Revision-Date: ' + now, header_trans)

out_parts = ['# Translation of Custom Service Calculator in Russian',
             '# This file is distributed under the GPLv2.',
             '# Synced from csc.pot by tools/i18n/sync-po.py',
             '',
             'msgid ""',
             'msgstr ""']
for hline in re.findall(r'"(?:[^"\\]|\\.)*"', header_trans):
    out_parts.append(hline)
out_parts.append('')

kept = auto = empty = 0
for e in pot_entries:
    mid = e['msgid'].get(0, '')
    if mid == '': continue
    ctx = e['ctx'].get(0, '')
    block = []
    if ctx: block.append('msgctxt "%s"' % esc(ctx))
    block.append('msgid "%s"' % esc(mid))
    src = old_map.get((ctx, mid))
    if e['plural'] is not None:
        pl = e['plural'].get(0, '')
        block.append('msgid_plural "%s"' % esc(pl))
        for n in range(3):
            v = ''
            if src and src['trans'].get(n): v = src['trans'][n]; kept += 1
            elif mid and not mid.isascii(): v = mid if n == 0 else (pl if n in (1,) else pl); auto += 1
            else: empty += 1
            block.append('msgstr[%d] "%s"' % (n, esc(v)))
    else:
        v = ''
        if src and src['trans'].get(0): v = src['trans'][0]; kept += 1
        elif not mid.isascii(): v = mid; auto += 1
        else: empty += 1
        block.append('msgstr "%s"' % esc(v))
    out_parts.append('\n'.join(block))

with open(PO, 'w', encoding='utf-8') as f:
    f.write('\n\n'.join(out_parts) + '\n')
print(f'Synced {PO}: {len(pot_entries)-1} entries (kept={kept}, auto-filled={auto}, empty={empty})')
