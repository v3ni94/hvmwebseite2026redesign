-- Benutzer des Admin-Bereichs (MP 6.5). Passwort als password_hash, TOTP-Geheimnis verschlüsselt (libsodium, APP_KEY).
CREATE TABLE admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    totp_secret VARCHAR(255) NULL COMMENT 'verschlüsselt, nie im Klartext',
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL COMMENT 'UTC',
    last_login_at DATETIME NULL COMMENT 'UTC',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
