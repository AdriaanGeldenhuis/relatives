<?php
/**
 * ============================================
 * SHARED APPLICATION HEADER
 * ============================================
 */

// Determine active page from URL path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath = parse_url($requestUri, PHP_URL_PATH);
$pathParts = array_filter(explode('/', trim($urlPath, '/')));
$activePage = !empty($pathParts) ? $pathParts[0] : 'home';

// Normalize: root path = home
if (empty($activePage) || $activePage === 'index.php') {
    $activePage = 'home';
}

$appVersion = '12.4.0';
// Use static cache version - bump this when deploying CSS/JS changes
// DO NOT use time() as it defeats browser caching!
$cacheVersion = '12.12.0';
$workspacePage = !isset($homeStylesheet) && !isset($messagesStylesheets);
$buildTime = $cacheVersion;
$familyPage = in_array($activePage, ['home', 'notes', 'shopping', 'messages', 'weather', 'holiday_traveling', 'games', 'admin', 'profile'], true);
$familyAppPage = in_array($activePage, ['home', 'tracking', 'weather', 'notes', 'shopping', 'calendar', 'schedule', 'holiday_traveling', 'games', 'messages'], true);
if ($familyAppPage) {
    require_once __DIR__ . '/family-app-art.php';
}

// Avatar cache-busting: use file modification time so browsers fetch the new image after upload
function avatarUrl($userId) {
    $file = __DIR__ . '/../../saves/' . (int)$userId . '/avatar/avatar.webp';
    $v = file_exists($file) ? filemtime($file) : 0;
    return '/saves/' . (int)$userId . '/avatar/avatar.webp?v=' . $v;
}

// Get unread notification count
$unreadNotifCount = 0;
if (isset($db) && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $unreadNotifCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('Header notification count error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f3f2e9">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Relatives">
    
    <title><?php echo $pageTitle ?? 'Relatives'; ?> - Family Hub</title>
    
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/icon-192.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800;900&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    
    <style>
        :root {
            --glass-light: rgba(255, 255, 255, 0.08);
            --glass-medium: rgba(255, 255, 255, 0.12);
            --glass-heavy: rgba(255, 255, 255, 0.18);
            --glass-border: rgba(255, 255, 255, 0.25);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.18);
            --shadow-xl: 0 24px 64px rgba(0, 0, 0, 0.25);
            --space-xs: 8px;
            --space-sm: 12px;
            --space-md: 16px;
            --space-lg: 24px;
            --space-xl: 32px;
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-full: 9999px;
            --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-bounce: 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            --footer-height: 120px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background:
                radial-gradient(120% 90% at 50% -20%, #141838 0%, transparent 60%),
                #06070f;
            min-height: 100vh;
            color: white;
            overflow-x: hidden;
            padding-bottom: var(--footer-height) !important;
            -webkit-font-smoothing: antialiased;
        }

        .app-loader {
            position: fixed;
            inset: 0;
            background:
                radial-gradient(120% 90% at 50% -20%, #141838 0%, transparent 60%),
                #06070f;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .app-loader.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        
        .loader-content {
            text-align: center;
        }
        
        .loader-icon {
            font-size: 64px;
            animation: loaderBounce 1s infinite;
        }
        
        @keyframes loaderBounce {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-20px) scale(1.1); }
        }
        
    </style>
    
    <?php if (isset($pageCSS)): ?>
        <?php foreach ((array)$pageCSS as $css): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($css) ?><?= str_contains($css, '?') ? '&amp;' : '?' ?>v=<?= $buildTime ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($homeStylesheet)): ?>
        <style><?php readfile($homeStylesheet); ?></style>
    <?php endif; ?>
    <?php foreach ($messagesStylesheets ?? [] as $stylesheet): ?>
        <style><?php readfile($stylesheet); ?></style>
    <?php endforeach; ?>
    <?php if ($workspacePage): ?>
        <style><?php readfile(__DIR__ . '/../css/workspace.css'); ?></style>
    <?php endif; ?>
    <?php foreach ($pageInlineStylesheets ?? [] as $stylesheet): ?>
        <style><?php readfile($stylesheet); ?></style>
    <?php endforeach; ?>
    <?php if ($familyPage || $familyAppPage): ?>
        <style><?php readfile(__DIR__ . '/../css/family-surfaces.css'); ?></style>
    <?php endif; ?>
    <?php if ($familyAppPage): ?>
        <style><?php readfile(__DIR__ . '/../css/family-app-art.css'); readfile(__DIR__ . '/../css/family-app-themes.css'); ?></style>
    <?php endif; ?>
    <style><?php readfile(__DIR__ . '/../css/site-navigation.css'); readfile(__DIR__ . '/../css/action-controls.css'); ?></style>
    <style><?php readfile(__DIR__ . '/../css/footer-assistance.css'); ?></style>
</head>
<body class="<?= $workspacePage ? 'workspace-page' : (isset($homeStylesheet) ? 'home-page' : 'messages-page') ?><?= $familyPage ? ' family-page' : '' ?>" data-area="<?= htmlspecialchars($activePage) ?>" <?php echo isset($user) ? 'data-logged-in="true"' : ''; ?>>
    
    <!-- App Loader -->
    <div id="appLoader" class="app-loader">
        <div class="loader-content">
            <div class="loader-icon">🏠</div>
        </div>
    </div>

    <!-- CRITICAL: Fallback loader hide - ensures loader ALWAYS disappears even if JS fails -->
    <script>
        (function() {
            // Fallback: hide loader after 1.5 seconds maximum, regardless of JS init
            var fallbackTimer = setTimeout(function() {
                var loader = document.getElementById('appLoader');
                if (loader && !loader.classList.contains('hidden')) {
                    console.warn('⚠️ Loader fallback triggered - JS init may have failed');
                    loader.classList.add('hidden');
                }
            }, 1500);

            // Allow main JS to cancel fallback if it runs successfully
            window._loaderFallbackTimer = fallbackTimer;
        })();
    </script>

    <?php require __DIR__ . '/site-navigation.php'; ?>

    <?php if (in_array($activePage, ['messages', 'holiday_traveling'], true)): ?>
        <script><?php readfile(__DIR__ . '/../js/action-popup.js'); ?></script>
    <?php endif; ?>
    <script src="/shared/js/app.js?v=<?php echo $buildTime; ?>"></script>
    <!-- Help System -->
    <script src="/help/js/help.js?v=<?= filemtime(__DIR__ . '/../../help/js/help.js') ?>"></script>
   
    <script>
        console.log('%c🏠 Relatives v<?php echo $appVersion; ?>', 'font-size: 16px; font-weight: bold; color: #667eea;');
        
        // Initialize notification count
        window.unreadNotificationCount = <?php echo $unreadNotifCount; ?>;

        // Avatar cache-busting version (file modification time)
        window.avatarVersion = <?php echo time(); ?>;
    </script>
