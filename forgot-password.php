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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0c29">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Reset Password - Relatives</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* Same base styles as login.php */
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
            max-width: 520px;
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
            animation: logoFloat 3s ease-in-out infinite;
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .logo-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.5rem;
            font-weight: 900;
            color: white;
            margin-bottom: 12px;
            letter-spacing: -1px;
        }
        
        .logo-subtitle {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
            line-height: 1.6;
        }
        
        .success-box {
            background: linear-gradient(135deg, rgba(72, 187, 120, 0.2), rgba(56, 161, 105, 0.2));
            border: 2px solid rgba(72, 187, 120, 0.5);
            border-radius: var(--radius-lg);
            padding: 30px;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: scaleIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        
        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }
        
        .success-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: #68d391;
            margin-bottom: 16px;
        }
        
        .success-text {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 24px;
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
        
        .form-group {
            margin-bottom: 28px;
            position: relative;
            z-index: 1;
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
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all var(--transition-base);
        }
        
        .back-link:hover {
            gap: 12px;
            color: #764ba2;
        }
        
        @media (max-width: 640px) {
            .auth-card {
                padding: 40px 30px;
            }
        }
        
        @media (max-width: 480px) {
            .auth-card {
                padding: 32px 24px;
            }
            
            .form-input {
                font-size: 16px; /* Prevent zoom on iOS */
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
                <div class="success-box">
                    <div class="success-icon">📧</div>
                    <h2 class="success-title">Check Your Email</h2>
                    <p class="success-text">
                        If an account exists for <strong><?php echo htmlspecialchars($email); ?></strong>, 
                        you will receive password reset instructions shortly.
                    </p>
                    <p class="success-text" style="font-size: 0.9rem; color: rgba(255, 255, 255, 0.6);">
                        💡 Check your spam folder if you don't see it within 5 minutes.
                    </p>
                    <a href="/login.php" class="back-link">
                        <span>←</span>
                        <span>Back to Sign In</span>
                    </a>
                </div>
            <?php else: ?>
                <div class="logo-section">
                    <div class="logo-icon">🔐</div>
                    <h1 class="logo-title">Reset Password</h1>
                    <p class="logo-subtitle">
                        Enter your email address and we'll send you instructions to reset your password.
                    </p>
                </div>

                <?php if ($error): ?>
                    <div class="error-box">
                        <span style="font-size: 28px;">⚠️</span>
                        <span style="font-weight: 600; color: #ffcccb;"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="resetForm">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input 
                            type="email" 
                            id="email"
                            name="email"
                            class="form-input"
                            placeholder="you@example.com"
                            value="<?php echo htmlspecialchars($email); ?>"
                            required
                            autocomplete="email"
                            autofocus>
                        <small class="form-hint">We'll send reset instructions to this email</small>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        Send Reset Link
                    </button>
                </form>

                <a href="/login.php" class="back-link">
                    <span>←</span>
                    <span>Back to Sign In</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('resetForm')?.addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('loading');
        });
    </script>
</body>
</html>