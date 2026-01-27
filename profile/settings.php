<?php
declare(strict_types=1);

/**
 * ============================================
 * ACCOUNT SETTINGS PAGE v1.0
 * User account settings and password change
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
    error_log('Settings page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if (empty($name) || strlen($name) < 2) {
                $error = 'Name must be at least 2 characters.';
            } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                // Check if email is already taken by another user
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user['id']]);
                if ($stmt->fetch()) {
                    $error = 'This email is already in use.';
                } else {
                    // Determine which name column to update
                    $nameColumn = isset($user['full_name']) ? 'full_name' : 'name';

                    $stmt = $db->prepare("UPDATE users SET {$nameColumn} = ?, email = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $user['id']]);

                    $user['name'] = $name;
                    $user['full_name'] = $name;
                    $user['email'] = $email;
                    $success = 'Profile updated successfully!';
                }
            }
        }

        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword)) {
                $error = 'Please enter your current password.';
            } elseif (empty($newPassword) || strlen($newPassword) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match.';
            } else {
                // Verify current password
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$user['id']]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!password_verify($currentPassword, $userData['password'])) {
                    $error = 'Current password is incorrect.';
                } else {
                    // Update password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $user['id']]);
                    $success = 'Password changed successfully!';
                }
            }
        }

    } catch (Exception $e) {
        error_log('Settings update error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Account Settings';
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
            <div class="profile-name">Account Settings</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Profile Information -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Profile Information</span>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? ''); ?>" required minlength="2">
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Change Password</span>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-input" required minlength="8" placeholder="At least 8 characters">
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-input" required minlength="8">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Change Password</button>
            </form>
        </div>

        <!-- Account Info -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Account Information</span>
            </div>

            <div class="form-group">
                <label class="form-label">User ID</label>
                <input type="text" class="form-input" value="<?php echo htmlspecialchars((string)$user['id']); ?>" disabled>
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <input type="text" class="form-input" value="<?php echo ucfirst(htmlspecialchars($user['role'] ?? 'member')); ?>" disabled>
            </div>

            <div class="form-group">
                <label class="form-label">Member Since</label>
                <input type="text" class="form-input" value="<?php echo isset($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'Unknown'; ?>" disabled>
            </div>
        </div>

        <a href="/profile/" class="btn btn-secondary btn-block" style="margin-top: 20px;">
            Back to Profile
        </a>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
