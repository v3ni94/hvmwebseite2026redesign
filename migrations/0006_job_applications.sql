-- Bewerbungen: nur Metadaten. Die Datei liegt verschlüsselt außerhalb des Webroots (storage/uploads),
-- datei_pfad ist der verschlüsselt abgelegte, relative Speicherort (MP 6.3, MP 10).
CREATE TABLE job_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    stelle VARCHAR(150) NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    telefon VARCHAR(40) NULL,
    nachricht TEXT NULL,
    datei_pfad VARCHAR(500) NULL COMMENT 'verschlüsselt (libsodium, APP_KEY)',
    datei_mime VARCHAR(100) NULL,
    datei_groesse INT UNSIGNED NULL COMMENT 'Bytes',
    consent_text_version VARCHAR(40) NULL,
    consent_at DATETIME NULL COMMENT 'UTC',
    status ENUM('neu', 'in_bearbeitung', 'abgeschlossen', 'spam') NOT NULL DEFAULT 'neu',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_applications_uuid (uuid),
    KEY idx_job_applications_status (status, created_at),
    KEY idx_job_applications_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
