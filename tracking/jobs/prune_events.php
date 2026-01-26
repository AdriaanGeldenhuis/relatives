<?php
/**
 * Prune Events Job
 *
 * Run via cron: 0 4 * * * php /path/to/tracking/jobs/prune_events.php
 *
 * Deletes tracking events older than retention period.
 * Also cleans up old alert deliveries.
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

echo "=== Prune Tracking Events ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$settingsRepo = new SettingsRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$alertsRepo = new AlertsRepo($db, $trackingCache);

// Get all families
$stmt = $db->query("SELECT id FROM families WHERE subscription_status NOT IN ('expired', 'cancelled')");
$families = $stmt->fetchAll(PDO::FETCH_COLUMN);

$totalEventsPruned = 0;
$totalDeliveriesPruned = 0;

foreach ($families as $familyId) {
    // Get family settings
    $settings = $settingsRepo->get($familyId);
    $retentionDays = $settings['events_retention_days'];

    // Calculate cutoff
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

    // Count events to delete
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tracking_events
        WHERE family_id = ? AND created_at < ?
    ");
    $stmt->execute([$familyId, $cutoff]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        echo "Family {$familyId}: {$count} events older than {$retentionDays} days\n";

        // Delete in batches
        $deleted = 0;
        while ($deleted < $count) {
            $stmt = $db->prepare("
                DELETE FROM tracking_events
                WHERE family_id = ? AND created_at < ?
                LIMIT 5000
            ");
            $stmt->execute([$familyId, $cutoff]);
            $batchDeleted = $stmt->rowCount();

            if ($batchDeleted === 0) break;

            $deleted += $batchDeleted;
            echo "  Deleted batch: {$batchDeleted} (total: {$deleted})\n";

            usleep(100000);
        }

        $totalEventsPruned += $deleted;
    }
}

// Prune alert deliveries (keep 30 days regardless of settings)
echo "\n--- Pruning alert deliveries ---\n";
$deliveryCutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

$stmt = $db->prepare("
    SELECT COUNT(*) FROM tracking_alert_deliveries
    WHERE delivered_at < ?
");
$stmt->execute([$deliveryCutoff]);
$deliveryCount = $stmt->fetchColumn();

if ($deliveryCount > 0) {
    echo "Alert deliveries to prune: {$deliveryCount}\n";

    $deleted = 0;
    while ($deleted < $deliveryCount) {
        $stmt = $db->prepare("
            DELETE FROM tracking_alert_deliveries
            WHERE delivered_at < ?
            LIMIT 5000
        ");
        $stmt->execute([$deliveryCutoff]);
        $batchDeleted = $stmt->rowCount();

        if ($batchDeleted === 0) break;

        $deleted += $batchDeleted;
        echo "  Deleted batch: {$batchDeleted} (total: {$deleted})\n";

        usleep(100000);
    }

    $totalDeliveriesPruned = $deleted;
}

// Clean up expired sessions
echo "\n--- Cleaning expired sessions ---\n";
$stmt = $db->prepare("
    UPDATE tracking_family_sessions
    SET active = 0
    WHERE active = 1 AND expires_at < NOW()
");
$stmt->execute();
$expiredSessions = $stmt->rowCount();
echo "Expired sessions cleaned: {$expiredSessions}\n";

echo "\n=== Summary ===\n";
echo "Total events pruned: {$totalEventsPruned}\n";
echo "Total deliveries pruned: {$totalDeliveriesPruned}\n";
echo "Expired sessions cleaned: {$expiredSessions}\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
