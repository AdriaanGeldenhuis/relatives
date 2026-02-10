<?php
/**
 * API Endpoint: /api/me.php
 * Returns current user information including family_id.
 */

declare(strict_types=1);

// Start session with correct name to match bootstrap config
if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
    session_start();
}

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Load bootstrap to get DB connection for fresh user data
require_once __DIR__ . '/../core/bootstrap.php';

$userId = (int) $_SESSION['user_id'];

// Fetch actual user data from database instead of nonexistent session keys
$stmt = $db->prepare("
    SELECT u.id, u.full_name as name, u.email, u.role, u.family_id, u.avatar_color
    FROM users u
    WHERE u.id = ? AND u.status = 'active'
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Return user info
echo json_encode([
    'user_id' => (int) $user['id'],
    'display_name' => $user['name'],
    'family_id' => (int) $user['family_id'],
    'is_admin' => in_array($user['role'], ['owner', 'admin'])
], JSON_THROW_ON_ERROR);
