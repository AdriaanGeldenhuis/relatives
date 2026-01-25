<?php
/**
 * API Endpoint: /api/me.php
 * Returns current user information including family_id.
 */

declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

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

// Get user data from session
$userId = (int) $_SESSION['user_id'];
$displayName = $_SESSION['display_name'] ?? 'Player';
$familyId = isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;
$isAdmin = (bool) ($_SESSION['is_admin'] ?? false);

// Return user info
echo json_encode([
    'user_id' => $userId,
    'display_name' => $displayName,
    'family_id' => $familyId,
    'is_admin' => $isAdmin
], JSON_THROW_ON_ERROR);
