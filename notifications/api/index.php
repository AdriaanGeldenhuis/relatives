<?php
/**
 * NOTIFICATIONS API ENDPOINT
 * Handles notification actions
 */

session_start();

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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'mark_read':
                $notifId = (int)$_POST['notification_id'];
                
                $stmt = $db->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notifId, $user['id']]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'mark_all_read':
                $stmt = $db->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE user_id = ? AND is_read = 0
                ");
                $stmt->execute([$user['id']]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_notification':
                $notifId = (int)$_POST['notification_id'];
                
                $stmt = $db->prepare("
                    DELETE FROM notifications 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notifId, $user['id']]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'clear_all':
                $stmt = $db->prepare("
                    DELETE FROM notifications 
                    WHERE user_id = ? AND is_read = 1
                ");
                $stmt->execute([$user['id']]);
                
                echo json_encode(['success' => true]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}