-- ============================================================
-- 03_migration_fach_vorgaben.sql – Vorgaben-Regelwerk + Archiv
-- Idempotent. Einspielen:
--   mysql hornse_fachkonferenzen < sql/03_migration_fach_vorgaben.sql
-- ============================================================

SET NAMES utf8mb4;

-- Regelwerk: gewünschte Fächer-Konfiguration. kuerzel darf mit *
-- enden (Präfix-Muster, z. B. 'LZ*'). Exakter Treffer schlägt
-- Muster; bei mehreren Mustern gewinnt das längste.
CREATE TABLE IF NOT EXISTS fach_vorgaben (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kuerzel     VARCHAR(50)  NOT NULL COMMENT 'Fachkürzel oder Präfix-Muster mit *',
    aktiv       TINYINT(1)   NOT NULL DEFAULT 1,
    fachgruppe  VARCHAR(100) NULL COMMENT 'NULL = eigene Konferenz (bzw. inaktiv)',
    aktualisiert TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fach_vorgaben_kuerzel (kuerzel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schnappschüsse der Fächer-Konfiguration (Verlauf, Wiederherstellen)
CREATE TABLE IF NOT EXISTS konfig_archiv (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    zeitpunkt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    kuerzel   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Admin-Kürzel',
    grund     VARCHAR(100) NOT NULL DEFAULT '',
    daten     MEDIUMTEXT   NOT NULL COMMENT 'JSON: [{kuerzel,aktiv,fachgruppe}]',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
