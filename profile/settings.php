<?php
declare(strict_types=1);

/**
 * ============================================
 * ACCOUNT SETTINGS PAGE v1.0
 * User account settings and password change
 * ============================================
 */

require_once __DIR__ . '/../core/session_boot.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CSRF.php';

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
$csrfToken = CSRF::generate();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    try {
        if (!is_string($token) || !CSRF::validate($token)) {
            http_response_code(403);
            throw new InvalidArgumentException('Reload Account Settings and try again.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
            $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';

            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                $error = 'Enter a name between 2 and 120 characters.';
            } elseif (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                // Check if email is already taken by another user
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user['id']]);
                if ($stmt->fetch()) {
                    $error = 'This email is already in use.';
                } else {
                    $stmt = $db->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $user['id']]);

                    $user['name'] = $name;
                    $user['email'] = $email;
                    $success = 'Profile updated successfully!';
                }
            }
        }

        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!is_string($currentPassword) || $currentPassword === '') {
                $error = 'Please enter your current password.';
            } elseif (!is_string($newPassword) || strlen($newPassword) < 12) {
                $error = 'New password must be at least 12 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match.';
            } else {
                $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$user['id']]);
                $currentHash = $stmt->fetchColumn();

                if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
                    $error = 'Current password is incorrect.';
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
                        WHERE id = ? AND password_hash = ?');
                    $stmt->execute([$hashedPassword, $user['id'], $currentHash]);
                    if ($stmt->rowCount()) $success = 'Password changed successfully!';
                    else $error = 'Your password changed during this request. Try again with the current password.';
                }
            }
        }

    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Settings update error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$stmt = $db->prepare('SELECT created_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$memberSince = $stmt->fetchColumn();

$pageTitle = 'Account Settings';
$pageCSS = ['/profile/css/profile.css?build=' . filemtime(__DIR__ . '/css/profile.css')];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="profile-container">

        <div class="profile-header">
            <div class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                <?php renderProfileAvatar($user); ?>
            </div>
            <h1 class="profile-name">Account Settings</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Profile Information -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Profile Information</span>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label class="form-label" for="accountName">Full Name</label>
                    <input id="accountName" type="text" name="name" class="form-input" autocomplete="name" value="<?= htmlspecialchars($user['name']) ?>" required minlength="2" maxlength="120">
                </div>

                <div class="form-group">
                    <label class="form-label" for="accountEmail">Email Address</label>
                    <input id="accountEmail" type="email" name="email" class="form-input" autocomplete="email" value="<?= htmlspecialchars($user['email']) ?>" required maxlength="190">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label class="form-label" for="currentPassword">Current Password</label>
                    <input id="currentPassword" type="password" name="current_password" class="form-input" autocomplete="current-password" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="newPassword">New Password</label>
                    <input id="newPassword" type="password" name="new_password" class="form-input" autocomplete="new-password" required minlength="12" aria-describedby="passwordLength">
                    <small id="passwordLength">Use at least 12 characters.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirmPassword">Confirm New Password</label>
                    <input id="confirmPassword" type="password" name="confirm_password" class="form-input" autocomplete="new-password" required minlength="12">
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
                <label class="form-label" for="accountId">User ID</label>
                <input id="accountId" type="text" class="form-input" value="<?php echo htmlspecialchars((string)$user['id']); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label" for="accountRole">Role</label>
                <input id="accountRole" type="text" class="form-input" value="<?php echo ucfirst(htmlspecialchars($user['role'] ?? 'member')); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label" for="accountMemberSince">Member Since</label>
                <input id="accountMemberSince" type="text" class="form-input" value="<?= $memberSince ? date('F j, Y', strtotime($memberSince)) : 'Unknown' ?>" readonly>
            </div>
        </div>

        <a href="/profile/" class="btn btn-secondary btn-block" style="margin-top: 20px;">
            Back to Profile
        </a>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
