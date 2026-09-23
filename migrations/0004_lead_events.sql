-- Verlauf je Lead: Eingang, Statuswechsel, Notizen (MP 6.3, 6.5).
CREATE TABLE lead_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id BIGINT UNSIGNED NOT NULL,
    typ VARCHAR(30) NOT NULL COMMENT 'z. B. eingang, status, notiz, import',
    von_status VARCHAR(20) NULL,
    nach_status VARCHAR(20) NULL,
    admin_user_id INT UNSIGNED NULL,
    notiz TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (id),
    KEY idx_lead_events_lead (lead_id, created_at),
    KEY idx_lead_events_admin (admin_user_id),
    CONSTRAINT fk_lead_events_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_events_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
