<?php
/**
 * Bulk Operations Handler
 */

class BulkOperations {
    private $db;
    private $familyId;
    private $userId;
    
    public function __construct($db, $familyId, $userId) {
        $this->db = $db;
        $this->familyId = $familyId;
        $this->userId = $userId;
    }
    
    /**
     * Bulk delete items
     */
    public function bulkDelete($itemIds) {
        if (empty($itemIds)) {
            throw new Exception('No items selected');
        }
        
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        
        $stmt = $this->db->prepare("
            DELETE si FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.id IN ($placeholders) AND sl.family_id = ?
        ");
        
        $params = array_merge($itemIds, [$this->familyId]);
        $result = $stmt->execute($params);
        
        // Log operation
        $this->logOperation('delete', count($itemIds), [
            'item_ids' => $itemIds
        ]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Bulk mark as bought
     */
    public function bulkMarkBought($itemIds) {
        if (empty($itemIds)) {
            throw new Exception('No items selected');
        }
        
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        
        // Get items first for history
        $stmt = $this->db->prepare("
            SELECT si.* FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.id IN ($placeholders) AND sl.family_id = ?
        ");
        $params = array_merge($itemIds, [$this->familyId]);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update status
        $stmt = $this->db->prepare("
            UPDATE shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            SET si.status = 'bought', si.bought_at = NOW()
            WHERE si.id IN ($placeholders) AND sl.family_id = ?
        ");
        $stmt->execute($params);
        
        // Add to history
        $historyStmt = $this->db->prepare("
            INSERT INTO shopping_history 
            (family_id, list_id, item_name, qty, price, category, store, bought_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($items as $item) {
            $historyStmt->execute([
                $this->familyId,
                $item['list_id'],
                $item['name'],
                $item['qty'],
                $item['price'],
                $item['category'],
                $item['store'],
                $this->userId
            ]);
        }
        
        // Log operation
        $this->logOperation('mark_bought', count($itemIds), [
            'item_ids' => $itemIds
        ]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Bulk move to category
     */
    public function bulkMoveCategory($itemIds, $category) {
        if (empty($itemIds)) {
            throw new Exception('No items selected');
        }
        
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        
        $stmt = $this->db->prepare("
            UPDATE shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            SET si.category = ?
            WHERE si.id IN ($placeholders) AND sl.family_id = ?
        ");
        
        $params = array_merge([$category], $itemIds, [$this->familyId]);
        $result = $stmt->execute($params);
        
        // Log operation
        $this->logOperation('move', count($itemIds), [
            'item_ids' => $itemIds,
            'category' => $category
        ]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Bulk assign to user
     */
    public function bulkAssign($itemIds, $assignToUserId) {
        if (empty($itemIds)) {
            throw new Exception('No items selected');
        }
        
        // Verify user is in family
        $stmt = $this->db->prepare("
            SELECT id FROM users 
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$assignToUserId, $this->familyId]);
        
        if (!$stmt->fetch()) {
            throw new Exception('User not in family');
        }
        
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        
        $stmt = $this->db->prepare("
            UPDATE shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            SET si.assigned_to = ?
            WHERE si.id IN ($placeholders) AND sl.family_id = ?
        ");
        
        $params = array_merge([$assignToUserId], $itemIds, [$this->familyId]);
        $result = $stmt->execute($params);
        
        // Log operation
        $this->logOperation('assign', count($itemIds), [
            'item_ids' => $itemIds,
            'assigned_to' => $assignToUserId
        ]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Bulk update prices
     */
    public function bulkUpdatePrices($itemPrices) {
        if (empty($itemPrices)) {
            throw new Exception('No prices provided');
        }
        
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                UPDATE shopping_items si
                JOIN shopping_lists sl ON si.list_id = sl.id
                SET si.price = ?
                WHERE si.id = ? AND sl.family_id = ?
            ");
            
            foreach ($itemPrices as $itemId => $price) {
                $stmt->execute([$price, $itemId, $this->familyId]);
            }
            
            $this->db->commit();
            
            // Log operation
            $this->logOperation('update_prices', count($itemPrices), [
                'updates' => $itemPrices
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Log bulk operation
     */
    private function logOperation($type, $count, $details) {
        $stmt = $this->db->prepare("
            INSERT INTO shopping_bulk_operations 
            (family_id, user_id, operation_type, item_count, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->familyId,
            $this->userId,
            $type,
            $count,
            json_encode($details)
        ]);
    }
    
    /**
     * Get bulk operation history
     */
    public function getOperationHistory($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT bo.*, u.full_name as user_name
            FROM shopping_bulk_operations bo
            JOIN users u ON bo.user_id = u.id
            WHERE bo.family_id = ?
            ORDER BY bo.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$this->familyId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}