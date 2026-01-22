<?php
/**
 * ============================================
 * HOLIDAY TRAVELING API - TRIPS
 * ============================================
 */

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetTrips($db, $user);
        break;
    case 'POST':
        handleCreateTrip($db, $user);
        break;
    case 'PUT':
        handleUpdateTrip($db, $user);
        break;
    case 'DELETE':
        handleDeleteTrip($db, $user);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function handleGetTrips($db, $user) {
    try {
        $stmt = $db->prepare("
            SELECT * FROM holiday_travels
            WHERE family_id = ?
            ORDER BY start_date ASC
        ");
        $stmt->execute([$user['family_id']]);
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'trips' => $trips]);
    } catch (Exception $e) {
        error_log('Get trips error: ' . $e->getMessage());
        echo json_encode(['success' => true, 'trips' => []]);
    }
}

function handleCreateTrip($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        return;
    }

    $destination = trim($input['destination'] ?? '');
    $startDate = $input['start_date'] ?? '';
    $endDate = $input['end_date'] ?? '';
    $notes = trim($input['notes'] ?? '');
    $travelers = $input['travelers'] ?? [];

    if (empty($destination) || empty($startDate) || empty($endDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Destination and dates are required']);
        return;
    }

    // Ensure table exists
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS holiday_travels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                family_id INT NOT NULL,
                destination VARCHAR(255) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                notes TEXT,
                travelers JSON,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_family (family_id),
                INDEX idx_dates (start_date, end_date)
            )
        ");
    } catch (Exception $e) {
        // Table might already exist or be a different engine, ignore
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO holiday_travels (family_id, destination, start_date, end_date, notes, travelers, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['family_id'],
            $destination,
            $startDate,
            $endDate,
            $notes,
            json_encode($travelers),
            $user['id']
        ]);

        $tripId = $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'trip_id' => $tripId,
            'message' => 'Trip created successfully'
        ]);
    } catch (Exception $e) {
        error_log('Create trip error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create trip']);
    }
}

function handleUpdateTrip($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        return;
    }

    $tripId = (int)$input['id'];
    $destination = trim($input['destination'] ?? '');
    $startDate = $input['start_date'] ?? '';
    $endDate = $input['end_date'] ?? '';
    $notes = trim($input['notes'] ?? '');
    $travelers = $input['travelers'] ?? [];

    try {
        $stmt = $db->prepare("
            UPDATE holiday_travels
            SET destination = ?, start_date = ?, end_date = ?, notes = ?, travelers = ?
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([
            $destination,
            $startDate,
            $endDate,
            $notes,
            json_encode($travelers),
            $tripId,
            $user['family_id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Trip updated successfully']);
    } catch (Exception $e) {
        error_log('Update trip error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update trip']);
    }
}

function handleDeleteTrip($db, $user) {
    $tripId = $_GET['id'] ?? null;

    if (!$tripId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Trip ID required']);
        return;
    }

    try {
        $stmt = $db->prepare("
            DELETE FROM holiday_travels
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([(int)$tripId, $user['family_id']]);

        echo json_encode(['success' => true, 'message' => 'Trip deleted successfully']);
    } catch (Exception $e) {
        error_log('Delete trip error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete trip']);
    }
}
