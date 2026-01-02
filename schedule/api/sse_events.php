<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Prevent timeout
set_time_limit(0);
ini_set('max_execution_time', 0);

// Get user's family_id
$stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    exit;
}

$familyId = $user['family_id'];
$date = $_GET['date'] ?? date('Y-m-d');

// Send initial connection message
echo "event: connected\n";
echo "data: " . json_encode(['status' => 'connected', 'timestamp' => time()]) . "\n\n";
flush();

// Track last check time
$lastCheck = time();
$lastEventId = 0;

// Get the latest event ID
$stmt = $db->prepare("
    SELECT MAX(id) as max_id 
    FROM events 
    WHERE family_id = ? AND DATE(starts_at) = ?
");
$stmt->execute([$familyId, $date]);
$result = $stmt->fetch();
$lastEventId = $result['max_id'] ?? 0;

// Keep connection alive and check for updates
while (true) {
    // Check if client is still connected
    if (connection_aborted()) {
        break;
    }
    
    // Check for new events every 3 seconds
    if (time() - $lastCheck >= 3) {
        try {
            // Check for new events
            $stmt = $db->prepare("
                SELECT e.*, u.full_name, u.avatar_color
                FROM events e
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.family_id = ?
                  AND DATE(e.starts_at) = ?
                  AND e.id > ?
                  AND e.status != 'cancelled'
                ORDER BY e.id ASC
            ");
            $stmt->execute([$familyId, $date, $lastEventId]);
            $newEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($newEvents as $event) {
                // Convert to proper types
                $event['id'] = (int)$event['id'];
                $event['user_id'] = (int)$event['user_id'];
                $event['family_id'] = (int)$event['family_id'];
                $event['reminder_minutes'] = $event['reminder_minutes'] ? (int)$event['reminder_minutes'] : null;
                
                // Send event to client
                echo "event: event_created\n";
                echo "data: " . json_encode(['event' => $event]) . "\n\n";
                flush();
                
                $lastEventId = max($lastEventId, $event['id']);
            }
            
            // Check for updated events
            $stmt = $db->prepare("
                SELECT e.*, u.full_name, u.avatar_color
                FROM events e
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.family_id = ?
                  AND DATE(e.starts_at) = ?
                  AND e.updated_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
                  AND e.id <= ?
            ");
            $stmt->execute([$familyId, $date, $lastEventId]);
            $updatedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($updatedEvents as $event) {
                // Convert to proper types
                $event['id'] = (int)$event['id'];
                $event['user_id'] = (int)$event['user_id'];
                $event['family_id'] = (int)$event['family_id'];
                
                echo "event: event_updated\n";
                echo "data: " . json_encode(['event' => $event]) . "\n\n";
                flush();
            }
            
            // Check for deleted events (status = cancelled)
            $stmt = $db->prepare("
                SELECT id
                FROM events
                WHERE family_id = ?
                  AND DATE(starts_at) = ?
                  AND status = 'cancelled'
                  AND updated_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
            ");
            $stmt->execute([$familyId, $date]);
            $deletedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedEvents as $event) {
                echo "event: event_deleted\n";
                echo "data: " . json_encode(['event_id' => (int)$event['id']]) . "\n\n";
                flush();
            }
            
            $lastCheck = time();
            
        } catch (Exception $e) {
            error_log('SSE error: ' . $e->getMessage());
        }
    }
    
    // Send keepalive ping every 15 seconds
    if (time() % 15 == 0) {
        echo ": keepalive\n\n";
        flush();
    }
    
    // Sleep to avoid CPU overload
    usleep(500000); // 0.5 seconds
}