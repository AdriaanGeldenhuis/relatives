<?php
declare(strict_types=1);

/**
 * ============================================
 * CORE BOOTSTRAP - SAFE & OPTIMIZED
 * ============================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Session - SAFE initialization (don't start if already started)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '2592000'); // 30 days
    ini_set('session.cookie_lifetime', '2592000'); // 30 days
    session_name('RELATIVES_SESSION');
    session_start();
}

// ============================================
// CORS HANDLING - Consistent for native apps
// ============================================
$allowedOrigins = [
    'https://relatives.co.za',
    'https://www.relatives.co.za',
    'http://localhost',
    'http://localhost:3000',
    'http://localhost:8080',
    'capacitor://localhost',
    'ionic://localhost',
    'http://127.0.0.1',
    'https://127.0.0.1'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Check if origin is in whitelist
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

date_default_timezone_set('Africa/Johannesburg');

// Load environment variables
$envFile = __DIR__ . '/../.env';

if (!file_exists($envFile)) {
    die('CRITICAL ERROR: .env file not found');
}

// Parse .env file
$envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($envLines as $line) {
    $line = trim($line);
    
    if (empty($line) || $line[0] === '#') {
        continue;
    }
    
    if (strpos($line, '=') !== false) {
        list($key, $value) = array_map('trim', explode('=', $line, 2));
        $value = trim($value, '"\'');
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

// Verify required environment variables
$requiredEnvVars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_URL'];

foreach ($requiredEnvVars as $var) {
    if (!isset($_ENV[$var]) || empty($_ENV[$var])) {
        die("CRITICAL ERROR: Missing required environment variable: $var");
    }
}

// Load core classes
$coreClasses = [
    'DB.php',
    'Response.php',
    'Session.php',
    'Auth.php',
    'CSRF.php',
    'Validator.php',
    'EmailService.php',
    'Cache.php',
    'SubscriptionManager.php',
    'GooglePlayVerifier.php',
    'AppleAppStoreVerifier.php'
];

foreach ($coreClasses as $class) {
    $classPath = __DIR__ . '/' . $class;
    
    if (!file_exists($classPath)) {
        if (in_array($class, ['EmailService.php', 'Cache.php', 'AppleAppStoreVerifier.php'])) {
            continue; // Optional classes
        }
        die("CRITICAL ERROR: Core class not found: $class");
    }
    
    require_once $classPath;
}

// Initialize database
try {
    $db = DB::getInstance();
    
    // Initialize cache if available
    if (class_exists('Cache')) {
        $cache = Cache::init($db);
    } else {
        $cache = new class {
            public function get($key) { return null; }
            public function set($key, $value, $ttl = 60) { return false; }
            public function clearExpired() { return false; }
        };
    }
    
} catch (Exception $e) {
    error_log('Database Connection Error: ' . $e->getMessage());
    
    http_response_code(503);
    die('
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Service Unavailable</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0;
                    padding: 20px;
                }
                .error-box {
                    background: white;
                    padding: 40px;
                    border-radius: 20px;
                    text-align: center;
                    max-width: 500px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                }
                .error-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                }
                h1 {
                    color: #333;
                    margin-bottom: 15px;
                    font-size: 24px;
                }
                p {
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 20px;
                }
                .retry-btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: linear-gradient(135deg, #667eea, #764ba2);
                    color: white;
                    text-decoration: none;
                    border-radius: 10px;
                    font-weight: 700;
                    border: none;
                    cursor: pointer;
                    font-size: 16px;
                }
                .retry-btn:hover {
                    opacity: 0.9;
                }
            </style>
        </head>
        <body>
            <div class="error-box">
                <div class="error-icon">🔧</div>
                <h1>Service Temporarily Unavailable</h1>
                <p>We\'re having trouble connecting to the database. Please try again in a moment.</p>
                <button class="retry-btn" onclick="location.reload()">Try Again</button>
            </div>
        </body>
        </html>
    ');
}

define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost');
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('IS_PRODUCTION', APP_ENV === 'production');

// Helper functions
if (!function_exists('dd')) {
    function dd(...$vars) {
        if (!IS_PRODUCTION) {
            echo '<pre style="background: #000; color: #0f0; padding: 20px; margin: 20px; border-radius: 5px; overflow: auto;">';
            foreach ($vars as $var) {
                var_dump($var);
            }
            echo '</pre>';
            die();
        }
    }
}

if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Load Firebase if exists
$firebasePath = __DIR__ . '/FirebaseMessaging.php';
if (file_exists($firebasePath)) {
    require_once $firebasePath;
    $firebase = new FirebaseMessaging();
}

return true;