<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);

$locations = $locationRepo->getFamilyCurrentLocations($ctx->familyId);

// Format response — includes ALL family members, even those without a position yet
$members = [];
foreach ($locations as $row) {
    $hasLocation = $row['lat'] !== null;
    $members[] = [
        'user_id' => (int) $row['user_id'],
        'name' => $row['name'],
        'avatar_color' => $row['avatar_color'],
        'has_avatar' => (bool) ($row['has_avatar'] ?? false),
        'has_location' => $hasLocation,
        'lat' => $hasLocation ? (float) $row['lat'] : null,
        'lng' => $hasLocation ? (float) $row['lng'] : null,
        'accuracy_m' => $row['accuracy_m'] !== null ? (float) $row['accuracy_m'] : null,
        'speed_mps' => $row['speed_mps'] !== null ? (float) $row['speed_mps'] : null,
        'bearing_deg' => $row['bearing_deg'] !== null ? (float) $row['bearing_deg'] : null,
        'altitude_m' => $row['altitude_m'] !== null ? (float) $row['altitude_m'] : null,
        'motion_state' => $row['motion_state'] ?? 'unknown',
        'recorded_at' => $row['recorded_at'],
        'updated_at' => $row['updated_at'],
    ];
}

Response::success($members);
