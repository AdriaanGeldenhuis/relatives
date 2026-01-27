<?php
declare(strict_types=1);

/**
 * Tracking Dashboard
 *
 * Fullscreen Mapbox map with family member locations.
 */

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Quick session check
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

// Load bootstrap
require_once __DIR__ . '/../../core/bootstrap.php';

// Validate session with database
try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }

} catch (Exception $e) {
    error_log('Tracking page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Check subscription
require_once __DIR__ . '/../../core/SubscriptionManager.php';
$subscriptionManager = new SubscriptionManager($db);

if ($subscriptionManager->isFamilyLocked($user['family_id'])) {
    header('Location: /subscription/locked.php', true, 302);
    exit;
}

// Get Mapbox token
$mapboxToken = $_ENV['MAPBOX_TOKEN'] ?? '';

// Get family members for initial render
$stmt = $db->prepare("
    SELECT id, full_name as name, avatar_color, has_avatar
    FROM users
    WHERE family_id = ? AND status = 'active' AND location_sharing = 1
    ORDER BY full_name
");
$stmt->execute([$user['family_id']]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set up page variables for global header
$pageTitle = 'Family Tracking';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];

// Include global header
require_once __DIR__ . '/../../shared/components/header.php';
?>

<!-- PWA Manifest for Native App Shell Support -->
<link rel="manifest" href="/tracking/app/manifest.json">
<meta name="format-detection" content="telephone=no">

<!-- Mapbox GL JS -->
<link href="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js"></script>

<!-- Tracking Page Wrapper -->
<div class="tracking-page-wrapper">
    <!-- Map Container -->
    <div id="map"></div>

    <!-- Session Indicator -->
    <div id="session-indicator" class="session-indicator hidden">
        <div class="session-dot"></div>
        <span class="session-text">Live session active</span>
    </div>

    <!-- Family Panel (popup) -->
    <div id="family-panel" class="family-panel">
        <div class="family-panel-header">
            <span>Family</span>
            <button class="family-panel-close" id="family-panel-close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="family-panel-content">
            <div id="family-list" class="family-list">
                <!-- Members populated by JS -->
            </div>
        </div>
    </div>

    <!-- Controls -->
    <div class="map-controls">
        <button id="btn-family" class="control-btn" title="Family members">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </button>
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
        <button id="btn-settings" class="control-btn" title="Settings">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
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
</div>

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
<script src="/tracking/app/assets/js/format.js"></script>
<script src="/tracking/app/assets/js/api.js"></script>
<script src="/tracking/app/assets/js/state.js"></script>
<script src="/tracking/app/assets/js/native-bridge.js"></script>
<script src="/tracking/app/assets/js/map.js"></script>
<script src="/tracking/app/assets/js/family-panel.js"></script>
<script src="/tracking/app/assets/js/ui-controls.js"></script>
<script src="/tracking/app/assets/js/follow.js"></script>
<script src="/tracking/app/assets/js/directions.js"></script>
<script src="/tracking/app/assets/js/polling.js"></script>
<script src="/tracking/app/assets/js/browser-tracking.js"></script>
<script src="/tracking/app/assets/js/bootstrap.js"></script>

<!-- Service Worker Registration -->
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/tracking/app/sw.js', {
                scope: '/tracking/'
            }).then((registration) => {
                console.log('[SW] Registered:', registration.scope);

                // Check for updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // New content available, show refresh prompt
                            if (window.Toast) {
                                Toast.show('Update available. Refresh for latest version.', 'info');
                            }
                        }
                    });
                });
            }).catch((error) => {
                console.warn('[SW] Registration failed:', error);
            });
        });
    }
</script>

<?php require_once __DIR__ . '/../../shared/components/footer.php'; ?>
