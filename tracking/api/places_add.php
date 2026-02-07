<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$validator = new TrackingValidator();
$data = $validator->validatePlace($input);

if ($data === null) {
    Response::error(implode('; ', $validator->getErrors()), 422);
}

$trackingCache = new TrackingCache($cache);
$repo = new PlacesRepo($db, $trackingCache);

$id = $repo->create($ctx->familyId, $ctx->userId, $data);

Response::success(['id' => $id], 'Place created');
