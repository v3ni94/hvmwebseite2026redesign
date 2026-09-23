-- Anmeldeversuche im Admin-Bereich (Brute-Force-Schutz). E-Mail und IP nur als HMAC-Hash.
CREATE TABLE admin_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (id),
    KEY idx_admin_login_attempts_email (email_hash, created_at),
    KEY idx_admin_login_attempts_ip (ip_hash, created_at),
    KEY idx_admin_login_attempts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
