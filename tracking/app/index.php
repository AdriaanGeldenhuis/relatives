<?php
/**
 * ============================================
 * FAMILY TRACKING - MAIN DASHBOARD
 * Fullscreen map with family member panel
 * ============================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . rawurlencode('/tracking/app/'));
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: /login.php?redirect=' . rawurlencode('/tracking/app/'));
    exit;
}

// Load tracking settings for the family
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$trackingCache = new TrackingCache($cache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$familyId = (int)$user['family_id'];
$settings = $settingsRepo->get($familyId);
$wakeCsrf = CSRF::generate();
header('Cache-Control: private, no-store');

// Load alert rules
$alertsRepo = new AlertsRepo($db, $trackingCache);
$alertRules = $alertsRepo->get($familyId);

// Mapbox token from environment
$mapboxToken = $_ENV['MAPBOX_TOKEN'] ?? '';

$pageTitle = 'Family Tracking';
$pageCSS = [
    'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css',
    '/tracking/app/assets/css/tracking.css?build=' . filemtime(__DIR__ . '/assets/css/tracking.css'),
];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<link rel="manifest" href="/tracking/app/manifest.json">

<style>
/* Critical inline styles - must be here, not just in CSS file */
body.tracking-page { overflow: hidden !important; padding-bottom: 0 !important; margin: 0 !important; }
body.tracking-page .app-loader { display: none !important; }
.tracking-app { position: fixed; left: 0; right: 0; bottom: 0; z-index: 10; }
#trackingMap { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; }
</style>
<script>
document.body.classList.add('tracking-page');
// Position tracking app below the global header
requestAnimationFrame(function() {
    var header = document.querySelector('.global-header');
    var app = document.querySelector('.tracking-app');
    if (header && app) {
        app.style.top = header.offsetHeight + 'px';
    } else if (app) {
        app.style.top = '0';
    }
    // Map resize handled in map.on('load') below - not here (map not yet created)
});
</script>

<div class="tracking-app">

    <!-- Top Bar -->
    <div class="tracking-topbar">
        <div class="tracking-topbar-left">
            <a href="/home/" class="tracking-topbar-back" title="Back to Home">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </a>
            <div>
                <h1 class="tracking-topbar-title">Family Tracking</h1>
                <div class="tracking-topbar-subtitle" id="trackingStatus">Connecting...</div>
            </div>
        </div>
        <?php renderFamilyAppArt('tracking', true); ?>
        <div class="tracking-topbar-actions">
            <a href="/tracking/app/events.php" class="tracking-topbar-btn" title="Events">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
            </a>
            <a href="/tracking/app/geofences.php" class="tracking-topbar-btn" title="Geofences">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
            </a>
            <a href="/tracking/app/settings.php" class="tracking-topbar-btn" title="Settings">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            </a>
            <button class="tracking-topbar-btn" id="mapStyleBtn" title="Change map view">
                <svg viewBox="0 0 24 24"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line></svg>
            </button>
        </div>
    </div>

    <!-- Map Container -->
    <div id="trackingMap"></div>

    <!-- Bottom Toolbar -->
    <div class="tracking-toolbar" id="trackingToolbar">
        <a href="/home/" class="tracking-toolbar-btn home-btn" title="Home">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <span>Home</span>
        </a>
        <button class="tracking-toolbar-btn wake-btn" id="wakeFab" title="Wake all devices">
            <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            <span>Wake</span>
        </button>
        <button class="tracking-toolbar-btn" id="panelToggleBtn" title="Toggle family panel">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <span>Family</span>
        </button>
    </div>

    <section class="tracking-wake-result" id="wakeResult" aria-live="polite" hidden></section>

    <!-- Navigation Header (next turn instruction) -->
    <div class="nav-header" id="navHeader">
        <div class="nav-header-icon" id="navIcon">
            <svg viewBox="0 0 24 24"><polyline points="5 12 12 5 19 12"></polyline><line x1="12" y1="19" x2="12" y2="5"></line></svg>
        </div>
        <div class="nav-header-info">
            <div class="nav-header-distance" id="navStepDist">--</div>
            <div class="nav-header-instruction" id="navInstruction">Getting route...</div>
        </div>
    </div>

    <!-- Navigation Bottom Bar (summary + controls) -->
    <div class="nav-bottom" id="navBottom">
        <div class="nav-bottom-stats">
            <div class="nav-bottom-stat">
                <span class="nav-bottom-value" id="navRemainDist">--</span>
                <span class="nav-bottom-label">remaining</span>
            </div>
            <div class="nav-bottom-stat">
                <span class="nav-bottom-value" id="navETA">--</span>
                <span class="nav-bottom-label">ETA</span>
            </div>
        </div>
        <div class="nav-bottom-actions">
            <button class="nav-steps-btn" id="navStepsBtn" title="View all steps">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                Steps
            </button>
            <button class="nav-end-btn" id="navEndBtn" title="End navigation">End</button>
        </div>
    </div>

    <!-- Steps List Panel -->
    <div class="nav-steps-panel" id="navStepsPanel">
        <div class="nav-steps-header">
            <span class="nav-steps-title">Turn-by-turn</span>
            <button class="nav-steps-close" id="navStepsClose">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nav-steps-list" id="navStepsList"></div>
    </div>

    <!-- Directions Info Bar (route preview before starting nav) -->
    <div class="directions-bar" id="directionsBar">
        <div class="directions-info">
            <div class="directions-stat">
                <div class="directions-stat-value" id="directionsDistance">--</div>
                <div class="directions-stat-label">Distance</div>
            </div>
            <div class="directions-divider"></div>
            <div class="directions-stat">
                <div class="directions-stat-value" id="directionsDuration">--</div>
                <div class="directions-stat-label">Duration</div>
            </div>
            <div class="directions-divider"></div>
            <button class="directions-nav-btn" id="directionsStartNav" title="Start navigation">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
                Start
            </button>
        </div>
        <button class="directions-close" id="directionsClose" title="Close directions">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>

</div><!-- .tracking-app -->

<!-- Family Members Panel (outside tracking-app for correct z-index stacking) -->
<div class="family-panel" id="familyPanel" style="display:none">
    <div class="family-panel-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="family-panel-title">Family</span>
            <span class="family-panel-badge" id="memberCount">0</span>
        </div>
        <button class="family-panel-close" id="familyPanelClose" title="Close panel">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>
    <div class="family-panel-body" id="memberList">
        <!-- Members loaded dynamically -->
        <div class="member-empty" id="memberEmpty">
            <div class="member-empty-icon"></div>
            <div class="member-empty-text">No members online</div>
            <div class="member-empty-sub">Waiting for family members to share location</div>
        </div>
    </div>
</div>

<!-- Consent Dialog -->
<div class="consent-overlay" id="consentOverlay">
    <div class="consent-card">
        <div class="consent-header">
            <div class="consent-icon"></div>
            <h2 class="consent-title">Enable Location Sharing</h2>
            <p class="consent-subtitle">Share your location with family members so everyone can stay connected and safe.</p>
        </div>
        <div class="consent-toggles">
            <div class="consent-toggle-row">
                <div>
                    <div class="consent-toggle-label">Location Sharing</div>
                    <div class="consent-toggle-desc">Share your real-time position with family</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="consentLocation" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="consent-toggle-row">
                <div>
                    <div class="consent-toggle-label">Background Tracking</div>
                    <div class="consent-toggle-desc">Continue tracking when app is in background</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="consentBackground" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="consent-toggle-row">
                <div>
                    <div class="consent-toggle-label">Geofence Alerts</div>
                    <div class="consent-toggle-desc">Notify when members enter or leave zones</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="consentGeofence" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <div class="consent-actions">
            <button class="consent-btn consent-btn-secondary" id="consentDecline">Not Now</button>
            <button class="consent-btn consent-btn-primary" id="consentAccept">Enable Sharing</button>
        </div>
    </div>
