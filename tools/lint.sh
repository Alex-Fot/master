#!/usr/bin/env bash
# Статические проверки плагина CSC: PHP-синтаксис, JS-синтаксис (если есть node),
# базовые grep-эвристики и проверка целостности POT/PO/MO.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 1
FAIL=0

echo "== PHP lint =="
while IFS= read -r -d '' f; do
  if ! out=$(php -l "$f" 2>&1); then echo "PHP FAIL: $out"; FAIL=1; fi
done < <(find . -name '*.php' -not -path './node_modules/*' -print0)
[ $FAIL -eq 0 ] && echo "PHP: OK ($(find . -name '*.php' -not -path './node_modules/*' | wc -l) files)"

echo "== JS syntax =="
if command -v node >/dev/null 2>&1; then
  for f in assets/js/*.js; do
    [ -f "$f" ] || continue
    if ! node --check "$f" 2>/dev/null; then echo "JS FAIL: $f"; FAIL=1; fi
  done
  echo "JS: checked with node --check"
else
  echo "JS: node not found — skipped"
fi

echo "== Heuristics =="
# запрещённые прямые error_log (должен использоваться csc_log из csc-logger.php)
BAD_LOG=$(grep -rn --include='*.php' 'error_log(' includes/ *.php 2>/dev/null | grep -v 'csc-logger.php' | grep -v '//') || true
if [ -n "$BAD_LOG" ]; then echo "WARN: direct error_log() calls:"; echo "$BAD_LOG"; fi
# токены не должны попадать в URL-сборку вида token=<переменная хэша> — эвристика на dashboard_token в add_query_arg
Q=$(grep -rn "add_query_arg.*token" includes/ 2>/dev/null | grep -viE "issue_master_login_token|session_token|nonce") || true
if [ -n "$Q" ]; then echo "WARN: possible raw-token URLs:"; echo "$Q"; fi

echo "== i18n consistency =="
POT_COUNT=$(grep -c '^msgid ' languages/csc.pot 2>/dev/null || echo 0)
PO_COUNT=$(grep -c '^msgid ' languages/csc-ru_RU.po 2>/dev/null || echo 0)
echo "POT msgids: $POT_COUNT, PO msgids: $PO_COUNT"
if [ "$POT_COUNT" -lt 50 ]; then echo "FAIL: POT looks empty/broken"; FAIL=1; fi
if [ "$PO_COUNT" -lt "$POT_COUNT" ]; then echo "FAIL: PO out of sync with POT"; FAIL=1; fi

echo "== make-pot idempotency check =="
TMP=$(mktemp); cp languages/csc.pot "$TMP"
if php tools/i18n/make-pot.php >/dev/null 2>&1; then
  # сравниваем состав msgid (даты заведомо различаются)
  if diff <(grep '^msgid ' "$TMP") <(grep '^msgid ' languages/csc.pot) >/dev/null; then
    echo "make-pot: stable"
  else
    echo "FAIL: make-pot output unstable"; FAIL=1
  fi
else
  echo "FAIL: make-pot exited with error"; cp "$TMP" languages/csc.pot; FAIL=1
fi
rm -f "$TMP"

if [ $FAIL -eq 0 ]; then echo "LINT PASSED"; else echo "LINT FAILED"; fi
exit $FAIL
