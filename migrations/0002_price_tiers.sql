-- Preisstaffeln für die unverbindliche Preisindikation (MP 6.2).
-- Bleibt leer, bis ein Export der Altdatenbank oder eine Vorgabe der Geschäftsführung vorliegt. Keine Beispielpreise.
CREATE TABLE price_tiers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    management_form ENUM('weg', 'miet', 'se') NOT NULL,
    unit_type ENUM('residential', 'commercial', 'parking') NOT NULL,
    units_from SMALLINT UNSIGNED NOT NULL,
    units_to SMALLINT UNSIGNED NULL COMMENT 'NULL = ohne Obergrenze',
    price_net_per_unit_month DECIMAL(10,2) NOT NULL,
    valid_from DATE NOT NULL,
    legacy_id INT UNSIGNED NULL COMMENT 'ID der Preisstufe in der Altdatenbank',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (id),
    KEY idx_price_tiers_lookup (management_form, unit_type, valid_from, units_from),
    KEY idx_price_tiers_legacy (legacy_id),
    CONSTRAINT chk_price_tiers_range CHECK (units_to IS NULL OR units_to >= units_from),
    CONSTRAINT chk_price_tiers_price CHECK (price_net_per_unit_month >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
