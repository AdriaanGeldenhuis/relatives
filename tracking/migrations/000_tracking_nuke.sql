-- ============================================
-- MIGRATION 000: Tracking Nuke (Clean Slate)
-- ============================================
-- Drops ALL tracking_* tables for complete rebuild
-- Does NOT touch core tables (users, families, etc.)
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- Core tracking tables
DROP TABLE IF EXISTS tracking_family_settings;
DROP TABLE IF EXISTS tracking_family_sessions;
DROP TABLE IF EXISTS tracking_current;
DROP TABLE IF EXISTS tracking_locations;

-- Places
DROP TABLE IF EXISTS tracking_places;

-- Geofences
DROP TABLE IF EXISTS tracking_geofences;
DROP TABLE IF EXISTS tracking_geofence_state;

-- Events & Alerts
DROP TABLE IF EXISTS tracking_events;
DROP TABLE IF EXISTS tracking_alert_rules;
DROP TABLE IF EXISTS tracking_alert_deliveries;

-- Legacy tables (from V1 - if any remain)
DROP TABLE IF EXISTS tracking_alerts;
DROP TABLE IF EXISTS tracking_browser_subscriptions;
DROP TABLE IF EXISTS tracking_cache_stats;
DROP TABLE IF EXISTS tracking_checkins;
DROP TABLE IF EXISTS tracking_devices;
DROP TABLE IF EXISTS tracking_geofence_queue;
DROP TABLE IF EXISTS tracking_history;
DROP TABLE IF EXISTS tracking_members;
DROP TABLE IF EXISTS tracking_settings;
DROP TABLE IF EXISTS tracking_zones;

SET FOREIGN_KEY_CHECKS = 1;

-- Verify all tracking tables are gone
-- Run this manually to confirm:
-- SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tracking_%';
