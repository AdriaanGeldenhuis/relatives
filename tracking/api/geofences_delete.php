<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int) ($input['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    Response::error('id is required', 422);
}

$trackingCache = new TrackingCache($cache);
$repo = new GeofenceRepo($db, $trackingCache);

$deleted = $repo->delete($ctx->familyId, $id);

if (!$deleted) {
    Response::error('geofence_not_found', 404);
}

Response::success(null, 'Geofence deleted');
