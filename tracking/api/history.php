<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);

$userId = TrackingValidator::positiveInt($_GET['user_id'] ?? null);
if (!$userId) {
    Response::error('user_id required', 400);
}

// Verify user is in same family
$stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND family_id = ?");
$stmt->execute([$userId, $ctx->familyId]);
if (!$stmt->fetch()) {
    Response::error('not_found', 404);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00');
$to = $_GET['to'] ?? date('Y-m-d 23:59:59');
$limit = min((int)($_GET['limit'] ?? 500), 2000);

$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);
$history = $locationRepo->getHistory($userId, $ctx->familyId, $from, $to, $limit);

Response::success($history);
