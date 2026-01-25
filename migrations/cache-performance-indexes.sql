-- =====================================================
-- CACHE PERFORMANCE INDEXES - Database Migration
-- Run this to add performance indexes for cache tables
-- =====================================================

-- Indexes for cache table
-- Primary lookup is by cache_key, checking expiration

-- Index for cache key lookups (most common query)
CREATE INDEX IF NOT EXISTS idx_cache_key
ON cache (cache_key);

-- Index for expiration cleanup queries
CREATE INDEX IF NOT EXISTS idx_cache_expires
ON cache (expires_at);

-- Composite index for the most common query pattern (get non-expired by key)
CREATE INDEX IF NOT EXISTS idx_cache_key_expires
ON cache (cache_key, expires_at);

-- Indexes for weather_cache table
-- Queries typically filter by lat/lon/type and check expiration

-- Index for location + type lookups (most common weather query)
CREATE INDEX IF NOT EXISTS idx_weather_cache_location_type
ON weather_cache (location_lat, location_lon, cache_type);

-- Index for expiration cleanup
CREATE INDEX IF NOT EXISTS idx_weather_cache_expires
ON weather_cache (expires_at);

-- Composite index for full weather cache query pattern
CREATE INDEX IF NOT EXISTS idx_weather_cache_full
ON weather_cache (location_lat, location_lon, cache_type, expires_at);

-- =====================================================
-- Note: Run these commands manually if IF NOT EXISTS
-- syntax is not supported by your MySQL version:
--
-- ALTER TABLE cache ADD INDEX idx_cache_key (cache_key);
-- ALTER TABLE cache ADD INDEX idx_cache_expires (expires_at);
-- ALTER TABLE cache ADD INDEX idx_cache_key_expires (cache_key, expires_at);
-- ALTER TABLE weather_cache ADD INDEX idx_location_type (location_lat, location_lon, cache_type);
-- ALTER TABLE weather_cache ADD INDEX idx_expires (expires_at);
-- ALTER TABLE weather_cache ADD INDEX idx_full (location_lat, location_lon, cache_type, expires_at);
-- =====================================================
