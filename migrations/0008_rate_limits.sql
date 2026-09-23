-- Rate Limiting je Schlüssel (z. B. IP). Die IP wird nur als HMAC-Hash gespeichert.
CREATE TABLE rate_limits (
    bucket VARCHAR(50) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    window_start DATETIME NOT NULL COMMENT 'UTC',
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, key_hash, window_start),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
