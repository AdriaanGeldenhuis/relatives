<?php
/**
 * Shopping List Management Class
 */

class ShoppingList {
    private $db;
    private $familyId;
    private $userId;
    
    public function __construct($db, $familyId, $userId) {
        $this->db = $db;
        $this->familyId = $familyId;
        $this->userId = $userId;
    }
    
    /**
     * Get all lists for family
     */
    public function getLists() {
        $stmt = $this->db->prepare("
            SELECT sl.*, 
                   COUNT(CASE WHEN si.status = 'pending' THEN 1 END) as pending_count,
                   COUNT(CASE WHEN si.status = 'bought' THEN 1 END) as bought_count,
                   COALESCE(SUM(CASE WHEN si.status = 'pending' THEN si.price END), 0) as total_price
            FROM shopping_lists sl
            LEFT JOIN shopping_items si ON sl.id = si.list_id
            WHERE sl.family_id = ?
            GROUP BY sl.id
            ORDER BY sl.id ASC
        ");
        $stmt->execute([$this->familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get single list with items
     */
    public function getList($listId) {
        // Get list info
        $stmt = $this->db->prepare("
            SELECT * FROM shopping_lists 
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$listId, $this->familyId]);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$list) {
            return null;
        }
        
        // Get items with user info
        $stmt = $this->db->prepare("
            SELECT si.*, 
                   u.full_name as added_by_name, 
                   u.avatar_color,
                   au.full_name as assigned_to_name
            FROM shopping_items si
            LEFT JOIN users u ON si.added_by = u.id
            LEFT JOIN users au ON si.assigned_to = au.id
            WHERE si.list_id = ?
            ORDER BY 
                si.sort_order ASC,
                si.status ASC,
                CASE si.category
                    WHEN 'dairy' THEN 1
                    WHEN 'meat' THEN 2
                    WHEN 'produce' THEN 3
                    WHEN 'bakery' THEN 4
                    WHEN 'pantry' THEN 5
                    WHEN 'frozen' THEN 6
                    WHEN 'snacks' THEN 7
                    WHEN 'beverages' THEN 8
                    WHEN 'household' THEN 9
                    WHEN 'other' THEN 10
                END,
                si.created_at DESC
        ");
        $stmt->execute([$listId]);
        $list['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $list;
    }
    
    /**
     * Create new list
     */
    public function createList($name, $icon = '🛒') {
        $stmt = $this->db->prepare("
            INSERT INTO shopping_lists (family_id, name, icon) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->familyId, $name, $icon]);
        return $this->db->lastInsertId();
    }
    
    /**
     * Update list
     */
    public function updateList($listId, $name, $icon) {
        $stmt = $this->db->prepare("
            UPDATE shopping_lists 
            SET name = ?, icon = ?, updated_at = NOW()
            WHERE id = ? AND family_id = ?
        ");
        return $stmt->execute([$name, $icon, $listId, $this->familyId]);
    }
    
    /**
     * Delete list
     */
    public function deleteList($listId) {
        // Check if only list
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM shopping_lists WHERE family_id = ?
        ");
        $stmt->execute([$this->familyId]);
        
        if ($stmt->fetchColumn() <= 1) {
            throw new Exception('Cannot delete the only list');
        }
        
        // Delete items first
        $stmt = $this->db->prepare("DELETE FROM shopping_items WHERE list_id = ?");
        $stmt->execute([$listId]);
        
        // Delete list
        $stmt = $this->db->prepare("
            DELETE FROM shopping_lists 
            WHERE id = ? AND family_id = ?
        ");
        return $stmt->execute([$listId, $this->familyId]);
    }
    
    /**
     * Get or create default list
     */
    public function getDefaultList() {
        $stmt = $this->db->prepare("
            SELECT * FROM shopping_lists 
            WHERE family_id = ? 
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$this->familyId]);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$list) {
            $listId = $this->createList('Main List', '🛒');
            $stmt->execute([$this->familyId]);
            $list = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $list;
    }
    
    /**
     * Get shopping analytics
     */
    public function getAnalytics($days = 30) {
        // Total spent
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_items,
                COALESCE(SUM(price), 0) as total_spent,
                AVG(price) as avg_price
            FROM shopping_history
            WHERE family_id = ? 
            AND bought_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$this->familyId, $days]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Top categories
        $stmt = $this->db->prepare("
            SELECT category, COUNT(*) as count, SUM(price) as total
            FROM shopping_history
            WHERE family_id = ? 
            AND bought_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY category
            ORDER BY count DESC
            LIMIT 5
        ");
        $stmt->execute([$this->familyId, $days]);
        $topCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Frequent items
        $stmt = $this->db->prepare("
            SELECT item_name, COUNT(*) as frequency, AVG(price) as avg_price
            FROM shopping_history
            WHERE family_id = ? 
            AND bought_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY item_name
            ORDER BY frequency DESC
            LIMIT 10
        ");
        $stmt->execute([$this->familyId, $days]);
        $frequentItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'totals' => $totals,
            'top_categories' => $topCategories,
            'frequent_items' => $frequentItems
        ];
    }
}