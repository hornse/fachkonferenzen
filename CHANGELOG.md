# Changelog – Fachkonferenzen FRG

## v0.2.2-beta (Juli 2026) – Sondierung: Format-Varianten

- Sondierungsbefund: entries scheitert an format=2 („Timetable format not
  found"). Neue Proben: format=1, format=4, ohne format, sowie resources
  als Komma-Liste (Batch-Test für Alle-Lehrkräfte-in-einem-Aufruf)

## v0.2.1-beta (Juli 2026) – REST-Beta auf weekly/data umgestellt

- Sondierungsbefund frg-dusseldorf: timetable/entries -> 404 (validationErrors),
  weekly/data -> 200. Beta-Sync nutzt jetzt /api/public/timetable/weekly/data
  je Lehrkraft und Woche; Paare direkt über WebUntis-IDs, Vertretungen werden
  auf die reguläre Lehrkraft (orgId) zurückgeführt, Perioden ohne Fach ignoriert
- Sondierung erweitert: validationErrors im Klartext, entries-Parametervarianten,
  weekly-Paarzählung mit aufgelösten Kürzeln

## v0.2.0-beta (Juli 2026) – Interne REST-API (experimentell)

- REST-Sondierung: Admin-Werkzeug auf der Sync-Seite, das die interne
  REST-API der eigenen Instanz abklopft (token/new, app/data,
  timetable/entries je TEACHER/SUBJECT, timetable/filter, weekly/data)
  und einen kopierbaren Bericht liefert – schreibt nichts
- Neuer Sync-Modus „Beta: interne REST-API": Zuordnungen über
  /api/rest/view/v1/timetable/entries je Lehrkraft, defensiver Extraktor
  (position1/position2, ignoriert removed-Einträge), sauberer Abbruch
  mit Hinweis, falls die Instanz anders antwortet
- Standard bleibt JSON-RPC; Beta ist explizit als experimentell markiert

## v0.1.3 (Juli 2026) – Sync robust gegen doppelte Kürzel

- Fix: Abbruch „Duplicate entry (B)" beim Übernehmen – doppelte Kürzel aus
  WebUntis (Fächer, Lehrkräfte, Räume) werden jetzt auf denselben Datensatz
  zusammengeführt, inkl. Groß-/Kleinschreibungs-Kollisionen
- Zusammengeführte Kürzel werden im Sync-Ergebnis angezeigt
- Sync-Logik nach backend/api/sync.php ausgelagert und mit eigener
  Testsuite abgedeckt (Duplikate, Idempotenz, Schutz manueller Einträge)

## v0.1.2 (Juli 2026) – Sync-Übernahme aus Vorschau-Zwischenspeicher

- Vorschau speichert die abgerufenen WebUntis-Daten serverseitig (15 Min.)
- „Übernehmen" schreibt aus dem Zwischenspeicher: sekundenschnell, kein
  zweiter Abruf, kein Proxy-Timeout mehr; Fallback auf Live-Abruf bleibt
- Sync läuft bei Verbindungsabbruch serverseitig zu Ende (ignore_user_abort)

## v0.1.1 (Juli 2026) – Sync-Feinschliff

- Sync-Vorschau zeigt Zuordnungen zu noch nicht angelegten Stammdaten separat an
- Fächer-Tab: Suche, Filter „nur aktive", Massenaktionen aktivieren/deaktivieren
- Lehrkräfte-Tab: Suche + Massenaktionen (Dummy-Konten schnell deaktivieren)
- Neue Endpunkte: POST /api/faecher/bulk-aktiv, POST /api/lehrer/bulk-aktiv

## v0.1.0 (Juli 2026) – Grundgerüst

- Projektstruktur nach Vorlage (router.php, API-Router, SPA, nummerierte SQL)
- Auth: WebUntis (personType 2 Lehrkraft, 16 Admin) + lokales E-Mail/Passwort
- Stammdaten: Lehrkräfte, Fächer, Fachgruppen, Räume, Lehrer-Fach-Zuordnung
  (Quellen webuntis/csv/manuell, Sperr-Flag gegen Sync-Überschreiben)
- WebUntis-Sync: getTeachers/getSubjects/getRooms + Zuordnung aus
  getTimetable(type=3) je Fach, mit Vorschau/Übernehmen und Protokoll
- CSV-Import als Fallback (Kürzel;Fachkürzel)
- Planungen (Pädagogischer Tag / Zeitraum) mit Slots und Konferenzen
- Konfliktprüfung (zeitliche Slot-Überlappung + gemeinsame Lehrkräfte)
- Automatische Berechnung (DSATUR-Heuristik; paed_tag packt, zeitraum verteilt;
  fixierte Zuweisungen bleiben erhalten; optionale Raumvorschläge)
- Lehrkräfte-Sicht: nur veröffentlichte Termine der eigenen Fächer
- iCal: persönlicher Abo-Feed (Token) + Feed je veröffentlichter Planung
