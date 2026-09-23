-- Admin-Bereich und Löschkonzept (MP 6.5):
-- totp_last_step verhindert die Wiederverwendung eines bereits akzeptierten TOTP-Codes,
-- disabled_at sperrt ein Konto dauerhaft (bin/admin-user.php disable),
-- anonymized_at kennzeichnet Leads, deren personenbezogene Daten der Löschlauf entfernt hat (bin/retention.php).
ALTER TABLE admin_users
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL COMMENT 'zuletzt akzeptierter TOTP-Zeitschritt' AFTER totp_enabled,
    ADD COLUMN disabled_at DATETIME NULL COMMENT 'UTC, Konto deaktiviert' AFTER locked_until,
    ADD COLUMN password_changed_at DATETIME NULL COMMENT 'UTC' AFTER disabled_at;

ALTER TABLE leads
    ADD COLUMN anonymized_at DATETIME NULL COMMENT 'UTC, personenbezogene Daten durch Löschlauf entfernt' AFTER legacy_id,
    ADD KEY idx_leads_anonymized_at (anonymized_at);
