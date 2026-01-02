<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $start = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('sunday this week'));
    
    $stmt = $db->prepare("
        SELECT 
            kind,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
            SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_minutes
        FROM events
        WHERE family_id = (SELECT family_id FROM users WHERE id = ?)
          AND DATE(starts_at) >= ?
          AND DATE(starts_at) <= ?
          AND kind IN ('work', 'study')
          AND status != 'cancelled'
        GROUP BY kind
    ");
    
    $stmt->execute([$_SESSION['user_id'], $start, $end]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [
        'study' => ['time' => 0, 'count' => 0, 'done' => 0],
        'work' => ['time' => 0, 'count' => 0, 'done' => 0]
    ];
    
    foreach ($results as $row) {
        $kind = $row['kind'];
        if (isset($stats[$kind])) {
            $stats[$kind] = [
                'time' => (int)$row['total_minutes'],
                'count' => (int)$row['total'],
                'done' => (int)$row['done']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'period' => [
            'start' => $start,
            'end' => $end
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Get stats error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load stats'
    ]);
}