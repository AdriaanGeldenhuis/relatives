-- Synthetic schema used by the authenticated release smoke test.
CREATE TABLE families (
    id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(120) NOT NULL,
    timezone VARCHAR(64) DEFAULT 'Africa/Johannesburg', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    trial_ends_at DATETIME, subscription_status VARCHAR(16) DEFAULT 'trial', subscription_expires_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE users (
    id BIGINT UNSIGNED PRIMARY KEY, family_id BIGINT UNSIGNED NOT NULL, role VARCHAR(16) NOT NULL,
    full_name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(16) DEFAULT 'active', avatar_color VARCHAR(7), has_avatar TINYINT DEFAULT 0,
    last_login DATETIME, location_sharing TINYINT DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
    session_token CHAR(64) NOT NULL UNIQUE, csrf_token CHAR(64) NOT NULL, ip VARBINARY(16), user_agent VARCHAR(255),
    expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, family_id BIGINT UNSIGNED NOT NULL, status VARCHAR(16), expires_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, family_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
    message_type VARCHAR(16), content TEXT, media_path TEXT, media_thumbnail TEXT, reply_to_message_id BIGINT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME, edit_count INT DEFAULT 0, deleted_at DATETIME, pinned TINYINT DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE message_reactions (
    message_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, emoji VARCHAR(40), created_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, family_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED,
    type ENUM('text','voice') NOT NULL DEFAULT 'text', title VARCHAR(160), body TEXT, audio_path VARCHAR(255),
    image_path VARCHAR(255), color CHAR(7) DEFAULT '#ffffff', pinned TINYINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, from_user_id BIGINT UNSIGNED, type VARCHAR(30),
    priority VARCHAR(16), category VARCHAR(30), title VARCHAR(255), message TEXT, icon VARCHAR(64), sound VARCHAR(64),
    vibrate TINYINT, action_url VARCHAR(500), data_json TEXT, requires_interaction TINYINT, expires_at DATETIME,
    is_read TINYINT DEFAULT 0, read_at DATETIME, created_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE notification_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, category VARCHAR(30) NOT NULL,
    enabled TINYINT DEFAULT 1, push_enabled TINYINT DEFAULT 1, sound_enabled TINYINT DEFAULT 1, vibrate_enabled TINYINT DEFAULT 1,
    quiet_hours_enabled TINYINT DEFAULT 0, quiet_hours_start TIME, quiet_hours_end TIME,
    UNIQUE KEY uq_pref (user_id, category)
) ENGINE=InnoDB;
CREATE TABLE fcm_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token VARCHAR(255),
    installation_id VARCHAR(64), wake_session_hash CHAR(64)
) ENGINE=InnoDB;
CREATE TABLE user_privacy_settings (
    user_id BIGINT UNSIGNED PRIMARY KEY, allow_messages TINYINT DEFAULT 1, show_online_status TINYINT DEFAULT 1,
    show_last_seen TINYINT DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE events (
    id BIGINT UNSIGNED PRIMARY KEY, family_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255), starts_at DATETIME NOT NULL, status VARCHAR(16) DEFAULT 'pending', kind VARCHAR(16),
    recurrence_parent_id BIGINT UNSIGNED, reminder_minutes INT, reminder_sent_at DATETIME, updated_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE event_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id BIGINT UNSIGNED, trigger_type VARCHAR(16),
    trigger_offset INT, snooze_until DATETIME, next_trigger_at DATETIME, is_sent TINYINT DEFAULT 0, sent_at DATETIME
) ENGINE=InnoDB;
CREATE TABLE event_sync_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id BIGINT UNSIGNED, status VARCHAR(32), created_at DATETIME,
    retry_count INT DEFAULT 0, max_retries INT DEFAULT 3, priority INT DEFAULT 0, next_retry_at DATETIME
) ENGINE=InnoDB;
