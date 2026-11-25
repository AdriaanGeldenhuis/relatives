<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if (TrackingPolicy::hasTable($db, 'tracking_fix_receipts')) {
    do {
        $deleted = $db->exec('DELETE FROM tracking_fix_receipts WHERE created_at < NOW() - INTERVAL 8 DAY LIMIT 5000');
    } while ($deleted === 5000);
}
if (TrackingPolicy::hasTable($db, 'tracking_wake_requests')) {
    $stmt = $db->prepare('DELETE FROM tracking_wake_requests WHERE expires_ms < ?');
    $stmt->execute([Time::nowMs() - 86400000]);
}
