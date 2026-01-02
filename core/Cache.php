<?php
declare(strict_types=1);

/**
 * Simple Database Cache Class
 * Stores cache in MySQL with expiration
 */
class Cache {
    private PDO $db;
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    /**
     * Get value from cache
     */
    public function get(string $key) {
        try {
            $stmt = $this->db->prepare("
                SELECT cache_value, expires_at 
                FROM cache 
                WHERE cache_key = ? 
                AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$key]);
            $result = $stmt->fetch();
            
            if ($result) {
                return json_decode($result['cache_value'], true);
            }
            
            return null;
        } catch (Exception $e) {
            error_log('Cache get error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set value in cache with TTL (seconds)
     */
    public function set(string $key, $value, int $ttl = 60): bool {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
            $jsonValue = json_encode($value);
            
            // Delete existing key first
            $stmt = $this->db->prepare("DELETE FROM cache WHERE cache_key = ?");
            $stmt->execute([$key]);
            
            // Insert new value
            $stmt = $this->db->prepare("
                INSERT INTO cache (cache_key, cache_value, expires_at) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$key, $jsonValue, $expiresAt]);
            
            return true;
        } catch (Exception $e) {
            error_log('Cache set error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete specific key
     */
    public function delete(string $key): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM cache WHERE cache_key = ?");
            $stmt->execute([$key]);
            return true;
        } catch (Exception $e) {
            error_log('Cache delete error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear all expired cache entries
     */
    public function clearExpired(): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM cache WHERE expires_at < NOW()");
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            error_log('Cache clear error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear all cache
     */
    public function clearAll(): bool {
        try {
            $stmt = $this->db->prepare("TRUNCATE TABLE cache");
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            error_log('Cache clear all error: ' . $e->getMessage());
            return false;
        }
    }
}