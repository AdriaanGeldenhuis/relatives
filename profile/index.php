<?php
declare(strict_types=1);

/**
 * ============================================
 * MY PROFILE PAGE v1.0
 * User profile overview and quick settings
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
    error_log('Profile page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get family info
$family = null;
try {
    $stmt = $db->prepare("SELECT * FROM families WHERE id = ?");
    $stmt->execute([$user['family_id']]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family fetch error: ' . $e->getMessage());
}

// Format member since date
$memberSince = isset($user['created_at'])
    ? date('F Y', strtotime($user['created_at']))
    : 'Unknown';

$pageTitle = 'My Profile';
$pageCSS = ['/profile/css/profile.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="profile-container">

        <?php $avatarPath = '/saves/' . $user['id'] . '/avatar/avatar.webp'; ?>
        <div class="profile-header">
            <a href="/profile/picture.php" class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>; text-decoration: none;">
                <img src="<?php echo htmlspecialchars($avatarPath); ?>?t=<?php echo time(); ?>"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                     alt="Profile Picture" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:48px; font-weight:800;">
                    <?php echo strtoupper(substr($user['name'] ?? $user['full_name'] ?? '?', 0, 1)); ?>
                </span>
                <div class="profile-avatar-edit">Change</div>
            </a>

            <div class="profile-name"><?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User'); ?></div>
            <div class="profile-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            <div class="profile-role"><?php echo htmlspecialchars($user['role'] ?? 'member'); ?></div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <?php
                $successMsg = match($_GET['success']) {
                    'profile' => 'Profile updated successfully!',
                    'picture' => 'Profile picture updated!',
                    'password' => 'Password changed successfully!',
                    default => 'Changes saved successfully!'
                };
                echo $successMsg;
                ?>
            </div>
        <?php endif; ?>

        <!-- Quick Links -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Settings</span>
            </div>

            <a href="/profile/picture.php" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Profile Picture</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>

            <a href="/profile/settings.php" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Account Settings</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>

            <a href="/profile/notifications.php" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Notification Preferences</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>

            <a href="/profile/privacy.php" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Privacy & Security</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>

            <?php if (in_array($user['role'] ?? '', ['owner', 'admin'])): ?>
            <a href="/profile/subscription.php" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Subscription & Billing</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Family Info -->
        <?php if ($family): ?>
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Family</span>
            </div>

            <div class="form-group">
                <label class="form-label">Family Name</label>
                <input type="text" class="form-input" value="<?php echo htmlspecialchars($family['name'] ?? 'My Family'); ?>" disabled>
            </div>

            <div class="form-group">
                <label class="form-label">Your Role</label>
                <input type="text" class="form-input" value="<?php echo ucfirst(htmlspecialchars($user['role'] ?? 'member')); ?>" disabled>
            </div>

            <?php if (in_array($user['role'], ['owner', 'admin'])): ?>
            <a href="/admin/" class="btn btn-secondary btn-block">
                Manage Family
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Help & Support -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Support</span>
            </div>

            <a href="/help/" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Help Center</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>

            <a href="/legal/" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Legal & Privacy Policy</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>
        </div>

        <!-- Logout -->
        <a href="/logout.php" class="btn btn-danger btn-block" style="margin-top: 20px;">
            Logout
        </a>

        <div class="member-since">
            Member since <?php echo $memberSince; ?>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
