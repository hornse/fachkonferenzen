-- ============================================================
-- 01_schema.sql – Fachkonferenzen FRG
-- MariaDB 10.6+, idempotent (IF NOT EXISTS)
-- Einspielen: mysql hornse_fachkonferenzen < sql/01_schema.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fachgruppen (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name    VARCHAR(100) NOT NULL COMMENT 'Fächer, die gemeinsam tagen (z. B. Religion ev./kath.)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_fachgruppen_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lehrer (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kuerzel     VARCHAR(20)  NOT NULL,
    vorname     VARCHAR(100) NOT NULL DEFAULT '',
    nachname    VARCHAR(100) NOT NULL DEFAULT '',
    webuntis_id INT          NULL COMMENT 'personId aus WebUntis (getTeachers)',
    ical_token  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Token für persönlichen Kalender-Feed',
    aktiv       TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lehrer_kuerzel (kuerzel),
    KEY idx_lehrer_webuntis (webuntis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faecher (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kuerzel     VARCHAR(20)  NOT NULL COMMENT 'WebUntis-Kürzel, z. B. M, D, BI',
    name        VARCHAR(100) NOT NULL,
    webuntis_id INT          NULL,
    gruppe_id   INT UNSIGNED NULL COMMENT 'Optionale Fachgruppe (gemeinsame Konferenz)',
    aktiv       TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faecher_kuerzel (kuerzel),
    KEY idx_faecher_webuntis (webuntis_id),
    CONSTRAINT fk_faecher_gruppe FOREIGN KEY (gruppe_id)
        REFERENCES fachgruppen (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lehrer_fach (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lehrer_id INT UNSIGNED NOT NULL,
    fach_id   INT UNSIGNED NOT NULL,
    quelle    ENUM('webuntis','csv','manuell') NOT NULL DEFAULT 'manuell',
    gesperrt  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = Sync darf diesen Eintrag nie entfernen',
    PRIMARY KEY (id),
    UNIQUE KEY uq_lehrer_fach (lehrer_id, fach_id),
    CONSTRAINT fk_lf_lehrer FOREIGN KEY (lehrer_id) REFERENCES lehrer (id) ON DELETE CASCADE,
    CONSTRAINT fk_lf_fach   FOREIGN KEY (fach_id)   REFERENCES faecher (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raeume (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kuerzel     VARCHAR(20)  NOT NULL,
    name        VARCHAR(100) NOT NULL DEFAULT '',
    webuntis_id INT          NULL,
    aktiv       TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_raeume_kuerzel (kuerzel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS benutzer (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    typ           ENUM('lokal','webuntis') NOT NULL DEFAULT 'lokal',
    email         VARCHAR(190) NULL,
    passwort_hash VARCHAR(255) NULL,
    kuerzel       VARCHAR(20)  NULL,
    name          VARCHAR(150) NOT NULL DEFAULT '',
    rolle         ENUM('admin','lehrkraft') NOT NULL DEFAULT 'lehrkraft',
    lehrer_id     INT UNSIGNED NULL,
    aktiv         TINYINT(1)   NOT NULL DEFAULT 1,
    erstellt_am   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_benutzer_email (email),
    CONSTRAINT fk_benutzer_lehrer FOREIGN KEY (lehrer_id)
        REFERENCES lehrer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planungen (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titel       VARCHAR(150) NOT NULL,
    typ         ENUM('paed_tag','zeitraum') NOT NULL DEFAULT 'zeitraum',
    schuljahr   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'z. B. 2026/27',
    status      ENUM('entwurf','veroeffentlicht') NOT NULL DEFAULT 'entwurf',
    erstellt_am TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slots (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    planung_id  INT UNSIGNED NOT NULL,
    datum       DATE         NOT NULL,
    beginn      TIME         NOT NULL,
    ende        TIME         NOT NULL,
    bezeichnung VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'z. B. Schiene 1',
    PRIMARY KEY (id),
    KEY idx_slots_planung (planung_id),
    CONSTRAINT fk_slots_planung FOREIGN KEY (planung_id)
        REFERENCES planungen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS konferenzen (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    planung_id INT UNSIGNED NOT NULL,
    fach_id    INT UNSIGNED NULL,
    gruppe_id  INT UNSIGNED NULL,
    slot_id    INT UNSIGNED NULL COMMENT 'NULL = noch nicht eingeplant',
    raum_id    INT UNSIGNED NULL,
    notiz      VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_konferenzen_planung (planung_id),
    CONSTRAINT fk_konf_planung FOREIGN KEY (planung_id) REFERENCES planungen  (id) ON DELETE CASCADE,
    CONSTRAINT fk_konf_fach    FOREIGN KEY (fach_id)    REFERENCES faecher    (id) ON DELETE CASCADE,
    CONSTRAINT fk_konf_gruppe  FOREIGN KEY (gruppe_id)  REFERENCES fachgruppen(id) ON DELETE CASCADE,
    CONSTRAINT fk_konf_slot    FOREIGN KEY (slot_id)    REFERENCES slots      (id) ON DELETE SET NULL,
    CONSTRAINT fk_konf_raum    FOREIGN KEY (raum_id)    REFERENCES raeume     (id) ON DELETE SET NULL,
    CONSTRAINT chk_konf_einheit CHECK ((fach_id IS NULL) != (gruppe_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_protokoll (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    zeitpunkt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    kuerzel   VARCHAR(20)  NOT NULL DEFAULT '',
    aktion    VARCHAR(50)  NOT NULL,
    details   TEXT         NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
