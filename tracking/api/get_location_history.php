<?php
declare(strict_types=1);

/**
 * ============================================
 * GET LOCATION HISTORY - FIXED
 * ============================================
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
    $date = $_GET['date'] ?? date('Y-m-d');
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
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
    
    // Get location points for the date
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
        ORDER BY created_at ASC
    ");
    
    $stmt->execute([$targetUserId, $date]);
    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("get_location_history: Found " . count($points) . " points for user $targetUserId on $date");
    
    // Format points
    $formattedPoints = [];
    foreach ($points as $point) {
        $formattedPoints[] = [
            'id' => (int)$point['id'],
            'latitude' => (float)$point['latitude'],
            'longitude' => (float)$point['longitude'],
            'accuracy_m' => $point['accuracy_m'] ? (int)$point['accuracy_m'] : null,
            'speed_kmh' => $point['speed_kmh'] ? (float)$point['speed_kmh'] : null,
            'heading_deg' => $point['heading_deg'] ? (float)$point['heading_deg'] : null,
            'battery_level' => $point['battery_level'] ? (int)$point['battery_level'] : null,
            'is_moving' => (bool)$point['is_moving'],
            'timestamp' => $point['created_at']
        ];
    }
    
    // Detect stops
    $stops = [];
    
    if (count($points) > 0) {
        $currentStop = null;
        $stopThresholdMeters = 50;
        $stopMinMinutes = 5;
        
        foreach ($points as $i => $point) {
            if ($point['is_moving'] == 0 || ($point['speed_kmh'] !== null && $point['speed_kmh'] < 1)) {
                if ($currentStop === null) {
                    // Start new stop
                    $currentStop = [
                        'latitude' => (float)$point['latitude'],
                        'longitude' => (float)$point['longitude'],
                        'start_time' => $point['created_at'],
                        'end_time' => $point['created_at'],
                        'points' => 1
                    ];
                } else {
                    // Check if still at same location
                    $distance = haversineDistance(
                        (float)$currentStop['latitude'],
                        (float)$currentStop['longitude'],
                        (float)$point['latitude'],
                        (float)$point['longitude']
                    );
                    
                    if ($distance < $stopThresholdMeters) {
                        // Still at same stop
                        $currentStop['end_time'] = $point['created_at'];
                        $currentStop['points']++;
                    } else {
                        // Moved to new location, save previous stop if long enough
                        $duration = (strtotime($currentStop['end_time']) - strtotime($currentStop['start_time'])) / 60;
                        
                        if ($duration >= $stopMinMinutes) {
                            $stops[] = [
                                'latitude' => $currentStop['latitude'],
                                'longitude' => $currentStop['longitude'],
                                'start_time' => date('H:i', strtotime($currentStop['start_time'])),
                                'end_time' => date('H:i', strtotime($currentStop['end_time'])),
                                'duration_minutes' => round($duration)
                            ];
                        }
                        
                        // Start new stop
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
                // Moving - finalize current stop if exists
                if ($currentStop !== null) {
                    $duration = (strtotime($currentStop['end_time']) - strtotime($currentStop['start_time'])) / 60;
                    
                    if ($duration >= $stopMinMinutes) {
                        $stops[] = [
                            'latitude' => $currentStop['latitude'],
                            'longitude' => $currentStop['longitude'],
                            'start_time' => date('H:i', strtotime($currentStop['start_time'])),
                            'end_time' => date('H:i', strtotime($currentStop['end_time'])),
                            'duration_minutes' => round($duration)
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
                    'latitude' => $currentStop['latitude'],
                    'longitude' => $currentStop['longitude'],
                    'start_time' => date('H:i', strtotime($currentStop['start_time'])),
                    'end_time' => date('H:i', strtotime($currentStop['end_time'])),
                    'duration_minutes' => round($duration)
                ];
            }
        }
    }
    
    error_log("get_location_history: Detected " . count($stops) . " stops");
    
    echo json_encode([
        'success' => true,
        'user_name' => $targetUser['full_name'],
        'date' => $date,
        'points' => $formattedPoints,
        'stops' => $stops
    ]);
    
} catch (Exception $e) {
    error_log('get_location_history ERROR: ' . $e->getMessage());
    error_log('get_location_history TRACE: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error', 'details' => $e->getMessage()]);
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