-- ============================================================
-- 04_migration_zuordnung_ausschluss.sql
-- Ausschluss-Kennzeichen für Lehrer-Fach-Zuordnungen
-- (z. B. Vertretungsunterricht, der keine Fachschafts-
-- Mitgliedschaft bedeutet). Idempotent. Einspielen:
--   mysql hornse_fachkonferenzen < sql/04_migration_zuordnung_ausschluss.sql
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE lehrer_fach
    ADD COLUMN IF NOT EXISTS ausgeschlossen TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = zählt nirgends mit; Sperrvermerk gegen erneutes Anlegen durch Sync'
        AFTER gesperrt;
