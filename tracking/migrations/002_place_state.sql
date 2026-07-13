-- ============================================
-- MIGRATION 002: Per-user saved-place state
-- ============================================
-- Places previously had NO persistent inside/outside state: GeofenceEngine
-- reconstructed it from tracking_events rows in the last 24 hours, so anyone
-- who stayed at a saved place longer than a day generated a fresh
-- "arrived at Home" alert roughly every 24h and never produced the
-- "left Home" event they actually wanted. This table mirrors
-- tracking_geofence_state so place transitions are tracked the same way.
-- (tracking_geofence_state cannot be reused: its geofence_id is UNSIGNED
-- with a FK to tracking_geofences, so negative "place ids" are impossible.)
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_place_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,
    place_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    is_inside TINYINT(1) NOT NULL DEFAULT 0,

    last_entered_at TIMESTAMP NULL,
    last_exited_at TIMESTAMP NULL,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_place_user (place_id, user_id),
    INDEX idx_placestate_family (family_id),
    INDEX idx_placestate_user (user_id),

    CONSTRAINT fk_placestate_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_placestate_place
        FOREIGN KEY (place_id) REFERENCES tracking_places(id) ON DELETE CASCADE,
    CONSTRAINT fk_placestate_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
