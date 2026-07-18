# SICHERHEIT.md – Datenschutz & Sicherheit

Stand: Juli 2026 (Audit zu v0.8.0). Dieses Dokument hält fest, welche Daten
wo liegen, was bewusst öffentlich ist und wie der Sicherheits-Check
wiederholt wird.

## Grundsätze

1. **Zugangsdaten werden nie gespeichert oder geloggt.** WebUntis-Benutzername/
   -Passwort werden ausschließlich für den jeweiligen Abruf (Login, Sync,
   Sondierung) verwendet und danach verworfen. Das Sync-Protokoll enthält nur
   Statistik-JSON, `error_log` nur Exception-Texte ohne Credentials.
2. **`backend/config.php` liegt nie in git** (.gitignore) – dort stehen
   DB-Passwort und Admin-Kürzel. In git liegt nur `config.example.php`
   mit Platzhaltern.
3. **Sessions**: Cookie mit `Secure`, `HttpOnly`, `SameSite=Lax`;
   `session_regenerate_id()` bei jedem Login.
4. **SQL** ausschließlich über PDO-Prepared-Statements; HTML-Ausgaben im
   Frontend über die Escaping-Funktion `q()`.
5. **Rollen**: Alle Admin-Routen hinter `require_admin()`; Lehrkräfte sehen
   nur veröffentlichte Termine der eigenen Fächer.

## Bewusst öffentlich (ohne Login erreichbar)

| Was | Warum | Enthält |
|---|---|---|
| `#/plan` + `GET /api/oeffentlich/plan` | Terminaushang für Kollegium/Sekretariat ohne Login-Hürde | Nur **veröffentlichte** Planungen: Konferenzname, Datum, Uhrzeit, Raum, Schiene. **Keine Lehrkräfte-Daten** (keine Kürzel, Namen, Teilnehmerzahlen, Zuordnungen). |
| `GET /api/ical/planung/{id}.ics` | Kalender-Abo/Download je Planung | Dieselben Felder; nur bei Status „veröffentlicht", sonst 404. |
| `GET /api/ical/persoenlich/{token}.ics` | Persönliches Kalender-Abo | Termine der eigenen Fächer; Zugriff nur mit 160-Bit-Zufallstoken aus dem eigenen Login. |

Entwürfe sind nirgends öffentlich sichtbar.

## Was liegt in git (privates Repo)?

- Quellcode, SQL-Schemata/Migrationen, Doku – **keine Personendaten**
  (alle Lehrer-/Fächer-Daten entstehen erst zur Laufzeit in der DB).
- `deploy.sh` und `docs/INSTALL.md` nennen Server (`halimede`), Account
  (`hornse`) und Pfade. Für ein **privates** Repo in Ordnung (Betriebsdoku);
  vor einer etwaigen Veröffentlichung des Repos durch Platzhalter ersetzen.
- `config.example.php` nennt die WebUntis-Instanz-URL (öffentlich bekannt,
  auf der Schulwebsite verlinkt) – Admin-Kürzel und Passwörter nur als
  Platzhalter.

## Wiederkehrender Check (vor jedem Push mit neuen Dateien)

```bash
# 1. Ist config.php wirklich untracked?
git check-ignore -v backend/config.php    # muss die .gitignore-Regel zeigen

# 2. Geheimnisse in getrackten Dateien?
git grep -niE "passwor[dt]\s*['\"]?\s*=>?\s*['\"][^'\"]{4,}" -- ':!*.md' | grep -v HIER_DB

# 3. Wurde je ein Geheimnis committet? (durchsucht die GESAMTE Historie)
git log -p -S "HIER_DB_PASSWORT" --oneline | head        # Platzhalter ok
git log --all -p -- backend/config.php | head            # muss LEER sein

# 4. Session-/Upload-Reste?
git status --ignored | head
```

Falls Schritt 3 jemals einen echten Treffer zeigt: Passwort **sofort ändern**
(DB via Uberspace, WebUntis in der Benutzerverwaltung) – Historie-Rewriting
allein genügt nicht, das Geheimnis gilt als kompromittiert.

## Serverseitig

- DB-Passwort nur in `~/.my.cnf` und `backend/config.php` (Rechte 600 empfohlen:
  `chmod 600 ~/fachkonferenzen/backend/config.php`).
- Log-Einsicht: `supervisorctl tail fachkonferenzen stderr` – enthält keine
  Credentials (siehe Grundsatz 1).
