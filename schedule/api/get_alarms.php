<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $stmt = $db->prepare("
        SELECT e.*, u.full_name, u.avatar_color
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.family_id = (SELECT family_id FROM users WHERE id = ?)
          AND e.status = 'pending'
          AND e.reminder_minutes IS NOT NULL
          AND e.reminder_minutes > 0
          AND e.starts_at >= NOW()
          AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
        ORDER BY e.starts_at ASC
        LIMIT 10
    ");
    
    $stmt->execute([$_SESSION['user_id']]);
    $alarms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to proper types
    foreach ($alarms as &$alarm) {
        $alarm['id'] = (int)$alarm['id'];
        $alarm['reminder_minutes'] = (int)$alarm['reminder_minutes'];
    }
    
    echo json_encode([
        'success' => true,
        'alarms' => $alarms
    ]);
    
} catch (Exception $e) {
    error_log('Get alarms error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load alarms'
    ]);
}