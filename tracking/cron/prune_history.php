<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

echo "[" . date('Y-m-d H:i:s') . "] Pruning location history...\n";

// Get all families with custom retention settings
$stmt = $db->query("SELECT family_id, history_retention_days FROM tracking_settings");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPruned = 0;
$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);

// Prune per-family with custom retention
$familiesProcessed = [];
foreach ($settings as $s) {
    $days = max(1, (int)$s['history_retention_days']);
    $familiesProcessed[] = (int)$s['family_id'];

    $stmt = $db->prepare("DELETE FROM tracking_location_history WHERE family_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$s['family_id'], $days]);
    $count = $stmt->rowCount();
    $totalPruned += $count;

    if ($count > 0) {
        echo "  Family {$s['family_id']}: pruned $count records (retention: {$days}d)\n";
    }
}

// Prune remaining families with default 30-day retention
$defaultPruned = $locationRepo->pruneHistory(30);
$totalPruned += $defaultPruned;

echo "Total pruned: $totalPruned records.\n";

// Also prune old events (90 days)
$eventsRepo = new EventsRepo($db);
$eventsPruned = $eventsRepo->pruneOld(90);
echo "Pruned $eventsPruned old events.\n";

echo "Done.\n";
