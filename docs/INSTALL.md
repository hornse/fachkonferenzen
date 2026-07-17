# INSTALL.md – Fachkonferenzen FRG

Einrichtung auf Uberspace (halimede, Account `hornse`) und lokal.
Feste Werte: Domain `fachkonferenzen.hornse.de`, Port **8084**,
DB `hornse_fachkonferenzen`, Work-Tree `/home/hornse/fachkonferenzen`.

---

## 1. Lokal

```bash
cd /Users/sebastianhorn/Projekte
mkdir fachkonferenzen && cd fachkonferenzen
# Grundgerüst-Zip hier hinein entpacken, dann:
git init -b main
git add -A && git commit -m "Grundgerüst v0.1.0"
```

GitHub-Repo anlegen (privat) und Remote **github** nennen:

```bash
gh repo create hornse/fachkonferenzen --private --source=. --remote=github
# oder in IntelliJ: Git -> GitHub -> Share Project on GitHub (Private),
# danach Git -> Manage Remotes -> "origin" in "github" umbenennen
```

## 2. Server: Repo, Hook, DB

```bash
ssh hornse@halimede.uberspace.de

git init --bare ~/repos/fachkonferenzen.git
mkdir -p ~/fachkonferenzen

cat > ~/repos/fachkonferenzen.git/hooks/post-receive << 'EOF'
#!/bin/sh
GIT_WORK_TREE=/home/hornse/fachkonferenzen git checkout -f main
EOF
chmod +x ~/repos/fachkonferenzen.git/hooks/post-receive

mysql -e "CREATE DATABASE IF NOT EXISTS hornse_fachkonferenzen"
ls -d ~/tmp/sessions   # muss existieren (kommt von Projektstunden)
```

## 3. Server: Service, Domain, Backend

```bash
cat > ~/etc/services.d/fachkonferenzen.ini << 'EOF'
[program:fachkonferenzen]
command=php -S 0.0.0.0:8084 /home/hornse/fachkonferenzen/backend/router.php
autostart=true
autorestart=true
EOF
supervisorctl reread && supervisorctl update

uberspace web domain add fachkonferenzen.hornse.de
uberspace web backend set fachkonferenzen.hornse.de/ --http --port 8084
```

DNS: Falls kein Wildcard-Record existiert, beim DNS-Anbieter A- und
AAAA-Record für `fachkonferenzen` mit den IPs aus `uberspace web domain add`
anlegen. Zertifikat holt Uberspace automatisch.

## 4. Erster Deploy

Lokal:

```bash
git remote add uberspace hornse@halimede.uberspace.de:repos/fachkonferenzen.git
./deploy.sh "Initiales Grundgerüst"
```

Auf dem Server (macht deploy.sh bewusst NICHT):

```bash
cp ~/fachkonferenzen/backend/config.example.php ~/fachkonferenzen/backend/config.php
nano ~/fachkonferenzen/backend/config.php     # DB-Passwort aus ~/.my.cnf

mysql hornse_fachkonferenzen < ~/fachkonferenzen/sql/01_schema.sql
mysql hornse_fachkonferenzen < ~/fachkonferenzen/sql/02_seed.sql
mysql hornse_fachkonferenzen < ~/fachkonferenzen/sql/03_migration_fach_vorgaben.sql

supervisorctl restart fachkonferenzen
supervisorctl status fachkonferenzen          # muss RUNNING sein
```

Optional lokaler Admin-Notzugang (unabhängig von WebUntis):

```bash
php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
mysql hornse_fachkonferenzen -e "INSERT INTO benutzer
  (typ, email, passwort_hash, kuerzel, name, rolle)
  VALUES ('lokal','admin@frg-duesseldorf.de','HASH_HIER','Hor','Sebastian Horn','admin')"
```

## 5. Funktionstest

1. `https://fachkonferenzen.hornse.de` → Login-Seite erscheint
2. WebUntis-Login mit Lehrerkonto (Kürzel `Hor` → Admin-Rolle)
3. WebUntis-Sync ausführen (Vorschau → Übernehmen)
4. Stammdaten → Fächer: Fachgruppen zuordnen (Religion, Diff-Angebote)
5. Planung anlegen → Slots → „Alle Fächer & Gruppen anlegen" → Berechnen

Bei Fehlern: `supervisorctl tail fachkonferenzen stderr | tail -20`
(`<!DOCTYPE` statt JSON im Frontend = PHP-500, dort nachsehen).

---

## Betrieb

### WebUntis-Sync

Der Sync leitet die Lehrer-Fach-Zuordnung aus dem **Stundenplan** ab
(je Fach ein `getTimetable(type=3)`-Aufruf über den gewählten Zeitraum).
Empfohlen: zwei normale Unterrichtswochen ohne Ferien. Wichtig:

- Der Sync erfasst nur, wer ein Fach **aktuell unterrichtet**. Facultas ohne
  Unterricht im Zeitraum → manuell ergänzen (Stammdaten → Zuordnungen),
  dort ggf. **Gesperrt** setzen, dann fasst der Sync den Eintrag nie an.
- Quellen `manuell` und `csv` werden vom Sync grundsätzlich nicht entfernt.
- Zugangsdaten werden nur für den Abruf verwendet, nie gespeichert.
- **Beim ersten Lauf gegen die echte Instanz verifizieren** (Vorschau!):
  Stimmen die Zahlen mit dem Kollegium überein? Die Instanz
  frg-dusseldorf hatte schon API-Eigenheiten (vgl. Projektstunden).

### CSV-Fallback

Stammdaten → Zuordnungen → CSV-Import. Format je Zeile: `Kürzel;Fachkürzel`
(auch `,` oder Tab als Trenner, `#` beginnt Kommentarzeilen). Lehrkraft und
Fach müssen bereits als Stammdaten existieren.

### Rollen

| Rolle | Rechte |
|---|---|
| `admin` | alles: Stammdaten, Sync, Planungen, Veröffentlichen |
| `lehrkraft` | nur veröffentlichte Termine der eigenen Fächer + iCal-Abo |

Admin wird, wer sich mit personType 16 anmeldet **oder** dessen Kürzel in
`config.php` unter `admin_kuerzel` steht.

### API-Routen (Auszug)

```
POST /api/auth/login            {quelle: webuntis|lokal, ...}
GET  /api/auth/me
POST /api/sync/webuntis         {benutzername, passwort, von, bis, modus}
POST /api/import/csv            {csv}
GET/POST/PATCH  /api/faecher, /api/lehrer, /api/raeume, /api/fachgruppen
GET/POST/PATCH/DELETE /api/lehrer-fach
GET/POST /api/planungen         PATCH/DELETE /api/planungen/{id}
POST /api/planungen/{id}/slots        PATCH/DELETE .../slots/{sid}
POST /api/planungen/{id}/konferenzen  PATCH/DELETE .../konferenzen/{kid}
GET  /api/planungen/{id}/konflikte
POST /api/planungen/{id}/berechnen    {alles_neu, raeume_vorschlagen}
GET  /api/meine-termine
GET  /api/mein-ical
GET  /api/ical/persoenlich/{token}.ics
GET  /api/ical/planung/{id}.ics       (nur veröffentlichte)
```
