<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

echo "[" . date('Y-m-d H:i:s') . "] Recomputing geofence states...\n";

// Get all active geofences
$stmt = $db->query("SELECT g.*, f.id as fam_id FROM tracking_geofences g JOIN families f ON g.family_id = f.id WHERE g.active = 1");
$geofences = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Processing " . count($geofences) . " geofences...\n";

$geofenceEngine = new GeofenceEngine($db);
$updated = 0;

foreach ($geofences as $gf) {
    // Get current locations for this family
    $stmt = $db->prepare("SELECT user_id, lat, lng FROM tracking_current_locations WHERE family_id = ?");
    $stmt->execute([$gf['family_id']]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($locations as $loc) {
        // Check if inside geofence
        $lat = (float)$loc['lat'];
        $lng = (float)$loc['lng'];
        $userId = (int)$loc['user_id'];

        $isInside = false;
        if ($gf['type'] === 'circle') {
            $distance = geo_haversineDistance($lat, $lng, (float)$gf['lat'], (float)$gf['lng']);
            $isInside = $distance <= (int)$gf['radius_m'];
        } elseif ($gf['type'] === 'polygon' && !empty($gf['polygon_json'])) {
            $polygon = json_decode($gf['polygon_json'], true);
            if (is_array($polygon) && count($polygon) >= 3) {
                $isInside = geo_isPointInPolygon($lat, $lng, $polygon);
            }
        }

        // Upsert state
        $stmt2 = $db->prepare("
            INSERT INTO tracking_geofence_states (geofence_id, user_id, is_inside, entered_at, exited_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_inside = VALUES(is_inside),
                updated_at = NOW()
        ");
        $stmt2->execute([
            $gf['id'],
            $userId,
            $isInside ? 1 : 0,
            $isInside ? date('Y-m-d H:i:s') : null,
            !$isInside ? date('Y-m-d H:i:s') : null,
        ]);
        $updated++;
    }
}

echo "Updated $updated geofence states.\n";
echo "Done.\n";
