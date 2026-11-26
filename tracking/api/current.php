<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);
session_write_close();
header('Cache-Control: private, no-store');

$trackingCache = new TrackingCache($cache);
$locationRepo = new LocationRepo($db, $trackingCache);
$policy = TrackingPolicy::native((new SettingsRepo($db, $trackingCache))->get($ctx->familyId), $ctx->locationSharing);
$quality = TrackingPolicy::locationQuality($db);

$locations = $locationRepo->getFamilyCurrentLocations(
    $ctx->familyId, TrackingPolicy::hasColumn($db, 'tracking_current', 'recorded_ms'), $policy
);

// Format response — includes ALL family members, even those without a position yet
$members = [];
foreach ($locations as $row) {
    $hasLocation = $policy['mode'] !== 0 && $row['lat'] !== null;
    $members[] = [
        'user_id' => (int) $row['user_id'],
        'name' => $row['name'],
        'avatar_color' => $row['avatar_color'],
        'has_avatar' => (bool) ($row['has_avatar'] ?? false),
        'has_location' => $hasLocation,
        'lat' => $hasLocation ? (float) $row['lat'] : null,
        'lng' => $hasLocation ? (float) $row['lng'] : null,
        'accuracy_m' => isset($row['accuracy_m']) && $hasLocation ? (float) $row['accuracy_m'] : null,
        'speed_mps' => isset($row['speed_mps']) && $hasLocation ? (float) $row['speed_mps'] : null,
        'bearing_deg' => isset($row['bearing_deg']) && $hasLocation ? (float) $row['bearing_deg'] : null,
        'altitude_m' => isset($row['altitude_m']) && $hasLocation ? (float) $row['altitude_m'] : null,
        'motion_state' => $hasLocation ? ($row['motion_state'] ?? 'unknown') : 'unknown',
        'recorded_at' => $hasLocation ? $row['recorded_at'] : null,
        'updated_at' => $hasLocation ? $row['updated_at'] : null,
        'recorded_at_ms' => $hasLocation ? (int) ($row['recorded_ms'] ?? Time::captureMs($row['recorded_at'])) : null,
        'received_at_ms' => isset($row['received_at_unix']) && $hasLocation ? (int) $row['received_at_unix'] * 1000 : null,
        'location_sharing' => (bool) $row['location_sharing'],
        'approximate' => $hasLocation && ($quality ? !LocationQuality::reliable($row, $policy)
            : ($row['accuracy_m'] === null || (float) $row['accuracy_m'] > $policy['min_accuracy_m'])),
    ];
}

Response::json(['success' => true, 'data' => $members, 'server_settings' => $policy]);
