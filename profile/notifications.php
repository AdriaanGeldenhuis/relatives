<?php
declare(strict_types=1);

/**
 * ============================================
 * NOTIFICATION PREFERENCES PAGE v1.0
 * Manage notification settings
 * ============================================
 */

require_once __DIR__ . '/../core/session_boot.php';

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

// This page reads/writes notification_preferences — the table
// NotificationManager actually consults when deciding whether to store a
// notification and send its push. (It previously wrote to its own private
// user_notification_prefs table that nothing else ever read, so every toggle
// here was a no-op.)

// UI toggle name => notification_preferences.category
$categoryMap = [
    'messages_notify'  => 'message',
    'shopping_notify'  => 'shopping',
    'calendar_notify'  => 'calendar',
    'reminders_notify' => 'schedule',
    'tracking_notify'  => 'tracking',
    'family_notify'    => 'system',
];
// Categories with no dedicated toggle; the global push/sound switches still
// apply to them without touching their enabled state.
$extraCategories = ['weather', 'note'];

$preferences = [
    'push_enabled' => true,
    'sound_enabled' => true,
    'messages_notify' => true,
    'shopping_notify' => true,
    'calendar_notify' => true,
    'reminders_notify' => true,
    'tracking_notify' => true,
    'family_notify' => true,
];

function loadNotificationPrefRows(PDO $db, int $userId): array {
    $stmt = $db->prepare("
        SELECT category, enabled, push_enabled, sound_enabled
        FROM notification_preferences
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pushEnabled = isset($_POST['push_enabled']) ? 1 : 0;
        $soundEnabled = isset($_POST['sound_enabled']) ? 1 : 0;

        // The /notifications settings modal manages push/sound PER CATEGORY.
        // Only fan the master toggles out to every category when the user
        // actually flipped them here; otherwise a save on this page would
        // silently clobber those finer-grained choices.
        $existingRows = loadNotificationPrefRows($db, (int)$user['id']);
        $currentPush = empty($existingRows);
        $currentSound = empty($existingRows);
        foreach ($existingRows as $row) {
            if ((int)$row['push_enabled'] === 1) $currentPush = true;
            if ((int)$row['sound_enabled'] === 1) $currentSound = true;
        }
        $applyGlobals = ($pushEnabled !== (int)$currentPush) || ($soundEnabled !== (int)$currentSound);

        if ($applyGlobals) {
            $upsert = $db->prepare("
                INSERT INTO notification_preferences
                (user_id, category, enabled, push_enabled, sound_enabled)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    push_enabled = VALUES(push_enabled),
                    sound_enabled = VALUES(sound_enabled)
            ");
        } else {
            $upsert = $db->prepare("
                INSERT INTO notification_preferences
                (user_id, category, enabled, push_enabled, sound_enabled)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled)
            ");
        }
        foreach ($categoryMap as $field => $category) {
            $upsert->execute([
                $user['id'],
                $category,
                isset($_POST[$field]) ? 1 : 0,
                $pushEnabled,
                $soundEnabled,
            ]);
        }

        if ($applyGlobals) {
            $upsertKeepEnabled = $db->prepare("
                INSERT INTO notification_preferences
                (user_id, category, enabled, push_enabled, sound_enabled)
                VALUES (?, ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                    push_enabled = VALUES(push_enabled),
                    sound_enabled = VALUES(sound_enabled)
            ");
            foreach ($extraCategories as $category) {
                $upsertKeepEnabled->execute([$user['id'], $category, $pushEnabled, $soundEnabled]);
            }
        }

        $success = 'Notification preferences saved!';

    } catch (Exception $e) {
        error_log('Notification prefs save error: ' . $e->getMessage());
        $error = 'Failed to save preferences. Please try again.';
    }
}

// Load current preferences for rendering
try {
    $rows = loadNotificationPrefRows($db, (int)$user['id']);

    if ($rows) {
        $byCategory = [];
        $anyPush = false;
        $anySound = false;
        foreach ($rows as $row) {
            $byCategory[$row['category']] = $row;
            if ((int)$row['push_enabled'] === 1) $anyPush = true;
            if ((int)$row['sound_enabled'] === 1) $anySound = true;
        }
        $preferences['push_enabled'] = $anyPush;
        $preferences['sound_enabled'] = $anySound;
        foreach ($categoryMap as $field => $category) {
            if (isset($byCategory[$category])) {
                $preferences[$field] = (bool)(int)$byCategory[$category]['enabled'];
            }
        }
    }
} catch (Exception $e) {
    error_log('Notification prefs error: ' . $e->getMessage());
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
