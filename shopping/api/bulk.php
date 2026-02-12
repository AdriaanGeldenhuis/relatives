<?php
/**
 * Bulk Operations API - FIXED SESSION
 */

session_name('RELATIVES_SESSION');
session_start();

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

// Check session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get user
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// ========== SUBSCRIPTION LOCK CHECK ==========
require_once __DIR__ . '/../../core/SubscriptionManager.php';

$subscriptionManager = new SubscriptionManager($db);

if ($subscriptionManager->isFamilyLocked($user['family_id'])) {
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'error' => 'subscription_locked',
        'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
    ]);
    exit;
}
// ========== END SUBSCRIPTION LOCK ==========

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }
    
    $action = $_POST['action'] ?? null;
    $itemIds = json_decode($_POST['item_ids'] ?? '[]', true);
    
    if (empty($itemIds)) {
        throw new Exception('No items selected');
    }
    
    // Verify all items belong to family
    $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM shopping_items si
        JOIN shopping_lists sl ON si.list_id = sl.id
        WHERE si.id IN ($placeholders) AND sl.family_id = ?
    ");
    $params = array_merge($itemIds, [$user['family_id']]);
    $stmt->execute($params);
    
    if ($stmt->fetchColumn() != count($itemIds)) {
        throw new Exception('Some items do not belong to your family');
    }
    
    switch ($action) {
        
        // BULK DELETE
        case 'delete':
            $stmt = $db->prepare("DELETE FROM shopping_items WHERE id IN ($placeholders)");
            $stmt->execute($itemIds);
            $count = $stmt->rowCount();
            
            // Log operation
            $stmt = $db->prepare("
                INSERT INTO shopping_bulk_operations 
                (family_id, user_id, operation_type, item_count, details)
                VALUES (?, ?, 'delete', ?, ?)
            ");
            $stmt->execute([
                $user['family_id'],
                $user['id'],
                $count,
                json_encode(['item_ids' => $itemIds])
            ]);
            
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Deleted $count items"
            ]);
            break;
        
        // BULK MARK BOUGHT
        case 'mark_bought':
            // Move to history
            $stmt = $db->prepare("
                INSERT INTO shopping_history 
                (family_id, list_id, item_name, qty, price, category, store, bought_by)
                SELECT ?, si.list_id, si.name, si.qty, si.price, si.category, si.store, ?
                FROM shopping_items si
                WHERE si.id IN ($placeholders)
            ");
            $params = array_merge([$user['family_id'], $user['id']], $itemIds);
            $stmt->execute($params);
            
            // Update status
            $stmt = $db->prepare("
                UPDATE shopping_items 
                SET status = 'bought', bought_at = NOW()
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($itemIds);
            $count = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Marked $count items as bought"
            ]);
            break;
        
        // BULK MOVE CATEGORY
        case 'move_category':
            $category = $_POST['category'] ?? null;
            
            if (empty($category)) {
                throw new Exception('Category required');
            }
            
            $stmt = $db->prepare("
                UPDATE shopping_items 
                SET category = ?
                WHERE id IN ($placeholders)
            ");
            $params = array_merge([$category], $itemIds);
            $stmt->execute($params);
            $count = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Moved $count items"
            ]);
            break;
        
        // BULK ASSIGN
        case 'assign':
            $assignTo = (int)($_POST['assign_to'] ?? 0);
            
            if (!$assignTo) {
                throw new Exception('User ID required');
            }
            
            // Verify user is in family
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND family_id = ?");
            $stmt->execute([$assignTo, $user['family_id']]);
            
            if (!$stmt->fetch()) {
                throw new Exception('User not in family');
            }
            
            $stmt = $db->prepare("
                UPDATE shopping_items 
                SET assigned_to = ?
                WHERE id IN ($placeholders)
            ");
            $params = array_merge([$assignTo], $itemIds);
            $stmt->execute($params);
            $count = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Assigned $count items"
            ]);
            break;
        
        // BULK UPDATE PRICES
        case 'update_prices':
            $prices = json_decode($_POST['prices'] ?? '{}', true);
            
            if (empty($prices)) {
                throw new Exception('Prices required');
            }
            
            $stmt = $db->prepare("UPDATE shopping_items SET price = ? WHERE id = ?");
            
            foreach ($prices as $itemId => $price) {
                $stmt->execute([$price, $itemId]);
            }
            
            echo json_encode([
                'success' => true,
                'count' => count($prices),
                'message' => 'Prices updated'
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