# Fachkonferenzen FRG

Konfliktfreie Planung von Fachkonferenzen am Friedrich-Rückert-Gymnasium
Düsseldorf. Zwei Konferenzen dürfen nicht zeitgleich stattfinden, wenn
mindestens eine Lehrkraft beide Fächer unterrichtet.

- **Domain:** https://fachkonferenzen.hornse.de (Port 8084)
- **Stack:** PHP 8.1+ (kein Framework), Vanilla JS, MariaDB – Vorlage
  „Projektstunden NRW"
- **Daten:** Lehrer-Fach-Zuordnung per WebUntis-Sync (aus dem Stundenplan
  abgeleitet), Fallback CSV, manuelle Ergänzungen
- **Algorithmus:** Graphfärbung (DSATUR-Heuristik) über den Konfliktgraphen

Installation und Betrieb: siehe `docs/INSTALL.md`.
