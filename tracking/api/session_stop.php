<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('method_not_allowed', 405);

$ctx = SiteContext::require($db);

$sessionsRepo = new SessionsRepo($db);
$stopped = $sessionsRepo->stop($ctx->userId);

$eventsRepo = new EventsRepo($db);
$eventsRepo->create($ctx->familyId, $ctx->userId, 'session_stop', $ctx->userName . ' stopped live tracking');

Response::success(['stopped' => $stopped]);
