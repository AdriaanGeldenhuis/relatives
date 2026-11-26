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
        return $this->addItems($listId, [$data])[0];
    }

    public function addItems($listId, array $items): array {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT id FROM shopping_lists WHERE id = ? AND family_id = ? FOR UPDATE");
            $stmt->execute([$listId, $this->familyId]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('List not found');
            }

            $stmt = $this->db->prepare("
                INSERT INTO shopping_items
                (list_id, added_by, name, qty, price, currency, category, store, assigned_to, status, is_recurring, recurring_frequency)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");
            $ids = [];
            foreach ($items as $data) {
                $stmt->execute([
                    $listId, $this->userId, $data['name'], $data['qty'] ?? null, $data['price'] ?? null,
                    $data['currency'] ?? 'ZAR', $data['category'] ?? 'other', $data['store'] ?? null,
                    $data['assigned_to'] ?? null, $data['is_recurring'] ?? 0, $data['recurring_frequency'] ?? null
                ]);
                $ids[] = $this->db->lastInsertId();
                if (!empty($data['price'])) {
                    $this->addPriceHistory($data['name'], $data['price'], $data['store'] ?? null);
                }
            }

            $this->db->commit();
            return $ids;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateItem($itemId, $data): array {
        $this->db->beginTransaction();
        try {
            $item = $this->lockItems([$itemId])[0];
            $fields = [];
            $values = [];
            foreach (['name', 'qty', 'price', 'category', 'store', 'assigned_to', 'is_recurring', 'recurring_frequency'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            if ($fields) {
                $values[] = $itemId;
                $stmt = $this->db->prepare("UPDATE shopping_items SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($values);
                if (!empty($data['price']) && (float)$data['price'] !== (float)$item['price']) {
                    $store = array_key_exists('store', $data) ? $data['store'] : $item['store'];
                    $this->addPriceHistory($data['name'] ?? $item['name'], $data['price'], $store);
                }
            }
            $this->db->commit();
            return $item;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function toggleItem($itemId, ?string $status = null): string {
        return $this->changeStatus([$itemId], $status)['status'];
    }

    public function markBought(array $itemIds): int {
        return $this->changeStatus($itemIds, 'bought')['count'];
    }

    private function lockItems(array $itemIds): array {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->prepare("
            SELECT si.* FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE sl.family_id = ? AND si.id IN ($placeholders) AND si.status IN ('pending', 'bought')
            ORDER BY si.id FOR UPDATE
        ");
        $stmt->execute(array_merge([$this->familyId], $itemIds));
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($items) !== count($itemIds)) {
            throw new Exception('One or more items are no longer available');
        }
        return $items;
    }

    private function changeStatus(array $itemIds, ?string $status): array {
        $this->db->beginTransaction();
        try {
            $items = $this->lockItems($itemIds);
            $status ??= $items[0]['status'] === 'bought' ? 'pending' : 'bought';
            $stmt = $this->db->prepare("UPDATE shopping_items SET status = ?, bought_at = ? WHERE id = ?");
            $boughtAt = $status === 'bought' ? date('Y-m-d H:i:s') : null;
            $count = 0;
            foreach ($items as $item) {
                if ($item['status'] === $status) {
                    continue;
                }
                $stmt->execute([$status, $boughtAt, $item['id']]);
                if ($boughtAt !== null) {
                    $item['bought_at'] = $boughtAt;
                    $this->addToHistory($item);
                }
                $count++;
            }
            $this->db->commit();
            return ['status' => $status, 'count' => $count];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
    public function clearBought($listId): int {
        // Delete bought items
        $stmt = $this->db->prepare("
            DELETE si FROM shopping_items si
            JOIN shopping_lists sl ON si.list_id = sl.id
            WHERE si.list_id = ? 
            AND si.status = 'bought' 
            AND sl.family_id = ?
        ");
        $stmt->execute([$listId, $this->familyId]);
        return $stmt->rowCount();
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