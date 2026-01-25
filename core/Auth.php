<?php
declare(strict_types=1);

/**
 * ============================================
 * AUTHENTICATION SYSTEM
 * Secure login, registration, and password handling
 * WITH PASSWORD RESET FUNCTIONALITY
 * ============================================
 */

class Auth {
    private PDO $db;
    private Session $session;
    private ?EmailService $emailService = null;
    
    public function __construct(PDO $db) {
        $this->db = $db;
        $this->session = new Session($db);
        
        // Initialize email service if available
        if (class_exists('EmailService')) {
            $this->emailService = new EmailService();
        }
    }
    
/**
 * Login user with email and password
 */
public function login(string $email, string $password): array {
    // Validate input
    $email = trim(strtolower($email));
    
    if (empty($email) || empty($password)) {
        throw new Exception('Email and password are required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    // Get user from database
    $stmt = $this->db->prepare("
        SELECT id, password_hash, status, family_id
        FROM users 
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Prevent user enumeration - same error message
        sleep(1); // Rate limiting
        throw new Exception('Invalid email or password');
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        sleep(1); // Rate limiting
        throw new Exception('Invalid email or password');
    }
    
    // Check if account is active
    if ($user['status'] !== 'active') {
        throw new Exception('Account is not active. Please contact support.');
    }
    
    // Create new session
    $tokens = $this->session->create((int)$user['id']);
    
    return [
        'success' => true,
        'user_id' => $user['id'],
        'family_id' => $user['family_id'],
        'csrf_token' => $tokens['csrf_token'],
        'session_token' => $tokens['session_token'] ?? null
    ];
}
    
    /**
     * Register new user and create family
     */
    public function registerNewFamily(
        string $familyName,
        string $fullName,
        string $email,
        string $password
    ): array {
        // Validate input
        $familyName = trim($familyName);
        $fullName = trim($fullName);
        $email = trim(strtolower($email));
        
        if (empty($familyName) || empty($fullName) || empty($email) || empty($password)) {
            throw new Exception('All fields are required');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        if (strlen($password) < 12) {
            throw new Exception('Password must be at least 12 characters long');
        }
        
        // Check if email already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email address is already registered');
        }
        
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Generate unique invite code
            $inviteCode = $this->generateInviteCode();
            
            // Create family with 3-day free trial
            $stmt = $this->db->prepare("
                INSERT INTO families (
                    name,
                    invite_code,
                    timezone,
                    subscription_status,
                    trial_started_at,
                    trial_ends_at,
                    created_at
                ) VALUES (?, ?, 'Africa/Johannesburg', 'trial', NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), NOW())
            ");
            $stmt->execute([$familyName, $inviteCode]);
            $familyId = (int)$this->db->lastInsertId();
            
            // Create owner user
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $avatarColor = $this->generateAvatarColor();
            
            $stmt = $this->db->prepare("
                INSERT INTO users (
                    family_id,
                    role,
                    email,
                    password_hash,
                    full_name,
                    avatar_color,
                    location_sharing,
                    location_tracking_mode,
                    status,
                    created_at
                ) VALUES (?, 'owner', ?, ?, ?, ?, 1, 'always', 'active', NOW())
            ");
            $stmt->execute([
                $familyId,
                $email,
                $passwordHash,
                $fullName,
                $avatarColor
            ]);
            $userId = (int)$this->db->lastInsertId();
            
            // Update family owner
            $stmt = $this->db->prepare("UPDATE families SET owner_user_id = ? WHERE id = ?");
            $stmt->execute([$userId, $familyId]);
            
            // Create default shopping list
            $stmt = $this->db->prepare("
                INSERT INTO shopping_lists (family_id, name, icon, created_at) 
                VALUES (?, 'Main List', '🛒', NOW())
            ");
            $stmt->execute([$familyId]);
            
            $this->db->commit();
            
            // Send welcome email (non-blocking)
            if ($this->emailService) {
                try {
                    $this->emailService->sendWelcomeFamily($email, $fullName, $familyName, $inviteCode);
                } catch (Exception $e) {
                    error_log("Failed to send welcome email: " . $e->getMessage());
                }
            }
            
            return [
                'success' => true,
                'user_id' => $userId,
                'family_id' => $familyId,
                'invite_code' => $inviteCode
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Register user joining existing family
     */
    public function registerJoinFamily(
        string $inviteCode,
        string $fullName,
        string $email,
        string $password
    ): array {
        // Validate input
        $inviteCode = strtoupper(trim($inviteCode));
        $fullName = trim($fullName);
        $email = trim(strtolower($email));
        
        if (empty($inviteCode) || empty($fullName) || empty($email) || empty($password)) {
            throw new Exception('All fields are required');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        if (strlen($password) < 12) {
            throw new Exception('Password must be at least 12 characters long');
        }
        
        // Check if email already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email address is already registered');
        }
        
        // Find family by invite code
        $stmt = $this->db->prepare("SELECT id, name FROM families WHERE invite_code = ?");
        $stmt->execute([$inviteCode]);
        $family = $stmt->fetch();
        
        if (!$family) {
            throw new Exception('Invalid invite code');
        }
        
        // Create user
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $avatarColor = $this->generateAvatarColor();
        
        $stmt = $this->db->prepare("
            INSERT INTO users (
                family_id,
                role,
                email,
                password_hash,
                full_name,
                avatar_color,
                location_sharing,
                location_tracking_mode,
                status,
                created_at
            ) VALUES (?, 'member', ?, ?, ?, ?, 1, 'always', 'active', NOW())
        ");
        $stmt->execute([
            $family['id'],
            $email,
            $passwordHash,
            $fullName,
            $avatarColor
        ]);
        $userId = (int)$this->db->lastInsertId();
        
        // Send welcome email (non-blocking)
        if ($this->emailService) {
            try {
                $this->emailService->sendWelcomeMember($email, $fullName, $family['name']);
            } catch (Exception $e) {
                error_log("Failed to send welcome email: " . $e->getMessage());
            }
        }

        // Notify family members about new member
        try {
            require_once __DIR__ . '/NotificationManager.php';
            require_once __DIR__ . '/NotificationTriggers.php';
            $triggers = new NotificationTriggers($this->db);
            $triggers->onFamilyMemberJoined((int)$family['id'], $userId, $fullName);
        } catch (Exception $e) {
            error_log("Failed to send family member joined notification: " . $e->getMessage());
        }

        return [
            'success' => true,
            'user_id' => $userId,
            'family_id' => $family['id'],
            'family_name' => $family['name']
        ];
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordReset(string $email): array {
        // Validate email
        $email = trim(strtolower($email));
        
        if (empty($email)) {
            throw new Exception('Email address is required');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        // Find user by email
        $stmt = $this->db->prepare("
            SELECT id, full_name, status 
            FROM users 
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        // Always return success to prevent email enumeration
        // But only send email if user exists and is active
        if ($user && $user['status'] === 'active') {
            // Generate secure reset token
            $token = bin2hex(random_bytes(32)); // 64 characters
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            
            // Store hashed token in database
            $stmt = $this->db->prepare("
                UPDATE users 
                SET reset_token = ?,
                    reset_token_expires = ?
                WHERE id = ?
            ");
            $stmt->execute([$tokenHash, $expiresAt, $user['id']]);
            
            // Send reset email
            if ($this->emailService) {
                try {
                    $this->emailService->sendPasswordReset(
                        $email,
                        $user['full_name'],
                        $token // Send plain token to user
                    );
                } catch (Exception $e) {
                    error_log("Failed to send password reset email: " . $e->getMessage());
                    // Don't throw - we still want to return success
                }
            }
            
            error_log("Password reset requested for: {$email}");
        }
        
        // Always return success (security best practice)
        return [
            'success' => true,
            'message' => 'If an account exists, a password reset email will be sent.'
        ];
    }
    
    /**
     * Validate password reset token
     */
    public function validateResetToken(string $token): bool {
        if (empty($token)) {
            return false;
        }
        
        $tokenHash = hash('sha256', $token);
        
        $stmt = $this->db->prepare("
            SELECT id 
            FROM users 
            WHERE reset_token = ?
              AND reset_token_expires > NOW()
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Reset password using token
     */
    public function resetPassword(string $token, string $newPassword): array {
        // Validate input
        if (empty($token)) {
            throw new Exception('Reset token is required');
        }
        
        if (empty($newPassword)) {
            throw new Exception('New password is required');
        }
        
        if (strlen($newPassword) < 12) {
            throw new Exception('Password must be at least 12 characters long');
        }
        
        $tokenHash = hash('sha256', $token);
        
        // Find user with valid token
        $stmt = $this->db->prepare("
            SELECT id, email, full_name
            FROM users 
            WHERE reset_token = ?
              AND reset_token_expires > NOW()
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Invalid or expired reset token. Please request a new password reset.');
        }
        
        // Hash new password
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Update password and clear reset token
            $stmt = $this->db->prepare("
                UPDATE users 
                SET password_hash = ?,
                    reset_token = NULL,
                    reset_token_expires = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$passwordHash, $user['id']]);
            
            // Invalidate all existing sessions for security
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            $this->db->commit();
            
            error_log("Password reset successful for user ID: {$user['id']}");
            
            return [
                'success' => true,
                'message' => 'Password has been reset successfully. You can now sign in with your new password.'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Password reset failed: " . $e->getMessage());
            throw new Exception('Failed to reset password. Please try again.');
        }
    }
    
    /**
     * Get current logged-in user
     */
    public function getCurrentUser(): ?array {
        return $this->session->validate();
    }
    
    /**
     * Logout current user
     */
    public function logout(): void {
        $this->session->destroy();
    }
    
    /**
     * Get CSRF token
     */
    public function getCSRFToken(): string {
        return $this->session->getCSRFToken();
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCSRF(string $token): bool {
        return $this->session->validateCSRF($token);
    }
    
    /**
     * Generate unique invite code
     */
    private function generateInviteCode(): string {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8));
            $stmt = $this->db->prepare("SELECT id FROM families WHERE invite_code = ?");
            $stmt->execute([$code]);
        } while ($stmt->fetch());
        
        return $code;
    }
    
    /**
     * Generate random avatar color
     */
    private function generateAvatarColor(): string {
        $colors = [
            '#667eea', '#764ba2', '#f093fb', '#4facfe',
            '#43e97b', '#fa709a', '#fee140', '#30cfd0',
            '#a8edea', '#fed6e3', '#fccb90', '#d57eeb'
        ];
        
        return $colors[array_rand($colors)];
    }
}