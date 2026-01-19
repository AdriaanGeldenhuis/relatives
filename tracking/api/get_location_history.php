<?php
declare(strict_types=1);

/**
 * ============================================
 * GET LOCATION HISTORY v2.0
 * ============================================
 *
 * DB ONLY - No cache
 * Paginated with offset/limit
 * Ordered by created_at DESC
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $currentUserId = (int)$_SESSION['user_id'];
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUserId;

    // Pagination params
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Optional date filter
    $date = $_GET['date'] ?? null;

    // Validate date format if provided
    if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_date_format']);
        exit;
    }

    // Get current user's family
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$currentUserId]);
    $currentUser = $stmt->fetch();

    if (!$currentUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }

    // Verify target user is in same family
    $stmt = $db->prepare("
        SELECT family_id, full_name
        FROM users
        WHERE id = ? AND family_id = ?
        LIMIT 1
    ");
    $stmt->execute([$targetUserId, $currentUser['family_id']]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'access_denied']);
        exit;
    }

    // Build query based on date filter
    if ($date) {
        // Get location points for specific date
        $stmt = $db->prepare("
            SELECT
                id,
                latitude,
                longitude,
                accuracy_m,
                speed_kmh,
                heading_deg,
                battery_level,
                is_moving,
                created_at
            FROM tracking_locations
            WHERE user_id = ?
              AND DATE(created_at) = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$targetUserId, $date, $limit, $offset]);

        // Get total count for pagination
        $countStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM tracking_locations
            WHERE user_id = ? AND DATE(created_at) = ?
        ");
        $countStmt->execute([$targetUserId, $date]);
    } else {
        // Get all location points (paginated)
        $stmt = $db->prepare("
            SELECT
                id,
                latitude,
                longitude,
                accuracy_m,
                speed_kmh,
                heading_deg,
                battery_level,
                is_moving,
                created_at
            FROM tracking_locations
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$targetUserId, $limit, $offset]);

        // Get total count for pagination
        $countStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM tracking_locations
            WHERE user_id = ?
        ");
        $countStmt->execute([$targetUserId]);
    }

    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCount = (int)$countStmt->fetch()['total'];

    // Format points
    $formattedPoints = [];
    foreach ($points as $point) {
        $formattedPoints[] = [
            'id' => (int)$point['id'],
            'lat' => (float)$point['latitude'],
            'lng' => (float)$point['longitude'],
            'accuracy' => $point['accuracy_m'] ? (int)$point['accuracy_m'] : null,
            'speed' => $point['speed_kmh'] ? (float)$point['speed_kmh'] : null,
            'heading' => $point['heading_deg'] ? (float)$point['heading_deg'] : null,
            'battery' => $point['battery_level'] ? (int)$point['battery_level'] : null,
            'moving' => (bool)$point['is_moving'],
            'ts' => $point['created_at']
        ];
    }

    // Detect stops (only if date filter is used - for timeline view)
    $stops = [];

    if ($date && count($points) > 0) {
        // Reverse to ASC order for stop detection
        $ascPoints = array_reverse($points);

        $currentStop = null;
        $stopThresholdMeters = 50;
        $stopMinMinutes = 5;

        foreach ($ascPoints as $point) {
            if ($point['is_moving'] == 0 || ($point['speed_kmh'] !== null && $point['speed_kmh'] < 1)) {
                if ($currentStop === null) {
                    $currentStop = [
                        'latitude' => (float)$point['latitude'],
                        'longitude' => (float)$point['longitude'],
                        'start_time' => $point['created_at'],
                        'end_time' => $point['created_at'],
                        'points' => 1
                    ];
                } else {
                    $distance = haversineDistance(
                        (float)$currentStop['latitude'],
                        (float)$currentStop['longitude'],
                        (float)$point['latitude'],
                        (float)$point['longitude']
                    );

                    if ($distance < $stopThresholdMeters) {
                        $currentStop['end_time'] = $point['created_at'];
                        $currentStop['points']++;
                    } else {
                        $duration = (strtotime($currentStop['end_time']) - strtotime($currentStop['start_time'])) / 60;
                        if ($duration >= $stopMinMinutes) {
                            $stops[] = [
                                'lat' => $currentStop['latitude'],
                                'lng' => $currentStop['longitude'],
                                'start' => date('H:i', strtotime($currentStop['start_time'])),
                                'end' => date('H:i', strtotime($currentStop['end_time'])),
                                'duration_min' => round($duration)
                            ];
                        }
                        $currentStop = [
                            'latitude' => (float)$point['latitude'],
                            'longitude' => (float)$point['longitude'],
                            'start_time' => $point['created_at'],
                            'end_time' => $point['created_at'],
                            'points' => 1
                        ];
                    }
                }
            } else {
                if ($currentStop !== null) {
                    $duration = (strtotime($currentStop['end_time']) - strtotime($currentStop['start_time'])) / 60;
                    if ($duration >= $stopMinMinutes) {
                        $stops[] = [
                            'lat' => $currentStop['latitude'],
                            'lng' => $currentStop['longitude'],
                            'start' => date('H:i', strtotime($currentStop['start_time'])),
                            'end' => date('H:i', strtotime($currentStop['end_time'])),
                            'duration_min' => round($duration)
                        ];
                    }
                    $currentStop = null;
                }
            }
        }

        // Finalize last stop
        if ($currentStop !== null) {
            $duration = (strtotime($currentStop['end_time']) - strtotime($currentStop['start_time'])) / 60;
            if ($duration >= $stopMinMinutes) {
                $stops[] = [
                    'lat' => $currentStop['latitude'],
                    'lng' => $currentStop['longitude'],
                    'start' => date('H:i', strtotime($currentStop['start_time'])),
                    'end' => date('H:i', strtotime($currentStop['end_time'])),
                    'duration_min' => round($duration)
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'user_name' => $targetUser['full_name'],
        'date' => $date,
        'pagination' => [
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount
        ],
        'points' => $formattedPoints,
        'stops' => $stops
    ]);

} catch (Exception $e) {
    error_log('get_location_history ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}

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
