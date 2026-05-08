#!/usr/bin/env bash
set -e
BASE="${BASE:-http://localhost:8000}"
EASY_FLAG="${EASY_FLAG:?set EASY_FLAG}"

init=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN=$(echo "$init" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')

# Without solving easy: medium fetch should 403
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/api.php?action=fetch_binary&tier=medium&token=$TOKEN")
[ "$status" = "403" ] || { echo "FAIL: medium without easy not 403 (got $status)"; exit 1; }

# Bad token → 401
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/api.php?action=fetch_binary&tier=medium&token=GARBAGE")
[ "$status" = "401" ] || { echo "FAIL: bad token not 401 (got $status)"; exit 1; }

# Solve easy, then medium fetch should 200 + ELF magic
curl -s -X POST -d "token=$TOKEN&tier=easy&flag=$EASY_FLAG" "$BASE/lab/api.php?action=submit_flag" > /dev/null
out=$(mktemp)
status=$(curl -s -o "$out" -w '%{http_code}' "$BASE/lab/api.php?action=fetch_binary&tier=medium&token=$TOKEN")
[ "$status" = "200" ] || { echo "FAIL: medium with easy solved not 200 (got $status)"; exit 1; }
head -c 4 "$out" | xxd | grep -q "7f45 4c46" || { echo "FAIL: medium binary missing ELF magic"; exit 1; }
rm -f "$out"

echo "PASS: fetch_binary"
