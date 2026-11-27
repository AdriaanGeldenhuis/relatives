<?php
declare(strict_types=1);

/**
 * ============================================
 * MY PROFILE PAGE v1.0
 * User profile overview and quick settings
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
    error_log('Profile page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get family info
$family = null;
try {
    $stmt = $db->prepare('SELECT f.name, u.created_at FROM families f JOIN users u ON u.family_id = f.id WHERE u.id = ?');
    $stmt->execute([$user['id']]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family fetch error: ' . $e->getMessage());
}

// Format member since date
$memberSince = $family && $family['created_at'] ? new DateTimeImmutable($family['created_at']) : null;

$pageTitle = 'My Profile';
$pageCSS = ['/profile/css/profile.css?build=' . filemtime(__DIR__ . '/css/profile.css')];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="profile-container">

        <div class="profile-header photo-hero photo-hero-compact family-hero-profile">
            <a href="/profile/picture.php" aria-label="Change profile picture" class="profile-avatar-large" style="background: <?= htmlspecialchars($user['avatar_color'] ?? '#667eea') ?>">
                <?php renderProfileAvatar($user); ?>
                <span class="profile-avatar-edit" aria-hidden="true">Change</span>
            </a>

            <h1 class="profile-name"><?= htmlspecialchars($user['name']) ?></h1>
            <div class="profile-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            <div class="profile-role"><?php echo htmlspecialchars($user['role'] ?? 'member'); ?></div>
            <nav class="family-jump" aria-label="Profile sections">
                <a href="#profileSettings">Settings</a>
                <?php if ($family): ?><a href="#profileFamily">Family</a><?php endif; ?>
                <a href="#profileSupport">Support</a>
            </nav>
            <?php renderFamilyScene('family'); ?>
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
        <div class="settings-section" id="profileSettings" data-family-jump>
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
        <div class="settings-section" id="profileFamily" data-family-jump>
            <div class="settings-section-title">
                <span class="icon">Family</span>
            </div>

            <div class="form-group">
                <label class="form-label" for="profileFamilyName">Family Name</label>
                <input id="profileFamilyName" type="text" class="form-input" value="<?php echo htmlspecialchars($family['name'] ?? 'My Family'); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label" for="profileFamilyRole">Your Role</label>
                <input id="profileFamilyRole" type="text" class="form-input" value="<?php echo ucfirst(htmlspecialchars($user['role'] ?? 'member')); ?>" readonly>
            </div>

            <?php if (in_array($user['role'], ['owner', 'admin'])): ?>
            <a href="/admin/" class="btn btn-secondary btn-block">
                Manage Family
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Help & Support -->
        <div class="settings-section" id="profileSupport" data-family-jump>
            <div class="settings-section-title">
                <span class="icon">Support</span>
            </div>

            <a href="/help/" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Help Center</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>

            <a href="/legal/privacy.php" class="settings-link">
                <div class="settings-link-content">
                    <span class="settings-link-icon">Legal & Privacy Policy</span>
                </div>
                <span class="settings-link-arrow"></span>
            </a>
        </div>

        <a href="/legal/delete-account.php" class="settings-link">
            <span class="settings-link-content">Delete account</span>
            <span class="settings-link-arrow"></span>
        </a>

        <!-- Logout -->
        <a href="/logout.php" class="btn btn-danger btn-block" style="margin-top: 20px;">
            Logout
        </a>

        <?php if ($memberSince): ?>
        <div class="member-since">Member since <time datetime="<?= $memberSince->format('Y-m') ?>"><?= $memberSince->format('F Y') ?></time></div>
        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
