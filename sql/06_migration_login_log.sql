-- ============================================================
-- 06_migration_login_log.sql
-- Protokoll der Anmeldeversuche — Grundlage der Brute-Force-Bremse.
-- Idempotent. Einspielen:
--   mysql hornse_fachkonferenzen < sql/06_migration_login_log.sql
--
-- MUSS VOR ODER MIT DEM ZUGEHOERIGEN DEPLOY EINGESPIELT WERDEN.
-- Die Bremse scheitert geschlossen: Fehlt die Tabelle, weist sie jede
-- Anmeldung ab. Das ist die richtige Richtung, aber es sperrt alle aus,
-- solange die Tabelle fehlt.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS login_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    benutzername VARCHAR(190) NOT NULL,
    erfolg       TINYINT(1)   NOT NULL DEFAULT 0,
    grund        VARCHAR(60)  NULL COMMENT 'nur bei erfolg = 0',
    ip           VARCHAR(45)  NOT NULL DEFAULT '',
    zeitpunkt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_benutzer_zeit (benutzername, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Anmeldeversuche. Der Grund steht hier, nicht in der Antwort.';
