<?php
declare(strict_types=1);

/**
 * ============================================
 * LOGIN PAGE - COMPLETE FILE
 * ============================================
 */

// Start output buffering
ob_start();

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect immediately
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: /home/', true, 302);
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
            // Clear any existing output
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Get session token
            $sessionToken = $result['session_token'] ?? '';
            $userId = $_SESSION['user_id'] ?? '';
            
            // Output login success page with IMPROVED redirect
            ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <!-- META REFRESH FALLBACK - Redirects after 2 seconds if JavaScript fails -->
    <meta http-equiv="refresh" content="2;url=/home/">
    
    <title>Logging in - Relatives</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100vh;
            overflow: hidden;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .loading-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 50px 60px;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 420px;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .spinner {
            width: 64px;
            height: 64px;
            border: 5px solid rgba(255, 255, 255, 0.2);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 30px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading-icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: bounce 1s ease infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        h1 {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        p {
            font-size: 16px;
            opacity: 0.9;
            line-height: 1.6;
            margin-bottom: 8px;
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
            margin-top: 30px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: white;
            width: 0%;
            animation: progress 1.5s ease-out forwards;
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
        }
        
        @keyframes progress {
            to { width: 100%; }
        }
        
        .status {
            font-size: 14px;
            opacity: 0.7;
            margin-top: 16px;
        }
        
        .manual-link {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            color: white;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .manual-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="loading-container">
        <div class="loading-icon">🏠</div>
        <div class="spinner"></div>
        <h1>Logging you in...</h1>
        <p>Setting up your session</p>
        <p class="status" id="statusText">Please wait</p>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        
        <!-- Manual link if redirect fails -->
        <a href="/home/" class="manual-link" id="manualLink" style="display: none;">
            Click here if not redirected
        </a>
    </div>
    
    <script>
        (function() {
            'use strict';
            
            console.log('✅ Login success - initializing redirect...');
            
            var redirected = false;
            var userId = '<?php echo addslashes($userId); ?>';
            var sessionToken = '<?php echo addslashes($sessionToken); ?>';
            
            // Prevent back button
            if (window.history && window.history.pushState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Android WebView integration
            if (window.Android && typeof Android.setAuthData === 'function') {
                try {
                    console.log('📱 Setting Android auth data...');
                    Android.setAuthData(userId, sessionToken);
                    
                    // Get FCM token
                    setTimeout(function() {
                        if (typeof Android.getFCMToken === 'function') {
                            console.log('🔔 Getting FCM token...');
                            Android.getFCMToken();
                        }
                    }, 100);
                } catch (e) {
                    console.error('⚠️ Android integration error:', e);
                }
            }
            
            // Function to perform redirect
            function doRedirect() {
                if (redirected) return;
                redirected = true;
                
                console.log('🚀 Redirecting to /home/...');
                document.getElementById('statusText').textContent = 'Redirecting...';
                
                // Try multiple redirect methods
                try {
                    window.location.replace('/home/');
                } catch (e) {
                    console.error('Replace failed:', e);
                    try {
                        window.location.href = '/home/';
                    } catch (e2) {
                        console.error('Href failed:', e2);
                        // Show manual link as last resort
                        document.getElementById('manualLink').style.display = 'inline-block';
                    }
                }
            }
            
            // Primary redirect after short delay (500ms for faster redirect)
            var redirectTimer = setTimeout(doRedirect, 500);
            
            // Backup redirect if primary fails (1500ms)
            var backupTimer = setTimeout(function() {
                if (!redirected) {
                    console.warn('⚠️ Primary redirect failed, using backup...');
                    doRedirect();
                }
            }, 1500);
            
            // Show manual link after 3 seconds if still not redirected
            setTimeout(function() {
                if (!redirected && window.location.pathname !== '/home/') {
                    console.error('❌ All automatic redirects failed');
                    document.getElementById('statusText').textContent = 'Redirect taking longer than expected...';
                    document.getElementById('manualLink').style.display = 'inline-block';
                }
            }, 3000);
            
            // Handle page visibility change
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden && !redirected) {
                    console.log('👁️ Page became visible, redirecting...');
                    doRedirect();
                }
            });
            
            // Handle page load complete
            if (document.readyState === 'complete') {
                console.log('📄 Page already loaded');
            } else {
                window.addEventListener('load', function() {
                    console.log('📄 Page load complete');
                });
            }
            
        })();
    </script>
    
    <!-- Noscript fallback -->
    <noscript>
        <meta http-equiv="refresh" content="0;url=/home/">
        <div style="text-align: center; padding: 20px;">
            <p>JavaScript is disabled. <a href="/home/" style="color: white; text-decoration: underline;">Click here to continue</a></p>
        </div>
    </noscript>
</body>
</html>
<?php
            exit;
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log('Login error: ' . $e->getMessage());
    }
}

