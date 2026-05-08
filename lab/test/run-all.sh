#!/usr/bin/env bash
# Runs every test-*.sh in this directory. Expects PHP server already running on $BASE.
# Resets sessions/leaderboard/rate-limits between tests so they don't interfere.
set -e
cd "$(dirname "$0")"
REPO_ROOT="$(cd ../.. && pwd)"
reset_state() {
    rm -f "$REPO_ROOT/lab/data/sessions.json" "$REPO_ROOT/lab/data/leaderboard.json"
    rm -rf "$REPO_ROOT/rate_limits"
}
for t in test-*.sh; do
    echo "== $t =="
    reset_state
    bash "$t" || { echo "FAIL in $t"; exit 1; }
done
echo "ALL PASS"
