<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

echo "[" . date('Y-m-d H:i:s') . "] Cleaning up expired sessions...\n";

$sessionsRepo = new SessionsRepo($db);
$cleaned = $sessionsRepo->cleanupExpired();

echo "Expired $cleaned sessions.\n";

// Also clean old session records (> 7 days)
$stmt = $db->prepare("DELETE FROM tracking_sessions WHERE status IN ('stopped', 'expired') AND stopped_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute();
$deleted = $stmt->rowCount();

echo "Deleted $deleted old session records.\n";
echo "Done.\n";