// If we get here, show the login form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0c29">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Sign In - Relatives</title>
    
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
            height: 100vh;
            overflow: hidden;
            position: fixed;
            width: 100%;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            color: white;
            background: #0f0c29;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
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
        
        .bg-animation::before,
        .bg-animation::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.3;
            animation: floatOrb 15s ease-in-out infinite;
        }
        
        .bg-animation::before {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, #667eea, transparent);
            top: -250px;
            left: -250px;
            animation-delay: -7s;
        }
        
        .bg-animation::after {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, #f093fb, transparent);
            bottom: -200px;
            right: -200px;
            animation-delay: -3s;
        }
        
        @keyframes floatOrb {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(100px, -100px) scale(1.1); }
            66% { transform: translate(-100px, 100px) scale(0.9); }
        }
        
        .container {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .auth-card {
            background: var(--glass-medium);
            backdrop-filter: blur(40px) saturate(180%);
            -webkit-backdrop-filter: blur(40px) saturate(180%);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 40px 50px;
            max-width: 480px;
            width: 90%;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            margin: auto;
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
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }
        
        .logo-icon {
            font-size: 50px;
            margin-bottom: 12px;
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
            font-size: 2rem;
            font-weight: 900;
            background: linear-gradient(135deg, #fff 0%, #f0f0f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -2px;
        }
        
        .logo-subtitle {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }
        
        .error-box {
            background: linear-gradient(135deg, rgba(255, 71, 87, 0.2), rgba(232, 65, 24, 0.2));
            border: 2px solid rgba(255, 71, 87, 0.5);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.5s;
            position: relative;
            z-index: 1;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }
        
        .error-icon {
            font-size: 22px;
            filter: drop-shadow(0 2px 8px rgba(255, 71, 87, 0.4));
        }
        
        .error-text {
            font-weight: 600;
            color: #ffcccb;
            font-size: 0.85rem;
        }
        
        .auth-form {
            position: relative;
            z-index: 1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .form-input {
            width: 100%;
            padding: 14px 18px;
            background: var(--glass-light);
            border: 2px solid var(--glass-border);
            border-radius: var(--radius-lg);
            color: white;
            font-size: 0.95rem;
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
        
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            padding: 6px;
            transition: all var(--transition-base);
            filter: grayscale(1);
        }
        
        .password-toggle:hover {
            transform: translateY(-50%) scale(1.15);
            filter: grayscale(0);
        }
        
        .form-hint {
            display: block;
            margin-top: 6px;
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 500;
        }
        
        .forgot-password {
            text-align: right;
            margin: -10px 0 20px 0;
        }
        
        .forgot-link {
            color: #667eea;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            transition: all var(--transition-base);
        }
        
        .forgot-link:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            border: none;
            border-radius: var(--radius-lg);
            color: white;
            font-size: 1rem;
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
        
        .submit-btn:active:not(:disabled) {
            transform: translateY(-1px);
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
            margin: 24px 0;
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
            padding: 0 16px;
            background: var(--glass-medium);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .link-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            z-index: 1;
        }
        
        .link-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 20px;
            background: var(--glass-light);
            border: 2px solid var(--glass-border);
            border-radius: var(--radius-lg);
            color: white;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all var(--transition-base);
        }
        
        .link-btn:hover {
            background: var(--glass-medium);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .link-btn-icon {
            font-size: 1.2rem;
            filter: drop-shadow(0 2px 8px rgba(255, 255, 255, 0.3));
        }
        
        @media (max-height: 800px) {
            .auth-card {
                padding: 30px 40px;
            }
            
            .logo-section {
                margin-bottom: 20px;
            }
            
            .logo-icon {
                font-size: 40px;
                margin-bottom: 8px;
            }
            
            .logo-title {
                font-size: 1.75rem;
            }
            
            .logo-subtitle {
                font-size: 0.85rem;
            }
            
            .form-group {
                margin-bottom: 16px;
            }
            
            .divider {
                margin: 20px 0;
            }
        }
        
        @media (max-height: 700px) {
            .auth-card {
                padding: 24px 36px;
            }
            
            .logo-icon {
                font-size: 36px;
            }
            
            .logo-title {
                font-size: 1.5rem;
            }
            
            .form-group {
                margin-bottom: 14px;
            }
            
            .form-input {
                padding: 12px 16px;
            }
            
            .submit-btn {
                padding: 14px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 640px) {
            .auth-card {
                padding: 32px 28px;
                width: 95%;
            }
        }
        
        @media (max-width: 480px) {
            .auth-card {
                padding: 28px 24px;
            }
            
            .form-input {
                padding: 12px 16px;
                font-size: 16px;
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
                <div class="logo-icon">🏠</div>
                <h1 class="logo-title">Relatives</h1>
                <p class="logo-subtitle">Welcome back to your family hub</p>
            </div>

            <?php if ($error): ?>
                <div class="error-box">
                    <span class="error-icon">⚠️</span>
                    <span class="error-text"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form" id="loginForm">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-wrapper">
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
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                            minlength="12">
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            👁️
                        </button>
                    </div>
                    <small class="form-hint">Minimum 12 characters</small>
                </div>

                <div class="forgot-password">
                    <a href="/forgot-password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    Sign In
                </button>
            </form>

            <div class="divider">
                <span class="divider-text">New to Relatives?</span>
            </div>

            <div class="link-section">
                <a href="/register-create.php" class="link-btn">
                    <span class="link-btn-icon">🏠</span>
                    <span>Create New Family</span>
                </a>
                <a href="/register-join.php" class="link-btn">
                    <span class="link-btn-icon">🎫</span>
                    <span>Join Existing Family</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        let passwordVisible = false;
        
        function togglePassword() {
            const input = document.getElementById('password');
            const btn = document.querySelector('.password-toggle');
            
            passwordVisible = !passwordVisible;
            input.type = passwordVisible ? 'text' : 'password';
            btn.textContent = passwordVisible ? '🙈' : '👁️';
        }
        
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('loading');
        });
        
        <?php if ($error && $email): ?>
            document.getElementById('password').focus();
        <?php endif; ?>
    </script>
</body>
</html>