</div>

<!-- Notification Permission Prompt -->
<div class="notification-prompt" id="notifPrompt">
    <div class="notification-prompt-text">
        Enable notifications to get alerts when family members arrive or leave places.
    </div>
    <div class="notification-prompt-actions">
        <button class="notification-prompt-btn notification-prompt-allow" id="notifAllow">Allow</button>
        <button class="notification-prompt-btn notification-prompt-dismiss" id="notifDismiss">Later</button>
    </div>
</div>

<!-- Initial Data -->
<script>
    window.MAPBOX_TOKEN = <?php echo json_encode($mapboxToken); ?>;
    window.TrackingConfig = {
        mapboxToken: window.MAPBOX_TOKEN,
        userId: <?php echo (int)$user['id']; ?>,
        familyId: <?php echo $familyId; ?>,
        userName: <?php echo json_encode($user['name'] ?? $user['full_name'] ?? 'User'); ?>,
        userRole: <?php echo json_encode($user['role']); ?>,
        avatarColor: <?php echo json_encode($user['avatar_color'] ?? '#667eea'); ?>,
        settings: <?php echo json_encode($settings); ?>,
        alertRules: <?php echo json_encode($alertRules); ?>,
        apiBase: '/tracking/api',
        wakeCsrf: <?php echo json_encode($wakeCsrf); ?>,
        isAdmin: <?php echo json_encode(in_array($user['role'], ['owner', 'admin'])); ?>
    };
</script>

<script src="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js"></script>

<?php
$pageJS = [];
require_once __DIR__ . '/../../shared/components/footer.php';
?>

