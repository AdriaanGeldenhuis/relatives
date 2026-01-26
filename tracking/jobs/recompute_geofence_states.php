<?php
/**
 * Recompute Geofence States Job
 *
 * Run manually when needed: php /path/to/tracking/jobs/recompute_geofence_states.php
 *
 * Repair tool: Recalculates all geofence states based on current user locations.
 * Useful after creating/modifying geofences or fixing data issues.
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

echo "=== Recompute Geofence States ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$geofenceRepo = new GeofenceRepo($db, $trackingCache);

// Get all families with geofences
$stmt = $db->query("
    SELECT DISTINCT family_id FROM tracking_geofences WHERE active = 1
");
$families = $stmt->fetchAll(PDO::FETCH_COLUMN);

$totalProcessed = 0;
$totalUpdated = 0;

foreach ($families as $familyId) {
    echo "Processing family {$familyId}...\n";

    // Get active geofences
    $geofences = $geofenceRepo->getActive($familyId);
    if (empty($geofences)) {
        echo "  No active geofences, skipping\n";
        continue;
    }

    // Get users with current locations
    $stmt = $db->prepare("
        SELECT tc.user_id, tc.lat, tc.lng
        FROM tracking_current tc
        JOIN users u ON tc.user_id = u.id
        WHERE tc.family_id = ?
          AND u.status = 'active'
          AND u.location_sharing = 1
    ");
    $stmt->execute([$familyId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        foreach ($geofences as $geofence) {
            $isInside = $geofenceRepo->isPointInside(
                $user['lat'],
                $user['lng'],
                $geofence
            );

            // Get current state
            $stmt2 = $db->prepare("
                SELECT is_inside FROM tracking_geofence_state
                WHERE geofence_id = ? AND user_id = ?
            ");
            $stmt2->execute([$geofence['id'], $user['user_id']]);
            $currentState = $stmt2->fetchColumn();

            $needsUpdate = ($currentState === false) || ((bool)$currentState !== $isInside);

            if ($needsUpdate) {
                // Upsert state
                $stmt3 = $db->prepare("
                    INSERT INTO tracking_geofence_state (
                        family_id, geofence_id, user_id, is_inside, updated_at
                    ) VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        is_inside = VALUES(is_inside),
                        updated_at = NOW()
                ");
                $stmt3->execute([
                    $familyId,
                    $geofence['id'],
                    $user['user_id'],
                    $isInside ? 1 : 0
                ]);

                echo "  User {$user['user_id']}: geofence '{$geofence['name']}' -> " . ($isInside ? 'inside' : 'outside') . "\n";
                $totalUpdated++;
            }

            $totalProcessed++;
        }

        // Clear user's geofence state cache
        $trackingCache->deleteGeofenceState($user['user_id']);
    }
}

echo "\n=== Summary ===\n";
echo "States processed: {$totalProcessed}\n";
echo "States updated: {$totalUpdated}\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
