<?php
declare(strict_types=1);

session_name('RELATIVES_SESSION');
session_start();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0c29">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Create Family - Relatives</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --glass-light: rgba(255, 255, 255, 0.08);
            --glass-medium: rgba(255, 255, 255, 0.12);
            --glass-border: rgba(255, 255, 255, 0.25);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.18);
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
                radial-gradient(ellipse at 50% 50%, rgba(240, 147, 251, 0.3) 0%, transparent 50%),
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
            max-width: 580px;
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
        
        .auth-card::before {
            content: '';
            position: absolute;
            inset: -200%;
            background: conic-gradient(
                from 0deg,
                transparent 0deg 330deg,
                rgba(255, 255, 255, 0.15) 330deg 360deg
            );
            animation: cardShine 6s linear infinite;
        }
        
        @keyframes cardShine {
            to { transform: rotate(360deg); }
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
            animation: logoFloat 3s ease-in-out infinite;
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(5deg); }
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
        
        /* Success State */
        .success-box {
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .success-icon {
            font-size: 100px;
            margin-bottom: 24px;
            display: inline-block;
            animation: celebrate 1s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        
        @keyframes celebrate {
            0% { transform: scale(0) rotate(0deg); }
            50% { transform: scale(1.2) rotate(180deg); }
            100% { transform: scale(1) rotate(360deg); }
        }
        
        .success-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.25rem;
            font-weight: 900;
            color: #68d391;
            margin-bottom: 16px;
        }
        
        .success-text {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.125rem;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        
        .invite-code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: var(--radius-lg);
            padding: 40px 30px;
            margin: 32px 0;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: transform var(--transition-base);
        }
        
        .invite-code-box:hover {
            transform: translateY(-4px);
        }
        
        .invite-code-box::before {
            content: '';
            position: absolute;
            inset: -100%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.15), transparent);
            animation: shimmer 3s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .invite-label {
            position: relative;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.875rem;
            font-weight: 700;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        
        .invite-code {
            position: relative;
            color: white;
            font-size: 3.5rem;
            font-weight: 900;
            letter-spacing: 8px;
            font-family: 'Space Grotesk', monospace;
            user-select: all;
            text-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        }
        
        .copy-hint {
            position: relative;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.875rem;
            margin-top: 16px;
            font-weight: 600;
        }
        
        .copied-toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            padding: 18px 32px;
            border-radius: 50px;
            font-weight: 800;
            font-size: 1rem;
            box-shadow: 0 12px 32px rgba(72, 187, 120, 0.4);
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        
        .copied-toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        
        .info-box {
            background: var(--glass-light);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin: 24px 0;
            display: flex;
            align-items: start;
            gap: 16px;
        }
        
        .info-icon {
            font-size: 32px;
            flex-shrink: 0;
        }
        
        .info-text {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        /* Error Box */
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
        
        /* Form Styles */
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
        
        .password-strength {
            margin-top: 12px;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            transition: all var(--transition-base);
            width: 0%;
            border-radius: 3px;
        }
        
        .password-strength-bar.weak {
            width: 33%;
            background: linear-gradient(90deg, #f56565, #e53e3e);
        }
        
        .password-strength-bar.medium {
            width: 66%;
            background: linear-gradient(90deg, #ed8936, #dd6b20);
        }
        
        .password-strength-bar.strong {
            width: 100%;
            background: linear-gradient(90deg, #48bb78, #38a169);
        }
        
        .form-hint {
            display: block;
            margin-top: 8px;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 500;
        }
        
        .strength-text {
            margin-top: 8px;
            font-size: 0.8rem;
            font-weight: 600;
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
            box-shadow: var(--shadow-lg);
        }
        
        .link-btn-icon {
            font-size: 2rem;
            filter: drop-shadow(0 2px 8px rgba(255, 255, 255, 0.3));
        }
        
        /* Responsive */
        @media (max-width: 640px) {
            .auth-card {
                padding: 40px 30px;
            }
            
            .logo-title {
                font-size: 2.25rem;
            }
            
            .invite-code {
                font-size: 2.5rem;
                letter-spacing: 6px;
            }
            
            .link-section {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .auth-card {
                padding: 32px 24px;
            }
            
            .logo-icon {
                font-size: 64px;
            }
            
            .form-input {
                font-size: 16px; /* Prevent zoom on iOS */
            }
            
            .invite-code {
                font-size: 2rem;
                letter-spacing: 4px;
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
            <?php if ($success): ?>
                <!-- Success State -->
                <div class="success-box">
                    <div class="success-icon">🎉</div>
                    <h2 class="success-title">Family Created!</h2>
                    <p class="success-text">
                        Your family hub is ready! Share this invite code with your family members:
                    </p>
                    
                    <div class="invite-code-box" onclick="copyInviteCode()">
                        <div class="invite-label">Family Invite Code</div>
                        <div class="invite-code" id="inviteCode"><?php echo htmlspecialchars($inviteCode); ?></div>
                        <div class="copy-hint">👆 Tap to copy</div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-icon">💡</div>
                        <div class="info-text">
                            <strong>Important:</strong> Save this code! Family members will need it to join your family hub.
                            You can also find it later in Settings.
                        </div>
                    </div>
                    
                    <a href="/login.php" class="submit-btn" style="text-decoration: none; display: block; text-align: center;">
                        Continue to Sign In
                    </a>
                </div>
            <?php else: ?>
                <!-- Registration Form -->
                <div class="logo-section">
                    <div class="logo-icon">🏠</div>
                    <h1 class="logo-title">Create Family</h1>
                    <p class="logo-subtitle">Start your family hub today</p>
                </div>

                <?php if ($error): ?>
                    <div class="error-box">
                        <span style="font-size: 28px;">⚠️</span>
                        <span style="font-weight: 600; color: #ffcccb;"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" id="registerForm">
                    <div class="form-group">
                        <label for="family_name" class="form-label">Family Name</label>
                        <input 
                            type="text" 
                            id="family_name"
                            name="family_name"
                            class="form-input"
                            placeholder="e.g., Smith Family"
                            value="<?php echo htmlspecialchars($formData['family_name']); ?>"
                            required
                            autofocus>
                        <small class="form-hint">This will be your family hub's name</small>
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
                                minlength="12"
                                oninput="checkPasswordStrength()">
                            <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                👁️
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="strengthBar"></div>
                        </div>
                        <small class="form-hint strength-text" id="strengthText">Minimum 12 characters</small>
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
                                minlength="12"
                                oninput="checkPasswordMatch()">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                👁️
                            </button>
                        </div>
                        <small class="form-hint" id="matchText"></small>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        Create Family
                    </button>
                </form>

                <div class="divider">
                    <span class="divider-text">Already have an account?</span>
                </div>

                <div class="link-section">
                    <a href="/register-join.php" class="link-btn">
                        <span class="link-btn-icon">🎫</span>
                        <span>Join Family</span>
                    </a>
                    <a href="/login.php" class="link-btn">
                        <span class="link-btn-icon">🔑</span>
                        <span>Sign In</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="copied-toast" id="copiedToast">✅ Code copied to clipboard!</div>

    <script>
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
        
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const bar = document.getElementById('strengthBar');
            const text = document.getElementById('strengthText');
            
            let strength = 0;
            
            if (password.length >= 12) strength++;
            if (password.length >= 16) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            bar.className = 'password-strength-bar';
            
            if (strength <= 2) {
                bar.classList.add('weak');
                text.textContent = '❌ Weak password';
                text.style.color = '#fc8181';
            } else if (strength <= 3) {
                bar.classList.add('medium');
                text.textContent = '⚠️ Medium strength';
                text.style.color = '#f6ad55';
            } else {
                bar.classList.add('strong');
                text.textContent = '✅ Strong password';
                text.style.color = '#68d391';
            }
            
            checkPasswordMatch();
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const text = document.getElementById('matchText');
            
            if (confirm.length === 0) {
                text.textContent = '';
                return;
            }
            
            if (password === confirm) {
                text.textContent = '✅ Passwords match';
                text.style.color = '#68d391';
            } else {
                text.textContent = '❌ Passwords do not match';
                text.style.color = '#fc8181';
            }
        }
        
        function copyInviteCode() {
            const code = document.getElementById('inviteCode').textContent;
            const toast = document.getElementById('copiedToast');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(() => {
                    showToast();
                });
            } else {
                // Fallback
                const input = document.createElement('input');
                input.value = code;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showToast();
            }
        }
        
        function showToast() {
            const toast = document.getElementById('copiedToast');
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        document.getElementById('registerForm')?.addEventListener('submit', function(e) {
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