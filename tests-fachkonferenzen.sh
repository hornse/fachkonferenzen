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
echo "Gerüst"
grep -q 'ci-huelle--kopf' "$HTML" \
    && gruen "Kopfleisten-Variante eingebunden" || rot "kein ci-huelle--kopf"
grep -q 'ci-shell.css' "$HTML" \
    && gruen "ci-shell.css eingebunden" || rot "ci-shell.css fehlt"
grep -q 'ci-komponenten.css' "$HTML" \
    && gruen "ci-komponenten.css eingebunden" || rot "ci-komponenten.css fehlt"
grep -q 'data-ci-icons' "$HTML" \
    && gruen "Sprite-Pfad durchgereicht" || rot "data-ci-icons fehlt"
# Entschieden: helle Leiste in allen fünf Anwendungen. Die Akzentfarbe
# markiert den aktiven Punkt, nicht die Fläche.
grep -q 'ci-leiste--farbig' "$HTML" \
    && rot "farbige Leiste – entschieden ist hell für alle" \
    || gruen "helle Leiste wie in der übrigen Reihe"
grep -q "aria-current" "$JS" \
    && gruen "aktiver Punkt über aria-current" || rot "aktiver Punkt nur über Klasse"
grep -q "navPunkt" "$JS" \
    && gruen "Navigation mit Symbolen" || rot "keine Symbole in der Navigation"
# Die alten Kopfregeln muss das Modul ersetzen, nicht doppeln.
grep -qE '^\.kopf |^\.kopf-innen|^\.nav \{' "$CSS" \
    && rot "eigene Kopfregeln noch vorhanden" || gruen "keine doppelten Kopfregeln"

FEHLENDE=""
for N in $(grep -oE "ci-i-[a-z]+" "$JS" "$HTML" | sed 's/.*://' | sort -u); do
    grep -q "id=\"$N\"" frontend/vendor/ci-css/ci-icons.svg || FEHLENDE="$FEHLENDE $N"
done
[ -z "$FEHLENDE" ] && gruen "alle benutzten Symbole existieren im Sprite" \
    || rot "im Sprite fehlen:$FEHLENDE"

echo ""
echo "Projekteigene Klassen"
# app.js erzeugt diese Klassen; das Modul kennt sie NICHT (dort heißen
# sie ci-karte, ci-knopf). Wer sie hier löscht, steht ohne Karten da.
for K in "^\.karte" "^button {" "^\.raster" "^table" "^\.chip" "^\.slot-block"; do
    grep -qE "$K" "$CSS" \
        && gruen "Regel für ${K#^} vorhanden" \
        || rot "Regel für ${K#^} fehlt – app.js benutzt sie weiterhin"
done
NUTZT=$(grep -oE 'class="[a-z][a-z0-9 _-]*"' "$JS" | sed 's/class="//;s/"//' \
        | tr ' ' '\n' | sort -u | grep -vE '^(ci-|nav-|$)')
FEHLT=""
for K in $NUTZT; do
    grep -qE "\\.$K[ ,.{:]" "$CSS" || FEHLT="$FEHLT $K"
done
[ -z "$FEHLT" ] && gruen "alle von app.js gesetzten Klassen sind gestylt" \
    || rot "ohne Regel:$FEHLT"

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
echo "Logo"
grep -q 'id="schild-logo"' "$HTML" \
    && gruen "Logo-Element vorhanden" || rot "kein Logo-Element"
grep -q "logoAnzeigen" "$JS" \
    && gruen "Logo wird nach dem Laden eingeblendet" || rot "kein Logo-Umschalter"
perl -0777 -ne 'exit(!(/id="schild-logo"[^>]*alt=""/s))' "$HTML" \
    && gruen "Logo ist als dekorativ ausgezeichnet" || rot "alt am Logo prüfen"
[ -f frontend/logo.png ] \
    && gruen "logo.png liegt bereit" \
    || echo "  –  frontend/logo.png fehlt noch; bis dahin zeigt die Marke §"
