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
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            throw new Exception('Please enter your email address');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address');
        }
        
        $auth = new Auth($db);
        $result = $auth->sendPasswordReset($email);
        
        // Always show success to prevent email enumeration
        $success = true;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
$pageTitle = 'Reset Password - Relatives';
$authPage = true;
require __DIR__ . '/shared/components/public-head.php';
?>
<body>
    <?php require __DIR__ . '/shared/components/site-navigation.php'; ?>
    <div class="auth-layout">
        <?php require __DIR__ . '/shared/components/auth-aside.php'; ?>
        <main class="auth-main">
            <div class="auth-content">
                <?php if ($success): ?>
                    <h1>Check your email.</h1>
                    <p class="auth-description">If an account exists for <strong><?= htmlspecialchars($email) ?></strong>, you’ll receive a reset link. Check your spam folder if it doesn’t arrive.</p>
                <?php else: ?>
                    <h1>Reset your password.</h1>
                    <p class="auth-description">Enter your email address to request a reset link.</p>
                    <?php if ($error): ?>
                        <p class="form-error" role="alert"><?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>
                    <form method="post" class="auth-form">
                        <div class="form-field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" value="<?= htmlspecialchars($email) ?>" required>
                        </div>
                        <button class="button button-lime auth-submit" type="submit">Send reset link <span class="button-arrow" aria-hidden="true"></span></button>
                    </form>
                <?php endif; ?>
                <div class="auth-alternatives"><a class="text-link" href="/login.php">Back to sign in</a></div>
            </div>
        </main>
    </div>
</body>
</html>
