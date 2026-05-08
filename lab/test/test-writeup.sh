#!/usr/bin/env bash
set -e
BASE="${BASE:-http://localhost:8000}"
EASY_FLAG="${EASY_FLAG:?set EASY_FLAG}"

# Create a placeholder writeup so the action has something to return.
echo '<p>placeholder easy writeup</p>' > lab/writeups/easy.html

init=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN=$(echo "$init" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')

# Without solving: 403
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/api.php?action=writeup&tier=easy&token=$TOKEN")
[ "$status" = "403" ] || { echo "FAIL: writeup without solve not 403 (got $status)"; exit 1; }

# Solve and fetch
curl -s -X POST -d "token=$TOKEN&tier=easy&flag=$EASY_FLAG" "$BASE/lab/api.php?action=submit_flag" > /dev/null
resp=$(curl -s "$BASE/lab/api.php?action=writeup&tier=easy&token=$TOKEN")
echo "$resp" | grep -q "placeholder easy writeup" || { echo "FAIL: writeup body wrong"; exit 1; }

echo "PASS: writeup"
