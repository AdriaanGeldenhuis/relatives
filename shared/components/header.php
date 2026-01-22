<?php
/**
 * ============================================
 * GLOBAL HEADER v9.1
 * Added notification bell with live badge
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

$appVersion = '10.0.1';
// Use static cache version - bump this when deploying CSS/JS changes
// DO NOT use time() as it defeats browser caching!
$cacheVersion = '10.0.1';
$buildTime = $cacheVersion;

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#667eea">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Relatives">
    
    <title><?php echo $pageTitle ?? 'Relatives'; ?> - Family Hub</title>
    
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/icon-192.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800;900&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Help System -->
    <link rel="stylesheet" href="/help/css/help.css?v=<?php echo $buildTime; ?>">
    
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: white;
            overflow-x: hidden;
            padding-bottom: var(--footer-height) !important;
            -webkit-font-smoothing: antialiased;
        }
        
        .app-loader {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, #667eea, #764ba2);
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
        
        .mobile-menu-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            z-index: 1998;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .mobile-menu-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .mobile-sidebar {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            max-width: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            backdrop-filter: blur(40px);
            z-index: 1999;
            opacity: 0;
            visibility: hidden;
            transform: scale(0.95);
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .mobile-sidebar.active {
            opacity: 1;
            visibility: visible;
            transform: scale(1);
        }

        .mobile-sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            font-size: 36px;
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-5px) rotate(5deg); }
        }

        .logo-text {
            font-size: 24px;
            font-weight: 900;
            color: white;
            font-family: 'Space Grotesk', sans-serif;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .close-sidebar {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: white;
            font-size: 24px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .close-sidebar:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }
        
        .mobile-nav {
            flex: 1;
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            align-content: start;
        }

        .mobile-nav-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 20px 12px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            position: relative;
            text-align: center;
            min-height: 100px;
        }

        .mobile-nav-link:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            background: rgba(255, 255, 255, 0.15);
        }

        .mobile-nav-link:active {
            transform: translateY(-2px) scale(0.98);
        }

        .mobile-nav-link.active {
            background: rgba(255, 255, 255, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: white;
            font-weight: 700;
            box-shadow: 0 4px 20px rgba(255, 255, 255, 0.15), inset 0 0 20px rgba(255, 255, 255, 0.1);
        }

        .mobile-nav-link.active .nav-icon {
            transform: scale(1.2);
            filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.6));
        }

        .nav-icon {
            font-size: 32px;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
            transition: transform 0.3s ease, filter 0.3s ease;
        }

        .nav-text {
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            line-height: 1.2;
        }
        
        .mobile-sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }

        .user-profile {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 12px;
            border-radius: 12px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 18px;
            flex-shrink: 0;
            border: 3px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-weight: 700;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 16px;
        }

        .user-email {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            background: rgba(255, 71, 87, 0.2);
            border: 1px solid rgba(255, 71, 87, 0.4);
            border-radius: 12px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: rgba(255, 71, 87, 0.35);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(255, 71, 87, 0.3);
        }
        
        .global-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--glass-medium);
            backdrop-filter: blur(40px) saturate(180%);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: var(--shadow-md);
            animation: slideDown 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-left, .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .hamburger-menu {
            display: flex;
            flex-direction: column;
            gap: 5px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            transition: all 0.3s;
        }
        
        .hamburger-menu:hover {
            transform: scale(1.1);
        }
        
        .hamburger-menu span {
            width: 24px;
            height: 3px;
            background: white;
            border-radius: 2px;
            transition: all 0.3s;
        }
        
        .hamburger-menu:hover span {
            background: rgba(255, 255, 255, 0.8);
        }
        
        .header-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-size: 20px;
            cursor: pointer;
            text-decoration: none;
            position: relative;
            transition: all var(--transition-bounce);
        }
        
        .header-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-md);
        }
        
        .header-btn:active {
            transform: translateY(0) scale(1);
        }
        
        /* Notification Bell Styles */
        .notification-bell {
            position: relative;
        }
        
        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: linear-gradient(135deg, #ff4757, #e84118);
            color: white;
            font-size: 11px;
            font-weight: 900;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border: 2px solid rgba(102, 126, 234, 1);
            box-shadow: 0 4px 12px rgba(255, 71, 87, 0.5);
            animation: badgePulse 2s ease-in-out infinite;
        }
        
        @keyframes badgePulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 4px 12px rgba(255, 71, 87, 0.5);
            }
            50% {
                transform: scale(1.1);
                box-shadow: 0 4px 16px rgba(255, 71, 87, 0.8);
            }
        }
        
        .notification-bell .header-btn {
            animation: none;
        }
        
        .notification-bell.has-notifications .header-btn {
            animation: bellRing 3s ease-in-out infinite;
        }
        
        @keyframes bellRing {
            0%, 90%, 100% {
                transform: rotate(0deg);
            }
            92% {
                transform: rotate(-15deg);
            }
            94% {
                transform: rotate(15deg);
            }
            96% {
                transform: rotate(-10deg);
            }
            98% {
                transform: rotate(10deg);
            }
        }
        
        @media (max-width: 480px) {
            .mobile-sidebar {
                width: 100%;
            }

            .mobile-nav {
                padding: 15px;
                gap: 10px;
            }

            .mobile-nav-link {
                padding: 16px 10px;
                min-height: 90px;
            }

            .nav-icon {
                font-size: 28px;
            }

            .nav-text {
                font-size: 0.75rem;
            }

            .header-container {
                padding: 10px 15px;
            }

            .header-btn {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }

            .notification-badge {
                font-size: 10px;
                min-width: 18px;
                height: 18px;
            }
        }

        @media (min-width: 600px) {
            .mobile-nav {
                max-width: 500px;
                margin: 0 auto;
            }
        }

        @media (max-height: 600px) {
            .mobile-nav-link {
                padding: 12px 10px;
                min-height: 70px;
            }

            .nav-icon {
                font-size: 24px;
            }
        }
    </style>
    
    <?php if (isset($pageCSS)): ?>
        <?php foreach ((array)$pageCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>?v=<?php echo $buildTime; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body <?php echo isset($user) ? 'data-logged-in="true"' : ''; ?>>
    
    <!-- App Loader -->
    <div id="appLoader" class="app-loader">
        <div class="loader-content">
            <div class="loader-icon">🏠</div>
        </div>
    </div>

    <!-- CRITICAL: Fallback loader hide - ensures loader ALWAYS disappears even if JS fails -->
    <script>
        (function() {
            // Fallback: hide loader after 3 seconds maximum, regardless of JS init
            var fallbackTimer = setTimeout(function() {
                var loader = document.getElementById('appLoader');
                if (loader && !loader.classList.contains('hidden')) {
                    console.warn('⚠️ Loader fallback triggered - JS init may have failed');
                    loader.classList.add('hidden');
                }
            }, 3000);

            // Allow main JS to cancel fallback if it runs successfully
            window._loaderFallbackTimer = fallbackTimer;
        })();
    </script>

    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

    <!-- Mobile Sidebar -->
    <aside class="mobile-sidebar" id="mobileSidebar">
        <div class="mobile-sidebar-header">
            <div class="logo">
                <div class="logo-icon">🏠</div>
                <div class="logo-text">Relatives</div>
            </div>
            <button class="close-sidebar" id="closeSidebarBtn" aria-label="Close menu">✕</button>
        </div>

        <nav class="mobile-nav" role="navigation">
            <a href="/home/" class="mobile-nav-link <?php echo $activePage === 'home' ? 'active' : ''; ?>">
                <span class="nav-icon">🏠</span>
                <span class="nav-text">Home</span>
            </a>
                        
            <a href="/messages/" class="mobile-nav-link <?php echo $activePage === 'messages' ? 'active' : ''; ?>">
                <span class="nav-icon">💬</span>
                <span class="nav-text">Messages</span>
            </a>
            
            <a href="/shopping/" class="mobile-nav-link <?php echo $activePage === 'shopping' ? 'active' : ''; ?>">
                <span class="nav-icon">🛒</span>
                <span class="nav-text">Shopping</span>
            </a>
            
            <a href="/notes/" class="mobile-nav-link <?php echo $activePage === 'notes' ? 'active' : ''; ?>">
                <span class="nav-icon">📝</span>
                <span class="nav-text">Notes</span>
            </a>
            
            <a href="/calendar/" class="mobile-nav-link <?php echo $activePage === 'calendar' ? 'active' : ''; ?>">
                <span class="nav-icon">📅</span>
                <span class="nav-text">Calendar</span>
            </a>
            
            <a href="/schedule/" class="mobile-nav-link <?php echo $activePage === 'schedule' ? 'active' : ''; ?>">
                <span class="nav-icon">⏰</span>
                <span class="nav-text">Schedule</span>
            </a>
            
            <a href="/tracking/" class="mobile-nav-link <?php echo $activePage === 'tracking' ? 'active' : ''; ?>">
                <span class="nav-icon">📍</span>
                <span class="nav-text">Tracking</span>
            </a>

            <a href="/weather/" class="mobile-nav-link <?php echo $activePage === 'weather' ? 'active' : ''; ?>">
                <span class="nav-icon">🌤️</span>
                <span class="nav-text">Weather</span>
            </a>

            <a href="/holiday_traveling/" class="mobile-nav-link <?php echo $activePage === 'holiday_traveling' ? 'active' : ''; ?>">
                <span class="nav-icon">✈️</span>
                <span class="nav-text">Holiday & Traveling</span>
            </a>

            <a href="/games/" class="mobile-nav-link <?php echo $activePage === 'games' ? 'active' : ''; ?>">
                <span class="nav-icon">🎮</span>
                <span class="nav-text">Games</span>
            </a>

            <?php if (isset($user) && in_array($user['role'], ['owner', 'admin'])): ?>
            <a href="/admin/" class="mobile-nav-link <?php echo $activePage === 'admin' ? 'active' : ''; ?>">
                <span class="nav-icon">⚙️</span>
                <span class="nav-text">Admin</span>
            </a>

            <a href="/legal/" class="mobile-nav-link <?php echo $activePage === 'legal' ? 'active' : ''; ?>">
                <span class="nav-icon">⚖️</span>
                <span class="nav-text">Legal</span>
            </a>

            <?php endif; ?>
        </nav>

        <div class="mobile-sidebar-footer">
            <?php if (isset($user)): ?>
            <div class="user-profile">
                <div class="user-avatar" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                    <?php echo strtoupper(substr($user['name'] ?? $user['full_name'] ?? '?', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User'); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <a href="/logout.php" class="logout-btn">
                <span>🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- Global Header -->
    <header class="global-header" role="banner">
        <div class="header-container">
            <div class="header-left">
                <button class="hamburger-menu" id="hamburgerMenuBtn" aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>

            <div class="header-right">
                <button class="header-btn" onclick="history.back()" title="Go back">←</button>
                
                <!-- Notification Bell -->
                <div class="notification-bell <?php echo $unreadNotifCount > 0 ? 'has-notifications' : ''; ?>">
                    <a href="/notifications/" class="header-btn" id="notificationBell" title="Notifications">
                        🔔
                    </a>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="notification-badge" id="notificationBadge">
                            <?php echo $unreadNotifCount > 99 ? '99+' : $unreadNotifCount; ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <a href="/home/" class="header-btn" title="Home">🏠</a>
            </div>
        </div>
    </header>

    <script src="/shared/js/app.js?v=<?php echo $buildTime; ?>"></script>
    <script src="/shared/js/header.js?v=<?php echo $buildTime; ?>"></script>
    <!-- Help System -->
    <script src="/help/js/help.js?v=<?php echo $buildTime; ?>"></script>
   
    <script>
        console.log('%c🏠 Relatives v<?php echo $appVersion; ?>', 'font-size: 16px; font-weight: bold; color: #667eea;');
        
        // Initialize notification count
        window.unreadNotificationCount = <?php echo $unreadNotifCount; ?>;
    </script>