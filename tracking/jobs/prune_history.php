<?php
/**
 * Prune Location History Job
 *
 * Run via cron: 0 3 * * * php /path/to/tracking/jobs/prune_history.php
 *
 * Deletes location history older than retention period.
 * Processes in batches to avoid locking tables.
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

echo "=== Prune Location History ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$settingsRepo = new SettingsRepo($db, $trackingCache);
$locationRepo = new LocationRepo($db, $trackingCache);

// Get all families
$stmt = $db->query("SELECT id FROM families WHERE subscription_status NOT IN ('expired', 'cancelled')");
$families = $stmt->fetchAll(PDO::FETCH_COLUMN);

$totalPruned = 0;

foreach ($families as $familyId) {
    // Get family settings
    $settings = $settingsRepo->get($familyId);
    $retentionDays = $settings['history_retention_days'];

    // Calculate cutoff
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

    // Count records to delete
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tracking_locations
        WHERE family_id = ? AND created_at < ?
    ");
    $stmt->execute([$familyId, $cutoff]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        echo "Family {$familyId}: {$count} records older than {$retentionDays} days\n";

        // Delete in batches of 5000
        $deleted = 0;
        while ($deleted < $count) {
            $stmt = $db->prepare("
                DELETE FROM tracking_locations
                WHERE family_id = ? AND created_at < ?
                LIMIT 5000
            ");
            $stmt->execute([$familyId, $cutoff]);
            $batchDeleted = $stmt->rowCount();

            if ($batchDeleted === 0) break;

            $deleted += $batchDeleted;
            echo "  Deleted batch: {$batchDeleted} (total: {$deleted})\n";

            // Small delay to reduce DB load
            usleep(100000); // 100ms
        }

        $totalPruned += $deleted;
    }
}

echo "\n=== Summary ===\n";
echo "Total records pruned: {$totalPruned}\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
