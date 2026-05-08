#!/usr/bin/env bash
set -e
BASE="${BASE:-http://localhost:8000}"
EASY_FLAG="${EASY_FLAG:?set EASY_FLAG}"

init=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN=$(echo "$init" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')
curl -s -X POST -d "token=$TOKEN&tier=easy&flag=$EASY_FLAG" "$BASE/lab/api.php?action=submit_flag" > /dev/null

# Valid handle
HANDLE="u_$(date +%s)"
resp=$(curl -s -X POST -d "token=$TOKEN&handle=$HANDLE" "$BASE/lab/api.php?action=set_handle")
echo "set_handle ok: $resp"
echo "$resp" | grep -q "\"handle\":\"$HANDLE\"" || { echo "FAIL: handle not set"; exit 1; }

# Setting again on same session → 409
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "token=$TOKEN&handle=other" "$BASE/lab/api.php?action=set_handle")
[ "$status" = "409" ] || { echo "FAIL: re-set not 409 (got $status)"; exit 1; }

# New session, try same handle → 409 taken
init2=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN2=$(echo "$init2" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')
curl -s -X POST -d "token=$TOKEN2&tier=easy&flag=$EASY_FLAG" "$BASE/lab/api.php?action=submit_flag" > /dev/null
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "token=$TOKEN2&handle=$HANDLE" "$BASE/lab/api.php?action=set_handle")
[ "$status" = "409" ] || { echo "FAIL: duplicate handle not 409 (got $status)"; exit 1; }

# Bad handle (too short) → 400
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "token=$TOKEN2&handle=ab" "$BASE/lab/api.php?action=set_handle")
[ "$status" = "400" ] || { echo "FAIL: short handle not 400 (got $status)"; exit 1; }

# Profanity → 400
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "token=$TOKEN2&handle=fucker_99" "$BASE/lab/api.php?action=set_handle")
[ "$status" = "400" ] || { echo "FAIL: profanity not 400 (got $status)"; exit 1; }

echo "PASS: set_handle"
