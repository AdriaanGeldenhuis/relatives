-- Apply after the notification, privacy and tracking reliability migrations.
CREATE TABLE IF NOT EXISTS web_push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    endpoint VARCHAR(2048) CHARACTER SET ascii NOT NULL,
    public_key VARCHAR(128) CHARACTER SET ascii NOT NULL,
    auth_secret VARCHAR(64) CHARACTER SET ascii NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_web_push_endpoint (endpoint_hash),
    INDEX idx_web_push_user (user_id, session_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('fcm', 'web') NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    receipt CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('pending', 'accepted', 'delivered', 'skipped', 'failed') NOT NULL DEFAULT 'pending',
    reason VARCHAR(32) CHARACTER SET ascii NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_until DATETIME NULL,
    lease_id CHAR(32) CHARACTER SET ascii NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery (notification_id, channel, device_id),
    UNIQUE KEY uq_delivery_receipt (receipt),
    INDEX idx_delivery_due (state, next_at, id),
    INDEX idx_delivery_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_send_receipts (
    user_id BIGINT UNSIGNED NOT NULL,
    client_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, client_id),
    INDEX idx_message_receipts_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_revisions (
    family_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_changes (
    family_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (family_id, revision),
    INDEX idx_message_changes_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scheduled_deliveries (
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheduled_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scheduled_job_status (
    job_name VARCHAR(48) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    last_started_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL
) ENGINE=InnoDB;

-- Preserve complete attachment lists from the chat composer.
ALTER TABLE messages MODIFY COLUMN media_path TEXT NULL;
SET @app_media_ddl := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_archives' AND COLUMN_NAME = 'media_path'),
    'ALTER TABLE message_archives MODIFY COLUMN media_path TEXT NULL', 'SELECT 1');
PREPARE relatives_archive_ddl FROM @app_media_ddl;
EXECUTE relatives_archive_ddl;
DEALLOCATE PREPARE relatives_archive_ddl;

-- Record chat mutations in commit order for each family.
DELIMITER $$
DROP TRIGGER IF EXISTS relatives_chat_insert$$
CREATE TRIGGER relatives_chat_insert AFTER INSERT ON messages FOR EACH ROW
BEGIN
    INSERT INTO message_revisions VALUES (NEW.family_id, 1) ON DUPLICATE KEY UPDATE revision = revision + 1;
    INSERT INTO message_changes (family_id, revision, message_id, deleted)
        SELECT NEW.family_id, revision, NEW.id, NEW.deleted_at IS NOT NULL
        FROM message_revisions WHERE family_id = NEW.family_id;
END$$
DROP TRIGGER IF EXISTS relatives_chat_update$$
CREATE TRIGGER relatives_chat_update AFTER UPDATE ON messages FOR EACH ROW
BEGIN
    IF NOT (OLD.content <=> NEW.content AND OLD.edited_at <=> NEW.edited_at
        AND OLD.deleted_at <=> NEW.deleted_at AND OLD.pinned <=> NEW.pinned) THEN
        INSERT INTO message_revisions VALUES (NEW.family_id, 1) ON DUPLICATE KEY UPDATE revision = revision + 1;
        INSERT INTO message_changes (family_id, revision, message_id, deleted)
            SELECT NEW.family_id, revision, NEW.id, NEW.deleted_at IS NOT NULL
            FROM message_revisions WHERE family_id = NEW.family_id;
    END IF;
END$$
DROP TRIGGER IF EXISTS relatives_chat_delete$$
CREATE TRIGGER relatives_chat_delete AFTER DELETE ON messages FOR EACH ROW
BEGIN
    INSERT INTO message_revisions VALUES (OLD.family_id, 1) ON DUPLICATE KEY UPDATE revision = revision + 1;
    INSERT INTO message_changes (family_id, revision, message_id, deleted)
        SELECT OLD.family_id, revision, OLD.id, 1 FROM message_revisions WHERE family_id = OLD.family_id;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS weather_saved_cities (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    lat DECIMAL(7,4) NOT NULL,
    lon DECIMAL(7,4) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS account_deletion_outcomes (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    operator_id BIGINT UNSIGNED NOT NULL,
    status ENUM('in_progress', 'completed') NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
