<?php
declare(strict_types=1);

/**
 * ============================================
 * SESSION MANAGEMENT - COMPLETE FILE
 * ============================================
 */

class Session {
    private PDO $db;
    private const SESSION_LIFETIME = 30 * 24 * 3600; // 30 days
    
    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initializeSession();
    }
    
    /**
     * Initialize PHP session
     */
    private function initializeSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.gc_maxlifetime', (string)self::SESSION_LIFETIME);
            ini_set('session.cookie_lifetime', (string)self::SESSION_LIFETIME);
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', '1');
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.use_strict_mode', '1');
            
            session_name('RELATIVES_SESSION');
            session_start();
        }
    }
    
    /**
     * Create new session - IMPROVED VERSION
     */
    public function create(int $userId): array {
        // Generate tokens
        $sessionToken = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        
        // Ensure session is started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Store in PHP session FIRST (critical for immediate access)
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['csrf_token'] = $csrfToken;
        $_SESSION['created_at'] = time();
        
        // Force session write and restart
        session_write_close();
        session_start();
        
        // Save to database (backup/validation)
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sessions (
                    user_id, 
                    session_token, 
                    csrf_token, 
                    ip, 
                    user_agent, 
                    expires_at,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
            ");
            
            $stmt->execute([
                $userId,
                hash('sha256', $sessionToken),
                hash('sha256', $csrfToken),
                $this->getIPBinary(),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                self::SESSION_LIFETIME
            ]);
        } catch (Exception $e) {
            error_log('Session DB save error: ' . $e->getMessage());
            // Don't throw - session is already in PHP, DB is backup
        }
        
        // Update last login
        try {
            $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log('Last login update error: ' . $e->getMessage());
        }
        
        // Set long-lived cookie
        setcookie(
            session_name(),
            session_id(),
            [
                'expires' => time() + self::SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
        
        return [
            'session_token' => $sessionToken,
            'csrf_token' => $csrfToken
        ];
    }
    
    /**
     * Validate current session - OPTIMIZED VERSION
     */
    public function validate(): ?array {
        // Check PHP session first
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return null;
        }
        
        // OPTIMIZATION: Use cached user data if available and recent (5 minutes)
        if (isset($_SESSION['user_data_cached']) && 
            $_SESSION['user_data_cached'] === true &&
            isset($_SESSION['user_data_time']) &&
            (time() - $_SESSION['user_data_time']) < 300) {
            
            // Extend session every 7 days
            $age = time() - ($_SESSION['created_at'] ?? 0);
            if ($age > (7 * 24 * 3600)) {
                $this->extendSession();
            }
            
            return [
                'id' => $_SESSION['user_data']['id'],
                'family_id' => $_SESSION['user_data']['family_id'],
                'family_name' => $_SESSION['user_data']['family_name'],
                'role' => $_SESSION['user_data']['role'],
                'name' => $_SESSION['user_data']['name'],
                'email' => $_SESSION['user_data']['email'],
                'avatar_color' => $_SESSION['user_data']['avatar_color'],
                'csrf_token' => $_SESSION['csrf_token']
            ];
        }
        
        // Query database for fresh user data
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   u.id, u.family_id, u.role, u.full_name as name, 
                   u.email, u.avatar_color, u.status,
                   f.name as family_name
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            JOIN families f ON u.family_id = f.id
            WHERE s.session_token = ?
              AND s.expires_at > NOW()
              AND u.status = 'active'
            LIMIT 1
        ");
        
        $stmt->execute([hash('sha256', $_SESSION['session_token'])]);
        $session = $stmt->fetch();
        
        if (!$session) {
            $this->destroy();
            return null;
        }
        
        // Verify user ID matches
        if ($session['user_id'] !== $_SESSION['user_id']) {
            $this->destroy();
            return null;
        }
        
        // Cache user data for 5 minutes
        $_SESSION['user_data'] = [
            'id' => $session['id'],
            'family_id' => $session['family_id'],
            'family_name' => $session['family_name'],
            'role' => $session['role'],
            'name' => $session['name'],
            'email' => $session['email'],
            'avatar_color' => $session['avatar_color']
        ];
        $_SESSION['user_data_cached'] = true;
        $_SESSION['user_data_time'] = time();
        
        // Extend session every 7 days
        $age = time() - ($_SESSION['created_at'] ?? 0);
        if ($age > (7 * 24 * 3600)) {
            $this->extendSession();
        }
        
        return [
            'id' => $session['id'],
            'family_id' => $session['family_id'],
            'family_name' => $session['family_name'],
            'role' => $session['role'],
            'name' => $session['name'],
            'email' => $session['email'],
            'avatar_color' => $session['avatar_color'],
            'csrf_token' => $_SESSION['csrf_token']
        ];
    }
    
    /**
     * Extend session expiration
     */
    private function extendSession(): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE sessions 
                SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE session_token = ?
            ");
            $stmt->execute([
                self::SESSION_LIFETIME,
                hash('sha256', $_SESSION['session_token'])
            ]);
            $_SESSION['created_at'] = time();
        } catch (Exception $e) {
            error_log('Session extend error: ' . $e->getMessage());
        }
    }
    
    /**
     * Destroy session
     */
    public function destroy(): void {
        // Remove from database
        if (isset($_SESSION['session_token'])) {
            try {
                $stmt = $this->db->prepare("DELETE FROM sessions WHERE session_token = ?");
                $stmt->execute([hash('sha256', $_SESSION['session_token'])]);
            } catch (Exception $e) {
                error_log('Session destroy error: ' . $e->getMessage());
            }
        }
        
        // Clear PHP session
        $_SESSION = [];
        
        // Delete cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCSRF(string $token): bool {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Get CSRF token
     */
    public function getCSRFToken(): string {
        return $_SESSION['csrf_token'] ?? '';
    }
    
    /**
     * Get IP as binary
     */
    private function getIPBinary(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return inet_pton($ip) ?: inet_pton('0.0.0.0');
    }
    
    /**
     * Cleanup expired sessions
     */
    public function cleanup(): void {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
            $stmt->execute();
        } catch (Exception $e) {
            error_log('Session cleanup error: ' . $e->getMessage());
        }
    }
}