<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('method_not_allowed', 405);

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true);
$id = TrackingValidator::positiveInt($input['id'] ?? null);
if (!$id) Response::error('id required', 400);

$geofenceRepo = new GeofenceRepo($db);
if ($geofenceRepo->delete($id, $ctx->familyId)) {
    Response::success(null, 'Geofence deleted');
} else {
    Response::error('not_found', 404);
}
