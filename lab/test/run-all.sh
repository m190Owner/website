#!/usr/bin/env bash
# Runs every test-*.sh in this directory. Expects PHP server already running on $BASE.
set -e
cd "$(dirname "$0")"
for t in test-*.sh; do
    echo "== $t =="
    bash "$t" || { echo "FAIL in $t"; exit 1; }
done
echo "ALL PASS"
