<?php
declare(strict_types=1);

/**
 * ============================================
 * MAINTENANCE CLEANUP SCRIPT
 * Run this as a cron job every hour
 * ============================================
 * 
 * Crontab entry:
 * 0 * * * * php /path/to/maintenance/cleanup.php >> /path/to/logs/cleanup.log 2>&1
 */

require_once __DIR__ . '/../core/bootstrap.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting maintenance cleanup...\n";

try {
    // Clean up expired sessions
    $stmt = $db->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
    $stmt->execute();
    $sessionsCleaned = $stmt->rowCount();
    echo "Cleaned up {$sessionsCleaned} expired sessions\n";
    
    // Clean up expired cache entries if Cache class exists
    if (class_exists('Cache')) {
        $cache = Cache::init($db);
        $cache->clearExpired();
        echo "Cleaned up expired cache entries\n";
    }

    // Clean up expired weather cache
    $stmt = $db->prepare("DELETE FROM weather_cache WHERE expires_at < NOW()");
    $stmt->execute();
    $weatherCleaned = $stmt->rowCount();
    echo "Cleaned up {$weatherCleaned} expired weather cache entries\n";
    
    // Clean up old audit logs (older than 90 days)
    $stmt = $db->prepare("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $auditsCleaned = $stmt->rowCount();
    echo "Cleaned up {$auditsCleaned} old audit log entries\n";
    
    // Clean up old location history (older than 30 days)
    $stmt = $db->prepare("DELETE FROM locations WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $locationsCleaned = $stmt->rowCount();
    echo "Cleaned up {$locationsCleaned} old location records\n";
    
    echo "[" . date('Y-m-d H:i:s') . "] Maintenance cleanup completed successfully\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
    error_log("Maintenance cleanup error: " . $e->getMessage());
    exit(1);
}