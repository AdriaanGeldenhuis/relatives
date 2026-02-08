<?php
/**
 * Seed test location data into tracking_current.
 * Admin-only, GET request. Run once to populate test markers.
 * Delete this file after confirming the map works.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

// Require admin/owner
if (!in_array($ctx->role, ['owner', 'admin'])) {
    Response::error('admin_required', 403);
}

$familyId = $ctx->familyId;

// Get all active family members
$stmt = $db->prepare("
    SELECT id, full_name, avatar_color
    FROM users
    WHERE family_id = ? AND status = 'active'
");
$stmt->execute([$familyId]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($members)) {
    Response::error('no_family_members');
}

// Test locations around Johannesburg
$testLocations = [
    ['lat' => -26.2041, 'lng' => 28.0473],  // Johannesburg CBD
    ['lat' => -26.1076, 'lng' => 28.0567],  // Sandton
    ['lat' => -26.1496, 'lng' => 28.0080],  // Randburg
    ['lat' => -26.1929, 'lng' => 28.1137],  // Bedfordview
    ['lat' => -26.2650, 'lng' => 28.1310],  // Alberton
];

$now = date('Y-m-d H:i:s');
$inserted = 0;

foreach ($members as $i => $member) {
    $loc = $testLocations[$i % count($testLocations)];

    $stmt = $db->prepare("
        INSERT INTO tracking_current
            (user_id, family_id, lat, lng, accuracy_m, speed_mps, bearing_deg,
             altitude_m, motion_state, recorded_at, device_id, platform, app_version)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            lat = VALUES(lat), lng = VALUES(lng),
            accuracy_m = VALUES(accuracy_m), speed_mps = VALUES(speed_mps),
            bearing_deg = VALUES(bearing_deg), altitude_m = VALUES(altitude_m),
            motion_state = VALUES(motion_state), recorded_at = VALUES(recorded_at),
            device_id = VALUES(device_id), platform = VALUES(platform),
            app_version = VALUES(app_version)
    ");

    $stmt->execute([
        $member['id'],
        $familyId,
        $loc['lat'],
        $loc['lng'],
        5.0,          // accuracy_m
        0.5,          // speed_mps
        null,         // bearing_deg
        1600.0,       // altitude_m (Johannesburg elevation)
        'idle',       // motion_state
        $now,         // recorded_at
        'seed-test',  // device_id
        'web',        // platform
        '1.0.0',      // app_version
    ]);

    $inserted++;
}

// Clear cache so fresh data shows
$trackingCache = new TrackingCache($cache);
$trackingCache->deleteFamilySnapshot($familyId);

Response::success([
    'seeded' => $inserted,
    'members' => array_map(fn($m) => $m['full_name'], $members),
], 'Test locations seeded. Refresh the map to see markers.');
