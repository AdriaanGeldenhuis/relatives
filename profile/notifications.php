<?php
declare(strict_types=1);

/**
 * ============================================
 * NOTIFICATION PREFERENCES PAGE v1.0
 * Manage notification settings
 * ============================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
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
    error_log('Notifications page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get user preferences (create default if not exists)
$preferences = [
    'push_enabled' => true,
    'email_enabled' => false,
    'sound_enabled' => true,
    'messages_notify' => true,
    'shopping_notify' => true,
    'calendar_notify' => true,
    'reminders_notify' => true,
    'tracking_notify' => true,
    'family_notify' => true,
];

try {
    $stmt = $db->prepare("SELECT * FROM user_notification_prefs WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $savedPrefs = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($savedPrefs) {
        $preferences = array_merge($preferences, $savedPrefs);
    }
} catch (Exception $e) {
    // Table might not exist, use defaults
    error_log('Notification prefs error: ' . $e->getMessage());
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $newPrefs = [
            'push_enabled' => isset($_POST['push_enabled']),
            'email_enabled' => isset($_POST['email_enabled']),
            'sound_enabled' => isset($_POST['sound_enabled']),
            'messages_notify' => isset($_POST['messages_notify']),
            'shopping_notify' => isset($_POST['shopping_notify']),
            'calendar_notify' => isset($_POST['calendar_notify']),
            'reminders_notify' => isset($_POST['reminders_notify']),
            'tracking_notify' => isset($_POST['tracking_notify']),
            'family_notify' => isset($_POST['family_notify']),
        ];

        // Check if user_notification_prefs table exists, create if not
        $db->exec("CREATE TABLE IF NOT EXISTS user_notification_prefs (
            user_id INT PRIMARY KEY,
            push_enabled TINYINT(1) DEFAULT 1,
            email_enabled TINYINT(1) DEFAULT 0,
            sound_enabled TINYINT(1) DEFAULT 1,
            messages_notify TINYINT(1) DEFAULT 1,
            shopping_notify TINYINT(1) DEFAULT 1,
            calendar_notify TINYINT(1) DEFAULT 1,
            reminders_notify TINYINT(1) DEFAULT 1,
            tracking_notify TINYINT(1) DEFAULT 1,
            family_notify TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Upsert preferences
        $stmt = $db->prepare("
            INSERT INTO user_notification_prefs
            (user_id, push_enabled, email_enabled, sound_enabled, messages_notify, shopping_notify, calendar_notify, reminders_notify, tracking_notify, family_notify)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            push_enabled = VALUES(push_enabled),
            email_enabled = VALUES(email_enabled),
            sound_enabled = VALUES(sound_enabled),
            messages_notify = VALUES(messages_notify),
            shopping_notify = VALUES(shopping_notify),
            calendar_notify = VALUES(calendar_notify),
            reminders_notify = VALUES(reminders_notify),
            tracking_notify = VALUES(tracking_notify),
            family_notify = VALUES(family_notify)
        ");

        $stmt->execute([
            $user['id'],
            $newPrefs['push_enabled'] ? 1 : 0,
            $newPrefs['email_enabled'] ? 1 : 0,
            $newPrefs['sound_enabled'] ? 1 : 0,
            $newPrefs['messages_notify'] ? 1 : 0,
            $newPrefs['shopping_notify'] ? 1 : 0,
            $newPrefs['calendar_notify'] ? 1 : 0,
            $newPrefs['reminders_notify'] ? 1 : 0,
            $newPrefs['tracking_notify'] ? 1 : 0,
            $newPrefs['family_notify'] ? 1 : 0,
        ]);

        $preferences = $newPrefs;
        $success = 'Notification preferences saved!';

    } catch (Exception $e) {
        error_log('Notification prefs save error: ' . $e->getMessage());
        $error = 'Failed to save preferences. Please try again.';
    }
}

$pageTitle = 'Notification Preferences';
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
            <div class="profile-name">Notification Preferences</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST">

            <!-- General Settings -->
            <div class="settings-section">
                <div class="settings-section-title">
                    <span class="icon">General</span>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Push Notifications</div>
                        <div class="toggle-description">Receive push notifications on your device</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="push_enabled" <?php echo $preferences['push_enabled'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Email Notifications</div>
                        <div class="toggle-description">Receive important updates via email</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="email_enabled" <?php echo $preferences['email_enabled'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Sound</div>
                        <div class="toggle-description">Play sound for notifications</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="sound_enabled" <?php echo $preferences['sound_enabled'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>

            <!-- Notification Types -->
            <div class="settings-section">
                <div class="settings-section-title">
                    <span class="icon">Notification Types</span>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Messages</div>
                        <div class="toggle-description">New messages from family members</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="messages_notify" <?php echo $preferences['messages_notify'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Shopping Lists</div>
                        <div class="toggle-description">Updates to shared shopping lists</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="shopping_notify" <?php echo $preferences['shopping_notify'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Calendar Events</div>
                        <div class="toggle-description">Upcoming events and invitations</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="calendar_notify" <?php echo $preferences['calendar_notify'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Reminders</div>
                        <div class="toggle-description">Scheduled reminders and alerts</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="reminders_notify" <?php echo $preferences['reminders_notify'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Location Tracking</div>
                        <div class="toggle-description">Location updates from family members</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="tracking_notify" <?php echo $preferences['tracking_notify'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">Family Updates</div>
                        <div class="toggle-description">New members and family changes</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="family_notify" <?php echo $preferences['family_notify'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Save Preferences</button>

        </form>

        <a href="/profile/" class="btn btn-secondary btn-block" style="margin-top: 15px;">
            Back to Profile
        </a>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
