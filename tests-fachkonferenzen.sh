#!/usr/bin/env bash
# ============================================================
# tests-fachkonferenzen.sh – Prüfung des CI-Umbaus
#
# Aufruf im Projektordner:  ./tests-fachkonferenzen.sh
#
# Prüft, was beim Umbau schiefgehen kann: verbliebene Rohfarben,
# unausgeglichene Klammern, undefinierte Tokens, JS-Syntax, und ob
# die behobenen Mängel tatsächlich behoben sind.
#
# SPDX-License-Identifier: GPL-3.0-or-later
# ============================================================
set -uo pipefail
export LC_ALL=C
cd "$(dirname "$0")"

FEHLER=0
gruen() { echo "  ✓ $1"; }
rot()   { echo "  ✗ $1"; FEHLER=$((FEHLER + 1)); }

CSS=frontend/style.css
JS=frontend/app.js
HTML=frontend/index.html
TOK=frontend/vendor/ci-css/ci-tokens.css

echo "Dateien"
for D in "$CSS" "$JS" "$HTML" "$TOK" frontend/vendor/ci-css/ci-icons.svg; do
    [ -f "$D" ] && gruen "$D vorhanden" || rot "$D fehlt"
done

echo ""
echo "Aufbau"
AUF=$(tr -cd '{' < "$CSS" | wc -c | tr -d ' ')
ZU=$(tr -cd '}' < "$CSS" | wc -c | tr -d ' ')
[ "$AUF" -eq "$ZU" ] && gruen "Klammern ausgeglichen ($AUF)" \
    || rot "$AUF öffnende, $ZU schließende Klammern"

if command -v node > /dev/null 2>&1; then
    node --check "$JS" > /dev/null 2>&1 \
        && gruen "app.js ist syntaktisch fehlerfrei" \
        || rot "app.js hat einen Syntaxfehler"
else
    echo "  –  node nicht vorhanden, JS-Syntax nicht geprüft"
fi

echo ""
echo "Keine Rohfarben außerhalb des :root-Blocks"
REST=$(perl -0777 -pe 's{/\*.*?\*/}{}gs' "$CSS" \
    | perl -0777 -pe 's{^.*?\* \{ box-sizing: border-box; \}}{}s')
TREFFER=$(printf '%s' "$REST" | grep -oE '#[0-9a-fA-F]{3,8}\b' | sort -u || true)
[ -z "$TREFFER" ] && gruen "keine Hexfarben" \
    || rot "Hexfarben: $(echo "$TREFFER" | tr '\n' ' ')"
TREFFER=$(printf '%s' "$REST" | grep -oE 'rgba?\([^)]*\)' | sort -u || true)
[ -z "$TREFFER" ] && gruen "keine rgb/rgba-Angaben" \
    || rot "rgba: $(echo "$TREFFER" | tr '\n' ' ')"

echo ""
echo "Tokens vollständig"
UNBEKANNT=""
for V in $(grep -ohE 'var\(--ci-[a-z0-9-]+' "$CSS" | sed 's/var(//' | sort -u); do
    grep -qE "^[[:space:]]*$V:" "$TOK" || UNBEKANNT="$UNBEKANNT $V"
done
[ -z "$UNBEKANNT" ] && gruen "alle benutzten ci-Tokens sind definiert" \
    || rot "nicht definiert:$UNBEKANNT"

echo ""
echo "Einbindung"
grep -q 'data-projekt="fachkonferenzen"' "$HTML" \
    && gruen "Projektfarbe gesetzt" || rot "data-projekt fehlt"
grep -q 'ci-tokens.css' "$HTML" \
    && gruen "Tokens eingebunden" || rot "Tokens nicht eingebunden"
# Der Symbolsatz liegt bereit, wird aber noch nicht benutzt:
# fachkonferenzen hat keine Symbolnavigation.
[ -f frontend/vendor/ci-css/ci-icons.svg ] \
    && gruen "Symbolsatz liegt bereit" || rot "Symbolsatz fehlt"

echo ""
echo "Behobene Mängel"
grep -q 'class="skip-link"' "$HTML" \
    && gruen "Sprungmarke zum Inhalt vorhanden" || rot "keine Sprungmarke"
grep -q 'id="meldung".*role="status"' "$HTML" \
    && gruen "Meldungen werden angesagt" || rot "Meldung ohne role=status"
grep -q 'id="meldung".*aria-live' "$HTML" \
    && gruen "Meldung hat aria-live" || rot "Meldung ohne aria-live"
# aria-live auf dem gesamten Inhaltsbereich ließ bei jedem Wechsel die
# komplette Seite vorlesen.
grep -q 'id="ansicht".*aria-live' "$HTML" \
    && rot "aria-live liegt noch auf dem gesamten Inhaltsbereich" \
    || gruen "kein aria-live mehr am Inhaltsbereich"
grep -q 'id="ansicht".*tabindex="-1"' "$HTML" \
    && gruen "Inhaltsbereich ist fokussierbar" || rot "tabindex am Inhaltsbereich fehlt"
grep -q "fokusAufInhalt" "$JS" \
    && gruen "Fokus springt nach dem Ansichtswechsel" || rot "kein Fokussprung"

echo ""
echo "Schrift und Kontrast"
REST_S=$(perl -0777 -pe 's{/\*.*?\*/}{}gs' "$CSS")
printf '%s' "$REST_S" | grep -qiE 'Georgia|Palatino|Iowan|serif' \
    && rot "Serifenschrift noch vorhanden" \
    || gruen "Systemschrift wie in der übrigen Reihe"
# --gold erreichte auf Weiß nur 3.12 und auf der Hinweisfläche 2.70.
grep -q -- '--gold:.*var(--ci-warnung)' "$CSS" \
    && gruen "--gold nutzt das Warnungs-Token (5.06 statt 3.12)" \
    || rot "--gold zeigt noch auf einen eigenen Wert"
grep -q -- '--ok-flaeche:.*var(--ci-erfolg-flaeche)' "$CSS" \
    && gruen "Erfolgskasten nutzt das Token" || rot "Erfolgskasten mit eigener Fläche"
grep -q -- '--hinweis-flaeche:.*var(--ci-warnung-flaeche)' "$CSS" \
    && gruen "Hinweiskasten nutzt das Token" || rot "Hinweiskasten mit eigener Fläche"

echo ""
if [ "$FEHLER" -eq 0 ]; then echo "ALLES GRÜN"; exit 0; fi
echo "$FEHLER FEHLER"; exit 1