<script>
(function() {
    'use strict';

    // Local state for members (no external dependency)
    var _members = [];

    // Register service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/tracking/app/sw.js').catch(function(err) {
            console.warn('[SW] Registration failed:', err);
        });
    }

    // Initialize Mapbox. EVERY failure mode (missing token, CDN blocked,
    // no WebGL, GPU trouble in the Android WebView) must leave the rest of
    // the page alive — family panel, polling and the native bridge all work
    // without the map. Never `return` out of this IIFE for a map problem.
    var mapboxAvailable = typeof mapboxgl !== 'undefined';
    var mapboxToken = (window.TrackingConfig && window.TrackingConfig.mapboxToken) || '';
    if (!mapboxAvailable) console.error('[Tracking] Mapbox GL JS failed to load');
    if (!mapboxToken) console.error('[Tracking] No Mapbox token configured');

    var mapStyles = {
        dark:      'mapbox://styles/mapbox/dark-v11',
        streets:   'mapbox://styles/mapbox/streets-v12',
        satellite: 'mapbox://styles/mapbox/satellite-streets-v12',
        light:     'mapbox://styles/mapbox/light-v11'
    };
    var styleOrder = ['dark', 'streets', 'satellite', 'light'];
    // Personal choice first (localStorage), then the family default.
    var currentStyleKey = null;
    try { currentStyleKey = localStorage.getItem('tracking_map_style'); } catch (e) {}
    if (!currentStyleKey) {
        currentStyleKey = ((window.TrackingConfig || {}).settings || {}).map_style || 'dark';
    }
    if (!mapStyles[currentStyleKey]) currentStyleKey = 'dark';
    var mapStyle = mapStyles[currentStyleKey];

    var map = null;
    if (mapboxAvailable && mapboxToken) try {
        mapboxgl.accessToken = mapboxToken;
        map = new mapboxgl.Map({
            container: 'trackingMap',
            style: mapStyle,
            center: [28.0473, -26.2041],
            zoom: 12,
            attributionControl: false
        });

        map.addControl(new mapboxgl.NavigationControl(), 'top-right');
        map.addControl(new mapboxgl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            trackUserLocation: true,
            showUserHeading: true
        }), 'top-right');

        map.on('error', function(e) {
            console.warn('[Map] Error:', e && e.error ? e.error.message : e);
        });

        map.on('load', function() {
            map.resize();
        });

        // Force resize after layout settles
        setTimeout(function() { if (map) map.resize(); }, 100);
        setTimeout(function() { if (map) map.resize(); }, 500);
    } catch (mapErr) {
        console.error('[Tracking] Map init failed, continuing without map:', mapErr);
        map = null;
    }

    if (!map) {
        var mapStatusEl = document.getElementById('trackingStatus');
        if (mapStatusEl) mapStatusEl.textContent = 'Map unavailable on this device';
    }

    window.trackingMap = map;

    // Map style toggle
    document.getElementById('mapStyleBtn').addEventListener('click', function() {
        if (!map) return;
        var idx = styleOrder.indexOf(currentStyleKey);
        currentStyleKey = styleOrder[(idx + 1) % styleOrder.length];
        map.setStyle(mapStyles[currentStyleKey]);
        showToast('Map: ' + currentStyleKey.charAt(0).toUpperCase() + currentStyleKey.slice(1), 'info');
        // Persist locally for everyone; settings_save.php is admin-only, so
        // for regular members the old server POST 403'd and their choice
        // reverted on every reload. Admins still update the family default.
        try { localStorage.setItem('tracking_map_style', currentStyleKey); } catch (e) {}
        if (window.TrackingConfig.isAdmin) {
            fetch(window.TrackingConfig.apiBase + '/settings_save.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ map_style: currentStyleKey })
            }).catch(function(e) { console.warn('[MapStyle] Save failed:', e); });
        }
        // Re-add markers after style change
        map.once('style.load', function() {
            var oldMarkers = markers;
            markers = {};
            Object.keys(oldMarkers).forEach(function(uid) { oldMarkers[uid].remove(); });
            updateMarkers(_members);
            // Re-draw route if active
            if (_routeBounds && _navRoute && _navRoute.geometry) {
                drawRouteOnMap(_navRoute.geometry);
            }
        });
    });

    // Markers storage
    var markers = {};
    var initialFitDone = false;

    function showMembers(members) {
        _members = members;
        renderMembers(_members);
        updateMarkers(_members);
        if (!initialFitDone && fitMapToFamily(_members)) initialFitDone = true;
    }

    var mapRequest = null;
    var mapFetchAgain = false;
    var nativeCacheAllowed = true;
    var mapPolicy = null;
    var serverClockOffset = 0;
    var localTrackingStatus = '';
    var nativeTrackingStatus = '';

    function clearFamilySnapshot() {
        nativeCacheAllowed = false;
        if (mapRequest) mapRequest.abort();
        showMembers([]);
    }

    async function fetchMembers(urgent) {
        if (document.hidden) return;
        if (mapRequest) {
            if (urgent) mapFetchAgain = true;
            return;
        }
        var request = new AbortController();
        mapRequest = request;
        var requestTimeout = setTimeout(function() { request.abort(); }, 10000);
        try {
            var response = await fetch(window.TrackingConfig.apiBase + '/current.php', {
                credentials: 'same-origin', cache: 'no-store', signal: request.signal
            });
            if (response.status === 401 || response.status === 403) {
                clearFamilySnapshot();
                setOwnStatus('Sign in to see family locations');
                stopBrowserTracking();
                stopPolling();
                return;
            }
            var data = await response.json();
            if (!response.ok || !data.success || !Array.isArray(data.data)) throw new Error('snapshot_unavailable');
            if (request.signal.aborted || document.hidden) return;
            nativeCacheAllowed = false;
            var previousPolicy = mapPolicy;
            mapPolicy = data.server_settings || null;
            if (previousPolicy && mapPolicy && nativeBridge && nativeBridge.refreshTrackingPolicy &&
                (previousPolicy.mode !== mapPolicy.mode || previousPolicy.location_sharing !== mapPolicy.location_sharing)) {
                nativeBridge.refreshTrackingPolicy();
            }
            if (nativeBridge) updateNativeSharingButton();
            if (mapPolicy && Number.isFinite(mapPolicy.server_time_ms)) {
                serverClockOffset = mapPolicy.server_time_ms - Date.now();
            }
            localTrackingStatus = mapPolicy && mapPolicy.mode === 0 ? 'Family tracking is off' : '';
            if (mapPolicy && !mapPolicy.allowed) stopBrowserTracking();
            showMembers(data.data);
        } catch (error) {
            if (!document.hidden && error.name !== 'AbortError') {
                setOwnStatus(_members.length ? 'Connection lost · showing last known locations' : 'Waiting for a connection');
            }
        } finally {
            clearTimeout(requestTimeout);
            if (mapRequest === request) mapRequest = null;
            if (mapFetchAgain) {
                mapFetchAgain = false;
                fetchMembers();
            }
        }
    }

    function fitMapToFamily(members) {
        if (!map) return false;
        var pts = members.filter(function(m) { return m.has_location && m.lat !== null && m.lng !== null; });
        if (pts.length === 0) return false;
        if (pts.length === 1) {
            map.flyTo({ center: [parseFloat(pts[0].lng), parseFloat(pts[0].lat)], zoom: 15, duration: 1000 });
            return true;
        }
        var bounds = new mapboxgl.LngLatBounds();
        pts.forEach(function(m) { bounds.extend([parseFloat(m.lng), parseFloat(m.lat)]); });
        map.fitBounds(bounds, { padding: 80, maxZoom: 16, duration: 1000 });
        return true;
    }

    function renderMembers(members) {
        var list = document.getElementById('memberList');
        var empty = document.getElementById('memberEmpty');
        var badge = document.getElementById('memberCount');
        var statusEl = document.getElementById('trackingStatus');

        if (!list) return;

        var statusPrefix = map ? '' : 'Map unavailable · ';

        if (!members || members.length === 0) {
            if (badge) badge.textContent = '0';
            if (statusEl) statusEl.textContent = statusPrefix + 'No members';
            list.innerHTML = '<div class="member-empty" id="memberEmpty">' +
                '<div class="member-empty-icon"></div>' +
                '<div class="member-empty-text">No members online</div>' +
                '<div class="member-empty-sub">Waiting for family members to share location</div></div>';
            return;
        }

        if (empty) empty.style.display = 'none';
        if (badge) badge.textContent = members.length;
        var withLocation = members.filter(function(m) {
            return m.has_location && m.lat !== null;
        }).length;
        if (statusEl) statusEl.textContent = localTrackingStatus || nativeTrackingStatus || statusPrefix + withLocation + ' sharing locations';

        var html = '';
        members.forEach(function(m) {
            var initial = (m.name || 'U').charAt(0).toUpperCase();
            var hasLoc = m.has_location && m.lat !== null;
            var statusClass = hasLoc ? getStatusClass(m) : 'offline';
            var timeAgo = hasLoc ? (statusClass === 'offline' ? 'Last known · ' : '') + formatTimeAgo(captureTime(m))
                : (m.location_sharing === false ? 'Sharing off' : 'No location yet');
            if (hasLoc && m.approximate) timeAgo += ' · Approximate';
            var speed = hasLoc ? formatSpeed(m.speed_mps) : '';

            html += '<div class="member-item" data-user-id="' + m.user_id + '"' + (hasLoc ? ' onclick="flyToMember(' + m.user_id + ')"' : '') + '>';
            html += '  <div class="member-avatar" style="background:' + safeColor(m.avatar_color) + '">';
            if (m.has_avatar) {
                html += '    <img src="/saves/' + m.user_id + '/avatar/avatar.webp?v=' + (window.avatarVersion || 0) + '" alt="" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\'">';
                html += '    <span style="display:none">' + initial + '</span>';
            } else {
                html += '    <span>' + initial + '</span>';
            }
            html += '    <span class="member-status-dot ' + statusClass + '"></span>';
            html += '  </div>';
            html += '  <div class="member-info">';
            html += '    <div class="member-name">' + escapeHtml(m.name || 'Unknown') + '</div>';
            html += '    <div class="member-meta">';
            html += '      <span class="member-time">' + timeAgo + '</span>';
            if (speed) {
                html += '      <span class="member-speed' + (parseFloat(m.speed_mps) > 1 ? ' moving' : '') + '">' + speed + '</span>';
            }
            html += '    </div>';
            html += '  </div>';
            if (hasLoc) {
                html += '  <div class="member-actions">';
                html += '    <button class="member-action-btn" onclick="event.stopPropagation(); getDirections(' + m.user_id + ')" title="Directions">';
                html += '      <svg viewBox="0 0 24 24"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>';
                html += '    </button>';
                html += '  </div>';
            }
            html += '</div>';
        });

        var scrollTop = list.scrollTop;
        list.innerHTML = html;
        list.scrollTop = scrollTop;
    }

    function updateMarkers(members) {
        if (!map) return;
        var activeIds = {};
        members.forEach(function(m) {
            // Skip members without a location
            if (!m.has_location || m.lat === null || m.lng === null) return;

            activeIds[m.user_id] = true;
            var lngLat = [parseFloat(m.lng), parseFloat(m.lat)];
            if (markers[m.user_id]) {
                animateMarker(markers[m.user_id], lngLat);
                var popup = markers[m.user_id].getPopup();
                if (popup) popup.setHTML(buildPopupHTML(m));
            } else {
                var el = document.createElement('div');
                el.className = 'map-marker';
                var initial = (m.name || 'U').charAt(0).toUpperCase();
                if (m.has_avatar) {
                    el.innerHTML = '<div class="map-marker-inner map-marker-avatar" style="background:' + safeColor(m.avatar_color) + '">' +
                        '<img src="/saves/' + m.user_id + '/avatar/avatar.webp?v=' + (window.avatarVersion || 0) + '" alt="" onerror="this.style.display=\'none\';this.parentNode.textContent=\'' + initial + '\'">' +
                        '</div>';
                } else {
                    el.innerHTML = '<div class="map-marker-inner" style="background:' + safeColor(m.avatar_color) + '">' + initial + '</div>';
                }
                markers[m.user_id] = new mapboxgl.Marker({ element: el, anchor: 'top-left' })
                    .setLngLat(lngLat)
                    .setPopup(new mapboxgl.Popup({ offset: 25, maxWidth: '260px' }).setHTML(buildPopupHTML(m)))
                    .addTo(map);
            }
        });

        Object.keys(markers).forEach(function(uid) {
            if (!activeIds[uid]) {
                markers[uid].remove();
                delete markers[uid];
            }
        });
    }

    function captureTime(member) {
        if (Number.isFinite(member.recorded_at_ms)) return member.recorded_at_ms;
        if (!member.recorded_at) return 0;
        var value = member.recorded_at.replace(' ', 'T');
        if (!/(Z|[+-]\d{2}:?\d{2})$/i.test(value)) value += 'Z';
        return Date.parse(value) || 0;
    }

    function getStatusClass(m) {
        var ageSecs = (Date.now() + serverClockOffset - captureTime(m)) / 1000;
        var expect = mapPolicy && (m.motion_state === 'moving' ? mapPolicy.moving_interval : mapPolicy.idle_interval);
        if (ageSecs <= Math.max(m.motion_state === 'moving' ? 60 : 180, 2 * (expect || 0))) {
            return m.motion_state === 'idle' ? 'idle' : 'online';
        }
        return 'offline';
    }

    function formatTimeAgo(timestamp) {
        if (!timestamp) return 'Time unavailable';
        var diff = Math.max(0, (Date.now() + serverClockOffset - timestamp) / 1000);
        if (diff < 60) return Math.floor(diff) + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function animateMarker(marker, targetLngLat) {
        var start = marker.getLngLat();
        var startLng = start.lng, startLat = start.lat;
        var endLng = targetLngLat[0], endLat = targetLngLat[1];
        if (Math.abs(startLng - endLng) < 0.000001 && Math.abs(startLat - endLat) < 0.000001) return;
        var duration = 1000;
        var startTime = performance.now();
        function step(now) {
            var t = Math.min((now - startTime) / duration, 1);
            t = t * (2 - t); // ease-out
            marker.setLngLat([startLng + (endLng - startLng) * t, startLat + (endLat - startLat) * t]);
            if (t < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function buildPopupHTML(m) {
        var status = getStatusClass(m);
        var statusLabel = status === 'offline' ? 'Last known' : (status === 'idle' ? 'Stationary' : 'Recent');
        var statusColor = status === 'online' ? '#43e97b' : status === 'idle' ? '#f9d423' : '#718096';
        var timeAgo = formatTimeAgo(captureTime(m)) + (m.approximate ? ' · Approximate' : '');
        var speed = formatSpeed(m.speed_mps);
        var motionState = m.motion_state || 'unknown';
        var initial = (m.name || 'U').charAt(0).toUpperCase();

        var s = '<div style="min-width:200px;font-family:inherit">';

        // Header row: avatar + name + status
        s += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
        s += '<div style="width:36px;height:36px;min-width:36px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;background:' + safeColor(m.avatar_color) + '">';
        if (m.has_avatar) {
            s += '<img src="/saves/' + m.user_id + '/avatar/avatar.webp" style="width:36px;height:36px;object-fit:cover;border-radius:50%;display:block" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
            s += '<span style="display:none;width:100%;height:100%;align-items:center;justify-content:center">' + initial + '</span>';
        } else {
            s += initial;
        }
        s += '</div>';
        s += '<div style="flex:1;min-width:0">';
        s += '<div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(m.name || 'Unknown') + '</div>';
        s += '<div style="display:flex;align-items:center;gap:5px;font-size:11px;opacity:0.7;margin-top:1px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + statusColor + ';flex-shrink:0"></span>' + statusLabel + ' &middot; ' + timeAgo + '</div>';
        s += '</div></div>';

        // Info rows
        var rowStyle = 'display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:3px 0';
        var labelStyle = 'opacity:0.6';
        var valStyle = 'font-weight:600';

        s += '<div style="padding:6px 0;border-top:1px solid rgba(255,255,255,0.1);border-bottom:1px solid rgba(255,255,255,0.1);margin-bottom:8px">';

        if (speed) {
            s += '<div style="' + rowStyle + '"><span style="' + labelStyle + '">Speed</span><span style="' + valStyle + ';color:#f9d423">' + speed + '</span></div>';
        }

        var motionIcon = motionState === 'moving' ? '&#9654;' : motionState === 'idle' ? '&#9208;' : '&#8226;';
        var motionLabel = motionState.charAt(0).toUpperCase() + motionState.slice(1);
        s += '<div style="' + rowStyle + '"><span style="' + labelStyle + '">State</span><span style="' + valStyle + '">' + motionIcon + ' ' + motionLabel + '</span></div>';

        if (m.altitude_m !== null && m.altitude_m !== undefined) {
            var units = (window.TrackingConfig.settings || {}).units || 'metric';
            var alt = units === 'imperial' ? (m.altitude_m * 3.281).toFixed(0) + ' ft' : Math.round(m.altitude_m) + ' m';
            s += '<div style="' + rowStyle + '"><span style="' + labelStyle + '">Altitude</span><span style="' + valStyle + '">' + alt + '</span></div>';
        }

        if (m.accuracy_m !== null && m.accuracy_m !== undefined) {
            s += '<div style="' + rowStyle + '"><span style="' + labelStyle + '">Accuracy</span><span style="' + valStyle + '">' + Math.round(m.accuracy_m) + ' m</span></div>';
        }

        if (m.bearing_deg !== null && m.bearing_deg !== undefined && parseFloat(m.speed_mps) > 0.5) {
            var dirs = ['N','NE','E','SE','S','SW','W','NW'];
            var dir = dirs[Math.round(m.bearing_deg / 45) % 8];
            s += '<div style="' + rowStyle + '"><span style="' + labelStyle + '">Heading</span><span style="' + valStyle + '">' + dir + ' (' + Math.round(m.bearing_deg) + '&deg;)</span></div>';
        }

        s += '</div>';

        // Navigate button
        s += '<button onclick="getDirections(' + m.user_id + ')" style="width:100%;padding:7px 0;background:linear-gradient(135deg,#f5a623,#f7931e);border:none;border-radius:8px;color:#fff;font-weight:700;font-size:12px;cursor:pointer">Navigate</button>';

        s += '</div>';
        return s;
    }

    function formatSpeed(mps) {
        var val = parseFloat(mps);
        if (!val || val < 0.5) return '';
        var units = (window.TrackingConfig.settings || {}).units || 'metric';
        if (units === 'imperial') {
            return (val * 2.237).toFixed(0) + ' mph';
        }
        return (val * 3.6).toFixed(0) + ' km/h';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // avatar_color is interpolated raw into style="background:..." inside
    // innerHTML strings; today only profile picture upload writes it, but a
    // value like `#fff"><img src=x onerror=...>` would execute for every
    // family member viewing the map. Whitelist to a valid hex colour.
    function safeColor(c) {
        return /^#[0-9a-fA-F]{3,8}$/.test(c || '') ? c : '#667eea';
    }

    // Fly to member
    window.flyToMember = function(userId) {
        if (!map) return;
        var m = _members.find(function(x) { return x.user_id == userId; });
        // A poll may have replaced this member with has_location=false since
        // the row was rendered; parseFloat(null) → NaN → mapbox throws.
        if (m && m.has_location && m.lat !== null && m.lng !== null) {
            map.flyTo({ center: [parseFloat(m.lng), parseFloat(m.lat)], zoom: 16, duration: 1500 });

            document.querySelectorAll('.member-item').forEach(function(el) {
                el.classList.toggle('active', el.dataset.userId == userId);
            });

            if (markers[userId]) {
                markers[userId].togglePopup();
            }
        }
    };

    // ── NAVIGATION SYSTEM ─────────────────────────────────────
    var _routeBounds = null;
    var _navActive = false;
    var _navSteps = [];
    var _navCurrentStep = 0;
    var _navRoute = null;
    var _navTargetName = '';
    var _navWatchId = null;

    // Format distance nicely
    function fmtDist(m) {
        if (m >= 1000) return (m / 1000).toFixed(1) + ' km';
        return Math.round(m) + ' m';
    }
    function fmtDur(s) {
        if (s >= 3600) return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
        return Math.max(1, Math.floor(s / 60)) + ' min';
    }

    // SVG icons for maneuver types
    function maneuverIcon(maneuver) {
        var m = maneuver || '';
        if (m.indexOf('left') !== -1 && m.indexOf('slight') !== -1)
            return '<svg viewBox="0 0 24 24"><polyline points="14 6 8 12 14 18"></polyline><line x1="20" y1="12" x2="8" y2="12"></line></svg>';
        if (m.indexOf('left') !== -1)
            return '<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>';
        if (m.indexOf('right') !== -1 && m.indexOf('slight') !== -1)
            return '<svg viewBox="0 0 24 24"><polyline points="10 6 16 12 10 18"></polyline><line x1="4" y1="12" x2="16" y2="12"></line></svg>';
        if (m.indexOf('right') !== -1)
            return '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>';
        if (m.indexOf('roundabout') !== -1)
            return '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"></circle><polyline points="12 2 12 8"></polyline></svg>';
        if (m.indexOf('arrive') !== -1)
            return '<svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
        // Default: straight arrow
        return '<svg viewBox="0 0 24 24"><polyline points="5 12 12 5 19 12"></polyline><line x1="12" y1="19" x2="12" y2="5"></line></svg>';
    }

    // Haversine distance in metres
    function haversineDist(lat1, lng1, lat2, lng2) {
        var R = 6371000, dLat = (lat2 - lat1) * Math.PI / 180, dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    window.getDirections = function(userId) {
        if (!map) {
            showToast('Map unavailable on this device', 'error');
            return;
        }
        var target = _members.find(function(x) { return x.user_id == userId; });
        if (!target || !target.lat || !target.lng) {
            showToast('No location for this member', 'error');
            return;
        }

        var me = _members.find(function(x) { return x.user_id == window.TrackingConfig.userId; });
        if (!me || !me.lat || !me.lng) {
            showToast('Your location is not available yet', 'error');
            return;
        }

        // Close any open popup
        Object.keys(markers).forEach(function(uid) {
            var popup = markers[uid].getPopup();
            if (popup && popup.isOpen()) popup.remove();
        });

        showToast('Getting route to ' + (target.name || 'member') + '...', 'info');

        var url = window.TrackingConfig.apiBase + '/directions.php' +
            '?from_lat=' + parseFloat(me.lat) +
            '&from_lng=' + parseFloat(me.lng) +
            '&to_lat=' + parseFloat(target.lat) +
            '&to_lng=' + parseFloat(target.lng);

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) throw new Error('Server ' + r.status);
                return r.json();
            })
            .then(function(data) {
                if (data.success && data.data) {
                    showRoutePreview(data.data, target.name);
                } else {
                    showToast('No route found', 'error');
                }
            })
            .catch(function(err) {
                console.error('[Directions] Error:', err);
                showToast('Could not get route — try again later', 'error');
            });
    };

    // Show route on map with preview bar (distance/duration + Start button)
    function showRoutePreview(route, targetName) {
        _navRoute = route;
        _navTargetName = targetName;
        _navSteps = route.steps || [];

        var bar = document.getElementById('directionsBar');
        document.getElementById('directionsDistance').textContent = fmtDist(route.distance_m || 0);
        document.getElementById('directionsDuration').textContent = fmtDur(route.duration_s || 0);
        bar.classList.add('active');

        drawRouteOnMap(route.geometry);
        showToast('Route to ' + (targetName || 'member'), 'success');
    }

    function drawRouteOnMap(geometry) {
        if (!geometry || !map) return;
        if (map.getLayer('route')) map.removeLayer('route');
        if (map.getSource('route')) map.removeSource('route');

        map.addSource('route', {
            type: 'geojson',
            data: { type: 'Feature', geometry: geometry }
        });
        map.addLayer({
            id: 'route',
            type: 'line',
            source: 'route',
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: { 'line-color': '#667eea', 'line-width': 6, 'line-opacity': 0.9 }
        });

        _routeBounds = new mapboxgl.LngLatBounds();
        geometry.coordinates.forEach(function(c) { _routeBounds.extend(c); });
        map.fitBounds(_routeBounds, { padding: 80, duration: 1000 });
    }

    // ── START NAVIGATION ──
    function startNavigation() {
        if (!_navRoute || _navSteps.length === 0) {
            showToast('No steps available', 'error');
            return;
        }

        _navActive = true;
        _navCurrentStep = 0;

        // Hide route preview, show nav UI
        document.getElementById('directionsBar').classList.remove('active');
        document.getElementById('trackingToolbar').style.display = 'none';
        document.getElementById('navHeader').classList.add('active');
        document.getElementById('navBottom').classList.add('active');

        updateNavStep();
        updateNavRemaining();

        startNavigationWatch();

        // Zoom to first step area
        if (map && _navSteps[0] && _navSteps[0].location) {
            map.flyTo({
                center: _navSteps[0].location,
                zoom: 17,
                pitch: 45,
                duration: 1000
            });
        }
    }

    var navigationRequested = false;
    function startNavigationWatch() {
        if (!_navActive || document.hidden || !navigator.geolocation || _navWatchId !== null || navigationRequested) return;
        function beginWatch() {
            if (!_navActive || document.hidden || _navWatchId !== null) return;
            _navWatchId = navigator.geolocation.watchPosition(onNavPosition, function(e) {
                console.warn('[Nav] Geolocation error:', e.message);
            }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 5000 });
        }

        if (window.TrackingBridge?.prepareNavigation) {
            navigationRequested = true;
            window.TrackingBridge.prepareNavigation().then(function(allowed) {
                navigationRequested = false;
                if (allowed) beginWatch();
            });
        } else {
            beginWatch();
        }
    }

    function stopNavigationWatch() {
        if (_navWatchId === null) return;
        navigator.geolocation.clearWatch(_navWatchId);
        _navWatchId = null;
    }

    function updateNavStep() {
        var step = _navSteps[_navCurrentStep];
        if (!step) return;
        document.getElementById('navIcon').innerHTML = maneuverIcon(step.maneuver);
        document.getElementById('navStepDist').textContent = fmtDist(step.distance_m);
        document.getElementById('navInstruction').textContent = step.instruction || 'Continue';
    }

    function updateNavRemaining() {
        var remainDist = 0, remainDur = 0;
        for (var i = _navCurrentStep; i < _navSteps.length; i++) {
            remainDist += _navSteps[i].distance_m || 0;
            remainDur += _navSteps[i].duration_s || 0;
        }
        document.getElementById('navRemainDist').textContent = fmtDist(remainDist);

        // ETA
        var eta = new Date(Date.now() + remainDur * 1000);
        document.getElementById('navETA').textContent =
            eta.getHours().toString().padStart(2, '0') + ':' + eta.getMinutes().toString().padStart(2, '0');
    }

    // Auto-advance: when user is within 40m of next step's maneuver point, move to next
    function onNavPosition(pos) {
        if (!_navActive) return;
        var lat = pos.coords.latitude, lng = pos.coords.longitude;

        // Center map on user during navigation
        if (map) map.easeTo({ center: [lng, lat], duration: 500 });

        // Check if we're close to the current step's maneuver point
        var step = _navSteps[_navCurrentStep];
        if (step && step.location) {
            var dist = haversineDist(lat, lng, step.location[1], step.location[0]);
            // Update distance display to show live distance to next maneuver
            document.getElementById('navStepDist').textContent = fmtDist(dist);

            if (dist < 40 && _navCurrentStep < _navSteps.length - 1) {
                _navCurrentStep++;
                updateNavStep();
                updateNavRemaining();
                // Don't reuse this step's `dist` for the arrival check below —
                // it was measured against the step we just left, which would
                // declare arrival one turn early. Re-evaluate on the next fix.
                return;
            }

            // Arrival: on the final step, measure distance to the DESTINATION
            // (the last step's own location), not a stale value.
            if (_navCurrentStep === _navSteps.length - 1) {
                var last = _navSteps[_navSteps.length - 1];
                var destDist = last && last.location
                    ? haversineDist(lat, lng, last.location[1], last.location[0])
                    : dist;
                if (destDist < 50) {
                    showToast('You have arrived!', 'success');
                    endNavigation();
                }
            }
        }
    }

    function endNavigation() {
        _navActive = false;

        stopNavigationWatch();

        document.getElementById('navHeader').classList.remove('active');
        document.getElementById('navBottom').classList.remove('active');
        document.getElementById('navStepsPanel').classList.remove('active');
        document.getElementById('trackingToolbar').style.display = '';

        // Clean up route
        if (map) {
            if (map.getLayer('route')) map.removeLayer('route');
            if (map.getSource('route')) map.removeSource('route');
            map.easeTo({ pitch: 0, duration: 500 });
        }
        _routeBounds = null;
        _navRoute = null;
        _navSteps = [];
    }

    function clearDirections() {
        document.getElementById('directionsBar').classList.remove('active');
        if (_navActive) endNavigation();
        if (map) {
            if (map.getLayer('route')) map.removeLayer('route');
            if (map.getSource('route')) map.removeSource('route');
        }
        _routeBounds = null;
    }

    function renderStepsList() {
        var html = '';
        _navSteps.forEach(function(step, i) {
            var isCurrent = i === _navCurrentStep;
            html += '<div class="nav-step-item' + (isCurrent ? ' current' : '') + (i < _navCurrentStep ? ' done' : '') + '">';
            html += '<div class="nav-step-icon">' + maneuverIcon(step.maneuver) + '</div>';
            html += '<div class="nav-step-info">';
            html += '<div class="nav-step-instruction">' + escapeHtml(step.instruction || 'Continue') + '</div>';
            html += '<div class="nav-step-meta">' + fmtDist(step.distance_m) + (step.name ? ' · ' + escapeHtml(step.name) : '') + '</div>';
            html += '</div></div>';
        });
        document.getElementById('navStepsList').innerHTML = html;
    }

    // Event listeners for navigation controls
    document.getElementById('directionsClose').addEventListener('click', clearDirections);

    document.getElementById('directionsStartNav').addEventListener('click', function() {
        startNavigation();
    });

    document.getElementById('navEndBtn').addEventListener('click', function() {
        endNavigation();
        showToast('Navigation ended', 'info');
    });

    document.getElementById('navStepsBtn').addEventListener('click', function() {
        renderStepsList();
        document.getElementById('navStepsPanel').classList.add('active');
    });

    document.getElementById('navStepsClose').addEventListener('click', function() {
        document.getElementById('navStepsPanel').classList.remove('active');
    });

    // Panel toggle
    var panel = document.getElementById('familyPanel');
    var panelToggleBtn = document.getElementById('panelToggleBtn');
    var panelCloseBtn = document.getElementById('familyPanelClose');
    var toolbar = document.getElementById('trackingToolbar');

    function togglePanel() {
        var isOpen = panel.style.display !== 'flex';
        panel.style.display = isOpen ? 'flex' : 'none';
        toolbar.style.display = isOpen ? 'none' : '';
    }

    if (panelToggleBtn) panelToggleBtn.addEventListener('click', togglePanel);
    if (panelCloseBtn) panelCloseBtn.addEventListener('click', togglePanel);

    // Toast helper
    function showToast(message, type) {
        var existing = document.querySelector('.tracking-toast');
        if (existing) existing.remove();
        var toast = document.createElement('div');
        toast.className = 'tracking-toast ' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.classList.add('show'); });
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 400);
        }, 3000);
    }

    var wakeFab = document.getElementById('wakeFab');
    var wakeLabel = wakeFab.querySelector('span');
    var wakeResult = document.getElementById('wakeResult');
    var liveWake = null;
    var wakePoll = null;
    var wakePollInFlight = false;
    var localWakeId = null;
    var wakeCooldownUntil = 0;
    var wakeStatuses = {
        queued: 'Sending request', sent: 'Waiting for phone', received: 'Request received',
        refreshed: 'Location updated', paused: 'Sharing off', permission: 'Needs location permission',
        notifications: 'Needs notifications enabled', open_required: 'Open app to refresh',
        no_fix: 'Couldn’t get a fix', no_reply: 'No reply', update_timeout: 'Location update timed out',
        send_failed: 'Couldn’t send',
        unreachable: 'Phone needs to reconnect', unavailable: 'Wake service unavailable',
        legacy_sent: 'Request sent · app update needed for confirmation'
    };

    async function wakeRequest(path, options) {
        var controller = new AbortController();
        var timeout = setTimeout(function() { controller.abort(); }, 15000);
        try {
            var response = await fetch(window.TrackingConfig.apiBase + path, Object.assign({
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal
            }, options || {}));
            return { response: response, body: await response.json() };
        } finally {
            clearTimeout(timeout);
        }
    }

    function makeWakeId() {
        if (crypto.randomUUID) return crypto.randomUUID();
        var bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function(n) { return n.toString(16).padStart(2, '0'); }).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function displayWake(message, targets) {
        wakeResult.replaceChildren(document.createTextNode(message));
        wakeResult.hidden = false;
        var detailCount = 0;
        (targets || []).forEach(function(target) {
            var row = document.createElement('div');
            row.textContent = target.name + ' · ' + (wakeStatuses[target.state] || 'Waiting for phone');
            wakeResult.appendChild(row);
            detailCount++;
        });
        wakeResult.classList.toggle('has-targets', detailCount > 0);
    }

    function setWakeBusy(busy) {
        wakeFab.disabled = busy;
        wakeLabel.textContent = busy ? 'Waking…' : 'Wake';
    }

    function handleWakeStatus(status) {
        var priorCount = liveWake ? liveWake.targets.filter(function(target) { return target.state === 'refreshed'; }).length : 0;
        liveWake = status;
        wakeCooldownUntil = performance.now() + status.remaining_ms;
        var count = status.targets.filter(function(target) { return target.state === 'refreshed'; }).length;
        var oldCount = status.targets.filter(function(target) { return target.state === 'legacy_sent'; }).length;
        var title = count ? count + (count === 1 ? ' location updated' : ' locations updated')
            : (!status.complete ? 'Wake requested' : (oldCount ? 'Wake request sent' : 'Wake finished'));
        displayWake(title, status.targets);
        if (count > priorCount) fetchMembers(true);
        if (status.complete) liveWake = null;
        setWakeBusy(!status.complete);
        scheduleWakePoll();
    }

    function scheduleWakePoll() {
        clearTimeout(wakePoll);
        if (liveWake && !document.hidden) wakePoll = setTimeout(checkWakeStatus, 5000);
    }

    async function checkWakeStatus() {
        if (document.hidden || !liveWake || wakePollInFlight) return;
        wakePollInFlight = true;
        var request = liveWake;
        try {
            var reply = await wakeRequest('/wake_status.php?request_id=' + encodeURIComponent(request.request_id));
            var response = reply.response;
            var body = reply.body;
            if (response.ok && body.success) {
                handleWakeStatus(body.data);
            } else if (response.status === 401 || response.status === 403) {
                clearFamilySnapshot();
                liveWake = null;
                setWakeBusy(false);
                displayWake('Sign in to check Wake status');
            } else {
                throw new Error('status_unavailable');
            }
        } catch (error) {
            if (performance.now() >= wakeCooldownUntil) {
                liveWake = null;
                setWakeBusy(false);
                displayWake('Couldn’t verify the Wake result', request.targets);
            }
        } finally {
            wakePollInFlight = false;
            scheduleWakePoll();
        }
    }

    var attemptId = null;
    wakeFab.addEventListener('click', async function() {
        if (wakeFab.disabled) return;
        if (performance.now() < wakeCooldownUntil) {
            showToast('Try Wake again in ' + Math.ceil((wakeCooldownUntil - performance.now()) / 1000) + 's', 'info');
            return;
        }
        setWakeBusy(true);
        try {
            attemptId = attemptId || makeWakeId();
            var reply = await wakeRequest('/wake_devices.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.TrackingConfig.wakeCsrf },
                body: JSON.stringify({ client_request_id: attemptId })
            });
            var response = reply.response;
            var body = reply.body;
            attemptId = null;
            if (response.status === 429) {
                var wait = body.retry_after || 60;
                wakeCooldownUntil = performance.now() + wait * 1000;
                displayWake('Try Wake again in ' + wait + 's');
            } else if (response.ok && body.success) {
                var data = body.data || {};
                if (data.request_id) {
                    handleWakeStatus(data);
                } else if (data.sent) {
                    wakeCooldownUntil = performance.now() + 60000;
                    displayWake('Wake requested · confirmation unavailable');
                } else {
                    displayWake(data.devices_targeted ? 'Couldn’t send Wake' : 'No family phones ready for Wake', data.targets);
                }
                var admitted = data.request_id && data.remaining_ms > 0 ? data.request_id : data.sent && !data.request_id ? 'legacy' : null;
                if (admitted && admitted !== localWakeId && nativeBridge) {
                    localWakeId = admitted;
                    nativeBridge.wakeAllDevices();
                }
            } else {
                var failureMessages = {
                    tracking_disabled: 'Family tracking is off',
                    wake_setup_required: 'Wake needs a server database update',
                    firebase_key_missing: 'Wake needs a Firebase key on the server',
                    firebase_key_unreadable: 'The server cannot read its Firebase key',
                    firebase_key_invalid: 'The server Firebase key is invalid',
                    firebase_php_extension_missing: 'Wake needs PHP cURL and OpenSSL on the server',
                    firebase_auth_rejected: 'Google rejected the server Firebase credentials',
                    firebase_auth_unavailable: 'The server could not reach Firebase authentication'
                };
                displayWake(failureMessages[body.reason] || failureMessages[body.error] || 'Wake temporarily unavailable');
            }
        } catch (error) {
            displayWake('Couldn’t reach the Wake service · tap Wake to retry');
        } finally {
            setWakeBusy(!!liveWake);
        }
    });

    // Consent dialog logic (localStorage can throw in privacy modes)
    var consentOverlay = document.getElementById('consentOverlay');
    var hasConsent = null;
    var browserShareLocation = true;
    try {
        hasConsent = localStorage.getItem('tracking_consent');
        browserShareLocation = localStorage.getItem('tracking_browser_location') !== '0';
    } catch (e) {}

    if (!hasConsent) {
        setTimeout(function() {
            consentOverlay.classList.add('active');
        }, 1500);
    }

    document.getElementById('consentAccept').addEventListener('click', function() {
        try { localStorage.setItem('tracking_consent', '1'); } catch (e) {}
        hasConsent = '1';
        consentOverlay.classList.remove('active');
        requestNotificationPermission();
        // Honour the "Location Sharing" toggle in the dialog. In the app the
        // OS permission flow already governs sharing, so this only gates the
        // browser upload path.
        if (!isNativeApp) {
            var shareLoc = document.getElementById('consentLocation');
            browserShareLocation = !shareLoc || shareLoc.checked;
            try { localStorage.setItem('tracking_browser_location', browserShareLocation ? '1' : '0'); } catch (e) {}
            if (browserShareLocation) startBrowserTracking();
            else stopBrowserTracking();
        }
    });

    document.getElementById('consentDecline').addEventListener('click', function() {
        try { localStorage.setItem('tracking_consent', '0'); } catch (e) {}
        hasConsent = '0';
        consentOverlay.classList.remove('active');
        // Stop the BROWSER upload if a watch was already running. In the app
        // the OS PermissionGate governs sharing, not this in-page dialog, so
        // don't tear down the native WebView upload path here.
        if (!isNativeApp) stopBrowserTracking();
    });

    // Notification permission
    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            setTimeout(function() {
                document.getElementById('notifPrompt').classList.add('active');
            }, 2000);
        }
    }

    document.getElementById('notifAllow').addEventListener('click', function() {
        Notification.requestPermission();
        document.getElementById('notifPrompt').classList.remove('active');
    });

    document.getElementById('notifDismiss').addEventListener('click', function() {
        document.getElementById('notifPrompt').classList.remove('active');
    });

    if (hasConsent === '1' && 'Notification' in window && Notification.permission === 'default') {
        requestNotificationPermission();
    }

    // Polling starts at the bottom of this script, after the native bridge
    // environment has been detected (see startPolling below).

    // ── TRACKING INTEGRATION ─────────────────────────────────────
    var nativeBridge = window.TrackingBridge;
    var isNativeApp = !!nativeBridge || /\bRelativesAndroid\//.test(navigator.userAgent);

    var webPollOwner = !nativeBridge || typeof nativeBridge.claimFamilyPolling === 'function';
    if (nativeBridge) {
        if (webPollOwner) nativeBridge.claimFamilyPolling();
        if (!document.hidden) nativeBridge.onTrackingScreenVisible();
        showNativeSharingButton();
        window.addEventListener('relativestrackingstate', function(event) {
            updateNativeSharingButton(event.detail);
            if (event.detail === 'signed_out') clearFamilySnapshot();
        });
        window.addEventListener('relativesfamilyclear', clearFamilySnapshot);
        window.addEventListener('relativestrackingresume', function() {
            if (webPollOwner) fetchMembers(true);
            if (liveWake) checkWakeStatus();
            updateNativeSharingButton();
        });
        window.addEventListener('beforeunload', function() { nativeBridge.onTrackingScreenHidden(); });
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) nativeBridge.onTrackingScreenHidden();
            else nativeBridge.onTrackingScreenVisible();
        });
    } else if (isNativeApp) {
        setOwnStatus('Update Android System WebView to enable live location sharing.');
    }

    // The native service owns location sharing in the Android app.
    // A standalone browser may share its position after the user consents.
    var lastUploadTime = 0;
    var uploadTimer = null;
    var latestPos = null;
    var browserWatchId = null;

    if (!isNativeApp && hasConsent === '1' && browserShareLocation) {
        startBrowserTracking();
    }

    function showNativeSharingButton() {
        var toolbar = document.querySelector('.tracking-toolbar');
        if (!toolbar) return;
        var button = document.createElement('button');
        button.id = 'nativeLocationSharing';
        button.type = 'button';
        button.className = 'tracking-toolbar-btn enable-location-btn';
        button.onclick = function() {
            if (['enabled', 'battery_saver', 'providers_disabled'].indexOf(nativeBridge.getTrackingMode()) >= 0) nativeBridge.stopTracking();
            else nativeBridge.startTracking();
            setTimeout(updateNativeSharingButton, 1000);
        };
        toolbar.insertBefore(button, toolbar.firstChild);
        if (typeof nativeBridge.refreshMyLocation === 'function') {
            var refresh = document.createElement('button');
            refresh.id = 'refreshMyLocation';
            refresh.type = 'button';
            refresh.className = 'tracking-toolbar-btn';
            refresh.textContent = 'Refresh my location';
            refresh.hidden = true;
            refresh.onclick = function() {
                nativeBridge.refreshMyLocation();
                showToast('Refreshing this phone…', 'info');
            };
            toolbar.appendChild(refresh);
        }
        updateNativeSharingButton();
    }

    function updateNativeSharingButton(nextMode) {
        var button = document.getElementById('nativeLocationSharing');
        if (!button) return;
        var mode = nextMode || nativeBridge.getTrackingMode();
        button.disabled = ['loading', 'signed_out', 'family_off', 'account_off'].includes(mode);
        var refresh = document.getElementById('refreshMyLocation');
        if (refresh) refresh.hidden = !['enabled', 'battery_saver', 'paused'].includes(mode) || nativeBridge.hasRemoteWake();
        var messages = {
            no_permission: 'Location permission is needed to share',
            notifications_disabled: 'Enable tracking notifications to share',
            providers_disabled: 'Turn on location on this phone',
            family_off: 'Family tracking is off',
            account_off: 'Enable sharing in Privacy & Security',
            signed_out: 'Sign in to share your location',
            battery_saver: 'Battery Saver · slower location updates'
        };
        nativeTrackingStatus = messages[mode] || '';
        if (nativeTrackingStatus && !localTrackingStatus) {
            document.getElementById('trackingStatus').textContent = nativeTrackingStatus;
        }
        button.textContent = ['enabled', 'battery_saver', 'providers_disabled'].indexOf(mode) >= 0 ? 'Stop sharing' : (mode === 'paused' ? 'Resume sharing' : 'Enable live location');
    }

    // ── BROWSER GEOLOCATION ──────────────────────────────────────
    // (State vars declared above the geolocation gate — see note there.)

    function uploadPosition(pos) {
        if (browserWatchId === null) return;
        // Always upload the FRESHEST fix: during the 10s throttle window,
        // watchPosition keeps firing; the old code captured the first fix and
        // discarded every newer one, so a moving user uploaded a stale point.
        if (pos) latestPos = pos;
        var now = Date.now();
        if (now - lastUploadTime < 10000) {
            if (uploadTimer === null) {
                uploadTimer = setTimeout(function() {
                    uploadTimer = null;
                    if (latestPos) uploadPosition(latestPos);
                }, 10000 - (now - lastUploadTime));
            }
            return;
        }
        lastUploadTime = now;
        pos = latestPos;

        var payload = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy_m: pos.coords.accuracy || null,
            speed_mps: pos.coords.speed || 0,
            bearing_deg: pos.coords.heading || null,
            altitude_m: pos.coords.altitude || null,
            recorded_at: new Date(pos.timestamp).toISOString().slice(0, 19).replace('T', ' '),
            platform: 'web',
            device_id: 'browser'
        };
        fetch(window.TrackingConfig.apiBase + '/location.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function(r) {
            return r.json().then(function(data) { return { status: r.status, data: data }; });
        }).then(function(res) {
            var data = res.data || {};
            if (data.message === 'tracking_disabled') {
                setOwnStatus('Family tracking is off');
                stopBrowserTracking();
            } else if (data.success) {
                fetchMembers();
            } else if (res.status === 403) {
                // location_sharing disabled server-side — stop trying and tell
                // the user, rather than silently failing forever.
                setOwnStatus('Location sharing is turned off for your account');
                stopBrowserTracking();
            } else {
                console.warn('[Tracking] Server rejected:', data.error);
            }
        }).catch(function(e) { console.warn('[Tracking] Upload failed:', e); });
    }

    // Surface a persistent note in the topbar subtitle when the user's own
    // position can't be shared, so they don't believe they're on the map.
    function setOwnStatus(msg) {
        var el = document.getElementById('trackingStatus');
        localTrackingStatus = msg;
        if (el) el.textContent = msg;
    }

    function startBrowserTracking() {
        if (document.hidden || isNativeApp || !navigator.geolocation || browserWatchId !== null || (mapPolicy && !mapPolicy.allowed)) return;
        browserWatchId = navigator.geolocation.watchPosition(uploadPosition, function(err) {
            console.warn('[Tracking] Geolocation error:', err.message);
            if (err.code === 1) { // PERMISSION_DENIED
                setOwnStatus('Location blocked — your position is not shared');
                stopBrowserTracking();
            }
            // TIMEOUT / POSITION_UNAVAILABLE: keep the watch, it may recover.
        }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 10000 });
        console.log('[Tracking] Browser geolocation started');
    }

    function stopBrowserTracking() {
        if (browserWatchId !== null) {
            navigator.geolocation.clearWatch(browserWatchId);
            browserWatchId = null;
        }
        if (uploadTimer !== null) {
            clearTimeout(uploadTimer);
            uploadTimer = null;
        }
        latestPos = null;
    }

    // The Android app fetches family positions while the map is visible.
    // Use the web API as a fallback if the native cache stops refreshing.
    var memberPollTimer = null;
    var bridgeCacheTimer = null;
    var legacyFallbackTimer = null;
    var lastBridgeJson = null;
    var lastFallbackAt = 0;
    var nativeActivationAt = 0;
    var hasCacheAge = !!nativeBridge && typeof nativeBridge.getFamilyCacheAgeMillis === 'function';

    // Old APK versions emit a different key set from getCachedFamily();
    // normalize so both shapes render.
    function normalizeCachedMember(m) {
        if (m.user_id !== undefined && m.has_location !== undefined) return m;
        var lat = m.lat !== undefined ? m.lat : m.latitude;
        var lng = m.lng !== undefined ? m.lng : m.longitude;
        return {
            user_id: parseInt(m.user_id !== undefined ? m.user_id : m.id, 10),
            name: m.name,
            avatar_color: m.avatar_color || m.color || '#667eea',
            has_avatar: !!m.has_avatar,
            has_location: lat !== null && lat !== undefined,
            lat: lat !== undefined ? lat : null,
            lng: lng !== undefined ? lng : null,
            accuracy_m: m.accuracy_m !== undefined ? m.accuracy_m : (m.accuracy !== undefined ? m.accuracy : null),
            speed_mps: m.speed_mps !== undefined ? m.speed_mps : (m.speed !== undefined ? m.speed : null),
            bearing_deg: m.bearing_deg !== undefined ? m.bearing_deg : null,
            altitude_m: m.altitude_m !== undefined ? m.altitude_m : null,
            motion_state: m.motion_state || 'unknown',
            recorded_at: m.recorded_at || null,
            updated_at: m.updated_at || null
        };
    }

    function readBridgeCache() {
        if (!nativeCacheAllowed) return;
        try {
            if (hasCacheAge && window.TrackingBridge.getFamilyCacheAgeMillis() > 45000) {
                var now = Date.now();
                if (now - nativeActivationAt >= 20000 && now - lastFallbackAt >= 60000) {
                    lastFallbackAt = now;
                    lastBridgeJson = null;
                    fetchMembers();
                }
                return;
            }

            var cachedJson = window.TrackingBridge.getCachedFamily();
            if (!cachedJson || cachedJson === lastBridgeJson) return;
            var cached = JSON.parse(cachedJson);
            if (Array.isArray(cached) && (hasCacheAge || cached.length > 0)) {
                lastBridgeJson = cachedJson;
                showMembers(cached.map(normalizeCachedMember));
            }
        } catch (e) {
            console.warn('[Tracking] Cache read error:', e);
        }
    }

    function startPolling() {
        stopPolling();
        if (document.hidden) return;
        if (webPollOwner || !nativeCacheAllowed) {
            fetchMembers(true);
            memberPollTimer = setInterval(fetchMembers, 15000);
            return;
        }
        nativeActivationAt = Date.now();
        readBridgeCache();
        bridgeCacheTimer = setInterval(readBridgeCache, 5000);
        // Refresh consent and link state even for bridge versions with an active native cache.
        legacyFallbackTimer = setTimeout(function() {
            if (document.hidden || !bridgeCacheTimer) return;
            fetchMembers();
            memberPollTimer = setInterval(fetchMembers, 30000);
        }, 20000);
    }

    function stopPolling() {
        clearTimeout(legacyFallbackTimer);
        if (memberPollTimer) { clearInterval(memberPollTimer); memberPollTimer = null; }
        if (bridgeCacheTimer) { clearInterval(bridgeCacheTimer); bridgeCacheTimer = null; }
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            startPolling();
            startNavigationWatch();
            if (!isNativeApp && hasConsent === '1' && browserShareLocation) startBrowserTracking();
            if (liveWake) checkWakeStatus();
        } else {
            stopPolling();
            if (mapRequest) mapRequest.abort();
            clearTimeout(wakePoll);
            stopBrowserTracking();
            stopNavigationWatch();
        }
    });

    window.addEventListener('online', function() {
        if (document.hidden) return;
        fetchMembers(true);
        if (liveWake) checkWakeStatus();
    });
    window.addEventListener('pagehide', function(event) {
        if (event.persisted) clearFamilySnapshot();
    });
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) startPolling();
    });
    startPolling();

})();
</script>
