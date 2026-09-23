-- Verwaltungsanfragen aus dem Angebotsformular (MP 6.3), inklusive Felder für den Import der Alttabelle "Properties".
CREATE TABLE leads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

    management_form ENUM('weg', 'miet', 'se') NOT NULL,

    contact_salutation VARCHAR(20) NULL,
    contact_first_name VARCHAR(100) NULL,
    contact_last_name VARCHAR(100) NULL,
    contact_email VARCHAR(254) NULL COMMENT 'Pflicht im Formular, NULL nur für Altdaten',
    contact_phone VARCHAR(40) NULL,
    contact_role ENUM('eigentuemer', 'beirat', 'investor', 'sonstige') NULL,
    contact_street VARCHAR(150) NULL,
    contact_zip VARCHAR(10) NULL,
    contact_city VARCHAR(100) NULL,

    object_street VARCHAR(150) NULL,
    object_zip VARCHAR(10) NULL,
    object_city VARCHAR(100) NULL,
    year_of_construction SMALLINT UNSIGNED NULL,
    units_residential SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    units_commercial SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    units_parking SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    management_start DATE NULL COMMENT 'gewünschter Verwaltungsbeginn, Monatserster',
    has_current_manager TINYINT(1) NULL,
    message TEXT NULL,

    price_tier_residential_id INT UNSIGNED NULL,
    price_tier_commercial_id INT UNSIGNED NULL,
    price_tier_parking_id INT UNSIGNED NULL,

    source VARCHAR(50) NULL,
    utm_source VARCHAR(150) NULL,
    utm_medium VARCHAR(150) NULL,
    utm_campaign VARCHAR(150) NULL,
    utm_term VARCHAR(150) NULL,
    utm_content VARCHAR(150) NULL,
    landing_page VARCHAR(255) NULL COMMENT 'nur Pfad, ohne Query',
    referrer VARCHAR(255) NULL COMMENT 'nur Host und Pfad, ohne Query',
    region VARCHAR(100) NULL COMMENT 'Betreuungsgebiet aus der Vorbelegung (region=...)',

    consent_text_version VARCHAR(40) NULL,
    consent_at DATETIME NULL COMMENT 'UTC',

    status ENUM('neu', 'kontaktiert', 'angebot', 'gewonnen', 'verloren', 'spam') NOT NULL DEFAULT 'neu',
    assigned_to INT UNSIGNED NULL,
    notes TEXT NULL,

    legacy_id INT UNSIGNED NULL COMMENT 'ID aus der Alttabelle Properties',

    PRIMARY KEY (id),
    UNIQUE KEY uq_leads_uuid (uuid),
    UNIQUE KEY uq_leads_legacy_id (legacy_id),
    KEY idx_leads_status (status, created_at),
    KEY idx_leads_management_form (management_form, created_at),
    KEY idx_leads_object_zip (object_zip),
    KEY idx_leads_source (source, created_at),
    KEY idx_leads_created_at (created_at),
    KEY idx_leads_assigned_to (assigned_to),
    CONSTRAINT fk_leads_tier_residential FOREIGN KEY (price_tier_residential_id) REFERENCES price_tiers (id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_tier_commercial FOREIGN KEY (price_tier_commercial_id) REFERENCES price_tiers (id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_tier_parking FOREIGN KEY (price_tier_parking_id) REFERENCES price_tiers (id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_assigned_to FOREIGN KEY (assigned_to) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
