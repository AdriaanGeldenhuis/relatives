<?php
/**
 * Shopping Lists API
 */

session_name('RELATIVES_SESSION');
session_start();

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

// Auth check - use same pattern as items.php
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

// Load classes
require_once __DIR__ . '/../classes/ShoppingList.php';

$listManager = new ShoppingList($db, $user['family_id'], $user['id']);

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? null;
    
    switch ($action) {
        
        // GET ALL LISTS
        case 'get_all':
            $lists = $listManager->getLists();
            
            echo json_encode([
                'success' => true,
                'lists' => $lists
            ]);
            break;
        
        // GET SINGLE LIST
        case 'get':
            $listId = (int)($_GET['list_id'] ?? 0);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            $list = $listManager->getList($listId);
            
            if (!$list) {
                throw new Exception('List not found');
            }
            
            echo json_encode([
                'success' => true,
                'list' => $list
            ]);
            break;
        
        // CREATE LIST
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $name = trim($_POST['name'] ?? '');
            $icon = $_POST['icon'] ?? '🛒';
            
            if (empty($name)) {
                throw new Exception('List name required');
            }
            
            $listId = $listManager->createList($name, $icon);
            
            echo json_encode([
                'success' => true,
                'list_id' => $listId,
                'message' => 'List created'
            ]);
            break;
        
        // UPDATE LIST
        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $listId = (int)($_POST['list_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $icon = $_POST['icon'] ?? '🛒';
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            if (empty($name)) {
                throw new Exception('List name required');
            }
            
            $listManager->updateList($listId, $name, $icon);
            
            echo json_encode([
                'success' => true,
                'message' => 'List updated'
            ]);
            break;
        
        // DELETE LIST
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $listId = (int)($_POST['list_id'] ?? 0);

            if (!$listId) {
                throw new Exception('List ID required');
            }

            $listManager->deleteList($listId);

            echo json_encode([
                'success' => true,
                'message' => 'List deleted'
            ]);
            break;

        // GET LIST ITEMS (for AJAX switching)
        case 'get_items':
            $listId = (int)($_GET['list_id'] ?? 0);

            if (!$listId) {
                throw new Exception('List ID required');
            }

            // Verify list belongs to family
            $stmt = $db->prepare("SELECT id FROM shopping_lists WHERE id = ? AND family_id = ?");
            $stmt->execute([$listId, $user['family_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('List not found or access denied');
            }

            // Get items with user info
            $stmt = $db->prepare("
                SELECT si.*,
                       u.full_name as added_by_name,
                       au.full_name as assigned_to_name
                FROM shopping_items si
                LEFT JOIN users u ON si.added_by = u.id
                LEFT JOIN users au ON si.assigned_to = au.id
                WHERE si.list_id = ?
                ORDER BY si.status ASC, si.sort_order ASC, si.created_at DESC
            ");
            $stmt->execute([$listId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate stats
            $total = count($items);
            $pending = 0;
            $bought = 0;

            foreach ($items as $item) {
                if ($item['status'] === 'pending') {
                    $pending++;
                } else {
                    $bought++;
                }
            }

            echo json_encode([
                'success' => true,
                'items' => $items,
                'stats' => [
                    'total' => $total,
                    'pending' => $pending,
                    'bought' => $bought
                ]
            ]);
            break;
        
        // DUPLICATE LIST
        case 'duplicate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $listId = (int)($_POST['list_id'] ?? 0);
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            $list = $listManager->getList($listId);
            
            if (!$list) {
                throw new Exception('List not found');
            }
            
            // Create new list
            $newListId = $listManager->createList($list['name'] . ' (Copy)', $list['icon']);
            
            // Copy items
            require_once __DIR__ . '/../classes/ShoppingItem.php';
            $itemManager = new ShoppingItem($db, $user['family_id'], $user['id']);
            
            foreach ($list['items'] as $item) {
                if ($item['status'] === 'pending') {
                    $itemManager->addItem($newListId, [
                        'name' => $item['name'],
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'category' => $item['category'],
                        'store' => $item['store']
                    ]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'list_id' => $newListId,
                'message' => 'List duplicated'
            ]);
            break;
        
        // EXPORT LIST
        case 'export':
            $listId = (int)($_GET['list_id'] ?? 0);
            $format = $_GET['format'] ?? 'json';
            
            if (!$listId) {
                throw new Exception('List ID required');
            }
            
            $list = $listManager->getList($listId);
            
            if (!$list) {
                throw new Exception('List not found');
            }
            
            switch ($format) {
                case 'csv':
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="shopping-list-' . $listId . '.csv"');
                    
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['Name', 'Quantity', 'Price', 'Category', 'Store', 'Status']);
                    
                    foreach ($list['items'] as $item) {
                        fputcsv($output, [
                            $item['name'],
                            $item['qty'],
                            $item['price'],
                            $item['category'],
                            $item['store'],
                            $item['status']
                        ]);
                    }
                    
                    fclose($output);
                    exit;
                
                case 'text':
                    header('Content-Type: text/plain');
                    header('Content-Disposition: attachment; filename="shopping-list-' . $listId . '.txt"');
                    
                    echo $list['name'] . "\n";
                    echo str_repeat('=', strlen($list['name'])) . "\n\n";
                    
                    $currentCategory = null;
                    foreach ($list['items'] as $item) {
                        if ($item['status'] === 'pending') {
                            if ($item['category'] !== $currentCategory) {
                                $currentCategory = $item['category'];
                                echo "\n" . strtoupper($currentCategory) . "\n";
                                echo str_repeat('-', strlen($currentCategory)) . "\n";
                            }
                            
                            $line = '- ' . $item['name'];
                            if ($item['qty']) $line .= ' (' . $item['qty'] . ')';
                            if ($item['price']) $line .= ' - R' . number_format($item['price'], 2);
                            
                            echo $line . "\n";
                        }
                    }
                    exit;
                
                default: // JSON
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="shopping-list-' . $listId . '.json"');
                    echo json_encode($list, JSON_PRETTY_PRINT);
                    exit;
            }
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