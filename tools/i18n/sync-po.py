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
    """Блочный парсер PO/POT: записи разделяются пустыми строками.

    Каждая запись — dict ctx{}, msgid{}, plural|None, trans{}.
    Многострочные значения (идущие после ключа литеральные строки) склеиваются.
    Ключи msgstr[N] обрабатываются как отдельные переводы плюралов.
    Устойчив к лишним пустым строкам и заголовочной записи msgid "".
    """
    entries = []
    blocks = []          # блоки строк текущей записи
    cur_block = []

    def finalize(block):
        if not block:
            return
        e = {'ctx': {}, 'msgid': {}, 'plural': None, 'trans': {}}
        field = None     # 'ctx' | 'msgid' | 'plural' | 'trans'
        key = 0
        lit_re = re.compile(r'^"((?:[^"\\]|\\.)*)"$')
        pending = None   # накопитель для текущего поля/ключа
        def commit():
            nonlocal pending
            if field is not None and pending is not None:
                e[field][key] = e[field].get(key, '') + pending
            pending = None
        for ln in block:
            s = ln.strip()
            if not s or s.startswith('#'):
                continue
            m_lit = lit_re.match(s)
            if m_lit:                      # continuation-строка
                if pending is None:
                    pending = ''
                pending += unesc(m_lit.group(1))
                continue
            commit()
            if s.startswith('msgctxt'):
                field, key = 'ctx', 0
                pending = _tail(s, len('msgctxt'))
            elif s.startswith('msgid_plural'):
                field, key = 'plural', 0
                e['plural'] = {}
                pending = _tail(s, len('msgid_plural'))
            elif s.startswith('msgid'):
                field, key = 'msgid', 0
                pending = _tail(s, len('msgid'))
            elif s.startswith('msgstr['):
                m = re.match(r'msgstr\[(\d+)\]', s)
                field, key = 'trans', int(m.group(1))
                pending = _tail(s, m.end())
            elif s.startswith('msgstr'):
                field, key = 'trans', 0
                pending = _tail(s, len('msgstr'))
            else:
                # неизвестная строка — игнорируем (комментарии flags/location и т.п.)
                field = None
        commit()
        # запись валидна, только если есть msgid (в т.ч. пустой — заголовок)
        if e['msgid']:
            entries.append(e)

    def _tail(s, n):
        rest = s[n:].strip()
        m = re.match(r'^"((?:[^"\\]|\\.)*)"$', rest)
        return unesc(m.group(1)) if m else ''

    with open(path, encoding='utf-8') as f:
        for raw in f:
            if raw.strip() == '':
                if cur_block:
                    finalize(cur_block)
                    cur_block = []
                continue
            cur_block.append(raw)
    if cur_block:
        finalize(cur_block)
    return entries

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
