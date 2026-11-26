<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
$ctx->requireLocationSharing();
session_write_close();
header('Cache-Control: private, no-store');

$cache = new TrackingCache($cache);
$settings = (new SettingsRepo($db, $cache))->get($ctx->familyId);
$regions = [];
if ((int) $settings['mode'] !== 0) {
    foreach ((new GeofenceRepo($db, $cache))->listActive($ctx->familyId, false) as $fence) {
        if ($fence['type'] === 'polygon') {
            $polygon = TrackingValidator::polygon(json_decode($fence['polygon_json'] ?? '', true));
            if ($polygon) {
                $regions[] = ['id' => 'f' . $fence['id'], 'vertices' => $polygon];
            }
        } elseif (isset($fence['center_lat'], $fence['center_lng'], $fence['radius_m'])) {
            $regions[] = ['id' => 'f' . $fence['id'], 'lat' => (float) $fence['center_lat'],
                'lng' => (float) $fence['center_lng'], 'radius_m' => (int) $fence['radius_m']];
        }
    }
    foreach ((new PlacesRepo($db, $cache))->listAll($ctx->familyId, false) as $place) {
        $regions[] = ['id' => 'p' . $place['id'], 'lat' => (float) $place['lat'],
            'lng' => (float) $place['lng'], 'radius_m' => (int) $place['radius_m']];
    }
}
Response::success(['regions' => $regions]);
