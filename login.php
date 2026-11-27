<?php
declare(strict_types=1);

/**
 * ============================================
 * LOGIN PAGE - COMPLETE FILE
 * ============================================
 */

// Start session with correct name and security settings (shared helper —
// also applies the 30-day gc_maxlifetime/cookie_lifetime the old inline
// block was missing).
require_once __DIR__ . '/core/session_boot.php';

// Post-login redirect target (e.g. /login.php?redirect=%2Ftracking%2Fapp%2F
// sent by tracking/index.php). Internal paths only: must start with a single
// "/" and contain no ASCII control characters.
$postLoginRedirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '/home/';
if (!is_string($postLoginRedirect)
    || $postLoginRedirect === ''
    || $postLoginRedirect[0] !== '/'
    || (isset($postLoginRedirect[1]) && ($postLoginRedirect[1] === '/' || $postLoginRedirect[1] === '\\'))
    || preg_match('/[\x00-\x1f\x7f]/', $postLoginRedirect)
    || !preg_match('//u', $postLoginRedirect)
) {
    $postLoginRedirect = '/home/';
}

// If already logged in, redirect immediately
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: ' . $postLoginRedirect, true, 302);
    exit;
}

// Load bootstrap
require_once __DIR__ . '/core/bootstrap.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $auth = new Auth($db);
        
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            throw new Exception('Please enter both email and password');
        }
        
        // Login
        $result = $auth->login($email, $password);
        
        if ($result['success']) {
            header('Location: ' . $postLoginRedirect, true, 303);
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log('Login error: ' . $e->getMessage());
    }
}

$pageTitle = 'Sign In - Relatives';
$authPage = true;
require __DIR__ . '/shared/components/public-head.php';
?>
<body class="family-public" data-family-theme>
    <?php require __DIR__ . '/shared/components/site-navigation.php'; ?>
    <div class="auth-layout">
        <?php require __DIR__ . '/shared/components/auth-aside.php'; ?>
        <main class="auth-main">
            <div class="auth-content">
                <h1>Welcome back.</h1>
                <p class="auth-description">Sign in to your family hub.</p>

                <?php if ($error): ?>
                    <p class="form-error" role="alert"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <form method="post" class="auth-form">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($postLoginRedirect) ?>">
                    <div class="form-field">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="username" placeholder="you@example.com" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    <div class="form-field">
                        <div class="field-heading">
                            <label for="password">Password</label>
                            <a class="text-link" href="/forgot-password.php">Forgot password?</a>
                        </div>
                        <div class="password-field">
                            <input id="password" name="password" type="password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-password="password" aria-controls="password" hidden>Show</button>
                        </div>
                    </div>
                    <button class="button button-lime auth-submit" type="submit">Sign in <span class="button-arrow" aria-hidden="true"></span></button>
                </form>

                <div class="auth-alternatives">
                    <p>New to Relatives?</p>
                    <div>
                        <a class="text-link" href="/register-create.php">Create a family</a>
                        <a class="text-link" href="/register-join.php">Join with an invite</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
