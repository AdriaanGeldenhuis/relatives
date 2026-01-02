<?php
/**
 * Shopping Item Management Class
 */

class ShoppingItem {
    private $db;
    private $familyId;
    private $userId;
    
    public function __construct($db, $familyId, $userId) {
        $this->db = $db;
        $this->familyId = $familyId;
        $this->userId = $userId;
    }
    
    /**
     * Add item to list
     */
    public function addItem($listId, $data) {
        // Validate list belongs to family
        $stmt = $this->db->prepare("
            SELECT id FROM shopping_lists 
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$listId, $this->familyId]);
        
        if (!$stmt->fetch()) {
            throw new Exception('List not found');
        }
        
        // Insert item
        $stmt = $this->db->prepare("
            INSERT INTO shopping_items 
            (list_id, added_by, name, qty, price, currency, category, store, assigned_to, status, is_recurring, recurring_frequency) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        
        $stmt->execute([
            $listId,
            $this->userId,
            $data['name'],
            $data['qty'] ?? null,
            $data['price'] ?? null,
            $data['currency'] ?? 'ZAR',
            $data['category'] ?? 'other',
            $data['store'] ?? null,
            $data['assigned_to'] ?? null,
            $data['is_recurring'] ?? 0,
            $data['recurring_frequency'] ?? null
        ]);
        
        $itemId = $this->db->lastInsertId();
        
        // Add to price history if price provided
        if (!empty($data['price'])) {
            $this->addPriceHistory($data['name'], $data['price'], $data['store'] ?? null);
        }
        
        return $itemId;
    }
    
    /**
     * Update item
     */
    public function updateItem($itemId, $data) {
        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT si.* FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.id = ? AND sl.family_id = ?
        ");
        $stmt->execute([$itemId, $this->familyId]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Item not found');
        }
        
        $fields = [];
        $values = [];
        
        foreach (['name', 'qty', 'price', 'category', 'store', 'assigned_to', 'is_recurring', 'recurring_frequency'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $itemId;
        
        $stmt = $this->db->prepare("
            UPDATE shopping_items 
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ");
        
        return $stmt->execute($values);
    }
    
    /**
     * Toggle item bought status
     */
    public function toggleItem($itemId) {
        $stmt = $this->db->prepare("
            SELECT si.*, sl.family_id 
            FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.id = ? AND sl.family_id = ?
        ");
        $stmt->execute([$itemId, $this->familyId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            throw new Exception('Item not found');
        }
        
        $newStatus = $item['status'] === 'bought' ? 'pending' : 'bought';
        $boughtAt = $newStatus === 'bought' ? date('Y-m-d H:i:s') : null;
        
        $stmt = $this->db->prepare("
            UPDATE shopping_items 
            SET status = ?, bought_at = ? 
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $boughtAt, $itemId]);
        
        // If marked as bought, add to history
        if ($newStatus === 'bought') {
            $this->addToHistory($item);
        }
        
        return $newStatus;
    }
    
    /**
     * Delete item
     */
    public function deleteItem($itemId) {
        $stmt = $this->db->prepare("
            DELETE si FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.id = ? AND sl.family_id = ?
        ");
        return $stmt->execute([$itemId, $this->familyId]);
    }
    
    /**
     * Clear all bought items from list
     */
    public function clearBought($listId) {
        // Move to history first
        $stmt = $this->db->prepare("
            SELECT si.* FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.list_id = ? 
            AND si.status = 'bought' 
            AND sl.family_id = ?
        ");
        $stmt->execute([$listId, $this->familyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            $this->addToHistory($item);
        }
        
        // Delete bought items
        $stmt = $this->db->prepare("
            DELETE si FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.list_id = ? 
            AND si.status = 'bought' 
            AND sl.family_id = ?
        ");
        return $stmt->execute([$listId, $this->familyId]);
    }
    
    /**
     * Reorder items
     */
    public function reorderItems($listId, $itemIds) {
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                UPDATE shopping_items 
                SET sort_order = ? 
                WHERE id = ? AND list_id = ?
            ");
            
            foreach ($itemIds as $order => $itemId) {
                $stmt->execute([$order, $itemId, $listId]);
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get price history for item
     */
    public function getPriceHistory($itemName, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM shopping_price_history
            WHERE family_id = ? AND item_name = ?
            ORDER BY recorded_at DESC
            LIMIT ?
        ");
        $stmt->execute([$this->familyId, $itemName, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get frequently bought items
     */
    public function getFrequentItems($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT item_name, category, COUNT(*) as frequency, 
                   AVG(price) as avg_price, MAX(bought_at) as last_bought
            FROM shopping_history
            WHERE family_id = ?
            GROUP BY item_name, category
            ORDER BY frequency DESC, last_bought DESC
            LIMIT ?
        ");
        $stmt->execute([$this->familyId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Add item to shopping history
     */
    private function addToHistory($item) {
        $stmt = $this->db->prepare("
            INSERT INTO shopping_history 
            (family_id, list_id, item_name, qty, price, category, store, bought_by, bought_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->familyId,
            $item['list_id'],
            $item['name'],
            $item['qty'],
            $item['price'],
            $item['category'],
            $item['store'],
            $this->userId,
            $item['bought_at'] ?? date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Add to price history
     */
    private function addPriceHistory($itemName, $price, $store = null) {
        $stmt = $this->db->prepare("
            INSERT INTO shopping_price_history 
            (item_name, family_id, price, store)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$itemName, $this->familyId, $price, $store]);
    }
}