<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input) || empty($input['id'])) {
    Response::error('id is required', 422);
}

$id = (int) $input['id'];

$validator = new TrackingValidator();
$data = $validator->validateGeofence($input);

if ($data === null) {
    Response::error(implode('; ', $validator->getErrors()), 422);
}

if (isset($input['active'])) {
    $data['active'] = (int) (bool) $input['active'];
}

$trackingCache = new TrackingCache($cache);
$repo = new GeofenceRepo($db, $trackingCache);

$updated = $repo->update($ctx->familyId, $id, $data);

if (!$updated) {
    Response::error('geofence_not_found', 404);
}

Response::success(['id' => $id], 'Geofence updated');
