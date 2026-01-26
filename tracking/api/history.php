<?php
/**
 * GET /tracking/api/history.php
 *
 * Get location history for a user or family.
 *
 * Parameters:
 * - user_id (optional): specific user, defaults to all family members
 * - start_time (optional): ISO8601 or Unix timestamp
 * - end_time (optional): ISO8601 or Unix timestamp
 * - limit (optional): max points to return (default 100, max 1000)
 * - offset (optional): for pagination
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Parse parameters
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Parse time filters
$startTime = null;
$endTime = null;

if (isset($_GET['start_time'])) {
    $startTime = is_numeric($_GET['start_time'])
        ? date('Y-m-d H:i:s', (int)$_GET['start_time'])
        : $_GET['start_time'];
}

if (isset($_GET['end_time'])) {
    $endTime = is_numeric($_GET['end_time'])
        ? date('Y-m-d H:i:s', (int)$_GET['end_time'])
        : $_GET['end_time'];
}

// Default to last 24 hours if no start time
if (!$startTime) {
    $startTime = Time::subSeconds(86400);
}

// Initialize services
$locationRepo = new LocationRepo($db, $trackingCache);

// Fetch history
$options = [
    'limit' => $limit,
    'offset' => $offset,
    'start_time' => $startTime,
    'end_time' => $endTime
];

if ($userId) {
    // Verify user belongs to family
    $stmt = $db->prepare("SELECT 1 FROM users WHERE id = ? AND family_id = ?");
    $stmt->execute([$userId, $familyId]);
    if (!$stmt->fetchColumn()) {
        jsonError('user_not_found', 'User not in your family', 404);
    }

    $history = $locationRepo->getHistory($userId, $familyId, $options);
} else {
    // Get family history
    $history = $locationRepo->getFamilyHistory($familyId, $options);
}

jsonSuccess([
    'history' => $history,
    'count' => count($history),
    'filters' => [
        'user_id' => $userId,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'limit' => $limit,
        'offset' => $offset
    ]
]);
