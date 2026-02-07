<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('method_not_allowed', 405);

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$mode = in_array($input['mode'] ?? '', ['live', 'motion']) ? $input['mode'] : 'live';
$interval = max(5, min(300, (int)($input['interval'] ?? 30)));

$sessionsRepo = new SessionsRepo($db);
$sessionId = $sessionsRepo->start($ctx->userId, $ctx->familyId, $mode, $interval);

// Log event
$eventsRepo = new EventsRepo($db);
$eventsRepo->create($ctx->familyId, $ctx->userId, 'session_start', $ctx->userName . ' started live tracking');

// Update cache
$trackingCache = new TrackingCache($cache);
$sessionGate = new SessionGate($trackingCache, $db);
$sessionGate->keepalive($ctx->userId);

Response::success(['session_id' => $sessionId, 'mode' => $mode, 'interval' => $interval]);
