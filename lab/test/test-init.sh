#!/usr/bin/env bash
# Test: POST /lab/api.php?action=init returns ok=true and a 64-hex token.
set -e
BASE="${BASE:-http://localhost:8000}"
resp=$(curl -s -X POST "$BASE/lab/api.php?action=init")
echo "init response: $resp"
echo "$resp" | grep -q '"ok":true' || { echo "FAIL: missing ok=true"; exit 1; }
echo "$resp" | grep -qE '"token":"[a-f0-9]{64}"' || { echo "FAIL: bad token format"; exit 1; }
echo "$resp" | grep -q '"leaderboard"' || { echo "FAIL: missing leaderboard"; exit 1; }
echo "PASS: init"
