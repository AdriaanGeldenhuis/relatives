<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);
$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);

$members = $locationRepo->getFamilyCurrent($ctx->familyId);
Response::success($members);
