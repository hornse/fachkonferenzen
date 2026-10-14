#!/bin/bash
# ============================================================
# deploy.sh – Commit + Push zu GitHub und Uberspace
#   ./deploy.sh "Commit-Nachricht"
# Überträgt NUR Dateien. DB-Migrationen separat auf dem Server:
#   mysql hornse_fachkonferenzen < sql/NN_*.sql
# ============================================================
set -e
# Der Halt unten sieht mit "git ls-files -o" nur unterhalb des aktuellen
# Verzeichnisses - deshalb zuerst an die Wurzel.
cd "$(dirname "$0")"

# AUSGELIEFERT WIRD, WAS COMMITTET IST (REIHENREGELN 6). Bis zum 14.10.2026
# stand hier "git add -A": Eine Datei, die unverfolgt im Baum lag, waere
# ungesehen committet, nach GitHub gepusht und ausgeliefert worden. Deshalb
# ZUERST anhalten und nennen, was dazukaeme - vor Commit und Push. Wer es
# sieht, entscheidet: namentlich committen, verschieben oder in .gitignore.
# -z: Namen mit Umlauten erscheinen, wie sie heissen, nicht als Oktalfolge.
UNGETRACKT=$(git ls-files -o --exclude-standard -z | tr '\0' '\n')
GEAENDERT=$(git status --porcelain -z --untracked-files=no | tr '\0' '\n')
if [ -n "$UNGETRACKT" ] || [ -n "$GEAENDERT" ]; then
    echo "!! Ausgeliefert wird, was committet ist. Vorher entscheiden:" >&2
    if [ -n "$UNGETRACKT" ]; then
        echo "   UNVERFOLGT – namentlich committen, verschieben oder in .gitignore:" >&2
        printf '%s\n' "$UNGETRACKT" | sed 's/^/     /' >&2
    fi
    if [ -n "$GEAENDERT" ]; then
        echo "   GEAENDERT oder gemerkt, nicht committet – namentlich committen oder zuruecknehmen:" >&2
        printf '%s\n' "$GEAENDERT" | sed 's/^/     /' >&2
    fi
    echo "Kein Deploy." >&2
    exit 1
fi
echo "== Vor der Auslieferung: nichts Unverfolgtes, nichts ungeprueft Geaendertes =="

if [ -z "$1" ]; then
    echo "Aufruf: ./deploy.sh \"Commit-Nachricht\""
    exit 1
fi

# Cache-Busting: Zeitstempel in index.html setzen (?v=...)
STEMPEL=$(date +%Y%m%d%H%M%S)
sed -i '' -E "s/\?v=[A-Za-z0-9]+/?v=${STEMPEL}/g" frontend/index.html 2>/dev/null \
    || sed -i -E "s/\?v=[A-Za-z0-9]+/?v=${STEMPEL}/g" frontend/index.html

# NUR, was dieses Skript selbst aendert: den ?v=-Stempel. Alles andere ist
# vorher namentlich committet - sonst haette der Halt oben angehalten.
git add frontend/index.html
if ! git diff --cached --quiet; then
    git commit -m "$1"
else
    echo "  (nichts zu committen)"
fi
git push github main
git push uberspace main

echo ""
echo "Deployt. Nicht vergessen, falls neue SQL-Dateien dabei sind:"
echo "  ssh hornse@halimede.uberspace.de"
echo "  mysql hornse_fachkonferenzen < ~/fachkonferenzen/sql/NN_*.sql"
