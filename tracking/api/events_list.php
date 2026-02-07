<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$type   = isset($_GET['type']) ? trim($_GET['type']) : null;

if ($type === '') {
    $type = null;
}

$repo = new EventsRepo($db);

$events = $repo->list($ctx->familyId, $limit, $offset, $type);

Response::success($events);
