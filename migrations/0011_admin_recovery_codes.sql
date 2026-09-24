-- Wiederherstellungscodes für die TOTP-Anmeldung im Admin-Bereich (bin/admin-user.php recovery-codes).
-- Je Konto zehn Einmalcodes, gespeichert nur als HMAC-SHA256 (Schlüssel aus APP_KEY abgeleitet), nie im Klartext.
-- used_at und used_ip_hash protokollieren den Verbrauch, ein verbrauchter Code bleibt als Nachweis stehen.
CREATE TABLE admin_recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL COMMENT 'UTC',
    used_at DATETIME NULL COMMENT 'UTC, Code verbraucht',
    used_ip_hash CHAR(64) NULL COMMENT 'HMAC der IP beim Verbrauch',
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_recovery_codes_code (admin_user_id, code_hash),
    CONSTRAINT fk_admin_recovery_codes_user FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
