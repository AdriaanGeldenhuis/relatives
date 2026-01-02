<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $date = $_GET['date'] ?? date('Y-m-d');
    $date = date('Y-m-d', strtotime($date));
    
    $stmt = $db->prepare("
        SELECT e.*, u.full_name, u.avatar_color
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.family_id = (SELECT family_id FROM users WHERE id = ?)
          AND DATE(e.starts_at) = ?
          AND e.kind IN ('work', 'study', 'todo')
          AND e.status != 'cancelled'
        ORDER BY e.starts_at ASC
    ");
    
    $stmt->execute([$_SESSION['user_id'], $date]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to proper types
    foreach ($events as &$event) {
        $event['id'] = (int)$event['id'];
        $event['user_id'] = (int)$event['user_id'];
        $event['family_id'] = (int)$event['family_id'];
        $event['reminder_minutes'] = $event['reminder_minutes'] ? (int)$event['reminder_minutes'] : null;
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'date' => $date
    ]);
    
} catch (Exception $e) {
    error_log('Get events error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load events'
    ]);
}