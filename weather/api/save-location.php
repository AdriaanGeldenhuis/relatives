<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    if (!isset($input['lat']) || !isset($input['lng'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing coordinates']);
        exit;
    }
    
    $lat = (float)$input['lat'];
    $lng = (float)$input['lng'];
    
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid coordinates']);
        exit;
    }
    
    $accuracy = isset($input['accuracy']) ? (int)$input['accuracy'] : null;
    $batteryLevel = isset($input['battery_level']) ? (int)$input['battery_level'] : null;
    
    if ($accuracy !== null && ($accuracy < 0 || $accuracy > 100000)) {
        $accuracy = null;
    }
    
    if ($batteryLevel !== null && ($batteryLevel < 0 || $batteryLevel > 100)) {
        $batteryLevel = null;
    }
    
    $stmt = $db->prepare("
        INSERT INTO locations 
        (user_id, lat, lng, accuracy_m, battery_level, recorded_at, created_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $success = $stmt->execute([
        $_SESSION['user_id'],
        $lat,
        $lng,
        $accuracy,
        $batteryLevel
    ]);
    
    if (!$success) {
        throw new Exception('Database insert failed');
    }
    
    $locationId = $db->lastInsertId();
    
    try {
        // Get the IDs to keep (last 100 records)
        $keepStmt = $db->prepare("
            SELECT id FROM locations
            WHERE user_id = ?
            ORDER BY recorded_at DESC
            LIMIT 100
        ");
        $keepStmt->execute([$_SESSION['user_id']]);
        $keepIds = $keepStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Delete old records if we have any to keep
        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $cleanupStmt = $db->prepare("
                DELETE FROM locations
                WHERE user_id = ?
                AND id NOT IN ($placeholders)
            ");
            $params = array_merge([$_SESSION['user_id']], $keepIds);
            $cleanupStmt->execute($params);
        }
    } catch (Exception $e) {
        error_log('Cleanup warning: ' . $e->getMessage());
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Location saved',
        'data' => [
            'id' => (int)$locationId,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => $accuracy,
            'battery_level' => $batteryLevel,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Location save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}