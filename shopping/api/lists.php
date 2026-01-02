<?php
/**
 * Shopping Lists API
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