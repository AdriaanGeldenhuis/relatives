<?php
declare(strict_types=1);

/**
 * UPDATE LOCATION - WITH GEOFENCE & BATTERY NOTIFICATIONS
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cookie');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

session_start();

if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_COOKIE'])) {
        preg_match('/RELATIVES_SESSION=([^;]+)/', $_SERVER['HTTP_COOKIE'], $matches);
        
        if (isset($matches[1])) {
            $cookieSessionId = $matches[1];
            
            try {
                require_once __DIR__ . '/../../core/bootstrap.php';
                
                $stmt = $db->prepare("
                    SELECT s.user_id, u.family_id 
                    FROM sessions s
                    JOIN users u ON s.user_id = u.id
                    WHERE s.session_token = ? 
                      AND s.expires_at > NOW()
                      AND u.status = 'active'
                    LIMIT 1
                ");
                
                $stmt->execute([hash('sha256', $cookieSessionId)]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($session) {
                    $_SESSION['user_id'] = (int)$session['user_id'];
                    $_SESSION['family_id'] = (int)$session['family_id'];
                    error_log("Cookie auth successful for user: {$session['user_id']}");
                } else {
                    error_log("Invalid or expired session cookie");
                }
                
            } catch (Exception $e) {
                error_log("Cookie auth error: " . $e->getMessage());
            }
        }
    }
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/NotificationTriggers.php';

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }
    
    $requiredFields = ['device_uuid', 'latitude', 'longitude'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "missing_field_{$field}"]);
            exit;
        }
    }
    
    $deviceUuid = trim($input['device_uuid']);
    $latitude = (float)$input['latitude'];
    $longitude = (float)$input['longitude'];
    $accuracyM = isset($input['accuracy_m']) ? (int)$input['accuracy_m'] : null;
    $speedKmh = isset($input['speed_kmh']) ? (float)$input['speed_kmh'] : null;
    $headingDeg = isset($input['heading_deg']) ? (float)$input['heading_deg'] : null;
    $altitudeM = isset($input['altitude_m']) ? (float)$input['altitude_m'] : null;
    $isMoving = isset($input['is_moving']) ? (int)(bool)$input['is_moving'] : 0;
    $batteryLevel = isset($input['battery_level']) ? (int)$input['battery_level'] : null;
    $source = $input['source'] ?? 'native';
    
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_coordinates']);
        exit;
    }
    
    if ($batteryLevel !== null && ($batteryLevel < 0 || $batteryLevel > 100)) {
        $batteryLevel = null;
    }
    
    $userId = (int)$_SESSION['user_id'];
    
    $stmt = $db->prepare("SELECT family_id, full_name FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }
    
    $familyId = (int)$user['family_id'];
    $userName = $user['full_name'];
    
    // ========== SUBSCRIPTION LOCK CHECK ==========
    require_once __DIR__ . '/../../core/SubscriptionManager.php';
    
    $subscriptionManager = new SubscriptionManager($db);
    
    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
        ]);
        exit;
    }
    // ========== END SUBSCRIPTION LOCK ==========
    
    $stmt = $db->prepare("
        SELECT id FROM tracking_devices 
        WHERE device_uuid = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$deviceUuid, $userId]);
    $device = $stmt->fetch();
    
    if ($device) {
        $deviceId = (int)$device['id'];
        
        $stmt = $db->prepare("
            UPDATE tracking_devices 
            SET last_seen = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$deviceId]);
        
    } else {
        $platform = $input['platform'] ?? 'android';
        $deviceName = $input['device_name'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO tracking_devices 
            (user_id, device_uuid, platform, device_name, last_seen, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([$userId, $deviceUuid, $platform, $deviceName]);
        $deviceId = (int)$db->lastInsertId();
    }
    
    $stmt = $db->prepare("
        INSERT INTO tracking_locations 
        (device_id, user_id, family_id, latitude, longitude, accuracy_m, speed_kmh, 
         heading_deg, altitude_m, is_moving, battery_level, source, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $deviceId,
        $userId,
        $familyId,
        $latitude,
        $longitude,
        $accuracyM,
        $speedKmh,
        $headingDeg,
        $altitudeM,
        $isMoving,
        $batteryLevel,
        $source
    ]);
    
    $locationId = (int)$db->lastInsertId();
    
    // ========== SEND NOTIFICATIONS ==========
    try {
        $triggers = new NotificationTriggers($db);
        
        // LOW BATTERY NOTIFICATION
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
            }
        }
        
        // GEOFENCE CHECK
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
            
            // State changed
            if ($insideZone && !$wasInside) {
                // ENTERED ZONE
                $triggers->onGeofenceEnter($userId, $familyId, $zone['name'], $userName);
                
                $stmt = $db->prepare("
                    INSERT INTO tracking_events 
                    (user_id, family_id, device_id, event_type, zone_id, latitude, longitude, created_at)
                    VALUES (?, ?, ?, 'enter_zone', ?, ?, ?, NOW())
                ");
                $stmt->execute([$userId, $familyId, $deviceId, $zone['id'], $latitude, $longitude]);
                
            } elseif (!$insideZone && $wasInside) {
                // EXITED ZONE
                $triggers->onGeofenceExit($userId, $familyId, $zone['name'], $userName);
                
                $stmt = $db->prepare("
                    INSERT INTO tracking_events 
                    (user_id, family_id, device_id, event_type, zone_id, latitude, longitude, created_at)
                    VALUES (?, ?, ?, 'exit_zone', ?, ?, ?, NOW())
                ");
                $stmt->execute([$userId, $familyId, $deviceId, $zone['id'], $latitude, $longitude]);
            }
        }
        
    } catch (Exception $e) {
        error_log('Location notification error: ' . $e->getMessage());
    }
    
    // Cleanup old locations (keep last 1000 per user)
    try {
        $stmt = $db->prepare("
            SELECT id FROM tracking_locations
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1000
        ");
        $stmt->execute([$userId]);
        $keepIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $stmt = $db->prepare("
                DELETE FROM tracking_locations
                WHERE user_id = ?
                AND id NOT IN ($placeholders)
            ");
            $params = array_merge([$userId], $keepIds);
            $stmt->execute($params);
            
            $deleted = $stmt->rowCount();
            if ($deleted > 0) {
                error_log("Cleaned up $deleted old location records for user $userId");
            }
        }
    } catch (Exception $e) {
        error_log('Cleanup warning: ' . $e->getMessage());
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'location_id' => $locationId,
        'device_id' => $deviceId,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('Location update error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
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