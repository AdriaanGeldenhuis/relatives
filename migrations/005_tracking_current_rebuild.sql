-- ============================================
-- Migration 005: Tracking Current Table + Idempotency Index
-- ============================================
-- Purpose:
--   1. Create tracking_current table as source-of-truth for "best known position"
--   2. Add unique index on (user_id, client_event_id) for enforced idempotency
--
-- Safe to run: uses IF NOT EXISTS and checks before adding indexes
-- ============================================

-- 1) tracking_current: Source of truth for current best-known location
--    Cache is just a speed layer on top of this.
CREATE TABLE IF NOT EXISTS tracking_current (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    accuracy_m SMALLINT UNSIGNED DEFAULT NULL,
    speed_kmh FLOAT DEFAULT NULL,
    heading_deg FLOAT DEFAULT NULL,
    altitude_m FLOAT DEFAULT NULL,
    is_moving TINYINT(1) NOT NULL DEFAULT 0,
    battery_level TINYINT UNSIGNED DEFAULT NULL,
    fix_quality_score TINYINT UNSIGNED DEFAULT NULL COMMENT 'Computed 0-100 quality score',
    fix_source ENUM('gps', 'network', 'fused', 'unknown') NOT NULL DEFAULT 'unknown',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_family (family_id),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Best-known current location per user. Only updated by quality-gated fixes.';

-- 2) Enforced idempotency index on tracking_locations
--    Prevents duplicate inserts even if PHP check is bypassed.
--    Uses a partial index approach: NULL client_event_id values are allowed (non-unique)
--    but non-NULL values must be unique per user.
ALTER TABLE tracking_locations
    ADD UNIQUE INDEX idx_user_client_event (user_id, client_event_id);

-- Note: MySQL allows multiple NULL values in unique indexes,
-- so rows without client_event_id won't conflict.
