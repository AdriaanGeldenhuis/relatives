<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);

$limit = min((int)($_GET['limit'] ?? 50), 200);
$offset = max((int)($_GET['offset'] ?? 0), 0);
$type = $_GET['type'] ?? null;

$eventsRepo = new EventsRepo($db);
$events = $eventsRepo->getForFamily($ctx->familyId, $limit, $offset, $type);

Response::success($events);
