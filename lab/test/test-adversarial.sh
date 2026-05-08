#!/usr/bin/env bash
# Adversarial checks per spec section "Adversarial checks (run before shipping)"
set -e
BASE="${BASE:-http://localhost:8000}"
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

fail() { echo "FAIL: $1"; exit 1; }

# 1. Direct fetch of medium binary (bypassing api.php) → should be 403 in production.
#    Locally with `php -S` this WILL be 200 because PHP's built-in server ignores .htaccess.
#    We accept the local result and note it. Apache will enforce.
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/binaries/crackme-medium")
echo "direct fetch crackme-medium → $status (expected 403 in production)"

# 2. Fetch via api.php with garbage token → 401.
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/api.php?action=fetch_binary&tier=medium&token=GARBAGE")
[ "$status" = "401" ] || fail "fetch_binary garbage token: expected 401, got $status"

# 3. Fetch via api.php with valid token but no easy solve → 403.
init=$(curl -s -X POST "$BASE/lab/api.php?action=init")
TOKEN=$(echo "$init" | sed -n 's/.*"token":"\([a-f0-9]\{64\}\)".*/\1/p')
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/api.php?action=fetch_binary&tier=medium&token=$TOKEN")
[ "$status" = "403" ] || fail "fetch_binary unmet prereq: expected 403, got $status"

# 4. Wrong-flag rate limit. 11 wrong submissions in <1 min → 429 on the 11th.
for i in $(seq 1 11); do
    status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "token=$TOKEN&tier=easy&flag=wrong_$i" "$BASE/lab/api.php?action=submit_flag")
    if [ "$i" = "11" ]; then
        [ "$status" = "429" ] || fail "expected 429 on 11th submit, got $status"
    fi
done

# 5. Direct access to data dir → 403 in production. Note local result.
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/data/sessions.json")
echo "direct fetch data/sessions.json → $status (expected 403 in production)"

# 6. Direct access to writeups → 403 in production.
status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/lab/writeups/medium.html")
echo "direct fetch writeups/medium.html → $status (expected 403 in production)"

# 7. Search the JS bundle for flag hashes — there should be none.
[ -f "$REPO_ROOT/lab/lab.js" ] || fail "lab/lab.js not found at $REPO_ROOT/lab/lab.js"
if grep -E '[0-9a-f]{64}' "$REPO_ROOT/lab/lab.js" > /dev/null; then
    fail "lab.js contains hex strings that look like hashes — flag hashes should never be in JS"
fi

# 8. Check that crackme source isn't in the repo.
if [ -e "$REPO_ROOT/crackmes/easy.c" ] && (cd "$REPO_ROOT" && git ls-files --error-unmatch crackmes/easy.c) >/dev/null 2>&1; then
    fail "crackmes/easy.c is tracked by git — must be gitignored"
fi

echo "PASS: adversarial (production-only checks marked above)"
