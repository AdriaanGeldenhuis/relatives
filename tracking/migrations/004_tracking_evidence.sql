-- Additive and safe to re-run. Apply after 003_tracking_reliability.sql.
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_current' AND COLUMN_NAME = 'pending_fix_json');
SET @sql := IF(@exists = 0,
    'ALTER TABLE tracking_current ADD pending_fix_json TEXT NULL', 'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_current' AND COLUMN_NAME = 'consent_scope');
SET @sql := IF(@exists = 0,
    'ALTER TABLE tracking_current ADD consent_scope CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL', 'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_geofence_state' AND COLUMN_NAME = 'observed_ms');
SET @sql := IF(@exists = 0,
    'ALTER TABLE tracking_geofence_state ADD observed_ms BIGINT UNSIGNED NULL,
        ADD definition_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_place_state' AND COLUMN_NAME = 'observed_ms');
SET @sql := IF(@exists = 0,
    'ALTER TABLE tracking_place_state ADD observed_ms BIGINT UNSIGNED NULL,
        ADD definition_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_geofences' AND COLUMN_NAME = 'revision_ms');
SET @sql := IF(@exists = 0,
    'ALTER TABLE tracking_geofences ADD revision_ms BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE tracking_ddl FROM @sql;
EXECUTE tracking_ddl;
DEALLOCATE PREPARE tracking_ddl;
