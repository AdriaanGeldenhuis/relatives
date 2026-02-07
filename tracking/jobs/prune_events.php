<?php
/**
 * ============================================
 * CRON JOB: PRUNE OLD TRACKING EVENTS
 *
 * Deletes tracking events older than each family's
 * configured events_retention_days setting.
 *
 * Recommended schedule: daily (e.g. 3:00 AM)
 *   0 3 * * * php /path/to/tracking/jobs/prune_events.php
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
    // Get all families with their events_retention_days setting
    $stmt = $db->prepare("
        SELECT family_id, events_retention_days
        FROM tracking_family_settings
        WHERE events_retention_days > 0
    ");
    $stmt->execute();
    $families = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($families)) {
        // Use global default if no family settings exist
        $defaultRetention = SettingsRepo::getDefaults()['events_retention_days'];
        $eventsRepo = new EventsRepo($db);
        $deleted = $eventsRepo->prune($defaultRetention);
        $totalDeleted += $deleted;
        echo "[prune_events] Default retention {$defaultRetention}d: deleted {$deleted} events\n";
    } else {
        $eventsRepo = new EventsRepo($db);

        foreach ($families as $family) {
            $retention = (int)$family['events_retention_days'];
            $familyId = (int)$family['family_id'];

            // Delete events for this specific family older than retention period
            $stmt = $db->prepare("
                DELETE FROM tracking_events
                WHERE family_id = ?
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$familyId, $retention]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                echo "[prune_events] Family {$familyId} (retention {$retention}d): deleted {$deleted} events\n";
            }
        }
    }

    $elapsed = round(microtime(true) - $startTime, 3);
    echo "[prune_events] Complete. Total deleted: {$totalDeleted}. Time: {$elapsed}s\n";

} catch (Exception $e) {
    error_log('[prune_events] ERROR: ' . $e->getMessage());
    echo "[prune_events] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
