CREATE TABLE IF NOT EXISTS tracking_live_sessions (
    family_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_ms BIGINT UNSIGNED NOT NULL,
    KEY live_session_expiry (expires_ms)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracking_live_viewers (
    family_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    auth_session CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    viewer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_ms BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (family_id, user_id, auth_session, viewer_id),
    FOREIGN KEY (family_id) REFERENCES tracking_live_sessions (family_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracking_live_phones (
    family_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth_session CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('starting', 'live', 'open_required') NOT NULL,
    last_seen_ms BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (family_id, user_id, device_id),
    FOREIGN KEY (family_id) REFERENCES tracking_live_sessions (family_id) ON DELETE CASCADE
) ENGINE=InnoDB;
