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
$formData = [
    'invite_code' => '',
    'full_name' => '',
    'email' => ''
];

// Prefill invite code from link
if (!empty($_GET['code']) && empty($formData['invite_code'])) {
    $formData['invite_code'] = strtoupper(trim((string)$_GET['code']));
}
if (!empty($_GET['invite_code']) && empty($formData['invite_code'])) {
    $formData['invite_code'] = strtoupper(trim((string)$_GET['invite_code']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $auth = new Auth($db);
        
        $formData = [
            'invite_code' => strtoupper(trim($_POST['invite_code'] ?? '')),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'email' => trim($_POST['email'] ?? '')
        ];
        
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validate passwords match
        if ($password !== $confirmPassword) {
            throw new Exception('Passwords do not match');
        }
        
        // Register and join family
        $result = $auth->registerJoinFamily(
            $formData['invite_code'],
            $formData['full_name'],
            $formData['email'],
            $password
        );
        
        if ($result['success']) {
            // Auto-login after registration
            $loginResult = $auth->login($formData['email'], $password);
            
            if ($loginResult['success']) {
                header('Location: /home/?welcome=1', true, 302);
                exit;
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Join Family - Relatives';
$authPage = true;
require __DIR__ . '/shared/components/public-head.php';
?>
<body>
    <?php require __DIR__ . '/shared/components/site-navigation.php'; ?>
    <div class="auth-layout">
        <?php require __DIR__ . '/shared/components/auth-aside.php'; ?>
        <main class="auth-main">
            <div class="auth-content">
                <h1>Join your family.</h1>
                <p class="auth-description">Enter the invite code your family shared with you.</p>

                <?php if ($error): ?>
                    <p class="form-error" role="alert"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <form method="post" class="auth-form">
                    <div class="form-field">
                        <label for="invite_code">Invite code</label>
                        <input id="invite_code" name="invite_code" type="text" placeholder="e.g. ABC123" value="<?= htmlspecialchars($formData['invite_code']) ?>" autocapitalize="characters" spellcheck="false" maxlength="8" required>
                    </div>
                    <?php require __DIR__ . '/shared/components/register-fields.php'; ?>
                    <button type="submit" class="button button-lime auth-submit">Join family <span class="button-arrow" aria-hidden="true"></span></button>
                </form>

                <div class="auth-alternatives">
                    <a class="text-link" href="/login.php">Already have an account? Sign in</a>
                    <a class="text-link" href="/register-create.php">Create a family</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
