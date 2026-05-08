#!/usr/bin/env bash
set -e
BASE="${BASE:-http://localhost:8000}"
resp=$(curl -s "$BASE/lab/api.php?action=leaderboard")
echo "$resp" | grep -q '"ok":true' || { echo "FAIL: leaderboard not ok"; exit 1; }
echo "$resp" | grep -q '"leaderboard"' || { echo "FAIL: missing leaderboard key"; exit 1; }
echo "PASS: leaderboard"
