<?php
declare(strict_types=1);

/**
 * TrackingSettings - Single source of truth for all tracking timing configuration
 *
 * IMPORTANT: This file provides unified settings for BOTH:
 * - Web map polling (tracking/index.php uses tracking_getUpdateInterval())
 * - Native app uploads (server_settings response uses tracking_loadSettings())
 *
 * All timing values are read from tracking_settings table with validated defaults.
 * The users.location_update_interval column is deprecated but kept in sync for
 * backwards compatibility via save_settings.php.
 *
 * Default values (in seconds):
 * - UPDATE_INTERVAL: 30 (30s) - How often to send/poll location
 * - IDLE_HEARTBEAT: 300 (5 min) - Heartbeat interval when stationary
 * - OFFLINE_THRESHOLD: 660 (11 min) - Mark user offline after this (2x heartbeat + buffer)
 * - STALE_THRESHOLD: 3600 (1 hour) - Consider location data stale after this time
 */

// ====== DEFAULT CONSTANTS (single source of truth) ======
// NOTE: These must match Android PreferencesManager.kt defaults
const TRACKING_DEFAULT_UPDATE_INTERVAL = 30;      // 30 seconds (matches Android default)
const TRACKING_DEFAULT_IDLE_HEARTBEAT = 300;      // 5 minutes (keeps background position fresh)
const TRACKING_DEFAULT_OFFLINE_THRESHOLD = 660;   // 11 minutes (2x heartbeat + buffer)
const TRACKING_DEFAULT_STALE_THRESHOLD = 3600;    // 1 hour

// ====== VALIDATION RANGES ======
const TRACKING_UPDATE_INTERVAL_MIN = 10;
const TRACKING_UPDATE_INTERVAL_MAX = 300;

const TRACKING_IDLE_HEARTBEAT_MIN = 60;
const TRACKING_IDLE_HEARTBEAT_MAX = 1800;

const TRACKING_OFFLINE_THRESHOLD_MIN = 120;
const TRACKING_OFFLINE_THRESHOLD_MAX = 3600;

const TRACKING_STALE_THRESHOLD_MIN = 300;
const TRACKING_STALE_THRESHOLD_MAX = 86400;

/**
 * Load tracking settings for a user from DB, with defaults
 *
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return array{
 *   update_interval_seconds: int,
 *   idle_heartbeat_seconds: int,
 *   offline_threshold_seconds: int,
 *   stale_threshold_seconds: int
 * }
 */
function tracking_loadSettings(PDO $db, int $userId): array {
    $defaults = [
        'update_interval_seconds' => TRACKING_DEFAULT_UPDATE_INTERVAL,
        'idle_heartbeat_seconds' => TRACKING_DEFAULT_IDLE_HEARTBEAT,
        'offline_threshold_seconds' => TRACKING_DEFAULT_OFFLINE_THRESHOLD,
        'stale_threshold_seconds' => TRACKING_DEFAULT_STALE_THRESHOLD,
    ];

    try {
        $stmt = $db->prepare("
            SELECT update_interval_seconds, idle_heartbeat_seconds,
                   offline_threshold_seconds, stale_threshold_seconds
            FROM tracking_settings
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'update_interval_seconds' => (int)($row['update_interval_seconds'] ?? $defaults['update_interval_seconds']),
                'idle_heartbeat_seconds' => (int)($row['idle_heartbeat_seconds'] ?? $defaults['idle_heartbeat_seconds']),
                'offline_threshold_seconds' => (int)($row['offline_threshold_seconds'] ?? $defaults['offline_threshold_seconds']),
                'stale_threshold_seconds' => (int)($row['stale_threshold_seconds'] ?? $defaults['stale_threshold_seconds']),
            ];
        }
    } catch (Exception $e) {
        error_log('Failed to load tracking settings: ' . $e->getMessage());
    }

    return $defaults;
}

/**
 * Get default tracking settings (no DB lookup)
 *
 * @return array{
 *   update_interval_seconds: int,
 *   idle_heartbeat_seconds: int,
 *   offline_threshold_seconds: int,
 *   stale_threshold_seconds: int
 * }
 */
function tracking_getDefaults(): array {
    return [
        'update_interval_seconds' => TRACKING_DEFAULT_UPDATE_INTERVAL,
        'idle_heartbeat_seconds' => TRACKING_DEFAULT_IDLE_HEARTBEAT,
        'offline_threshold_seconds' => TRACKING_DEFAULT_OFFLINE_THRESHOLD,
        'stale_threshold_seconds' => TRACKING_DEFAULT_STALE_THRESHOLD,
    ];
}

/**
 * Validate and clamp a setting value within allowed range
 *
 * @param int $value The value to validate
 * @param int $min Minimum allowed value
 * @param int $max Maximum allowed value
 * @param int $default Default value if out of range
 * @return int Validated value
 */
function tracking_validateSetting(int $value, int $min, int $max, int $default): int {
    if ($value < $min || $value > $max) {
        return $default;
    }
    return $value;
}

/**
 * Get effective update interval for a user (single source of truth)
 *
 * Used by both web polling AND native app uploads. Reads from tracking_settings
 * with fallback to default.
 *
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return int Update interval in seconds (validated within allowed range)
 */
function tracking_getUpdateInterval(PDO $db, int $userId): int {
    $settings = tracking_loadSettings($db, $userId);
    $interval = $settings['update_interval_seconds'];

    // Validate within allowed range
    return tracking_validateSetting(
        $interval,
        TRACKING_UPDATE_INTERVAL_MIN,
        TRACKING_UPDATE_INTERVAL_MAX,
        TRACKING_DEFAULT_UPDATE_INTERVAL
    );
}
