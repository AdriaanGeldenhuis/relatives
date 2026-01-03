<?php
/**
 * SHOPPING ITEMS API - WITH NOTIFICATIONS
 */

session_start();

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/NotificationTriggers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');
header('Cache-Control: no-cache, must-revalidate');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please login']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'User not found or inactive']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    error_log('User fetch error: ' . $e->getMessage());
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

require_once __DIR__ . '/../classes/ShoppingItem.php';
$itemManager = new ShoppingItem($db, $user['family_id'], $user['id']);
$triggers = new NotificationTriggers($db);

function validateItemName($name) {
    $name = trim($name);
    if (empty($name)) {
        throw new Exception('Item name cannot be empty');
    }
    if (strlen($name) > 160) {
        throw new Exception('Item name too long (max 160 characters)');
    }
    return $name;
}

function validateNumeric($value, $fieldName, $min = null, $max = null) {
    if ($value === null || $value === '') {
        return null;
    }
    
    if (!is_numeric($value)) {
        throw new Exception("$fieldName must be a number");
    }
    
    $value = (float)$value;
    
    if ($min !== null && $value < $min) {
        throw new Exception("$fieldName must be at least $min");
    }
    
    if ($max !== null && $value > $max) {
        throw new Exception("$fieldName cannot exceed $max");
    }
    
    return $value;
}

