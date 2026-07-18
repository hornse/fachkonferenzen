-- ============================================================
-- 05_migration_zuordnung_stunden.sql
-- Perioden-Zähler je Zuordnung (Signal: Facultas vs. Vertretung)
-- Idempotent. Einspielen:
--   mysql hornse_fachkonferenzen < sql/05_migration_zuordnung_stunden.sql
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE lehrer_fach
    ADD COLUMN IF NOT EXISTS stunden INT NULL
        COMMENT 'Anzahl Perioden im letzten Sync-Zeitraum (NULL = unbekannt/manuell)'
        AFTER ausgeschlossen;
