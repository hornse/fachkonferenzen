# Projektgedächtnis: fachkonferenzen

Diese Datei wird von Claude Code bei **jedem** Sessionstart automatisch
gelesen. Sie liegt im Repo und wird per Push geteilt. Was hier steht,
muss nicht mehr erklärt werden.

Nicht hier hinein gehören Tagesaufgaben. Hier stehen Dinge, die in sechs
Monaten noch gelten sollen.

Ergänzend:
- Regeln der Reihe: @REIHENREGELN.md
- Fallstricke PHP/Router/WebUntis: @FALLSTRICKE.md

---

## Was hier nicht steht

**Die Regeln der Reihe stehen in `REIHENREGELN.md`**, die technischen
Fallstricke in `FALLSTRICKE.md`. Beide sind vendorte Kopien aus
`hornse/koordination` und oben importiert — sie gelten hier, ohne dass
diese Datei sie wiederholt. Wer sie ändern will, ändert die Quelle und
verteilt neu; der Bestandslauf misst die Kopien.

In diese Datei gehört nur, was **dieses Projekt** ausmacht.

**Was über die WebUntis-API gemessen ist, liegt verstreut.** Die Liste
aller Fundstellen steht in
`~/Projekte/koordination/docs/BEFUND-2026-10-11-webuntis-api-bestand.md`
— 846 Stellen in zehn Repos, Stand 11.10.2026. Sie sagt, **wo** etwas
steht, nicht was gilt. **Vor jedem neuen Aufruf dort nachsehen**, nicht
nur im eigenen Repo.

**Die Fundstellen widersprechen sich** (Abschnitt 4 der Liste), darunter:
Die Felder von `getStudents` werden in mehreren Fassungen beschrieben;
für den Mitteilungsversand nennen Modul-Doku und Modul-Code verschiedene
Wege; die Schülerzahl aus `getStudents` steht je nach Datum bei 3563,
3782 oder 3802. **Und keine Messung trägt die WebUntis-Version** — was
vor Wochen gemessen wurde, kann ein Update überholt haben.

## Der Stack

Ermittelt aus dem Repo, nicht aus dem Gedächtnis.

| | |
|---|---|
| Domain | `fachkonferenzen.hornse.de` |
| Dienst | PHP built-in Server via supervisord, **Port 8084** |
| Backend | PHP 8.1+, eigener Router in `backend/router.php` |
| Datenbank | MariaDB über PDO (`mysql:host=…;charset=utf8mb4`) |
| Frontend | Vanilla JS, HTML, CSS — kein Build-Schritt |
| Gerüst | `ci-huelle ci-huelle--kopf` aus `hornse/ci-css` |
| Module | `ci-css` unter `frontend/vendor/ci-css/`, WebUntis unter `backend/auth/` |
| Auslieferung | `deploy.sh` pusht auf `github` **und** `uberspace` |
| Testskript | `tests-fachkonferenzen.sh` |

**Nicht ermittelbar und deshalb nicht eingetragen:** die Lizenz. Es gibt
keine `LICENSE`-Datei und keine Lizenzangabe im `README.md`. Die übrigen
Projekte der Reihe stehen unter GPL-3.0-or-later; hier steht es nirgends,
und Raten wäre bei einer Lizenz das Falscheste.
