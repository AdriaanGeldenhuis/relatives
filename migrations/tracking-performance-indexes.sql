-- =====================================================
-- TRACKING PERFORMANCE INDEXES - Database Migration
-- Run this to add performance indexes for tracking system
-- =====================================================

-- Indexes for tracking_locations table
-- These support the common query patterns for location retrieval

-- Index for family + user + time (most common query for get_current_locations)
CREATE INDEX IF NOT EXISTS idx_tracking_locations_family_user_time
ON tracking_locations (family_id, user_id, created_at DESC);

-- Index for user + time (for history queries)
CREATE INDEX IF NOT EXISTS idx_tracking_locations_user_time
ON tracking_locations (user_id, created_at DESC);

-- Index for device + time (for rate limiting checks)
CREATE INDEX IF NOT EXISTS idx_tracking_locations_device_time
ON tracking_locations (device_id, created_at DESC);

-- Indexes for tracking_devices table
-- Unique constraint on user + device_uuid prevents duplicate devices
CREATE UNIQUE INDEX IF NOT EXISTS idx_tracking_devices_user_uuid
ON tracking_devices (user_id, device_uuid);

-- Index for quick device lookup
CREATE INDEX IF NOT EXISTS idx_tracking_devices_uuid
ON tracking_devices (device_uuid);

-- Indexes for tracking_events table (geofence/battery events)
CREATE INDEX IF NOT EXISTS idx_tracking_events_user_type_time
ON tracking_events (user_id, event_type, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_tracking_events_zone
ON tracking_events (zone_id, created_at DESC);

-- =====================================================
-- Note: Run these commands manually if IF NOT EXISTS
-- syntax is not supported by your MySQL version:
--
-- ALTER TABLE tracking_locations ADD INDEX idx_family_user_time (family_id, user_id, created_at DESC);
-- ALTER TABLE tracking_locations ADD INDEX idx_user_time (user_id, created_at DESC);
-- ALTER TABLE tracking_locations ADD INDEX idx_device_time (device_id, created_at DESC);
-- ALTER TABLE tracking_devices ADD UNIQUE INDEX idx_user_uuid (user_id, device_uuid);
-- ALTER TABLE tracking_devices ADD INDEX idx_uuid (device_uuid);
-- ALTER TABLE tracking_events ADD INDEX idx_user_type_time (user_id, event_type, created_at DESC);
-- ALTER TABLE tracking_events ADD INDEX idx_zone (zone_id, created_at DESC);
-- =====================================================
