-- Warteschlange für Webhook und Mail mit Wiederholung (MP 6.4). Verarbeitung durch bin/worker.php.
-- payload wird nach erfolgreichem Versand geleert. last_error enthält keine personenbezogenen Daten.
CREATE TABLE outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    typ ENUM('webhook', 'mail') NOT NULL,
    lead_id BIGINT UNSIGNED NULL,
    payload JSON NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    last_error VARCHAR(500) NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    locked_until DATETIME NULL COMMENT 'UTC, Sperre gegen Doppelverarbeitung',
    locked_by CHAR(32) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    sent_at DATETIME NULL COMMENT 'UTC',
    PRIMARY KEY (id),
    KEY idx_outbox_due (status, next_attempt_at),
    KEY idx_outbox_locked_by (locked_by),
    KEY idx_outbox_lead (lead_id),
    CONSTRAINT fk_outbox_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
