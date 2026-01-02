<?php
/**
 * Shopping List Sharing API
 */

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');

// Auth check
$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../classes/ShoppingList.php';

$listManager = new ShoppingList($db, $user['family_id'], $user['id']);

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? null;
    
    switch ($action) {
        
        // GENERATE SHARE LINK
        case 'generate_link':
            $listId = (int)($_POST['list_id'] ?? 0);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            // Verify ownership
            $list = $listManager->getList($listId);
            if (!$list) {
                throw new Exception('List not found');
            }
            
            // Generate shareable token
            $shareToken = bin2hex(random_bytes(16));
            
            $stmt = $db->prepare("
                INSERT INTO shopping_list_shares (list_id, share_token, created_by, expires_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ");
            $stmt->execute([$listId, $shareToken, $user['id']]);
            
            $shareUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/shopping/shared.php?token=' . $shareToken;
            
            echo json_encode([
                'success' => true,
                'share_url' => $shareUrl,
                'token' => $shareToken
            ]);
            break;
        
        // SHARE VIA WHATSAPP
        case 'whatsapp':
            $listId = (int)($_POST['list_id'] ?? 0);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            $list = $listManager->getList($listId);
            if (!$list) {
                throw new Exception('List not found');
            }
            
            // Format as text
            $text = "🛒 *" . $list['name'] . "*\n\n";
            
            $itemsByCategory = [];
            foreach ($list['items'] as $item) {
                if ($item['status'] === 'pending') {
                    $cat = $item['category'] ?: 'other';
                    if (!isset($itemsByCategory[$cat])) {
                        $itemsByCategory[$cat] = [];
                    }
                    $itemsByCategory[$cat][] = $item;
                }
            }
            
            foreach ($itemsByCategory as $category => $items) {
                $text .= "*" . strtoupper($category) . "*\n";
                foreach ($items as $item) {
                    $text .= "• " . $item['name'];
                    if ($item['qty']) $text .= " (" . $item['qty'] . ")";
                    $text .= "\n";
                }
                $text .= "\n";
            }
            
            $text .= "_Shared from RELATIVES Shopping_";
            
            echo json_encode([
                'success' => true,
                'text' => $text,
                'whatsapp_url' => 'https://wa.me/?text=' . urlencode($text)
            ]);
            break;
        
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Add this to your database
/*
CREATE TABLE IF NOT EXISTS shopping_list_shares (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    list_id BIGINT NOT NULL,
    share_token CHAR(32) NOT NULL UNIQUE,
    created_by BIGINT NOT NULL,
    expires_at DATETIME NOT NULL,
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (share_token),
    INDEX idx_list (list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/