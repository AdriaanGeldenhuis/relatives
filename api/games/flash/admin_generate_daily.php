<?php
declare(strict_types=1);

/**
 * ============================================
 * API: POST /api/games/flash/admin_generate_daily.php
 * Admin endpoint to generate/regenerate daily challenge
 * ============================================
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Load dependencies
require_once __DIR__ . '/../../../core/bootstrap.php';
require_once __DIR__ . '/FlashHelper.php';

try {
    // Check if user is admin
    $userId = (int) $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $isAdmin = (bool) $stmt->fetchColumn();

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    $helper = new FlashHelper($db);
    $today = $helper->getTodayDate();

    // Parse input
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetDate = $input['date'] ?? $today;
    $force = (bool) ($input['force'] ?? false);

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid date format (use YYYY-MM-DD)']);
        exit;
    }

    // Check if challenge already exists
    $existingChallenge = $helper->getDailyChallenge($targetDate);

    if ($existingChallenge && !$force) {
        echo json_encode([
            'success' => true,
            'message' => 'Challenge already exists for this date',
            'challenge' => $existingChallenge,
            'was_existing' => true
        ]);
        exit;
    }

    // Check if there are attempts for this date (prevent regenerating if played)
    if ($existingChallenge && $force) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM flash_attempts WHERE challenge_date = ?");
        $stmt->execute([$targetDate]);
        $attemptCount = (int) $stmt->fetchColumn();

        if ($attemptCount > 0) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => "Cannot regenerate challenge for {$targetDate}: {$attemptCount} attempts already exist"
            ]);
            exit;
        }
    }

    // Generate new challenge
    $newChallenge = $helper->generateDailyChallenge($targetDate);

    if (!$newChallenge) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to generate challenge']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $force && $existingChallenge ? 'Challenge regenerated' : 'Challenge generated',
        'challenge' => $newChallenge,
        'was_existing' => false
    ]);

} catch (Exception $e) {
    error_log('Flash admin_generate_daily error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
