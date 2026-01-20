#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ============================================
 * GEOFENCE PROCESSOR - Async Cron Job
 * ============================================
 *
 * Run via cron every minute:
 *   * * * * * php /path/to/tracking/jobs/process_geofences.php >> /var/log/geofence.log 2>&1
 *
 * Or use a process manager like supervisor for continuous processing.
 *
 * This script:
 * 1. Fetches pending items from tracking_geofence_queue
 * 2. Checks each location against family zones
 * 3. Sends enter/exit notifications
 * 4. Logs battery_low events
 * 5. Marks items as processed
 */

// Ensure this only runs from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Lock file to prevent overlapping runs
$lockFile = sys_get_temp_dir() . '/geofence_processor.lock';
$lockHandle = fopen($lockFile, 'c');

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another instance is running, exiting.\n";
    exit(0);
}

// Write PID
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());

// Cleanup on exit
register_shutdown_function(function() use ($lockHandle, $lockFile) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
});

// Load bootstrap
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/NotificationTriggers.php';

$startTime = microtime(true);
$processed = 0;
$errors = 0;
$notifications = 0;

echo "[" . date('Y-m-d H:i:s') . "] Geofence processor starting...\n";

try {
    // Get pending queue items (process in batches)
    $batchSize = 100;
    $maxRuntime = 55; // Stop after 55 seconds to avoid cron overlap

    do {
        // Check runtime
        if ((microtime(true) - $startTime) > $maxRuntime) {
            echo "[" . date('Y-m-d H:i:s') . "] Max runtime reached, stopping.\n";
            break;
        }

        // Fetch batch of pending items
        // Fix #11: Also retry failed items from the last hour (up to 3 implicit retries via time window)
        $stmt = $db->prepare("
            SELECT q.*, u.full_name
            FROM tracking_geofence_queue q
            JOIN users u ON q.user_id = u.id
            WHERE q.status = 'pending'
               OR (q.status = 'failed' AND q.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR))
            ORDER BY q.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$batchSize]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            break;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Processing batch of " . count($items) . " items...\n";

        foreach ($items as $item) {
            try {
                $queueId = (int)$item['id'];
                $userId = (int)$item['user_id'];
                $familyId = (int)$item['family_id'];
                $deviceId = (int)$item['device_id'];
                $latitude = (float)$item['latitude'];
                $longitude = (float)$item['longitude'];
                $batteryLevel = $item['battery_level'] ? (int)$item['battery_level'] : null;
                $userName = $item['full_name'];

                $triggers = new NotificationTriggers($db);

                // ========== LOW BATTERY CHECK ==========
                if ($batteryLevel !== null && $batteryLevel <= 15) {
                    // Check if we already notified recently (within last hour)
                    $stmt = $db->prepare("
                        SELECT id FROM tracking_events
                        WHERE user_id = ?
                          AND event_type = 'battery_low'
                          AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        LIMIT 1
                    ");
                    $stmt->execute([$userId]);
                    $recentBatteryAlert = $stmt->fetch();

                    if (!$recentBatteryAlert) {
                        $triggers->onLowBattery($userId, $familyId, $userName, $batteryLevel);
                        $notifications++;

                        // Log event
                        $stmt = $db->prepare("
                            INSERT INTO tracking_events
                            (user_id, family_id, device_id, event_type, latitude, longitude, payload_json, created_at)
                            VALUES (?, ?, ?, 'battery_low', ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $userId,
                            $familyId,
                            $deviceId,
                            $latitude,
                            $longitude,
                            json_encode(['battery_level' => $batteryLevel])
                        ]);

                        echo "  - Battery low notification for user $userId ($batteryLevel%)\n";
                    }
                }

                // ========== GEOFENCE CHECK ==========
                $stmt = $db->prepare("
                    SELECT id, name, type, center_lat, center_lng, radius_m, polygon_json
                    FROM tracking_zones
                    WHERE family_id = ? AND is_active = 1
                ");
                $stmt->execute([$familyId]);
                $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($zones as $zone) {
                    $insideZone = false;

                    if ($zone['type'] === 'circle') {
                        $distance = haversineDistance(
                            $latitude,
                            $longitude,
                            (float)$zone['center_lat'],
                            (float)$zone['center_lng']
                        );
                        $insideZone = ($distance <= (int)$zone['radius_m']);
                    } elseif ($zone['type'] === 'polygon' && $zone['polygon_json']) {
                        $polygon = json_decode($zone['polygon_json'], true);
                        if ($polygon) {
                            $insideZone = isPointInPolygon($latitude, $longitude, $polygon);
                        }
                    }

                    // Check previous state
                    $stmt = $db->prepare("
                        SELECT event_type
                        FROM tracking_events
                        WHERE user_id = ? AND zone_id = ?
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$userId, $zone['id']]);
                    $lastEvent = $stmt->fetch(PDO::FETCH_ASSOC);

                    $wasInside = ($lastEvent && $lastEvent['event_type'] === 'enter_zone');

                    // State changed?
                    if ($insideZone && !$wasInside) {
                        // ENTERED ZONE
                        $triggers->onGeofenceEnter($userId, $familyId, $zone['name'], $userName);
                        $notifications++;

                        $stmt = $db->prepare("
                            INSERT INTO tracking_events
                            (user_id, family_id, device_id, event_type, zone_id, latitude, longitude, created_at)
                            VALUES (?, ?, ?, 'enter_zone', ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$userId, $familyId, $deviceId, $zone['id'], $latitude, $longitude]);

                        echo "  - User $userId entered zone: {$zone['name']}\n";

                    } elseif (!$insideZone && $wasInside) {
                        // EXITED ZONE
                        $triggers->onGeofenceExit($userId, $familyId, $zone['name'], $userName);
                        $notifications++;

                        $stmt = $db->prepare("
                            INSERT INTO tracking_events
                            (user_id, family_id, device_id, event_type, zone_id, latitude, longitude, created_at)
                            VALUES (?, ?, ?, 'exit_zone', ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$userId, $familyId, $deviceId, $zone['id'], $latitude, $longitude]);

                        echo "  - User $userId exited zone: {$zone['name']}\n";
                    }
                }

                // Mark as processed
                $stmt = $db->prepare("
                    UPDATE tracking_geofence_queue
                    SET status = 'processed', processed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$queueId]);

                $processed++;

            } catch (Exception $e) {
                $errors++;
                error_log("Geofence processor error for queue item {$item['id']}: " . $e->getMessage());

                // Mark as failed
                try {
                    $stmt = $db->prepare("
                        UPDATE tracking_geofence_queue
                        SET status = 'failed', processed_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$item['id']]);
                } catch (Exception $e2) {
                    // Ignore
                }
            }
        }

    } while (count($items) === $batchSize);

    // ========== CLEANUP OLD QUEUE ITEMS ==========
    // Fix #11: Different cleanup windows for processed vs failed items
    try {
        // Processed items: keep for 24 hours for debugging
        $stmt = $db->prepare("
            DELETE FROM tracking_geofence_queue
            WHERE status = 'processed'
              AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $deletedProcessed = $stmt->rowCount();

        // Failed items: cleanup after 1 hour (they've already been retried)
        $stmt = $db->prepare("
            DELETE FROM tracking_geofence_queue
            WHERE status = 'failed'
              AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $deletedFailed = $stmt->rowCount();

        $deleted = $deletedProcessed + $deletedFailed;
        if ($deleted > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Cleaned up $deleted old queue items (processed: $deletedProcessed, failed: $deletedFailed).\n";
        }
    } catch (Exception $e) {
        // Cleanup errors are non-fatal
    }

} catch (Exception $e) {
    error_log("Geofence processor fatal error: " . $e->getMessage());
    echo "[" . date('Y-m-d H:i:s') . "] FATAL: " . $e->getMessage() . "\n";
    exit(1);
}

$duration = round(microtime(true) - $startTime, 2);
echo "[" . date('Y-m-d H:i:s') . "] Completed: $processed processed, $notifications notifications, $errors errors in {$duration}s\n";

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Calculate distance between two points using Haversine formula
 */
function haversineDistance($lat1, $lon1, $lat2, $lon2): float {
    $earthRadius = 6371000; // meters

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadius * $c;
}

/**
 * Check if point is inside polygon
 */
function isPointInPolygon($lat, $lng, $polygon): bool {
    $inside = false;
    $count = count($polygon);

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $xi = $polygon[$i]['lat'];
        $yi = $polygon[$i]['lng'];
        $xj = $polygon[$j]['lat'];
        $yj = $polygon[$j]['lng'];

        $intersect = (($yi > $lng) != ($yj > $lng))
            && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);

        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}
