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
    entries = []
    cur = None      # dict: ctx,msgid,plural,trans{}
    field = None    # текущее поле для continuation-строк
    cont = None
    def flush():
        nonlocal cur, field, cont
        if cur is not None:
            if cont is not None:
                cur[field][cur_key] += cont
                cont = None
            entries.append(cur)
        cur = None; field = None; cont = None
    with open(path, encoding='utf-8') as f:
        for raw in f:
            line = raw.rstrip('\n')
            s = line.strip()
            m_lit = re.fullmatch(r'"((?:[^"\\]|\\.)*)"', s)
            if s == '':
                flush(); continue
            if m_lit and cur is not None and cont is not None:
                cont += unesc(m_lit.group(1)); continue
            if m_lit and cur is not None and field and cont is None:
                cur[field].setdefault(cur_key, '')
                cur[field][cur_key] += unesc(m_lit.group(1))
                cont = ''  # следующие литералы дописываем
                continue
            cont_done = None
            if cont is not None and cur is not None:
                cur[field][cur_key] += cont; cont = None
            if s.startswith('msgctxt'):
                if cur is not None: flush()
                cur = {'ctx': {}, 'msgid': {}, 'plural': None, 'trans': {}}
                field = 'ctx'; cur_key = 0
                rest = s[len('msgctxt'):].strip()
                if rest: cur['ctx'][0] = unesc(re.match(r'"((?:[^"\\]|\\.)*)"', rest).group(1)); cont = ''
            elif s.startswith('msgid_plural'):
                cur['plural'] = {}
                field = 'plural'; cur_key = 0
                rest = s[len('msgid_plural'):].strip()
                if rest: cur['plural'][0] = unesc(re.match(r'"((?:[^"\\]|\\.)*)"', rest).group(1)); cont = ''
            elif s.startswith('msgid'):
                if cur is not None and cur.get('msgid'): flush()
                if cur is None:
                    cur = {'ctx': {}, 'msgid': {}, 'plural': None, 'trans': {}}
                field = 'msgid'; cur_key = 0
                rest = s[len('msgid'):].strip()
                if rest: cur['msgid'][0] = unesc(re.match(r'"((?:[^"\\]|\\.)*)"', rest).group(1)); cont = ''
            elif s.startswith('msgstr['):
                m = re.match(r'msgstr\[(\d+)\]', s)
                idx = int(m.group(1)); field = 'trans'; cur_key = idx
                cur['trans'].setdefault(idx, '')
                rest = s[m.end():].strip()
                if rest: cur['trans'][idx] = unesc(re.match(r'"((?:[^"\\]|\\.)*)"', rest).group(1)); cont = ''
            elif s.startswith('msgstr'):
                field = 'trans'; cur_key = 0
                cur['trans'].setdefault(0, '')
                rest = s[len('msgstr'):].strip()
                if rest: cur['trans'][0] = unesc(re.match(r'"((?:[^"\\]|\\.)*)"', rest).group(1)); cont = ''
            # комментарии — игнорируем
    flush()
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
