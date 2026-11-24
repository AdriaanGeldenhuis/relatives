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
$inviteCode = '';
$formData = [
    'family_name' => '',
    'full_name' => '',
    'email' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $auth = new Auth($db);
        
        $formData = [
            'family_name' => trim($_POST['family_name'] ?? ''),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'email' => trim($_POST['email'] ?? '')
        ];
        
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validate passwords match
        if ($password !== $confirmPassword) {
            throw new Exception('Passwords do not match');
        }
        
        // Register
        $result = $auth->registerNewFamily(
            $formData['family_name'],
            $formData['full_name'],
            $formData['email'],
            $password
        );
        
        if ($result['success']) {
            $success = true;
            $inviteCode = $result['invite_code'];
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Create Family - Relatives';
$authPage = true;
$showPasswordStrength = true;
require __DIR__ . '/shared/components/public-head.php';
?>
<body>
    <?php require __DIR__ . '/shared/components/site-navigation.php'; ?>
    <div class="auth-layout">
        <?php require __DIR__ . '/shared/components/auth-aside.php'; ?>
        <main class="auth-main">
            <div class="auth-content">
                <?php if ($success): ?>
                    <h1>Family Created!</h1>
                    <p class="auth-description">Share this invite code with the people you’d like to join.</p>
                    <div class="invite-card">
                        <span>Family invite code</span>
                        <strong id="inviteCode"><?= htmlspecialchars($inviteCode) ?></strong>
                        <button type="button" class="button button-outline" id="copy-invite" hidden>Copy code</button>
                        <small id="copy-status" role="status"></small>
                    </div>
                    <a href="/login.php" class="button button-lime auth-submit">Continue to sign in <span class="button-arrow" aria-hidden="true"></span></a>
                <?php else: ?>
                    <h1>Start your family hub.</h1>
                    <p class="auth-description">Create a space for your family, then invite everyone in.</p>

                    <?php if ($error): ?>
                        <p class="form-error" role="alert"><?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>

                    <form method="post" class="auth-form">
                        <div class="form-field">
                            <label for="family_name">Family name</label>
                            <input id="family_name" name="family_name" type="text" placeholder="e.g. The Smiths" value="<?= htmlspecialchars($formData['family_name']) ?>" required>
                        </div>
                        <?php require __DIR__ . '/shared/components/register-fields.php'; ?>
                        <button type="submit" class="button button-lime auth-submit">Create family <span class="button-arrow" aria-hidden="true"></span></button>
                    </form>

                    <div class="auth-alternatives">
                        <a class="text-link" href="/login.php">Already have an account? Sign in</a>
                        <a class="text-link" href="/register-join.php">Join with an invite</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
