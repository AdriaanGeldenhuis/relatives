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

        <!-- Device Permissions -->
        <div class="settings-section" style="margin-top: 20px;">
            <div class="settings-section-title">
                <span class="icon">Device Permissions</span>
            </div>

            <div class="permission-row" id="microphonePermissionRow">
                <div class="permission-info">
                    <div class="permission-icon">🎤</div>
                    <div class="permission-details">
                        <div class="permission-label">Microphone Access</div>
                        <div class="permission-description">Required for voice notes</div>
                        <div class="permission-status" id="microphoneStatus">
                            <span class="status-indicator status-unknown"></span>
                            <span class="status-text">Checking...</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-permission" id="btnRequestMicrophone" onclick="requestMicrophonePermission()">
                    <span class="btn-text">Allow</span>
                </button>
            </div>

            <div class="permission-row" id="notificationPermissionRow">
                <div class="permission-info">
                    <div class="permission-icon">🔔</div>
                    <div class="permission-details">
                        <div class="permission-label">Notification Access</div>
                        <div class="permission-description">Required for alerts and reminders</div>
                        <div class="permission-status" id="notificationStatus">
                            <span class="status-indicator status-unknown"></span>
                            <span class="status-text">Checking...</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-permission" id="btnRequestNotification" onclick="requestNotificationPermission()">
                    <span class="btn-text">Allow</span>
                </button>
            </div>

            <div class="permission-row" id="locationPermissionRow">
                <div class="permission-info">
                    <div class="permission-icon">📍</div>
                    <div class="permission-details">
                        <div class="permission-label">Location Access</div>
                        <div class="permission-description">Required for family tracking</div>
                        <div class="permission-status" id="locationStatus">
                            <span class="status-indicator status-unknown"></span>
                            <span class="status-text">Checking...</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-permission" id="btnRequestLocation" onclick="requestLocationPermission()">
                    <span class="btn-text">Allow</span>
                </button>
            </div>

            <div class="permission-note">
                <strong>Note:</strong> Permissions are managed by your browser or device. If a permission is blocked, you may need to update it in your browser settings.
            </div>
        </div>

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

<script>
// ============================================
// DEVICE PERMISSIONS MANAGEMENT
// ============================================

// Check all permissions on page load
document.addEventListener('DOMContentLoaded', function() {
    checkAllPermissions();
});

async function checkAllPermissions() {
    await checkMicrophonePermission();
    await checkNotificationPermission();
    await checkLocationPermission();
}

// ============================================
// MICROPHONE PERMISSION
// ============================================

async function checkMicrophonePermission() {
    const statusEl = document.getElementById('microphoneStatus');
    const btnEl = document.getElementById('btnRequestMicrophone');

    try {
        // Check if Permissions API is supported
        if (navigator.permissions && navigator.permissions.query) {
            const result = await navigator.permissions.query({ name: 'microphone' });
            updatePermissionUI('microphone', result.state, statusEl, btnEl);

            // Listen for permission changes
            result.addEventListener('change', function() {
                updatePermissionUI('microphone', this.state, statusEl, btnEl);
            });
        } else {
            // Fallback: try to enumerate devices
            if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const hasMic = devices.some(d => d.kind === 'audioinput' && d.label);
                updatePermissionUI('microphone', hasMic ? 'granted' : 'prompt', statusEl, btnEl);
            } else {
                updatePermissionUI('microphone', 'unknown', statusEl, btnEl);
            }
        }
    } catch (e) {
        console.log('Microphone permission check error:', e);
        updatePermissionUI('microphone', 'unknown', statusEl, btnEl);
    }
}