function validateCategory($category) {
    $validCategories = ['dairy', 'meat', 'produce', 'bakery', 'pantry', 'frozen', 'snacks', 'beverages', 'household', 'other'];
    
    if (empty($category)) {
        return 'other';
    }
    
    if (!in_array($category, $validCategories)) {
        return 'other';
    }
    
    return $category;
}

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? null;
    
    if (!$action) {
        throw new Exception('Action parameter required');
    }
    
    switch ($action) {
        
        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $listId = (int)($_POST['list_id'] ?? 0);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            $stmt = $db->prepare("SELECT id FROM shopping_lists WHERE id = ? AND family_id = ?");
            $stmt->execute([$listId, $user['family_id']]);
            
            if (!$stmt->fetch()) {
                throw new Exception('List not found or access denied');
            }
            
            $data = [
                'name' => validateItemName($_POST['name'] ?? '')
            ];
            
            if (!empty($_POST['qty'])) {
                $qty = trim($_POST['qty']);
                if (strlen($qty) > 40) {
                    throw new Exception('Quantity text too long (max 40 characters)');
                }
                $data['qty'] = $qty;
            }
            
            if (!empty($_POST['price'])) {
                $price = validateNumeric($_POST['price'], 'Price', 0, 999999.99);
                if ($price !== null) {
                    $data['price'] = $price;
                    $data['currency'] = $_POST['currency'] ?? 'ZAR';
                }
            }
            
            if (!empty($_POST['category'])) {
                $data['category'] = validateCategory($_POST['category']);
            }
            
            if (!empty($_POST['store'])) {
                $store = trim($_POST['store']);
                if (strlen($store) > 100) {
                    throw new Exception('Store name too long (max 100 characters)');
                }
                $data['store'] = $store;
            }
            
            if (!empty($_POST['assigned_to'])) {
                $assignedTo = (int)$_POST['assigned_to'];
                
                $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND family_id = ? AND status = 'active'");
                $stmt->execute([$assignedTo, $user['family_id']]);
                
                if ($stmt->fetch()) {
                    $data['assigned_to'] = $assignedTo;
                }
            }
            
            $itemId = $itemManager->addItem($listId, $data);
            
            // ========== SEND NOTIFICATION ==========
            try {
                $triggers->onShoppingItemAdded(
                    $itemId,
                    $user['id'],
                    $user['family_id'],
                    $data['name'],
                    $listId
                );
            } catch (Exception $e) {
                error_log('Shopping notification error: ' . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'message' => 'Item added successfully'
            ]);
            break;
        
        case 'toggle':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $itemId = (int)($_POST['item_id'] ?? 0);
            
            if (!$itemId) {
                throw new Exception('Item ID required');
            }
            
            $newStatus = $itemManager->toggleItem($itemId);
            
            echo json_encode([
                'success' => true,
                'status' => $newStatus,
                'message' => $newStatus === 'bought' ? 'Item marked as bought' : 'Item marked as pending'
            ]);
            break;
        
        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $itemId = (int)($_POST['item_id'] ?? 0);
            
            if (!$itemId) {
                throw new Exception('Item ID required');
            }
            
            $data = [];
            
            if (isset($_POST['name'])) {
                $data['name'] = validateItemName($_POST['name']);
            }
            
            if (isset($_POST['qty'])) {
                $qty = trim($_POST['qty']);
                $data['qty'] = !empty($qty) ? substr($qty, 0, 40) : null;
            }
            
            if (isset($_POST['price'])) {
                $data['price'] = validateNumeric($_POST['price'], 'Price', 0, 999999.99);
            }
            
            if (isset($_POST['category'])) {
                $data['category'] = validateCategory($_POST['category']);
            }
            
            if (isset($_POST['store'])) {
                $store = trim($_POST['store']);
                $data['store'] = !empty($store) ? substr($store, 0, 100) : null;
            }
            
            if (isset($_POST['assigned_to'])) {
                $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                
                if ($assignedTo) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND family_id = ? AND status = 'active'");
                    $stmt->execute([$assignedTo, $user['family_id']]);
                    
                    if (!$stmt->fetch()) {
                        throw new Exception('Assigned user not found in family');
                    }
                    
                    // ========== SEND ASSIGNMENT NOTIFICATION ==========
                    if ($assignedTo !== $user['id']) {
                        try {
                            $stmt = $db->prepare("SELECT name FROM shopping_items WHERE id = ?");
                            $stmt->execute([$itemId]);
                            $itemName = $stmt->fetchColumn();
                            
                            if ($itemName) {
                                $triggers->onShoppingItemAssigned(
                                    $itemId,
                                    $assignedTo,
                                    $user['id'],
                                    $itemName
                                );
                            }
                        } catch (Exception $e) {
                            error_log('Assignment notification error: ' . $e->getMessage());
                        }
                    }
                }
                
                $data['assigned_to'] = $assignedTo;
            }
            
            if (empty($data)) {
                throw new Exception('No fields to update');
            }
            
            $itemManager->updateItem($itemId, $data);
            
            echo json_encode([
                'success' => true,
                'message' => 'Item updated successfully'
            ]);
            break;
        
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $itemId = (int)($_POST['item_id'] ?? 0);
            
            if (!$itemId) {
                throw new Exception('Item ID required');
            }
            
            $result = $itemManager->deleteItem($itemId);
            
            if (!$result) {
                throw new Exception('Item not found or already deleted');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully'
            ]);
            break;
        
        case 'clear_bought':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $listId = (int)($_POST['list_id'] ?? 0);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            $stmt = $db->prepare("SELECT id FROM shopping_lists WHERE id = ? AND family_id = ?");
            $stmt->execute([$listId, $user['family_id']]);
            
            if (!$stmt->fetch()) {
                throw new Exception('List not found or access denied');
            }
            
            $result = $itemManager->clearBought($listId);
            
            echo json_encode([
                'success' => true,
                'count' => $result,
                'message' => "Cleared $result bought item" . ($result !== 1 ? 's' : '')
            ]);
            break;
        
        case 'price_history':
            $itemName = trim($_GET['item_name'] ?? '');
            
            if (empty($itemName)) {
                throw new Exception('Item name required');
            }
            
            $limit = min((int)($_GET['limit'] ?? 10), 50);
            $history = $itemManager->getPriceHistory($itemName, $limit);
            
            echo json_encode([
                'success' => true,
                'item_name' => $itemName,
                'history' => $history,
                'count' => count($history)
            ]);
            break;
        
        case 'frequent':
            $limit = min((int)($_GET['limit'] ?? 10), 50);
            $items = $itemManager->getFrequentItems($limit);
            
            echo json_encode([
                'success' => true,
                'items' => $items,
                'count' => count($items)
            ]);
            break;
        
        case 'reorder':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $listId = (int)($_POST['list_id'] ?? 0);
            $itemIds = json_decode($_POST['item_ids'] ?? '[]', true);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            if (empty($itemIds) || !is_array($itemIds)) {
                throw new Exception('Item IDs array required');
            }
            
            $itemManager->reorderItems($listId, $itemIds);
            
            echo json_encode([
                'success' => true,
                'message' => 'Items reordered successfully'
            ]);
            break;
        
        case 'duplicate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $itemId = (int)($_POST['item_id'] ?? 0);
            
            if (!$itemId) {
                throw new Exception('Item ID required');
            }
            
            $stmt = $db->prepare("
                SELECT si.*, sl.family_id 
                FROM shopping_items si
                JOIN shopping_lists sl ON si.list_id = sl.id
                WHERE si.id = ? AND sl.family_id = ?
            ");
            $stmt->execute([$itemId, $user['family_id']]);
            $originalItem = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$originalItem) {
                throw new Exception('Item not found');
            }
            
            $data = [
                'name' => $originalItem['name'],
                'qty' => $originalItem['qty'],
                'price' => $originalItem['price'],
                'category' => $originalItem['category'],
                'store' => $originalItem['store']
            ];
            
            $newItemId = $itemManager->addItem($originalItem['list_id'], $data);
            
            echo json_encode([
                'success' => true,
                'item_id' => $newItemId,
                'message' => 'Item duplicated successfully'
            ]);
            break;
        
        case 'get':
            $itemId = (int)($_GET['item_id'] ?? 0);
            
            if (!$itemId) {
                throw new Exception('Item ID required');
            }
            
            $stmt = $db->prepare("
                SELECT si.*, 
                       sl.name as list_name,
                       u.full_name as added_by_name,
                       au.full_name as assigned_to_name
                FROM shopping_items si
                JOIN shopping_lists sl ON si.list_id = sl.id
                LEFT JOIN users u ON si.added_by = u.id
                LEFT JOIN users au ON si.assigned_to = au.id
                WHERE si.id = ? AND sl.family_id = ?
            ");
            $stmt->execute([$itemId, $user['family_id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                throw new Exception('Item not found');
            }
            
            echo json_encode([
                'success' => true,
                'item' => $item
            ]);
            break;
        
        case 'search':
            $query = trim($_GET['q'] ?? '');

            if (empty($query)) {
                throw new Exception('Search query required');
            }

            if (strlen($query) < 2) {
                throw new Exception('Search query must be at least 2 characters');
            }

            $limit = min((int)($_GET['limit'] ?? 20), 100);

            $stmt = $db->prepare("
                SELECT si.*, sl.name as list_name
                FROM shopping_items si
                JOIN shopping_lists sl ON si.list_id = sl.id
                WHERE sl.family_id = ?
                  AND si.name LIKE ?
                  AND si.status = 'pending'
                ORDER BY si.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$user['family_id'], "%$query%", $limit]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'query' => $query,
                'items' => $items,
                'count' => count($items)
            ]);
            break;

        case 'poll':
            // Real-time polling - returns items updated since timestamp
            $listId = (int)($_GET['list_id'] ?? 0);
            $since = (int)($_GET['since'] ?? 0);

            if (!$listId) {
                throw new Exception('List ID required');
            }

            // Verify list access
            $stmt = $db->prepare("SELECT id FROM shopping_lists WHERE id = ? AND family_id = ?");
            $stmt->execute([$listId, $user['family_id']]);

            if (!$stmt->fetch()) {
                throw new Exception('List not found or access denied');
            }

            // Convert timestamp to datetime
            $sinceDate = date('Y-m-d H:i:s', $since / 1000);

            // Get items updated since timestamp (but not by current user in last 2 seconds to avoid echo)
            $stmt = $db->prepare("
                SELECT si.*,
                       u.full_name as added_by_name,
                       au.full_name as assigned_to_name,
                       GREATEST(si.created_at, COALESCE(si.bought_at, si.created_at)) as last_modified
                FROM shopping_items si
                LEFT JOIN users u ON si.added_by = u.id
                LEFT JOIN users au ON si.assigned_to = au.id
                WHERE si.list_id = ?
                  AND GREATEST(si.created_at, COALESCE(si.bought_at, si.created_at)) > ?
                ORDER BY GREATEST(si.created_at, COALESCE(si.bought_at, si.created_at)) DESC
            ");
            $stmt->execute([$listId, $sinceDate]);
            $updatedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Build updates array
            $updates = [];
            foreach ($updatedItems as $item) {
                $updates[] = [
                    'type' => 'item_updated',
                    'item' => $item,
                    'user' => $item['added_by_name'] ?: 'Someone'
                ];
            }

            echo json_encode([
                'success' => true,
                'updates' => $updates,
                'timestamp' => round(microtime(true) * 1000)
            ]);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'action' => $action ?? 'unknown'
    ]);
    
    error_log("Shopping Items API Error - Action: $action, Error: " . $e->getMessage());
}