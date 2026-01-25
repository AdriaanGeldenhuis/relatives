-- ============================================
-- MIGRATION 004: Tracking System Improvements
-- Run this BEFORE deploying the new tracking code
-- ============================================

-- MIGRATION A: tracking_locations columns for idempotency
ALTER TABLE tracking_locations
  ADD COLUMN IF NOT EXISTS client_event_id VARCHAR(64) NULL AFTER source,
  ADD COLUMN IF NOT EXISTS client_timestamp DATETIME NULL AFTER client_event_id;

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_tracking_locations_user_created
  ON tracking_locations (user_id, created_at);

CREATE INDEX IF NOT EXISTS idx_tracking_locations_family_created
  ON tracking_locations (family_id, created_at);

-- Unique index for idempotent uploads (prevents duplicates on retry)
-- Using a partial index approach - only check uniqueness where client_event_id is not null
-- Note: MySQL doesn't support partial unique indexes, so we use a conditional approach
-- The PHP code will handle duplicates via INSERT IGNORE

-- MIGRATION B: tracking_devices state columns
ALTER TABLE tracking_devices
  ADD COLUMN IF NOT EXISTS network_status VARCHAR(16) NULL AFTER last_seen,
  ADD COLUMN IF NOT EXISTS location_status VARCHAR(16) NULL AFTER network_status,
  ADD COLUMN IF NOT EXISTS permission_status VARCHAR(16) NULL AFTER location_status,
  ADD COLUMN IF NOT EXISTS app_state VARCHAR(16) NULL AFTER permission_status,
  ADD COLUMN IF NOT EXISTS last_fix_at TIMESTAMP NULL DEFAULT NULL AFTER app_state;

CREATE INDEX IF NOT EXISTS idx_tracking_devices_user_uuid
  ON tracking_devices (user_id, device_uuid);

-- MIGRATION C: tracking_geofence_queue for async processing
CREATE TABLE IF NOT EXISTS tracking_geofence_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  family_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  latitude DECIMAL(10, 8) NOT NULL,
  longitude DECIMAL(11, 8) NOT NULL,
  battery_level TINYINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  status ENUM('pending', 'processed', 'failed') DEFAULT 'pending',
  INDEX idx_geofence_queue_status (status, created_at),
  INDEX idx_geofence_queue_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MIGRATION D: Add Redis cache key tracking (optional, for debugging)
CREATE TABLE IF NOT EXISTS tracking_cache_stats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key VARCHAR(255) NOT NULL,
  hit_count INT UNSIGNED DEFAULT 0,
  miss_count INT UNSIGNED DEFAULT 0,
  last_hit_at TIMESTAMP NULL,
  last_miss_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
