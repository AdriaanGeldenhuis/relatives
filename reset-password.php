<?php
declare(strict_types=1);

require_once __DIR__ . '/core/session_boot.php';

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: /home/', true, 302);
    exit;
}

require_once __DIR__ . '/core/bootstrap.php';

$error = '';
$success = false;
$token = $_GET['token'] ?? '';
$validToken = false;

// Validate token on page load
if ($token) {
    try {
        $auth = new Auth($db);
        $validToken = $auth->validateResetToken($token);
        
        if (!$validToken) {
            $error = 'Invalid or expired reset link. Please request a new one.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($password) || empty($confirmPassword)) {
            throw new Exception('Please enter and confirm your new password');
        }
        
        if ($password !== $confirmPassword) {
            throw new Exception('Passwords do not match');
        }
        
        if (strlen($password) < 12) {
            throw new Exception('Password must be at least 12 characters');
        }
        
        $auth = new Auth($db);
        $result = $auth->resetPassword($token, $password);
        
        if ($result['success']) {
            $success = true;
        } else {
            throw new Exception($result['message'] ?? 'Failed to reset password');
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
$pageTitle = 'Choose a Password - Relatives';
$authPage = true;
$showPasswordStrength = true;
require __DIR__ . '/shared/components/public-head.php';
?>
<body class="family-public" data-family-theme>
    <?php require __DIR__ . '/shared/components/site-navigation.php'; ?>
    <div class="auth-layout">
        <?php require __DIR__ . '/shared/components/auth-aside.php'; ?>
        <main class="auth-main">
            <div class="auth-content">
                <?php if ($success): ?>
                    <h1>Password reset.</h1>
                    <p class="auth-description">You can now sign in with your new password.</p>
                <?php elseif (!$token || !$validToken): ?>
                    <h1>Reset link unavailable.</h1>
                    <p class="auth-description"><?= htmlspecialchars($error ?: 'This password reset link is invalid or has expired.') ?></p>
                    <div class="auth-alternatives"><a class="text-link" href="/forgot-password.php">Request a new reset link</a></div>
                <?php else: ?>
                    <h1>Choose a new password.</h1>
                    <?php if ($error): ?>
                        <p class="form-error" role="alert"><?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>
                    <form method="post" class="auth-form">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <?php require __DIR__ . '/shared/components/password-fields.php'; ?>
                        <button class="button button-lime auth-submit" type="submit">Reset password <span class="button-arrow" aria-hidden="true"></span></button>
                    </form>
                <?php endif; ?>
                <div class="auth-alternatives"><a class="text-link" href="/login.php">Back to sign in</a></div>
            </div>
        </main>
    </div>
</body>
</html>