async function requestMicrophonePermission() {
    const statusEl = document.getElementById('microphoneStatus');
    const btnEl = document.getElementById('btnRequestMicrophone');

    btnEl.disabled = true;
    btnEl.querySelector('.btn-text').textContent = 'Requesting...';

    try {
        // Check for native Android app
        if (window.Android && typeof window.Android.requestMicrophonePermission === 'function') {
            window.Android.requestMicrophonePermission();
            // Native callback will handle the result
            return;
        }

        // Web API request
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

        // Permission granted - stop the stream immediately
        stream.getTracks().forEach(track => track.stop());

        updatePermissionUI('microphone', 'granted', statusEl, btnEl);
        showToast('Microphone access granted!', 'success');

    } catch (error) {
        console.error('Microphone permission error:', error);

        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            updatePermissionUI('microphone', 'denied', statusEl, btnEl);
            showToast('Microphone permission denied. Please allow in browser settings.', 'error');
        } else if (error.name === 'NotFoundError') {
            updatePermissionUI('microphone', 'unavailable', statusEl, btnEl);
            showToast('No microphone found on this device.', 'error');
        } else {
            updatePermissionUI('microphone', 'denied', statusEl, btnEl);
            showToast('Could not access microphone: ' + error.message, 'error');
        }
    }

    btnEl.disabled = false;
}

// Native app callback for microphone permission
window.onMicrophonePermissionResult = function(granted) {
    const statusEl = document.getElementById('microphoneStatus');
    const btnEl = document.getElementById('btnRequestMicrophone');

    updatePermissionUI('microphone', granted ? 'granted' : 'denied', statusEl, btnEl);
    showToast(granted ? 'Microphone access granted!' : 'Microphone permission denied.', granted ? 'success' : 'error');

    btnEl.disabled = false;
};

// ============================================
// NOTIFICATION PERMISSION
// ============================================

async function checkNotificationPermission() {
    const statusEl = document.getElementById('notificationStatus');
    const btnEl = document.getElementById('btnRequestNotification');

    if (!('Notification' in window)) {
        updatePermissionUI('notification', 'unavailable', statusEl, btnEl);
        return;
    }

    updatePermissionUI('notification', Notification.permission, statusEl, btnEl);
}

async function requestNotificationPermission() {
    const statusEl = document.getElementById('notificationStatus');
    const btnEl = document.getElementById('btnRequestNotification');

    if (!('Notification' in window)) {
        showToast('Notifications not supported on this device.', 'error');
        return;
    }

    btnEl.disabled = true;
    btnEl.querySelector('.btn-text').textContent = 'Requesting...';

    try {
        const permission = await Notification.requestPermission();
        updatePermissionUI('notification', permission, statusEl, btnEl);

        if (permission === 'granted') {
            showToast('Notification access granted!', 'success');
        } else {
            showToast('Notification permission denied.', 'error');
        }
    } catch (error) {
        console.error('Notification permission error:', error);
        showToast('Could not request notification permission.', 'error');
    }

    btnEl.disabled = false;
}

// ============================================
// LOCATION PERMISSION
// ============================================

async function checkLocationPermission() {
    const statusEl = document.getElementById('locationStatus');
    const btnEl = document.getElementById('btnRequestLocation');

    try {
        if (navigator.permissions && navigator.permissions.query) {
            const result = await navigator.permissions.query({ name: 'geolocation' });
            updatePermissionUI('location', result.state, statusEl, btnEl);

            result.addEventListener('change', function() {
                updatePermissionUI('location', this.state, statusEl, btnEl);
            });
        } else {
            updatePermissionUI('location', 'unknown', statusEl, btnEl);
        }
    } catch (e) {
        console.log('Location permission check error:', e);
        updatePermissionUI('location', 'unknown', statusEl, btnEl);
    }
}

async function requestLocationPermission() {
    const statusEl = document.getElementById('locationStatus');
    const btnEl = document.getElementById('btnRequestLocation');

    btnEl.disabled = true;
    btnEl.querySelector('.btn-text').textContent = 'Requesting...';

    try {
        await new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(
                () => resolve(),
                (error) => reject(error),
                { timeout: 10000 }
            );
        });

        updatePermissionUI('location', 'granted', statusEl, btnEl);
        showToast('Location access granted!', 'success');

    } catch (error) {
        console.error('Location permission error:', error);

        if (error.code === 1) { // PERMISSION_DENIED
            updatePermissionUI('location', 'denied', statusEl, btnEl);
            showToast('Location permission denied. Please allow in browser settings.', 'error');
        } else {
            updatePermissionUI('location', 'denied', statusEl, btnEl);
            showToast('Could not access location: ' + error.message, 'error');
        }
    }

    btnEl.disabled = false;
}

