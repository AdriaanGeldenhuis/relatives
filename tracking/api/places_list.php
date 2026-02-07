<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$trackingCache = new TrackingCache($cache);
$repo = new PlacesRepo($db, $trackingCache);

$places = $repo->listAll($ctx->familyId);

Response::success($places);
