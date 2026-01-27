<?php
declare(strict_types=1);

/**
 * ============================================
 * PRIVACY & SECURITY PAGE v1.0
 * Privacy settings and security options
 * ============================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }
} catch (Exception $e) {
    error_log('Privacy page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get privacy settings
$privacySettings = [
    'location_sharing' => true,
    'show_online_status' => true,
    'show_last_seen' => true,
    'allow_messages' => true,
];

try {
    $stmt = $db->prepare("SELECT * FROM user_privacy_settings WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $savedSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($savedSettings) {
        $privacySettings = array_merge($privacySettings, $savedSettings);
    }
} catch (Exception $e) {
    error_log('Privacy settings error: ' . $e->getMessage());
}

// Get active sessions
$activeSessions = [];
try {
    $stmt = $db->prepare("
        SELECT id, device_info, ip_address, created_at, last_active
        FROM user_sessions
        WHERE user_id = ? AND expires_at > NOW()
        ORDER BY last_active DESC
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Sessions fetch error: ' . $e->getMessage());
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_privacy') {
            $newSettings = [
                'location_sharing' => isset($_POST['location_sharing']),
                'show_online_status' => isset($_POST['show_online_status']),
                'show_last_seen' => isset($_POST['show_last_seen']),
                'allow_messages' => isset($_POST['allow_messages']),
            ];

            // Create table if not exists
            $db->exec("CREATE TABLE IF NOT EXISTS user_privacy_settings (
                user_id INT PRIMARY KEY,
                location_sharing TINYINT(1) DEFAULT 1,
                show_online_status TINYINT(1) DEFAULT 1,
                show_last_seen TINYINT(1) DEFAULT 1,
                allow_messages TINYINT(1) DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            $stmt = $db->prepare("
                INSERT INTO user_privacy_settings
                (user_id, location_sharing, show_online_status, show_last_seen, allow_messages)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                location_sharing = VALUES(location_sharing),
                show_online_status = VALUES(show_online_status),
                show_last_seen = VALUES(show_last_seen),
                allow_messages = VALUES(allow_messages)
            ");

            $stmt->execute([
                $user['id'],
                $newSettings['location_sharing'] ? 1 : 0,
                $newSettings['show_online_status'] ? 1 : 0,
                $newSettings['show_last_seen'] ? 1 : 0,
                $newSettings['allow_messages'] ? 1 : 0,
            ]);

            $privacySettings = $newSettings;
            $success = 'Privacy settings saved!';
        }

        if ($action === 'logout_session') {
            $sessionId = (int)($_POST['session_id'] ?? 0);
            if ($sessionId > 0) {
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE id = ? AND user_id = ?");
                $stmt->execute([$sessionId, $user['id']]);
                $success = 'Session terminated successfully.';

                // Refresh sessions list
                $stmt = $db->prepare("
                    SELECT id, device_info, ip_address, created_at, last_active
                    FROM user_sessions
                    WHERE user_id = ? AND expires_at > NOW()
                    ORDER BY last_active DESC
                    LIMIT 10
                ");
                $stmt->execute([$user['id']]);
                $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        if ($action === 'logout_all') {
            // Logout all sessions except current
            $currentToken = $_SESSION['session_token'] ?? '';
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_token != ?");
            $stmt->execute([$user['id'], $currentToken]);
            $success = 'All other sessions have been logged out.';

            // Refresh sessions list
            $stmt = $db->prepare("
                SELECT id, device_info, ip_address, created_at, last_active
                FROM user_sessions
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY last_active DESC
                LIMIT 10
            ");
            $stmt->execute([$user['id']]);
            $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        error_log('Privacy update error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Privacy & Security';
$pageCSS = ['/profile/css/profile.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="profile-container">

        <div class="profile-header">
            <div class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <?php echo strtoupper(substr($user['name'] ?? $user['full_name'] ?? '?', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="profile-name">Privacy & Security</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Privacy Settings -->
        <form method="POST">
            <input type="hidden" name="action" value="save_privacy">

            <div class="settings-section">
                <div class="settings-section-title">
                    <span class="icon">Privacy</span>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Location Sharing</div>
                        <div class="toggle-description">Share your location with family members</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="location_sharing" <?php echo $privacySettings['location_sharing'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Online Status</div>
                        <div class="toggle-description">Show when you're online</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="show_online_status" <?php echo $privacySettings['show_online_status'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Last Seen</div>
                        <div class="toggle-description">Show when you were last active</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="show_last_seen" <?php echo $privacySettings['show_last_seen'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Allow Messages</div>
                        <div class="toggle-description">Allow family members to send you messages</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="allow_messages" <?php echo $privacySettings['allow_messages'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Save Privacy Settings</button>
        </form>

        <!-- Active Sessions -->
        <div class="settings-section" style="margin-top: 20px;">
            <div class="settings-section-title">
                <span class="icon">Active Sessions</span>
            </div>

            <?php if (empty($activeSessions)): ?>
                <p style="color: rgba(255, 255, 255, 0.6); font-size: 14px;">No active sessions found.</p>
            <?php else: ?>
                <?php foreach ($activeSessions as $session): ?>
                    <div class="settings-link" style="cursor: default;">
                        <div class="settings-link-content">
                            <div>
                                <div class="settings-link-text">
                                    <?php echo htmlspecialchars($session['device_info'] ?? 'Unknown Device'); ?>
                                </div>
                                <div style="font-size: 11px; color: rgba(255, 255, 255, 0.5); margin-top: 4px;">
                                    <?php echo htmlspecialchars($session['ip_address'] ?? 'Unknown IP'); ?>
                                    &bull;
                                    Last active: <?php echo isset($session['last_active']) ? date('M j, g:i a', strtotime($session['last_active'])) : 'Unknown'; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($session['id'] != ($_SESSION['current_session_id'] ?? 0)): ?>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="logout_session">
                                <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">
                                    Logout
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color: #43e97b; font-size: 12px; font-weight: 600;">Current</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (count($activeSessions) > 1): ?>
                    <form method="POST" style="margin-top: 15px;">
                        <input type="hidden" name="action" value="logout_all">
                        <button type="submit" class="btn btn-danger btn-block" onclick="return confirm('Logout from all other devices?')">
                            Logout All Other Sessions
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Security Info -->
        <div class="settings-section" style="margin-top: 20px;">
            <div class="settings-section-title">
                <span class="icon">Security Tips</span>
            </div>

            <div class="alert alert-info" style="margin-bottom: 0;">
                <strong>Keep your account secure:</strong>
                <ul style="margin: 10px 0 0 20px; padding: 0;">
                    <li>Use a strong, unique password</li>
                    <li>Don't share your login credentials</li>
                    <li>Review active sessions regularly</li>
                    <li>Log out from devices you don't recognize</li>
                </ul>
            </div>
        </div>

        <a href="/profile/" class="btn btn-secondary btn-block" style="margin-top: 20px;">
            Back to Profile
        </a>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
