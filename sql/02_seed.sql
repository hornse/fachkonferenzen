-- ============================================================
-- 02_seed.sql – Grunddaten (idempotent via INSERT IGNORE)
-- Einspielen: mysql hornse_fachkonferenzen < sql/02_seed.sql
-- ============================================================

SET NAMES utf8mb4;

-- Fachgruppen: Fächer, die als EINE Konferenz tagen.
-- Die Zuordnung Fach -> Gruppe erfolgt nach dem ersten WebUntis-Sync
-- in der App (Stammdaten -> Fächer), weil die WebUntis-Fachkürzel
-- erst dann bekannt sind.
INSERT IGNORE INTO fachgruppen (name) VALUES
    ('Religion / Praktische Philosophie'),
    ('Differenzierungsangebote');

-- ------------------------------------------------------------
-- Lokaler Admin-Notzugang (unabhängig von WebUntis).
-- Hash VOR dem Einspielen erzeugen:
--   php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
-- und unten eintragen, dann Kommentarzeichen entfernen:
--
-- INSERT IGNORE INTO benutzer (typ, email, passwort_hash, kuerzel, name, rolle)
-- VALUES ('lokal', 'admin@frg-duesseldorf.de', '$2y$10$HIER_DEN_HASH',
--         'Hor', 'Sebastian Horn', 'admin');
