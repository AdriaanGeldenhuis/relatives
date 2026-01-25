<?php
/**
 * MESSAGE LIMIT MANAGER
 * Enforces 500-message limit per family with automatic archival
 */

class MessageLimitManager {
    private $db;
    private $familyId;
    private $maxMessages;
    
    public function __construct($db, $familyId) {
        $this->db = $db;
        $this->familyId = $familyId;
        $this->maxMessages = $this->getMaxMessagesForFamily();
    }
    
    /**
     * Get max messages setting for family (default 500)
     */
    private function getMaxMessagesForFamily() {
        try {
            $stmt = $this->db->prepare("
                SELECT max_messages FROM message_settings 
                WHERE family_id = ?
            ");
            $stmt->execute([$this->familyId]);
            $result = $stmt->fetchColumn();
            
            return $result ? (int)$result : 500;
        } catch (Exception $e) {
            error_log("Error getting max messages: " . $e->getMessage());
            return 500;
        }
    }
    
    /**
     * Check and enforce message limit
     * Returns number of messages deleted
     */
    public function enforceLimit() {
        try {
            // Count current messages
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM messages 
                WHERE family_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$this->familyId]);
            $currentCount = (int)$stmt->fetchColumn();
            
            if ($currentCount <= $this->maxMessages) {
                return 0; // No action needed
            }
            
            $toDelete = $currentCount - $this->maxMessages;
            
            // Get oldest messages to delete
            $stmt = $this->db->prepare("
                SELECT id, user_id, message_type, content, media_path, created_at
                FROM messages
                WHERE family_id = ? AND deleted_at IS NULL
                ORDER BY created_at ASC
                LIMIT ?
            ");
            $stmt->execute([$this->familyId, $toDelete]);
            $oldMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($oldMessages)) {
                return 0;
            }
            
            // Archive messages before deletion
            $this->archiveMessages($oldMessages);
            
            // Soft delete messages
            $messageIds = array_column($oldMessages, 'id');
            $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
            
            $stmt = $this->db->prepare("
                UPDATE messages 
                SET deleted_at = NOW() 
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($messageIds);
            
            // Log cleanup
            error_log("MessageLimitManager: Cleaned up {$toDelete} old messages for family {$this->familyId}");
            
            return $toDelete;
            
        } catch (Exception $e) {
            error_log("MessageLimitManager error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Archive messages to archive table
     */
    private function archiveMessages($messages) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO message_archives 
                (original_message_id, family_id, user_id, message_type, content, media_path, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($messages as $msg) {
                $stmt->execute([
                    $msg['id'],
                    $this->familyId,
                    $msg['user_id'],
                    $msg['message_type'],
                    $msg['content'],
                    $msg['media_path'],
                    $msg['created_at']
                ]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Archive error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get archived messages
     */
    public function getArchivedMessages($limit = 100, $offset = 0) {
        try {
            $stmt = $this->db->prepare("
                SELECT ma.*, u.full_name, u.avatar_color
                FROM message_archives ma
                JOIN users u ON ma.user_id = u.id
                WHERE ma.family_id = ?
                ORDER BY ma.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$this->familyId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching archives: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get message statistics
     */
    public function getStatistics() {
        try {
            $stats = [];
            
            // Current message count
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM messages 
                WHERE family_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$this->familyId]);
            $stats['current_count'] = (int)$stmt->fetchColumn();
            
            // Archived count
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM message_archives 
                WHERE family_id = ?
            ");
            $stmt->execute([$this->familyId]);
            $stats['archived_count'] = (int)$stmt->fetchColumn();
            
            // Space remaining
            $stats['space_remaining'] = $this->maxMessages - $stats['current_count'];
            $stats['max_messages'] = $this->maxMessages;
            $stats['usage_percent'] = round(($stats['current_count'] / $this->maxMessages) * 100, 1);
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error getting statistics: " . $e->getMessage());
            return [];
        }
    }
}