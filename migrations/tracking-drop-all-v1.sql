-- ============================================
-- MIGRATION: Drop All Tracking Tables (Clean Slate)
-- Run this to remove all tracking data and tables
-- ============================================

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Drop all tracking tables
DROP TABLE IF EXISTS tracking_alerts;
DROP TABLE IF EXISTS tracking_browser_subscriptions;
DROP TABLE IF EXISTS tracking_cache_stats;
DROP TABLE IF EXISTS tracking_checkins;
DROP TABLE IF EXISTS tracking_current;
DROP TABLE IF EXISTS tracking_devices;
DROP TABLE IF EXISTS tracking_events;
DROP TABLE IF EXISTS tracking_geofence_queue;
DROP TABLE IF EXISTS tracking_history;
DROP TABLE IF EXISTS tracking_locations;
DROP TABLE IF EXISTS tracking_members;
DROP TABLE IF EXISTS tracking_places;
DROP TABLE IF EXISTS tracking_settings;
DROP TABLE IF EXISTS tracking_zones;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify all tracking tables are gone
-- SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tracking_%';
