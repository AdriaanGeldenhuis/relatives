<?php
/**
 * Tracking Dashboard
 *
 * Fullscreen Mapbox map with family member locations.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check auth
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

// Get user
$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check subscription
require_once __DIR__ . '/../../core/SubscriptionManager.php';
$subscriptionManager = new SubscriptionManager($db);

if ($subscriptionManager->isFamilyLocked($user['family_id'])) {
    header('Location: /subscription/locked.php');
    exit;
}

// Get Mapbox token
$mapboxToken = $_ENV['MAPBOX_API_KEY'] ?? '';

// Get family members for initial render
$stmt = $db->prepare("
    SELECT id, full_name as name, avatar_color, has_avatar
    FROM users
    WHERE family_id = ? AND status = 'active' AND location_sharing = 1
    ORDER BY full_name
");
$stmt->execute([$user['family_id']]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Family Tracking';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle) ?> - Relatives</title>

    <!-- Mapbox GL JS -->
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js"></script>

    <!-- Tracking CSS -->
    <link rel="stylesheet" href="assets/css/tracking.css">

    <!-- PWA -->
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
</head>
<body class="tracking-page">
    <!-- Map Container -->
    <div id="map"></div>

    <!-- Top Bar -->
    <div class="tracking-topbar">
        <a href="/home/" class="back-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </a>
        <div class="topbar-title">Family Tracking</div>
        <div class="topbar-actions">
            <button id="btn-settings" class="icon-btn" title="Settings">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Session Indicator -->
    <div id="session-indicator" class="session-indicator hidden">
        <div class="session-dot"></div>
        <span class="session-text">Live session active</span>
    </div>

    <!-- Family Panel (collapsible) -->
    <div id="family-panel" class="family-panel">
        <div class="family-panel-header" id="family-panel-toggle">
            <span>Family</span>
            <svg class="panel-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 9l6 6 6-6"/>
            </svg>
        </div>
        <div class="family-panel-content">
            <div id="family-list" class="family-list">
                <!-- Members populated by JS -->
            </div>
        </div>
    </div>

    <!-- Controls -->
    <div class="map-controls">
        <button id="btn-center-all" class="control-btn" title="Fit all members">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
            </svg>
        </button>
        <button id="btn-my-location" class="control-btn" title="My location">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"/>
                <path d="M12 2v4M12 18v4M2 12h4M18 12h4"/>
            </svg>
        </button>
        <button id="btn-geofences" class="control-btn" title="Geofences">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <circle cx="12" cy="12" r="4"/>
            </svg>
        </button>
        <button id="btn-events" class="control-btn" title="Events">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 8v4l3 3"/>
                <circle cx="12" cy="12" r="10"/>
            </svg>
        </button>
    </div>

    <!-- Member Popup (for directions etc) -->
    <div id="member-popup" class="member-popup hidden">
        <div class="popup-header">
            <div class="popup-avatar" id="popup-avatar"></div>
            <div class="popup-info">
                <div class="popup-name" id="popup-name"></div>
                <div class="popup-status" id="popup-status"></div>
            </div>
            <button class="popup-close" id="popup-close">&times;</button>
        </div>
        <div class="popup-actions">
            <button class="popup-btn" id="btn-follow">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                Follow
            </button>
            <button class="popup-btn" id="btn-directions">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 11l19-9-9 19-2-8-8-2z"/>
                </svg>
                Directions
            </button>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Config -->
    <script>
        window.TRACKING_CONFIG = {
            mapboxToken: '<?= htmlspecialchars($mapboxToken) ?>',
            userId: <?= (int)$user['id'] ?>,
            familyId: <?= (int)$user['family_id'] ?>,
            userName: '<?= htmlspecialchars($user['name']) ?>',
            members: <?= json_encode($members) ?>,
            apiBase: '/tracking/api',
            defaultCenter: [-26.2041, 28.0473], // Johannesburg
            defaultZoom: 12
        };
    </script>

    <!-- Tracking JS -->
    <script src="assets/js/format.js"></script>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/state.js"></script>
    <script src="assets/js/map.js"></script>
    <script src="assets/js/family-panel.js"></script>
    <script src="assets/js/ui-controls.js"></script>
    <script src="assets/js/follow.js"></script>
    <script src="assets/js/directions.js"></script>
    <script src="assets/js/polling.js"></script>
    <script src="assets/js/bootstrap.js"></script>
</body>
</html>
