-- Apply after the V2 tracking tables and the notifications table are installed.
-- Existing locations and per-account privacy settings are preserved.
CREATE TABLE IF NOT EXISTS tracking_fix_receipts (
    user_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_event_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, device_id, client_event_id),
    INDEX idx_fix_receipts_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracking_sharing_cutoffs (
    subject_type ENUM('user', 'family') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    allowed_since_ms BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (subject_type, subject_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracking_wake_requests (
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,
    requester_id BIGINT UNSIGNED NOT NULL,
    client_request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_ms BIGINT UNSIGNED NOT NULL,
    expires_ms BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_wake_retry (family_id, requester_id, client_request_id),
    INDEX idx_wake_family_time (family_id, created_ms),
    INDEX idx_wake_expiry (expires_ms)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracking_wake_targets (
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    installation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    state VARCHAR(24) CHARACTER SET ascii NOT NULL,
    recorded_ms BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (request_id, token_id),
    INDEX idx_wake_installation (request_id, user_id, installation_id),
    CONSTRAINT fk_wake_request FOREIGN KEY (request_id)
        REFERENCES tracking_wake_requests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fcm_tokens' AND COLUMN_NAME = 'installation_id');
SET @sql := IF(@exists = 0,
    'ALTER TABLE fcm_tokens ADD installation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD wake_capability VARCHAR(24) CHARACTER SET ascii NULL, ADD wake_session_hash CHAR(64) CHARACTER SET ascii NULL,
        ADD UNIQUE KEY uq_fcm_installation (device_type, installation_id)',
    'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fcm_tokens' AND INDEX_NAME = 'idx_fcm_wake_user_type');
SET @sql := IF(@exists = 0,
    'ALTER TABLE fcm_tokens ADD INDEX idx_fcm_wake_user_type (user_id, device_type)', 'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

-- Older production schemas allow only one token for a user and device type.
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fcm_tokens' AND INDEX_NAME = 'user_device');
SET @sql := IF(@exists > 0, 'ALTER TABLE fcm_tokens DROP INDEX user_device', 'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_current' AND COLUMN_NAME = 'recorded_ms');
SET @sql := IF(@exists = 0, 'ALTER TABLE tracking_current ADD recorded_ms BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;
