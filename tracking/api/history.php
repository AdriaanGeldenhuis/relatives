<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : $ctx->userId;

// Default time range: last 24 hours
$now = time();
$from = isset($_GET['from']) ? gmdate('Y-m-d H:i:s', strtotime($_GET['from'])) : gmdate('Y-m-d H:i:s', $now - 86400);
$to = isset($_GET['to']) ? gmdate('Y-m-d H:i:s', strtotime($_GET['to'])) : gmdate('Y-m-d H:i:s', $now);

$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);

$points = $locationRepo->getHistory($ctx->familyId, $userId, $from, $to);

Response::success([
    'user_id' => $userId,
    'from' => $from,
    'to' => $to,
    'count' => count($points),
    'points' => $points,
]);
