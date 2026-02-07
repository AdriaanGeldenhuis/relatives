<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);

$sessionsRepo = new SessionsRepo($db);
$session = $sessionsRepo->getActive($ctx->userId);
$familySessions = $sessionsRepo->getActiveForFamily($ctx->familyId);

Response::success([
    'my_session' => $session,
    'family_sessions' => $familySessions,
]);