# Ein eigener Ring am Sprungziel waere Doppelung - das Modul regelt es.
grep -q '#ansicht:focus-visible { outline: none' "$CSS" \
    && rot "eigene Fokusregel – seit ci-css v1.5.2 im Modul geregelt" \
    || gruen "Fokusring kommt aus dem Modul"

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
echo "Anmeldung"
# Drei Pruefungen ueber den WebUntis-Anmeldepfad. Sie greifen auf die
# FUNKTION login_webuntis, nicht auf die Datei: Rechtepruefungen an
# bestehenden Sitzungen und die Sondierungswerkzeuge hinter
# require_admin() duerfen sich anders verhalten — dort entscheidet kein
# Passwort ueber den Zugang (FALLSTRICKE.md 8).
API=backend/api/index.php
FUNK=$(awk '
    /^function login_webuntis\(/ { f = 1 }
    f {
        buf = buf $0 "\n"
        n = gsub(/\{/, "{"); m = gsub(/\}/, "}"); tiefe += n - m
        if (tiefe == 0 && NR > 1 && buf ~ /\{/) { printf "%s", buf; exit }
    }' "$API" 2>/dev/null)

if [ -z "$FUNK" ]; then
    rot "Anmeldung: login_webuntis() nicht gefunden – die Pruefung fand ihre Voraussetzung nicht"
    rot "Anmeldung: Statuscodes nicht pruefbar – Voraussetzung fehlt"
    rot "Anmeldung: Ausnahmetext nicht pruefbar – Voraussetzung fehlt"
else
    # --- 1. Die Bremse greift VOR dem Anmeldeversuch ---------------
    Z_SPERRE=$(printf '%s\n' "$FUNK" | grep -n 'anmeldung_gesperrt\|zu_viele_versuche' | head -1 | cut -d: -f1)
    Z_AUTH=$(printf '%s\n' "$FUNK" | grep -n '\->authenticate(' | head -1 | cut -d: -f1)
    if [ -z "$Z_AUTH" ]; then
        rot "Anmeldung: kein authenticate()-Aufruf gefunden – prueft die Pruefung noch etwas?"
    elif [ -z "$Z_SPERRE" ]; then
        rot "Anmeldung: keine Brute-Force-Bremse vor dem Anmeldeversuch"
    elif [ "$Z_SPERRE" -lt "$Z_AUTH" ]; then
        gruen "Anmeldung: Bremse greift vor dem Anmeldeversuch (Zeile $Z_SPERRE vor $Z_AUTH)"
    else
        rot "Anmeldung: Bremse steht NACH dem Anmeldeversuch (Zeile $Z_SPERRE nach $Z_AUTH)"
    fi

    # --- 2. Nach der Passwortpruefung nur noch 401 -----------------
    # Der Bereich beginnt beim authenticate()-Aufruf. Was davor liegt
    # (Konfiguration aus, Eingabe leer), faellt vor jeder Passwortpruefung
    # und verraet nichts ueber ein Konto.
    NACH=$(printf '%s\n' "$FUNK" | awk '/->authenticate\(/ {f=1} f {print}' | tr '\n' ' ')
    CODES=$(printf '%s' "$NACH" | awk '{
        n = split($0, t, /json_err\(/)
        for (i = 2; i <= n; i++) {
            u = t[i]; sub(/\);.*/, "", u)
            if (match(u, /[0-9][0-9][0-9][ \t]*$/)) print substr(u, RSTART, 3)
        }
    }' | sort -u | grep '^4' | tr '\n' ' ' | sed 's/ *$//')
    if [ -z "$CODES" ]; then
        rot "Anmeldung: kein 4xx nach der Passwortpruefung gefunden – prueft die Pruefung noch etwas?"
    elif [ "$CODES" = "401" ]; then
        gruen "Anmeldung lehnt einheitlich mit 401 ab"
    else
        rot "Anmeldung lehnt uneinheitlich ab (Statuscodes: $CODES)"
    fi

    # --- 3. Der Ausnahmetext geht nicht an den Benutzer ------------
    if printf '%s\n' "$FUNK" | grep -qE 'json_err\([^;]*getMessage\(\)'; then
        rot "Anmeldung: Ausnahmetext von WebUntis geht in die Antwort"
    else
        gruen "Anmeldung: Ausnahmetext bleibt im Protokoll"
    fi
fi

echo ""
if [ "$FEHLER" -eq 0 ]; then echo "ALLES GRÜN"; exit 0; fi
echo "$FEHLER FEHLER"; exit 1