// ============================================
// UI HELPERS
// ============================================

function updatePermissionUI(type, state, statusEl, btnEl) {
    const indicator = statusEl.querySelector('.status-indicator');
    const text = statusEl.querySelector('.status-text');
    const btnText = btnEl.querySelector('.btn-text');

    // Remove all status classes
    indicator.className = 'status-indicator';

    switch (state) {
        case 'granted':
            indicator.classList.add('status-granted');
            text.textContent = 'Allowed';
            btnEl.style.display = 'none';
            break;
        case 'denied':
            indicator.classList.add('status-denied');
            text.textContent = 'Blocked';
            btnText.textContent = 'Open Settings';
            btnEl.style.display = 'flex';
            btnEl.onclick = () => openPermissionSettings(type);
            break;
        case 'prompt':
            indicator.classList.add('status-prompt');
            text.textContent = 'Not set';
            btnText.textContent = 'Allow';
            btnEl.style.display = 'flex';
            break;
        case 'unavailable':
            indicator.classList.add('status-unavailable');
            text.textContent = 'Not available';
            btnEl.style.display = 'none';
            break;
        default:
            indicator.classList.add('status-unknown');
            text.textContent = 'Unknown';
            btnText.textContent = 'Allow';
            btnEl.style.display = 'flex';
    }
}

function openPermissionSettings(type) {
    // Different guidance based on platform
    const isAndroid = /Android/i.test(navigator.userAgent);
    const isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    const isChrome = /Chrome/i.test(navigator.userAgent);
    const isFirefox = /Firefox/i.test(navigator.userAgent);
    const isSafari = /Safari/i.test(navigator.userAgent) && !isChrome;

    let message = '';

    if (isAndroid) {
        message = `To enable ${type} access:\n\n1. Tap the lock icon in your browser's address bar\n2. Tap "Permissions" or "Site settings"\n3. Find "${type}" and change it to "Allow"`;
    } else if (isIOS) {
        message = `To enable ${type} access:\n\n1. Open Settings app\n2. Scroll down and tap Safari (or your browser)\n3. Tap "Settings for Websites"\n4. Find "${type}" and allow access`;
    } else if (isChrome) {
        message = `To enable ${type} access:\n\n1. Click the lock icon in the address bar\n2. Click "Site settings"\n3. Find "${type}" and change it to "Allow"\n4. Refresh this page`;
    } else if (isFirefox) {
        message = `To enable ${type} access:\n\n1. Click the lock icon in the address bar\n2. Click "Connection secure" > "More information"\n3. Go to "Permissions" tab\n4. Find "${type}" and allow it`;
    } else if (isSafari) {
        message = `To enable ${type} access:\n\n1. Go to Safari > Settings > Websites\n2. Find "${type}" in the left sidebar\n3. Find this website and change to "Allow"`;
    } else {
        message = `To enable ${type} access, please check your browser settings and allow this website to access your ${type}.`;
    }

    alert(message);
}

function showToast(message, type = 'info') {
    // Check if there's a global showToast function
    if (typeof window.showToast === 'function' && window.showToast !== showToast) {
        window.showToast(message, type);
        return;
    }

    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%);
        background: ${type === 'success' ? '#43e97b' : type === 'error' ? '#ff6b6b' : '#667eea'};
        color: white;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
        z-index: 10000;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        animation: toastIn 0.3s ease;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add toast animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes toastIn {
        from { opacity: 0; transform: translateX(-50%) translateY(20px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
    @keyframes toastOut {
        from { opacity: 1; transform: translateX(-50%) translateY(0); }
        to { opacity: 0; transform: translateX(-50%) translateY(20px); }
    }
`;
document.head.appendChild(style);
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
