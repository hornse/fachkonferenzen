# Bootstrap-Prompt für neue Schulprojekte (Vorlage)

> Diesen Text an den **Anfang eines neuen Projekt-Chats** kopieren. Er beschreibt
> alle Rahmenbedingungen, die für meine (bestehenden) Projekte gelten, damit sofort
> klar ist, in welcher Umgebung, mit welchem Stack und welchen Konventionen gearbeitet
> wird. Die mit «…» markierten Stellen für das jeweilige neue Projekt ausfüllen.

---

## 0. Auftrag an Claude

Du unterstützt mich (Sebastian Horn, IT-Administrator und Lehrer am
Friedrich-Rückert-Gymnasium Düsseldorf) bei der Entwicklung einer Webanwendung.
Das neue Projekt orientiert sich **eng an meinen bestehenden Projekten** (z. B.
„Projektstunden NRW", „Fachkonferenzen FRG"). Halte dich an die unten beschriebenen
Rahmenbedingungen, Konventionen und Fallstricke, ohne dass ich sie erneut erklären
muss. Frag nach, wenn ein projektspezifischer Wert («…») fehlt.

## 1. Neues Projekt – auszufüllen

| Was | Wert |
|---|---|
| **App-Name** | «…» |
| **Zweck / Kurzbeschreibung** | «…» |
| **Lokaler Pfad** | `/Users/sebastianhorn/Projekte/«projektname»` |
| **Domain** | `«projektname».hornse.de` |
| **Port** (built-in PHP-Server) | `«80xx»` – vorher prüfen: `uberspace web backend list`, `grep -h "command=" ~/etc/services.d/*.ini`, `ss -tln \| grep ':«80xx» '` (8082 = Projektstunden, 8083 = Prozesse, 8084 = Fachkonferenzen) |
| **Datenbank** | `hornse_«projektname»` (MariaDB) |
| **GitHub-Repo** | `hornse/«projektname»` (privat) |
| **Braucht WebUntis-Login/-Daten?** | ja / nein (falls ja: siehe Abschnitt 6) |

## 2. Infrastruktur (fix, für alle Projekte gleich)

- **Hosting:** Uberspace 7, Server `halimede.uberspace.de`, Account `hornse`.
- **Laufzeit:** PHP built-in Server via **supervisord**, nicht Apache direkt
  (wegen sauberem URL-Routing). Config: `~/etc/services.d/«projektname».ini`
  mit `command=php -S 0.0.0.0:«PORT» /home/hornse/«projektname»/backend/router.php`.
  Danach `supervisorctl reread && supervisorctl update`.
- **Web-Backend-Weiterleitung:** erst `uberspace web domain add «projektname».hornse.de`
  (DNS ggf. beim Anbieter nachziehen), **dann**
  `uberspace web backend set «projektname».hornse.de/ --http --port «PORT»`.
