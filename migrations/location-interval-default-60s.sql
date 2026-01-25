-- =============================================
-- MIGRATION: Update location_update_interval default to 60 seconds
-- Purpose: Improve battery efficiency by reducing polling frequency
-- Date: 2026-01-06
-- =============================================

-- 1. Update the column default from 300 to 60 seconds
ALTER TABLE users MODIFY COLUMN location_update_interval INT(11) DEFAULT 60;

-- 2. Update existing users who still have the old default of 300
-- (Only update if they never changed it from the old default)
UPDATE users
SET location_update_interval = 60
WHERE location_update_interval = 300;

-- 3. Also update tracking_settings default
ALTER TABLE tracking_settings MODIFY COLUMN update_interval_seconds INT(11) DEFAULT 60;

-- 4. Update existing tracking_settings with old default
UPDATE tracking_settings
SET update_interval_seconds = 60
WHERE update_interval_seconds = 10;

-- =============================================
-- Notes:
-- - Old default was 300 seconds (5 min) in users table
-- - Old default was 10 seconds in tracking_settings table
-- - New default is 60 seconds for better battery efficiency
-- - 60 seconds = 60 requests/hour vs 450 requests/hour at 8s
-- =============================================
