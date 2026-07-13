<?php
/**
 * ============================================
 * CRON JOB: RECOMPUTE GEOFENCE STATES
 *
 * Safety net that re-evaluates all active users'
 * current positions against all active geofences
 * and places. Corrects any stale or missed state
 * transitions.
 *
 * Recommended schedule: every 15 minutes
 *   0,15,30,45 * * * * php /path/to/tracking/jobs/recompute_geofence_states.php
 * ============================================
 */
declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

$startTime = microtime(true);
$processed = 0;
$transitions = 0;

try {
    $trackingCache = new TrackingCache($cache);
    $geoRepo = new GeofenceRepo($db, $trackingCache);
    $placesRepo = new PlacesRepo($db, $trackingCache);
    $eventsRepo = new EventsRepo($db);
    $alertsRepo = new AlertsRepo($db, $trackingCache);
    $alertsEngine = new AlertsEngine($db, $trackingCache, $alertsRepo);
    $geofenceEngine = new GeofenceEngine(
        $db, $trackingCache, $geoRepo, $placesRepo, $eventsRepo, $alertsEngine
    );
    $locationRepo = new LocationRepo($db, $trackingCache);

    // Get all families that have active geofences or places
    $stmt = $db->prepare("
        SELECT DISTINCT family_id
        FROM tracking_geofences
        WHERE active = 1
        UNION
        SELECT DISTINCT family_id
        FROM tracking_places
    ");
    $stmt->execute();
    $familyIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($familyIds)) {
        echo "[recompute_geofence_states] No families with active geofences/places. Skipping.\n";
        exit(0);
    }

    foreach ($familyIds as $familyId) {
        $familyId = (int)$familyId;

        // Get current locations for all active members in this family
        $members = $locationRepo->getFamilyCurrentLocations($familyId);

        if (empty($members)) {
            continue;
        }

        foreach ($members as $member) {
            // Members without a fix yet come back with NULL lat/lng — never
            // evaluate them against geofences (that fired bogus exit events
            // at Null Island for anyone with a lingering is_inside state).
            if ($member['lat'] === null || $member['lng'] === null) {
                continue;
            }

            $userId = (int)$member['user_id'];
            $lat = (float)$member['lat'];
            $lng = (float)$member['lng'];
            $name = $member['name'] ?? 'User';

            // Skip if location is too stale (older than 1 hour). recorded_at
            // is UTC — parsing it as server-local (+02:00) made every fresh
            // fix look 2h old, so this safety-net job silently processed
            // no one. Time::parse reads the UTC convention correctly.
            $recordedRaw = $member['recorded_at'] ?? $member['updated_at'] ?? '';
            $recordedAt = $recordedRaw !== '' ? Time::parse($recordedRaw) : 0;
            if ($recordedAt && (time() - $recordedAt) > 3600) {
                continue;
            }

            // Re-process through the geofence engine
            // The engine handles state comparison internally and only fires
            // events/alerts on actual transitions
            $accuracy = isset($member['accuracy_m']) ? (float) $member['accuracy_m'] : null;
            $geofenceEngine->process($familyId, $userId, $lat, $lng, $name, $accuracy);
            $processed++;
        }

        // Clear family's cached geofence data to force fresh reads on next tick
        $trackingCache->deleteGeofences($familyId);
    }

    $elapsed = round(microtime(true) - $startTime, 3);
    echo "[recompute_geofence_states] Complete. Processed {$processed} member(s) across " .
         count($familyIds) . " family(ies). Time: {$elapsed}s\n";

} catch (Exception $e) {
    error_log('[recompute_geofence_states] ERROR: ' . $e->getMessage());
    echo "[recompute_geofence_states] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
