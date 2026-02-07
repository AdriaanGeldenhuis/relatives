<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);
$geofenceRepo = new GeofenceRepo($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) Response::error('invalid_json', 400);

    // Update existing
    if (!empty($input['id'])) {
        $id = TrackingValidator::positiveInt($input['id']);
        if (!$id) Response::error('invalid id', 400);
        $geofenceRepo->update($id, $ctx->familyId, $input);
        Response::success($geofenceRepo->getById($id, $ctx->familyId), 'Geofence updated');
    }

    // Create new
    $errors = TrackingValidator::geofence($input);
    if (!empty($errors)) Response::error(implode(', ', $errors), 400);

    $id = $geofenceRepo->create($ctx->familyId, $ctx->userId, $input);
    Response::success($geofenceRepo->getById($id, $ctx->familyId), 'Geofence created');
} else {
    Response::success($geofenceRepo->getForFamily($ctx->familyId));
}