- **SSL** wird von Uberspace **vor** PHP terminiert (wichtig, siehe Fallstricke).
- **Proxy-Timeout:** Der Uberspace-Proxy kappt lang laufende Requests (~1 Min.).
  Lange Abläufe (API-Massenabrufe, Importe) nach dem Muster
  **Vorschau-mit-Zwischenspeicher** bauen: Der lange Lese-Schritt legt sein
  Ergebnis in der Session ab, der Schreib-Schritt („Übernehmen") arbeitet nur
  noch aus dem Cache (Sekunden). Zusätzlich `ignore_user_abort(true)` +
  `set_time_limit(0)`, damit ein gekappter Request serverseitig zu Ende läuft.
- **DB-Zugang:** MariaDB-Passwort steht auf dem Server in `~/.my.cnf`.

## 3. Stack & Konventionen (fix)

- **Backend:** PHP 8.1+, **kein Framework**, eigener Router + eigener API-Router,
  Datenbankzugriff via **PDO**.
- **Frontend:** Vanilla JS / HTML / CSS, **kein Build-Schritt**.
- **Gesamtes JavaScript gehört in `frontend/app.js`** – nicht ins HTML einbetten
  (Uberspace-Proxy begrenzt HTML auf ~63 KB). CSS in `frontend/style.css`.
- **Datenbank:** MariaDB, nummerierte SQL-Dateien (siehe unten).
- **Wiederverwendbare Module** werden als eigene GitHub-Repos gepflegt und in die
  Projekte **vendored** (Dateien kopiert, im Kopf markiert:
  „VENDORED aus hornse/«modul» – dort ändern, hierher kopieren!").

### Verzeichnisstruktur (Vorlage)
```
«projektname»/
├── backend/
│   ├── router.php          ← HTTPS + session_name GANZ OBEN (kritisch!)
│   ├── config.php          ← NICHT in git (.gitignore)
│   ├── config.example.php  ← Vorlage in git
│   ├── bootstrap.php       ← Session, PDO, JSON-Helfer, Auth-Guards
│   ├── auth/               ← Auth- und API-Clients (ggf. vendored Module)
│   └── api/index.php       ← API-Router + Handler (größere Logik in eigene
│                             Dateien daneben, z. B. sync.php – testbar!)
├── frontend/
│   ├── index.html          ← SPA-Gerüst (kein JS!)
│   ├── style.css
│   └── app.js              ← gesamtes JavaScript
├── sql/
│   ├── 01_schema.sql
│   ├── 02_seed.sql
│   └── NN_migration_*.sql / NN_seed_*.sql   ← fortlaufend nummeriert
├── docs/
├── bundles/                ← Zip-Ablage, aus git ausgeschlossen
├── deploy.sh
└── .gitignore
```

## 4. Git- & Deploy-Workflow (fix)

- **Bare Repo auf dem Server:** `/home/hornse/repos/«projektname».git`
  (`git init --bare`, dann `symbolic-ref HEAD refs/heads/main`),
  Work-Tree `/home/hornse/«projektname»`, Post-Receive-Hook:
  `GIT_WORK_TREE=/home/hornse/«projektname» git checkout -f main`.
- **GitHub** zusätzlich als privates Remote namens `github`
  (in IntelliJ: „Share Project on GitHub", danach Remote von `origin`
  in `github` umbenennen; `gh` CLI ist lokal nicht installiert).
- **Deploy-Skript:** `./deploy.sh "Commit-Nachricht"` – setzt Cache-Busting-Timestamp
  in `index.html`, macht `git add -A`, commit, dann `git push github main &&
  git push uberspace main`.
- **Wichtig:** `deploy.sh` überträgt nur **Dateien**. DB-Migrationen/Seeds werden
  **separat** auf dem Server eingespielt: `mysql hornse_«projektname» < sql/NN_*.sql`.
- **`config.php`** liegt nicht in git und muss nach frischem Deploy einmalig aus
  `config.example.php` erzeugt und mit DB-Passwort (aus `~/.my.cnf`) befüllt werden.

## 5. Kritische Fallstricke (immer beachten)

1. **`router.php` – zwei Zeilen ganz oben, vor jedem `require`:**
   ```php
   $_SERVER['HTTPS'] = 'on';        // Uberspace terminiert SSL vor PHP
   session_name('«proj»_session');  // sonst Session-Verlust nach Login
   ```
2. **Nie `empty()` für IDs, die 0 sein dürfen** (z. B. WebUntis-Lehrer mit id=0):
   `if (!isset($_SESSION['benutzer_id']) || $_SESSION['benutzer_id'] === null)`.
3. **Nie `isset()` für Array-Einträge, deren Wert `null` sein kann** –
   `isset($arr[$k])` ist bei `null` false und hat schon Diff-Logik zerlegt.
   Für Existenzprüfung **`array_key_exists()`** verwenden.
4. **`session.save_path` per `~/etc/php.d/sessions.ini`** setzen, nicht per
   `ini_set()` (greift bei PHP-FPM zu spät):
   `session.save_path = /home/hornse/tmp/sessions`.
5. **JavaScript nur in `app.js`** (63-KB-HTML-Limit des Proxys).
6. **SQL-Migrationen idempotent** schreiben (`ADD COLUMN IF NOT EXISTS`, …).
   In **MariaDB** gehört `COMMENT` in der Spaltendefinition **vor** `AFTER …`
   (nicht danach – sonst Syntaxfehler 1064). Seeds mit `INSERT IGNORE` bzw.
   idempotentem `DELETE … ; INSERT …` in einer Transaktion.
7. **Externe Datenquellen liefern Duplikate:** Upserts müssen doppelte Kürzel
   **innerhalb desselben Laufs** erkennen (In-Memory-Maps beim Einfügen
   mitpflegen!) und case-insensitiv vergleichen – die DB-Kollation
   `utf8mb4_unicode_ci` behandelt `m`/`M` als gleich, PHP-Arrays nicht.
8. **Debug:** Server-Log via `supervisorctl tail «projektname» stderr | tail -20`
   (bei `<!DOCTYPE` statt JSON steckt meist ein PHP-500 oder das Proxy-Timeout
   dahinter – erst Log prüfen, dann raten).

## 6. WebUntis-Anbindung (nur falls benötigt)

**Wiederverwendbares Modul: `hornse/webuntis-client-php`** (löst das ältere
`webuntis-auth-php` ab). Enthält `WebUntisAuth` (offizielle JSON-RPC-API),
`WebUntisRest` (interne REST-API) und `src/extractors.php`
(u. a. Lehrer-Fach-Zuordnungen aus Stundenplandaten, mit Perioden-Zählung
als Stunden-Signal). Framework-frei, Offline-Testsuite (`php tests/run.php`),
Sondierungswissen im README. **Ins Projekt vendoren** (`backend/auth/`),
nicht neu erfinden.

### 6a. Offizielle JSON-RPC-API (Standard, für Login + Stammdaten)

- Config-Werte: `base_url` (`https://«schule».webuntis.com`), `school`, `client`,
  `allowed_person_types`, `admin_kuerzel` (Groß-/Kleinschreibung exakt wie in
  Untis – im Zweifel mehrere Varianten eintragen).
- Rollen-Mapping: personType **2** → Lehrkraft, **16** → WebUntis-Admin
  (hat `personId = -1`, kein Eintrag in `getTeachers()` → Name aus DB per Kürzel),
  **5** → Schüler (`key` = Schild-ID).
- **`JSESSIONID`** aus der `authenticate`-Antwort speichern und bei allen
  Folgeaufrufen (`getTeachers`, `getSubjects`, `getRooms`, `getTimetable`,
  `logout`) mitschicken.
- `getStudents()` liefert (an frg-dusseldorf) **kein** `idOfClass`.
- Lehrer-Fach-Zuordnung: kein direkter Endpunkt – aus `getTimetable(type=3)`
  je Fach ableiten (nur `lstype = 'ls'`). **Achtung:** zählt Vertretungsstunden
  mit; Zeitraum lang wählen (8–12 normale Wochen, Abitur-/Ferienzeiten meiden)
  und Perioden je Paar mitzählen (Stunden-Signal: Facultas vs. Vertretung).

### 6b. Interne REST-API (Beta/undokumentiert – kann sich mit Untis-Updates ändern)

Zugang: bestehende JSON-RPC-Session → Cookie `JSESSIONID` +
`schoolname=_base64(schule)` → `GET /WebUntis/api/token/new` liefert JWT →
REST-Aufrufe mit `Authorization: Bearer` (+ optional `tenant-id` aus
`/api/rest/view/v1/app/data`).

Gesichertes Wissen (Sondierung frg-dusseldorf, 07/2026):

- `GET /api/rest/view/v1/timetable/entries?start&end&resourceType=TEACHER&resources=<id>`:
  **`format`-Parameter WEGLASSEN** (unbekannte Format-ID → 404
  „Timetable format not found"; ohne Parameter greift das Instanz-Standardformat).
  Ein Aufruf deckt den **gesamten Zeitraum** ab.
- Antwort `{format, days, errors}`; Einträge haben `position1..7` – die
  Positionsbedeutung ist **formatabhängig**, daher NIE fest interpretieren,
  sondern **`current.type`** jedes Elements lesen (`CLASS`/`SUBJECT`/`ROOM`/`TEACHER`).
- Eintrags-`type` (z. B. `NORMAL_TEACHING_PERIOD`) zum Filtern von
  Nicht-Unterricht nutzen; Status `SUBSTITUTION`/`CHANGED` überspringen
  (→ Vertretungen fallen automatisch raus – der große Vorteil gegenüber RPC).
- `resources` als Komma-Liste wird akzeptiert, aber die Einträge tragen
  **keine Zuordnung zur angefragten Ressource** → je Ressource einzeln abfragen.
- Fach-Anzeigenamen können vom RPC-`name` abweichen → gestuftes Matching
  (name → longName/alternateName → Leerzeichen-bereinigt).
- Fallback: `GET /api/public/timetable/weekly/data?elementType=2&elementId=<id>&date=YYYY-MM-DD&formatId=1`
  (Legacy, eine Woche je Aufruf; `elements[{type,id,orgId}]`, type 2 = Lehrer,
  3 = Fach; bei Vertretung steht die reguläre Lehrkraft in `orgId`).
- **Vorgehen bei neuer Instanz/Funktion: Sondierung zuerst!** Ein Admin-Werkzeug
  bauen, das Kandidaten-Endpunkte mit echter Session abklopft (Status,
  JSON-Schlüssel, `validationErrors` im Klartext, Roh-Beispiel-Eintrag) –
  erst auf Basis des Berichts den Adapter implementieren.
- Empfehlung: JSON-RPC als Standard für Login/Stammdaten, REST als zweites
  Standbein für Stundenplan-Ableitungen; im UI klar als „Beta" kennzeichnen
  und sauber auf RPC zurückfallen können.

### 6c. Untis-Export als dritter Weg

`GPU001.TXT` (Unterrichtsdatei aus Untis-Desktop) enthält die geplante
Unterrichtsverteilung ohne Vertretungsgeschehen – als Import-Fallback und
Referenz zur Validierung der API-Wege einplanen.

## 7. Erwartete Arbeitsweise von Claude

- **Recherche zuerst** (offizielle Quellen/PDFs; bei undokumentierten APIs:
  Sondierung gegen die echte Instanz), erst dann Deliverables bauen.
- Bei jeder Änderung eine **Zip mit Zeitstempel** bereitstellen
  (`«projektname»_«thema»_YYYYMMDD_HHMMSS.zip`, Dateien mit Pfadstruktur ab
  `«projektname»/…`), zusätzlich Kopie in `bundles/` (aus git ausgeschlossen).
  **Zips immer in `~/Projekte` entpacken** (eine Ebene ÜBER dem Projektordner),
  sonst entsteht ein verschachtelter Ordner und der Deploy fährt alten Stand.
- **Deploy-Befehl** + separate **DB-Schritte** immer explizit angeben.
- **Vor jeder Auslieferung automatisiert verifizieren:** PHP-Lint aller Dateien,
  `node --check` fürs JS, jede SQL-Datei **zweimal** gegen MariaDB einspielen
  (Idempotenz-Beweis), Rechenlogik als reine Funktionen mit Unit-Tests,
  Integrationstests gegen den laufenden built-in Server (Login, Rollen/Guards,
  Kern-Workflows). Testskripte mitwachsen lassen; ein roter Test stoppt die
  Auslieferung.
- Generierte Massendaten (SQL-Seeds) vor Auslieferung **verifizieren**.
- Vorlage-Repos: `hornse/schulprojekt-template` (Projektstart),
  `hornse/webuntis-client-php` (WebUntis), `hornse/fachkonferenzen`
  (Referenz-Implementierung dieser Vorlage).

---

*Stand: 18. Juli 2026 – konsolidiert aus „Projektstunden NRW" und
„Fachkonferenzen FRG" (v0.7.1).*
