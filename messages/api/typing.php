<?php
/**
 * TYPING INDICATOR API
 * POST: {typing: true/false} - Set typing status
 * GET: - Get who is typing
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = $_SESSION['user_id'];

    // Get user's family
    $stmt = $db->prepare("SELECT family_id, full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('User not found');
    }

    $familyId = $user['family_id'];

    // typing_indicators table must exist via migration/schema
    // Removed runtime CREATE TABLE - DDL on every request is expensive and unnecessary

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // SET TYPING STATUS
        $input = json_decode(file_get_contents('php://input'), true);
        $isTyping = !empty($input['typing']);

        if ($isTyping) {
            // Insert or update typing indicator
            $stmt = $db->prepare("
                INSERT INTO typing_indicators (family_id, user_id, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $stmt->execute([$familyId, $userId]);
        } else {
            // Remove typing indicator
            $stmt = $db->prepare("
                DELETE FROM typing_indicators
                WHERE family_id = ? AND user_id = ?
            ");
            $stmt->execute([$familyId, $userId]);
        }

        echo json_encode(['success' => true]);

    } else {
        // GET WHO IS TYPING

        // Clean up old indicators (older than 5 seconds)
        $stmt = $db->prepare("
            DELETE FROM typing_indicators
            WHERE family_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)
        ");
        $stmt->execute([$familyId]);

        // Get current typers (exclude self)
        $stmt = $db->prepare("
            SELECT u.id, u.full_name as name
            FROM typing_indicators ti
            JOIN users u ON ti.user_id = u.id
            WHERE ti.family_id = ? AND ti.user_id != ?
            ORDER BY ti.updated_at DESC
        ");
        $stmt->execute([$familyId, $userId]);
        $typing = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'typing' => $typing
        ]);
    }

} catch (Exception $e) {
    error_log('Typing API error: ' . $e->getMessage());

    // Typing is non-critical - return graceful response instead of 500
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'typing' => []
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}