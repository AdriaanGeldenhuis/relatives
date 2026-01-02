<?php
declare(strict_types=1);

session_start();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0c29">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Join Family - Relatives</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* Same base styles as register-create.php */
        :root {
            --primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --glass-light: rgba(255, 255, 255, 0.08);
            --glass-medium: rgba(255, 255, 255, 0.12);
            --glass-border: rgba(255, 255, 255, 0.25);
            --shadow-xl: 0 24px 64px rgba(0, 0, 0, 0.25);
            --radius-lg: 24px;
            --radius-xl: 32px;
            --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
            overflow-x: hidden;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            color: white;
            background: #0f0c29;
            -webkit-font-smoothing: antialiased;
        }
        
        .bg-animation {
            position: fixed;
            inset: 0;
            z-index: -1;
            overflow: hidden;
        }
        
        .bg-gradient {
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(ellipse at 20% 20%, rgba(102, 126, 234, 0.4) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(118, 75, 162, 0.4) 0%, transparent 50%),
                linear-gradient(180deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            background-size: 200% 200%;
            animation: auroraFlow 20s ease infinite;
        }
        
        @keyframes auroraFlow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .auth-card {
            background: var(--glass-medium);
            backdrop-filter: blur(40px) saturate(180%);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 60px 50px;
            max-width: 560px;
            width: 100%;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(40px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }
        
        .logo-icon {
            font-size: 80px;
            margin-bottom: 20px;
            display: inline-block;
            filter: drop-shadow(0 8px 24px rgba(102, 126, 234, 0.5));
            animation: ticketFloat 4s ease-in-out infinite;
        }
        
        @keyframes ticketFloat {
            0%, 100% { transform: translateY(0) rotate(-5deg); }
            50% { transform: translateY(-15px) rotate(5deg); }
        }
        
        .logo-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.75rem;
            font-weight: 900;
            background: linear-gradient(135deg, #fff 0%, #f0f0f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
            letter-spacing: -2px;
        }
        
        .logo-subtitle {
            font-size: 1.125rem;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }
        
        .error-box {
            background: linear-gradient(135deg, rgba(255, 71, 87, 0.2), rgba(232, 65, 24, 0.2));
            border: 2px solid rgba(255, 71, 87, 0.5);
            border-radius: var(--radius-lg);
            padding: 18px 24px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 14px;
            animation: shake 0.5s;
            position: relative;
            z-index: 1;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }
        
        .auth-form {
            position: relative;
            z-index: 1;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .form-input {
            width: 100%;
            padding: 18px 20px;
            background: var(--glass-light);
            border: 2px solid var(--glass-border);
            border-radius: var(--radius-lg);
            color: white;
            font-size: 1rem;
            font-weight: 500;
            transition: all var(--transition-base);
            font-family: inherit;
        }
        
        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        
        .form-input:focus {
            outline: none;
            border-color: rgba(102, 126, 234, 0.8);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
        }
        
        /* Special styling for invite code input */
        input[name="invite_code"] {
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 4px;
            font-size: 1.5rem;
            text-align: center;
            font-family: 'Space Grotesk', monospace;
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
            transition: all var(--transition-base);
            filter: grayscale(1);
        }
        
        .password-toggle:hover {
            transform: translateY(-50%) scale(1.15);
            filter: grayscale(0);
        }
        
        .form-hint {
            display: block;
            margin-top: 8px;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 500;
        }
        
        .submit-btn {
            width: 100%;
            padding: 20px;
            background: var(--primary);
            border: none;
            border-radius: var(--radius-lg);
            color: white;
            font-size: 1.125rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            cursor: pointer;
            transition: all var(--transition-base);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.4);
            font-family: 'Space Grotesk', sans-serif;
            margin-top: 12px;
        }
        
        .submit-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 16px 40px rgba(102, 126, 234, 0.6);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .submit-btn.loading {
            position: relative;
            color: transparent;
        }
        
        .submit-btn.loading::after {
            content: '';
            position: absolute;
            width: 24px;
            height: 24px;
            top: 50%;
            left: 50%;
            margin: -12px 0 0 -12px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .divider {
            position: relative;
            text-align: center;
            margin: 32px 0;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            background: var(--glass-border);
        }
        
        .divider-text {
            position: relative;
            display: inline-block;
            padding: 0 20px;
            background: var(--glass-medium);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .link-section {
            display: flex;
            gap: 12px;
            position: relative;
            z-index: 1;
        }
        
        .link-btn {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 20px 16px;
            background: var(--glass-light);
            border: 2px solid var(--glass-border);
            border-radius: var(--radius-lg);
            color: white;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all var(--transition-base);
            text-align: center;
        }
        
        .link-btn:hover {
            background: var(--glass-medium);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
        }
        
        .link-btn-icon {
            font-size: 2rem;
            filter: drop-shadow(0 2px 8px rgba(255, 255, 255, 0.3));
        }
        
        @media (max-width: 640px) {
            .auth-card {
                padding: 40px 30px;
            }
            
            .logo-title {
                font-size: 2.25rem;
            }
            
            .link-section {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .auth-card {
                padding: 32px 24px;
            }
            
            .form-input {
                font-size: 16px;
            }
            
            input[name="invite_code"] {
                font-size: 1.25rem;
                letter-spacing: 3px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="bg-gradient"></div>
    </div>

    <div class="container">
        <div class="auth-card">
            <div class="logo-section">
                <div class="logo-icon">🎫</div>
                <h1 class="logo-title">Join Family</h1>
                <p class="logo-subtitle">Enter your invite code to join</p>
            </div>

            <?php if ($error): ?>
                <div class="error-box">
                    <span style="font-size: 28px;">⚠️</span>
                    <span style="font-weight: 600; color: #ffcccb;"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form" id="joinForm">
                <div class="form-group">
                    <label for="invite_code" class="form-label">Invite Code</label>
                    <input 
                        type="text" 
                        id="invite_code"
                        name="invite_code"
                        class="form-input"
                        placeholder="ABC123"
                        value="<?php echo htmlspecialchars($formData['invite_code']); ?>"
                        required
                        maxlength="8"
                        autofocus>
                    <small class="form-hint">6-8 character code from your family</small>
                </div>

                <div class="form-group">
                    <label for="full_name" class="form-label">Your Full Name</label>
                    <input 
                        type="text" 
                        id="full_name"
                        name="full_name"
                        class="form-input"
                        placeholder="John Smith"
                        value="<?php echo htmlspecialchars($formData['full_name']); ?>"
                        required>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input 
                        type="email" 
                        id="email"
                        name="email"
                        class="form-input"
                        placeholder="you@example.com"
                        value="<?php echo htmlspecialchars($formData['email']); ?>"
                        required
                        autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input 
                            type="password" 
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="Create a strong password"
                            required
                            autocomplete="new-password"
                            minlength="12">
                        <button type="button" class="password-toggle" onclick="togglePassword('password')">
                            👁️
                        </button>
                    </div>
                    <small class="form-hint">Minimum 12 characters</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="password-wrapper">
                        <input 
                            type="password" 
                            id="confirm_password"
                            name="confirm_password"
                            class="form-input"
                            placeholder="Repeat your password"
                            required
                            autocomplete="new-password"
                            minlength="12">
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                            👁️
                        </button>
                    </div>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    Join Family
                </button>
            </form>

            <div class="divider">
                <span class="divider-text">Don't have a code?</span>
            </div>

            <div class="link-section">
                <a href="/register-create.php" class="link-btn">
                    <span class="link-btn-icon">🏠</span>
                    <span>Create Family</span>
                </a>
                <a href="/login.php" class="link-btn">
                    <span class="link-btn-icon">🔑</span>
                    <span>Sign In</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Auto-uppercase invite code
        document.getElementById('invite_code').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
        
        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const btn = input.parentElement.querySelector('.password-toggle');
            
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🙈';
            } else {
                input.type = 'password';
                btn.textContent = '👁️';
            }
        }
        
        document.getElementById('joinForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password !== confirm) {
                e.preventDefault();
                alert('❌ Passwords do not match!');
                return;
            }
            
            if (password.length < 12) {
                e.preventDefault();
                alert('❌ Password must be at least 12 characters!');
                return;
            }
            
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('loading');
        });
    </script>
</body>
</html>