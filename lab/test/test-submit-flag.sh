#!/usr/bin/env bash
# Test: submit_flag with right/wrong flags, valid/invalid tokens.
set -e
BASE="${BASE:-http://localhost:8000}"
EASY_FLAG="${EASY_FLAG:?set EASY_FLAG to the easy flag string}"
WRONG_FLAG="m190{wrong}"

init=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN=$(echo "$init" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')
[ -n "$TOKEN" ] || { echo "FAIL: no token from init"; exit 1; }

# Wrong flag -> correct=false
resp=$(curl -s -X POST -d "token=$TOKEN&tier=easy&flag=$WRONG_FLAG" "$BASE/lab/api.php?action=submit_flag")
echo "wrong: $resp"
echo "$resp" | grep -q '"correct":false' || { echo "FAIL: wrong flag not rejected"; exit 1; }

# Right flag -> correct=true, needs_handle=true, next_tier_unlocked=medium
resp=$(curl -s -X POST -d "token=$TOKEN&tier=easy&flag=$EASY_FLAG" "$BASE/lab/api.php?action=submit_flag")
echo "right: $resp"
echo "$resp" | grep -q '"correct":true'              || { echo "FAIL: right flag not accepted"; exit 1; }
echo "$resp" | grep -q '"needs_handle":true'         || { echo "FAIL: needs_handle not set"; exit 1; }
echo "$resp" | grep -q '"next_tier_unlocked":"medium"' || { echo "FAIL: next tier wrong"; exit 1; }

# Out-of-order: try medium without prereq met (we only solved easy). Reset by getting a new session.
init2=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN2=$(echo "$init2" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')
resp=$(curl -s -X POST -d "token=$TOKEN2&tier=medium&flag=anything" "$BASE/lab/api.php?action=submit_flag")
echo "prereq-fail: $resp"
echo "$resp" | grep -q '"error":"prerequisite tier not solved"' || { echo "FAIL: prereq not enforced"; exit 1; }

# Bad token -> 401
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "token=GARBAGE&tier=easy&flag=anything" "$BASE/lab/api.php?action=submit_flag")
[ "$status" = "401" ] || { echo "FAIL: bad token not 401 (got $status)"; exit 1; }

echo "PASS: submit_flag"
