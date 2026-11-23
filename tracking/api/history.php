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
$fromInput = $_GET['from'] ?? null;
$toInput = $_GET['to'] ?? null;
$fromTs = $fromInput === null ? $now - 86400 : (is_string($fromInput) ? strtotime($fromInput) : false);
$toTs = $toInput === null ? $now : (is_string($toInput) ? strtotime($toInput) : false);
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
    Response::error('invalid_date_range', 422);
}
$from = gmdate('Y-m-d H:i:s', $fromTs);
$to = gmdate('Y-m-d H:i:s', $toTs);

$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);

$points = $locationRepo->getHistory($ctx->familyId, $userId, $from, $to, $ctx->userId);

Response::success([
    'user_id' => $userId,
    'from' => $from,
    'to' => $to,
    'count' => count($points),
    'points' => $points,
]);
