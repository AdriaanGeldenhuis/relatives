<?php
/**
 * Shopping Analytics API
 */

session_name('RELATIVES_SESSION');
session_start();

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

// Load classes
require_once __DIR__ . '/../classes/ShoppingList.php';

$listManager = new ShoppingList($db, $user['family_id'], $user['id']);

try {
    $action = $_GET['action'] ?? null;
    
    switch ($action) {
        
        // GET ANALYTICS
        case 'get':
            $days = (int)($_GET['days'] ?? 30);
            $analytics = $listManager->getAnalytics($days);
            
            echo json_encode([
                'success' => true,
                'analytics' => $analytics
            ]);
            break;
        
        // GET SPENDING TREND
        case 'spending_trend':
            $days = (int)($_GET['days'] ?? 30);
            
            $stmt = $db->prepare("
                SELECT 
                    DATE(bought_at) as date,
                    COUNT(*) as items,
                    SUM(price) as total
                FROM shopping_history
                WHERE family_id = ? 
                AND bought_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(bought_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$user['family_id'], $days]);
            $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'trend' => $trend
            ]);
            break;
        
        // GET CATEGORY BREAKDOWN
        case 'category_breakdown':
            $days = (int)($_GET['days'] ?? 30);
            
            $stmt = $db->prepare("
                SELECT 
                    category,
                    COUNT(*) as count,
                    SUM(price) as total,
                    AVG(price) as avg_price
                FROM shopping_history
                WHERE family_id = ? 
                AND bought_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY category
                ORDER BY total DESC
            ");
            $stmt->execute([$user['family_id'], $days]);
            $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'breakdown' => $breakdown
            ]);
            break;
        
        // GET STORE COMPARISON
        case 'store_comparison':
            $days = (int)($_GET['days'] ?? 30);
            
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(store, 'Unknown') as store,
                    COUNT(*) as items,
                    SUM(price) as total,
                    AVG(price) as avg_price
                FROM shopping_history
                WHERE family_id = ? 
                AND bought_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY store
                ORDER BY total DESC
            ");
            $stmt->execute([$user['family_id'], $days]);
            $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'stores' => $stores
            ]);
            break;
        
        // GET PRICE TRENDS
        case 'price_trends':
            $itemName = $_GET['item_name'] ?? null;
            
            if (!$itemName) {
                throw new Exception('Item name required');
            }
            
            $stmt = $db->prepare("
                SELECT 
                    DATE(bought_at) as date,
                    AVG(price) as avg_price,
                    MIN(price) as min_price,
                    MAX(price) as max_price,
                    store
                FROM shopping_history
                WHERE family_id = ? AND item_name = ?
                GROUP BY DATE(bought_at), store
                ORDER BY date ASC
            ");
            $stmt->execute([$user['family_id'], $itemName]);
            $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'trends' => $trends
            ]);
            break;
        
        // GET SHOPPING PATTERNS
        case 'patterns':
            // Day of week analysis
            $stmt = $db->prepare("
                SELECT 
                    DAYNAME(bought_at) as day_name,
                    DAYOFWEEK(bought_at) as day_num,
                    COUNT(*) as count,
                    SUM(price) as total
                FROM shopping_history
                WHERE family_id = ? 
                AND bought_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY day_name, day_num
                ORDER BY day_num
            ");
            $stmt->execute([$user['family_id']]);
            $dayPattern = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Hour of day analysis
            $stmt = $db->prepare("
                SELECT 
                    HOUR(bought_at) as hour,
                    COUNT(*) as count
                FROM shopping_history
                WHERE family_id = ? 
                AND bought_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY hour
                ORDER BY hour
            ");
            $stmt->execute([$user['family_id']]);
            $hourPattern = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'patterns' => [
                    'by_day' => $dayPattern,
                    'by_hour' => $hourPattern
                ]
            ]);
            break;
        
        // GET SAVINGS OPPORTUNITIES
        case 'savings':
            // Items with high price variance
            $stmt = $db->prepare("
                SELECT 
                    item_name,
                    COUNT(DISTINCT store) as store_count,
                    MIN(price) as min_price,
                    MAX(price) as max_price,
                    AVG(price) as avg_price,
                    (MAX(price) - MIN(price)) as price_difference,
                    ((MAX(price) - MIN(price)) / MAX(price) * 100) as savings_percent
                FROM shopping_history
                WHERE family_id = ? 
                AND price IS NOT NULL
                AND bought_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY item_name
                HAVING store_count > 1 AND price_difference > 5
                ORDER BY savings_percent DESC
                LIMIT 10
            ");
            $stmt->execute([$user['family_id']]);
            $savings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'savings_opportunities' => $savings
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