#!/usr/bin/env bash
set -e
BASE="${BASE:-http://localhost:8000}"
EASY_FLAG="${EASY_FLAG:?set EASY_FLAG}"
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Confirm a writeup file exists (Tasks 20-22 author the real content).
[ -s "$REPO_ROOT/lab/writeups/easy.html" ] || { echo "FAIL: lab/writeups/easy.html missing or empty"; exit 1; }

init=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN=$(echo "$init" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')

# Without solving: 403
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/api.php?action=writeup&tier=easy&token=$TOKEN")
[ "$status" = "403" ] || { echo "FAIL: writeup without solve not 403 (got $status)"; exit 1; }

# Solve and fetch — the writeup body should match the file contents.
curl -s -X POST -d "token=$TOKEN&tier=easy&flag=$EASY_FLAG" "$BASE/lab/api.php?action=submit_flag" > /dev/null
resp=$(curl -s "$BASE/lab/api.php?action=writeup&tier=easy&token=$TOKEN")
# Look for the h4 header from the real writeup (Task 20).
echo "$resp" | grep -q 'XOR warmup' || { echo "FAIL: writeup body did not contain expected marker"; echo "got: $resp" | head -c 200; exit 1; }

echo "PASS: writeup"
