<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}
require_once __DIR__ . '/../../core/bootstrap.php';
$auth = new Auth($db);
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: /login.php', true, 302);
    exit;
}

// Check consent
$hasConsent = false;
try {
    $stmt = $db->prepare("SELECT location_consent FROM tracking_consent WHERE user_id = ? AND location_consent = 1");
    $stmt->execute([$user['id']]);
    $hasConsent = (bool)$stmt->fetch();
} catch (\Exception $e) {}

$mapboxToken = $_ENV['MAPBOX_TOKEN'] ?? '';
$pageTitle = 'Family Tracking';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<style>
body.tracking-page { overflow: hidden !important; padding-bottom: 0 !important; margin: 0 !important; }
body.tracking-page .global-header { display: none !important; }
body.tracking-page .app-loader { display: none !important; }
</style>
<script>document.body.classList.add('tracking-page');</script>

<?php if (!$hasConsent): ?>
<!-- Consent overlay - OUTSIDE .tracking-app so it renders above footer -->
<div class="tracking-consent-overlay" id="consentOverlay">
    <div class="consent-card">
        <div class="consent-header">
            <div class="consent-icon">📍</div>
            <h2>Enable Family Tracking</h2>
            <p>Share your location with family members to stay connected and safe.</p>
        </div>
        <div class="consent-options">
            <label class="consent-toggle">
                <span class="consent-label">
                    <strong>Location Sharing</strong>
                    <small>Share your real-time location with family</small>
                </span>
                <div class="toggle-switch">
                    <input type="checkbox" id="consentLocation" checked>
                    <span class="toggle-slider"></span>
                </div>
            </label>
            <label class="consent-toggle">
                <span class="consent-label">
                    <strong>Push Notifications</strong>
                    <small>Get alerts when family arrives or leaves places</small>
                </span>
                <div class="toggle-switch">
                    <input type="checkbox" id="consentNotifications" checked>
                    <span class="toggle-slider"></span>
                </div>
            </label>
            <label class="consent-toggle">
                <span class="consent-label">
                    <strong>Background Tracking</strong>
                    <small>Track location even when app is in background</small>
                </span>
                <div class="toggle-switch">
                    <input type="checkbox" id="consentBackground" checked>
                    <span class="toggle-slider"></span>
                </div>
            </label>
        </div>
        <div class="consent-actions">
            <button class="consent-btn consent-btn-primary" onclick="saveConsent()">Enable Tracking</button>
            <button class="consent-btn consent-btn-secondary" onclick="dismissConsent()">Maybe Later</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="tracking-app" id="trackingApp">
    <!-- Map -->
    <div id="trackingMap"></div>

    <!-- Topbar -->
    <div class="tracking-topbar" id="trackingTopbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <button class="tracking-topbar-btn" id="trackingMenuBtn" title="Menu">
                <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <span class="tracking-topbar-title">Family Tracking</span>
        </div>
        <div class="tracking-topbar-actions">
            <a href="/tracking/app/events.php" class="tracking-topbar-btn" title="Events">
                <svg viewBox="0 0 24 24"><path d="M12 8v4l3 3"></path><circle cx="12" cy="12" r="10"></circle></svg>
            </a>
            <a href="/tracking/app/geofences.php" class="tracking-topbar-btn" title="Geofences">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
            </a>
            <a href="/tracking/app/settings.php" class="tracking-topbar-btn" title="Settings">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            </a>
        </div>
    </div>

    <!-- Family Panel (left on desktop, bottom sheet on mobile) -->
    <div class="tracking-family-panel" id="familyPanel">
        <div class="panel-drag-handle" id="panelDragHandle"></div>
        <div class="panel-header">
            <h3>Family Members</h3>
            <span class="panel-member-count" id="memberCount">0</span>
        </div>
        <div class="panel-members" id="panelMembers">
            <div class="panel-loading">Loading members...</div>
        </div>
    </div>

    <!-- Directions Bar -->
    <div class="tracking-directions-bar" id="directionsBar" style="display:none;">
        <div class="directions-info">
            <span class="directions-distance" id="directionsDistance"></span>
            <span class="directions-duration" id="directionsDuration"></span>
        </div>
        <button class="directions-close" onclick="closeDirections()">✕</button>
    </div>

    <!-- Wake FAB -->
    <button class="tracking-wake-fab" id="wakeFab" title="Request family locations">
        <svg viewBox="0 0 24 24"><path d="M1 1l22 22"></path><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"></path><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"></path><path d="M10.71 5.05A16 16 0 0 1 22.56 9"></path><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"></path><path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path><line x1="12" y1="20" x2="12.01" y2="20"></line></svg>
    </button>

    <!-- Notification prompt -->
    <div class="tracking-notification-prompt" id="notificationPrompt" style="display:none;">
        <span>Enable notifications for location alerts?</span>
        <button onclick="enableNotifications()">Enable</button>
        <button onclick="this.parentElement.style.display='none'">Dismiss</button>
    </div>
</div>

<script>
window.MAPBOX_TOKEN = '<?php echo addslashes($mapboxToken); ?>';
window.TRACKING_USER = {
    id: <?php echo (int)$user['id']; ?>,
    familyId: <?php echo (int)$user['family_id']; ?>,
    name: '<?php echo addslashes($user['name'] ?? ''); ?>',
    avatarColor: '<?php echo addslashes($user['avatar_color'] ?? '#667eea'); ?>'
};
</script>

<?php
$pageJS = ['/tracking/app/assets/js/state.js'];
require_once __DIR__ . '/../../shared/components/footer.php';
?>

<script>
// Wire hamburger to global sidebar
document.getElementById('trackingMenuBtn').addEventListener('click', function() {
    var sidebar = document.getElementById('mobileSidebar');
    var overlay = document.getElementById('mobileMenuOverlay');
    if (sidebar) sidebar.classList.add('active');
    if (overlay) overlay.classList.add('active');
});

// Consent functions
function saveConsent() {
    fetch('/tracking/api/consent.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
            location_consent: document.getElementById('consentLocation').checked ? 1 : 0,
            notification_consent: document.getElementById('consentNotifications').checked ? 1 : 0,
            background_consent: document.getElementById('consentBackground').checked ? 1 : 0
        })
    }).then(function() {
        dismissConsent();
        if (typeof Tracking !== 'undefined' && Tracking.startPolling) Tracking.startPolling();
    });
}

function dismissConsent() {
    var overlay = document.getElementById('consentOverlay');
    if (overlay) overlay.style.display = 'none';
}

function closeDirections() {
    document.getElementById('directionsBar').style.display = 'none';
    if (typeof Tracking !== 'undefined' && Tracking.clearDirections) Tracking.clearDirections();
}

function enableNotifications() {
    if ('Notification' in window) {
        Notification.requestPermission();
    }
    document.getElementById('notificationPrompt').style.display = 'none';
}

// Wake FAB
document.getElementById('wakeFab').addEventListener('click', function() {
    this.classList.add('pulsing');
    fetch('/tracking/api/wake_devices.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: '{}'
    }).then(function(r) { return r.json(); }).then(function(data) {
        document.getElementById('wakeFab').classList.remove('pulsing');
    });
});
</script>
