# Changelog – Fachkonferenzen FRG

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
