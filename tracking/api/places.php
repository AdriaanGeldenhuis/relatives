<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);
$placesRepo = new PlacesRepo($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) Response::error('invalid_json', 400);

    if (empty($input['name'])) Response::error('name required', 400);
    $errors = TrackingValidator::coordinates($input);
    if (!empty($errors)) Response::error(implode(', ', $errors), 400);

    $id = $placesRepo->create($ctx->familyId, $ctx->userId, $input);
    Response::success(['id' => $id], 'Place saved');
} else {
    Response::success($placesRepo->getForFamily($ctx->familyId));
}
