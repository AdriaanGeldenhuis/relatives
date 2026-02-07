<?php
/**
 * ============================================
 * CRON JOB: PRUNE OLD LOCATION HISTORY
 *
 * Deletes location history records older than each
 * family's configured history_retention_days setting.
 *
 * Recommended schedule: daily (e.g. 3:15 AM)
 *   15 3 * * * php /path/to/tracking/jobs/prune_history.php
 * ============================================
 */
declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

$startTime = microtime(true);
$totalDeleted = 0;

try {
    // Get all families with their history_retention_days setting
    $stmt = $db->prepare("
        SELECT family_id, history_retention_days
        FROM tracking_family_settings
        WHERE history_retention_days > 0
    ");
    $stmt->execute();
    $families = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($families)) {
        // Use global default if no family settings exist
        $defaultRetention = SettingsRepo::getDefaults()['history_retention_days'];
        $trackingCache = new TrackingCache($db);
        $locationRepo = new LocationRepo($db, $trackingCache);
        $deleted = $locationRepo->pruneHistory($defaultRetention);
        $totalDeleted += $deleted;
        echo "[prune_history] Default retention {$defaultRetention}d: deleted {$deleted} rows\n";
    } else {
        foreach ($families as $family) {
            $retention = (int)$family['history_retention_days'];
            $familyId = (int)$family['family_id'];

            // Delete location history for this specific family older than retention period
            $stmt = $db->prepare("
                DELETE FROM tracking_locations
                WHERE family_id = ?
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$familyId, $retention]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                echo "[prune_history] Family {$familyId} (retention {$retention}d): deleted {$deleted} rows\n";
            }
        }
    }

    $elapsed = round(microtime(true) - $startTime, 3);
    echo "[prune_history] Complete. Total deleted: {$totalDeleted}. Time: {$elapsed}s\n";

} catch (Exception $e) {
    error_log('[prune_history] ERROR: ' . $e->getMessage());
    echo "[prune_history] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
