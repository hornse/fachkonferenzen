#!/bin/bash
# ============================================================
# deploy.sh – Commit + Push zu GitHub und Uberspace
#   ./deploy.sh "Commit-Nachricht"
# Überträgt NUR Dateien. DB-Migrationen separat auf dem Server:
#   mysql hornse_fachkonferenzen < sql/NN_*.sql
# ============================================================
set -e

if [ -z "$1" ]; then
    echo "Aufruf: ./deploy.sh \"Commit-Nachricht\""
    exit 1
fi

# Cache-Busting: Zeitstempel in index.html setzen (?v=...)
STEMPEL=$(date +%Y%m%d%H%M%S)
sed -i '' -E "s/\?v=[A-Za-z0-9]+/?v=${STEMPEL}/g" frontend/index.html 2>/dev/null \
    || sed -i -E "s/\?v=[A-Za-z0-9]+/?v=${STEMPEL}/g" frontend/index.html

git add -A
git commit -m "$1"
git push github main
git push uberspace main

echo ""
echo "Deployt. Nicht vergessen, falls neue SQL-Dateien dabei sind:"
echo "  ssh hornse@halimede.uberspace.de"
echo "  mysql hornse_fachkonferenzen < ~/fachkonferenzen/sql/NN_*.sql"
