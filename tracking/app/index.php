<?php
/**
 * ============================================
 * FAMILY TRACKING - MAIN DASHBOARD
 * Fullscreen map with family member panel
 * ============================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';

$setupRequested = ($_GET['setup'] ?? '') === '1';
$mapPath = $setupRequested ? '/tracking/app/?setup=1' : '/tracking/app/';
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . rawurlencode($mapPath));
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: /login.php?redirect=' . rawurlencode($mapPath));
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
        <button type="button" class="tracking-toolbar-btn wake-btn" id="wakeFab" title="Refresh family locations">
            <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            <span>Wake</span>
        </button>
        <button class="tracking-toolbar-btn" id="panelToggleBtn" title="Toggle family panel">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <span>Family</span>
        </button>
    </div>

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

<?php if ($setupRequested): ?>
<dialog class="tracking-setup-dialog" id="trackingSetupDialog" aria-labelledby="trackingSetupTitle">
    <h2 id="trackingSetupTitle">Set up family tracking</h2>
    <p id="trackingSetupDescription" aria-live="polite">Checking location sharing…</p>
    <form class="tracking-setup-actions" method="dialog">
        <button class="family-button family-button-primary" id="trackingSetupStart" type="button" hidden></button>
        <a class="family-button family-button-primary" id="trackingSetupSettings" hidden></a>
        <button class="family-button" type="submit">Close</button>
    </form>
</dialog>
<?php endif; ?>

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
        liveAvailable: <?php echo json_encode(FamilyLive::available($db)); ?>,
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
            updateAccuracyAreas(_members);
        });
        map.on('zoomend', function() { updateMarkers(_members); });

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
            updateAccuracyAreas(_members);
            // Re-draw route if active
            if (_routeBounds && _navRoute && _navRoute.geometry) {
                drawRouteOnMap(_navRoute.geometry);
            }
        });
    });

    // Markers storage
    var markers = {};
    var memberMarkers = {};
    var initialFitDone = false;

    function showMembers(members) {
        _members = members;
        renderMembers(_members);
        updateMarkers(_members);
        updateAccuracyAreas(_members);
        if (!initialFitDone && fitMapToFamily(_members)) initialFitDone = true;
    }

    var mapRequest = null;
    var mapFetchAgain = false;
    var nativeCacheAllowed = true;
    var mapPolicy = null;
    var serverTimeAnchor = null;
    function serverNow() {
        return serverTimeAnchor ? serverTimeAnchor.time + performance.now() - serverTimeAnchor.elapsed : Date.now();
    }
    var localTrackingStatus = '';
    var nativeTrackingStatus = '';

    function clearFamilySnapshot() {
        nativeCacheAllowed = false;
        if (mapRequest) mapRequest.abort();
        wakeRound = null;
        clearTimeout(wakeTimer);
        wakeFab.removeAttribute('aria-busy');
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
                updateTrackingSetup('signed_out');
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
                serverTimeAnchor = { time: mapPolicy.server_time_ms, elapsed: performance.now() };
            }
            localTrackingStatus = mapPolicy && mapPolicy.mode === 0 ? 'Family tracking is off' : '';
            if (!isNativeApp) {
                if (mapPolicy && !mapPolicy.allowed) stopBrowserTracking();
                else resumeBrowserTracking();
                updateBrowserShareButton();
            }
            showMembers(data.data);
            scheduleMemberPoll();
            automaticallyRefreshFamily();
        } catch (error) {
            if (!document.hidden && error.name !== 'AbortError') {
                setOwnStatus(_members.length ? 'Connection lost · showing last known locations' : 'Waiting for a connection');
                if (!mapPolicy) updateTrackingSetup('connection_lost');
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
            var statusClass = getStatusClass(m);
            var timeAgo = hasLoc ? liveAgeMarkup(m)
                : (m.location_sharing === false ? 'Sharing off' : 'No location yet');
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
            var phoneStatus = livePhoneText(m);
            if (phoneStatus) html += '<div class="member-meta" data-phone-status="' + m.user_id + '">' + phoneStatus + '</div>';
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
        var groups = [];
        memberMarkers = {};
        members.filter(function(member) {
            return member.has_location && member.lat !== null && member.lng !== null;
        }).sort(function(a, b) { return a.user_id - b.user_id; }).forEach(function(member) {
            var coordinates = [Number(member.lng), Number(member.lat)];
            var pixel = map.project(coordinates);
            var group = groups.find(function(candidate) { return pixel.dist(candidate.pixel) < 30; });
            if (group) group.members.push(member);
            else groups.push({ coordinates: coordinates, pixel: pixel, members: [member] });
        });

        var active = new Set();
        groups.forEach(function(group) {
            var first = group.members[0];
            var key = group.members.map(function(member) { return member.user_id; }).join('-');
            active.add(key);
            group.members.forEach(function(member) { memberMarkers[member.user_id] = key; });
            var popupHTML = group.members.length === 1 ? buildPopupHTML(first)
                : '<ul class="tracking-marker-members">' + group.members.map(function(member) {
                    return '<li><strong>' + escapeHtml(member.name || 'Member') + '</strong><br>' + liveAgeMarkup(member) + '</li>';
                }).join('') + '</ul>';
            if (markers[key]) {
                animateMarker(markers[key], group.coordinates);
                markers[key].getPopup().setHTML(popupHTML);
                return;
            }

            var element = document.createElement('button');
            element.type = 'button';
            element.className = 'map-marker';
            element.setAttribute('aria-label', group.members.map(function(member) { return member.name; }).join(', '));
            var inner = document.createElement('span');
            inner.className = 'map-marker-inner';
            inner.style.background = safeColor(first.avatar_color);
            inner.textContent = group.members.length > 1 ? group.members.length : (first.name || 'U').charAt(0);
            if (group.members.length === 1 && first.has_avatar) {
                var avatar = document.createElement('img');
                avatar.alt = '';
                avatar.src = '/saves/' + first.user_id + '/avatar/avatar.webp?v=' + (window.avatarVersion || 0);
                avatar.addEventListener('load', function() {
                    inner.classList.add('map-marker-avatar');
                    inner.replaceChildren(avatar);
                }, { once: true });
            }
            element.appendChild(inner);
            markers[key] = new mapboxgl.Marker({ element: element, anchor: 'center' })
                .setLngLat(group.coordinates)
                .setPopup(new mapboxgl.Popup({ offset: 24, maxWidth: '260px' }).setHTML(popupHTML))
                .addTo(map);
        });
        Object.keys(markers).forEach(function(key) {
            if (active.has(key)) return;
            cancelAnimationFrame(markers[key].animationFrame);
            markers[key].remove();
            delete markers[key];
        });
    }

    function updateAccuracyAreas(members) {
        if (!map || !map.isStyleLoaded()) return;
        var features = members.filter(function(member) {
            return member.has_location && member.approximate && Number(member.accuracy_m) > 0;
        }).map(function(member) {
            var lng = Number(member.lng);
            var lat = Number(member.lat);
            var radius = Number(member.accuracy_m) / 111195;
            var longitudeScale = Math.max(0.01, Math.cos(lat * Math.PI / 180));
            var ring = Array.from({ length: 40 }, function(_, index) {
                var angle = index * 2 * Math.PI / 40;
                return [lng + radius * Math.cos(angle) / longitudeScale, lat + radius * Math.sin(angle)];
            });
            ring.push(ring[0]);
            return {
                type: 'Feature',
                properties: { color: safeColor(member.avatar_color) },
                geometry: { type: 'Polygon', coordinates: [ring] }
            };
        });
        var data = { type: 'FeatureCollection', features: features };
        var existing = map.getSource('tracking-accuracy');
        if (existing) {
            existing.setData(data);
            return;
        }
        map.addSource('tracking-accuracy', { type: 'geojson', data: data });
        map.addLayer({
            id: 'tracking-accuracy',
            type: 'fill',
            source: 'tracking-accuracy',
            paint: { 'fill-color': ['get', 'color'], 'fill-outline-color': ['get', 'color'], 'fill-opacity': 0.2 }
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
        if (liveViewer?.lease && m.location_sharing) {
            var seen = m.live_phone?.last_seen_ms;
            if (seen) return serverNow() - seen < 60000 ? 'online' : 'offline';
            return m.live_phone?.state === 'starting' ? 'idle' : 'offline';
        }
        if (!m.has_location) return 'offline';
        var ageSecs = (serverNow() - captureTime(m)) / 1000;
        var expect = mapPolicy && (m.motion_state === 'moving' ? mapPolicy.moving_interval : mapPolicy.idle_interval);
        if (ageSecs <= Math.max(m.motion_state === 'moving' ? 60 : 180, 2 * (expect || 0))) {
            return m.motion_state === 'idle' ? 'idle' : 'online';
        }
        return 'offline';
    }

    function formatTimeAgo(timestamp) {
        if (!timestamp) return 'Time unavailable';
        var diff = Math.max(0, (serverNow() - timestamp) / 1000);
        if (diff < 60) return Math.floor(diff) + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function liveAgeMarkup(member) {
        return '<span data-location-age="' + member.user_id + '">' + memberAgeText(member) + '</span>';
    }

    function memberAgeText(member) {
        return (liveViewer?.lease ? 'Location ' : (getStatusClass(member) === 'offline' ? 'Last known · ' : '')) + formatTimeAgo(captureTime(member))
            + (member.approximate ? ' · Approximate' : '');
    }

    function livePhoneText(member) {
        if (!liveViewer?.lease || !member.location_sharing) return '';
        var phone = member.live_phone;
        if (phone?.last_seen_ms) {
            var live = serverNow() - phone.last_seen_ms < 60000;
            var status = live ? 'Live' : 'Delayed';
            if (live && Number.isInteger(phone.phones) && Number.isInteger(phone.live_phones) && phone.phones > 1) {
                status += ' (' + phone.live_phones + ' of ' + phone.phones + ' phones)';
            }
            return status +
                ' · Phone checked in ' + formatTimeAgo(phone.last_seen_ms);
        }
        if (phone?.state === 'open_required') return 'Open app to join';
        if (phone?.state === 'starting' && performance.now() - liveViewer.started < 60000) return 'Starting';
        return phone ? 'Waiting for phone' : 'Open Tracking to join';
    }

    function updateLocationAges() {
        document.querySelectorAll('[data-location-age]').forEach(function(element) {
            var member = _members.find(function(item) { return String(item.user_id) === element.dataset.locationAge; });
            if (!member) return;
            var label = memberAgeText(member);
            if (element.textContent !== label) element.textContent = label;
            var dot = element.closest('.member-item')?.querySelector('.member-status-dot');
            if (dot) dot.className = 'member-status-dot ' + getStatusClass(member);
        });
        document.querySelectorAll('[data-phone-status]').forEach(function(element) {
            var member = _members.find(function(item) { return String(item.user_id) === element.dataset.phoneStatus; });
            if (!member) return;
            var label = livePhoneText(member);
            if (element.textContent !== label) element.textContent = label;
            var dot = element.closest('.member-item')?.querySelector('.member-status-dot');
            if (dot) dot.className = 'member-status-dot ' + getStatusClass(member);
        });
    }

    function animateMarker(marker, targetLngLat) {
        if (marker.animationTarget?.[0] === targetLngLat[0] && marker.animationTarget?.[1] === targetLngLat[1]) return;
        cancelAnimationFrame(marker.animationFrame);
        marker.animationTarget = targetLngLat;
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
            if (t < 1) marker.animationFrame = requestAnimationFrame(step);
        }
        marker.animationFrame = requestAnimationFrame(step);
    }

    function buildPopupHTML(m) {
        var status = getStatusClass(m);
        var statusColor = status === 'online' ? '#43e97b' : status === 'idle' ? '#f9d423' : '#718096';
        var timeAgo = liveAgeMarkup(m);
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
        s += '<div style="display:flex;align-items:center;gap:5px;font-size:11px;opacity:0.7;margin-top:1px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + statusColor + ';flex-shrink:0"></span>' + timeAgo + '</div>';
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

            var marker = markers[memberMarkers[userId]];
            if (marker) marker.togglePopup();
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
    var wakeRound = null;
    var wakeTimer = null;
    var automaticWakePending = false;
    var nextAutomaticWake = 0;

    function makeWakeId() {
        if (crypto.randomUUID) return crypto.randomUUID();
        var bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function(n) { return n.toString(16).padStart(2, '0'); }).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    async function wakeRequest(path, options) {
        var controller = new AbortController();
        var timeout = setTimeout(function() { controller.abort(); }, 8000);
        try {
            var response = await fetch(window.TrackingConfig.apiBase + path, Object.assign({
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal
            }, options));
            if (response.status === 401 || response.status === 403) clearFamilySnapshot();
            if (!response.ok) return null;
            var reply = await response.json();
            return reply.success ? reply.data : null;
        } catch (error) {
            return null;
        } finally {
            clearTimeout(timeout);
        }
    }

    function ownMember() {
        return _members.find(function(member) { return member.user_id === window.TrackingConfig.userId; });
    }

    function refreshOwnPhone() {
        if (nativeBridge && ['enabled', 'battery_saver', 'approximate'].includes(nativeBridge.getTrackingMode())) {
            var refresh = nativeBridge.refreshMyLocation || nativeBridge.wakeAllDevices;
            if (refresh) {
                refresh.call(nativeBridge);
                return true;
            }
        }
        if (isNativeApp || browserWatchId === null || !browserConsent) return false;
        var watch = browserWatchId;
        navigator.geolocation.getCurrentPosition(function(position) {
            if (browserWatchId !== watch || !browserConsent || !mapPolicy?.allowed) return;
            if (!latestPos || position.timestamp >= latestPos.timestamp) {
                lastUploadTime = 0;
                uploadPosition(position);
            }
        }, function() {}, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
        return true;
    }

    function setWakeStatus(round, status) {
        if (wakeRound !== round || !status?.request_id) return;
        round.requestId = status.request_id;
        round.remoteDone = status.complete;
        round.remoteUntil = performance.now() + status.remaining_ms;
        var confirmed = JSON.stringify((status.targets || []).filter(function(target) {
            return target.state === 'refreshed';
        }).map(function(target) { return [target.user_id, target.recorded_at_ms]; }));
        if (confirmed !== round.confirmed) {
            round.confirmed = confirmed;
            if (confirmed !== '[]') fetchMembers(true);
        }
    }

    function scheduleWakeCheck() {
        clearTimeout(wakeTimer);
        if (!wakeRound || document.hidden) return;
        wakeTimer = setTimeout(checkWakeStatus, 2000);
    }

    async function checkWakeStatus() {
        var round = wakeRound;
        if (!round || round.checking || document.hidden) return;
        round.checking = true;
        var now = performance.now();
        var own = ownMember();
        if (round.localUntil > now && (!own || captureTime(own) < round.localAfter)) fetchMembers(true);
        else round.localUntil = 0;
        if (round.requestId && !round.remoteDone && now < round.remoteUntil) {
            var status = await wakeRequest('/wake_status.php?request_id=' + encodeURIComponent(round.requestId));
            setWakeStatus(round, status);
        }
        if (wakeRound !== round) return;
        round.checking = false;
        if (!round.posting && !round.localUntil && (round.remoteDone || performance.now() >= round.remoteUntil)) {
            wakeRound = null;
            wakeFab.removeAttribute('aria-busy');
        } else {
            scheduleWakeCheck();
        }
    }

    async function refreshFamily(mode) {
        if (document.hidden || (mapPolicy && mapPolicy.mode === 0)) return;
        var manual = mode === 'manual';
        if (wakeRound?.posting) {
            if (manual && !wakeRound.manual) wakeRound.upgrade = true;
            return;
        }
        if (wakeRound && (!manual || wakeRound.manual)) return;

        var round = wakeRound || { localUntil: 0, localAfter: 0, remoteUntil: 0, confirmed: '[]' };
        wakeRound = round;
        round.manual = manual;
        round.posting = true;
        var own = ownMember();
        var now = serverNow();
        var staleAfter = own?.motion_state === 'moving'
            ? 1000 * Math.min(45, Math.max(15, mapPolicy?.moving_interval || 30)) : 90000;
        var legacyOnly = !manual && window.TrackingConfig.liveAvailable === true;
        var localUsesLive = legacyOnly && (!isNativeApp || typeof nativeBridge?.joinLiveTracking === 'function');
        if (!localUsesLive && !round.localUntil && (manual || !own?.has_location || own.approximate || now - captureTime(own) > staleAfter)
            && refreshOwnPhone()) {
            round.localAfter = now;
            round.localUntil = performance.now() + 28000;
        }
        if (manual || round.localUntil) wakeFab.setAttribute('aria-busy', 'true');
        var status = await wakeRequest('/wake_devices.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.TrackingConfig.wakeCsrf },
            body: JSON.stringify(Object.assign({ client_request_id: makeWakeId(), mode: mode,
                refreshing_local: !!round.localUntil && !!nativeBridge }, legacyOnly ? { legacy_only: true } : {}))
        });
        if (wakeRound !== round) return;
        round.posting = false;
        setWakeStatus(round, status);
        if (round.upgrade) {
            round.upgrade = false;
            return refreshFamily('manual');
        }
        if (!round.remoteDone && round.requestId) wakeFab.setAttribute('aria-busy', 'true');
        fetchMembers(true);
        await checkWakeStatus();
    }

    function automaticallyRefreshFamily() {
        if (!automaticWakePending) return;
        automaticWakePending = false;
        if (performance.now() < nextAutomaticWake) return;
        nextAutomaticWake = performance.now() + 60000;
        refreshFamily('automatic');
    }

    wakeFab.addEventListener('click', function() { refreshFamily('manual'); });

    // ── TRACKING INTEGRATION ─────────────────────────────────────
    var nativeBridge = window.TrackingBridge;
    var isNativeApp = !!nativeBridge || /\bRelativesAndroid(?:\/|App\b)/.test(navigator.userAgent);
    var setupDialog = document.getElementById('trackingSetupDialog');
    var activeNativeModes = ['enabled', 'battery_saver', 'approximate'];

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
            if (wakeRound) checkWakeStatus();
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

    var lastUploadTime = 0;
    var liveViewer = null;
    var lastBrowserPulse = null;
    var uploadTimer = null;
    var latestPos = null;
    var browserWatchId = null;
    var browserUpload = null;
    var browserConsent = false;
    var browserShareButton = null;
    var browserConsentKey = 'relatives.shareLocation.' + window.TrackingConfig.userId;
    var browserDeviceId = 'browser';
    var browserShareScope = null;
    if (!isNativeApp && window.crypto?.getRandomValues) {
        browserDeviceId = 'web-' + makeWakeId();
        try {
            var storedDeviceId = localStorage.getItem('relatives.locationDevice');
            if (/^web-[a-f0-9-]{36}$/.test(storedDeviceId || '')) browserDeviceId = storedDeviceId;
            else localStorage.setItem('relatives.locationDevice', browserDeviceId);
        } catch (error) {}
    }
    if (!isNativeApp && navigator.geolocation) {
        try {
            browserConsent = localStorage.getItem(browserConsentKey) === '1';
            browserShareScope = localStorage.getItem(browserConsentKey + '.scope');
        } catch (error) {
            // Sharing still works for this page when storage is unavailable.
        }
        browserShareButton = document.createElement('button');
        browserShareButton.type = 'button';
        browserShareButton.className = 'tracking-toolbar-btn enable-location-btn';
        browserShareButton.title = 'Share your location with your family while this tab is visible';
        browserShareButton.addEventListener('click', function() {
            browserConsent = browserWatchId === null;
            if (browserConsent) {
                browserShareScope = makeWakeId();
                startBrowserTracking();
            } else {
                browserShareScope = null;
                stopBrowserTracking();
            }
            try {
                localStorage.setItem(browserConsentKey, browserConsent ? '1' : '0');
                if (browserShareScope) localStorage.setItem(browserConsentKey + '.scope', browserShareScope);
                else localStorage.removeItem(browserConsentKey + '.scope');
            } catch (error) {}
            updateBrowserShareButton();
        });
        document.getElementById('trackingToolbar').appendChild(browserShareButton);
        updateBrowserShareButton();
    }

    function updateBrowserShareButton() {
        if (!browserShareButton) return;
        browserShareButton.disabled = !mapPolicy || !mapPolicy.allowed;
        browserShareButton.textContent = browserWatchId === null ? 'Share my location' : 'Stop sharing';
        browserShareButton.setAttribute('aria-pressed', String(browserWatchId !== null));
        updateTrackingSetup();
    }

    async function resumeBrowserTracking() {
        if (!browserConsent || browserWatchId !== null || !mapPolicy || !mapPolicy.allowed) return;
        try {
            var permission = await navigator.permissions?.query({ name: 'geolocation' });
            if (permission?.state === 'granted' && browserConsent) startBrowserTracking();
        } catch (error) {
            // Browsers with no permission query can start sharing from the button.
        }
    }

    function showNativeSharingButton() {
        var toolbar = document.querySelector('.tracking-toolbar');
        if (!toolbar) return;
        var button = document.createElement('button');
        button.id = 'nativeLocationSharing';
        button.type = 'button';
        button.className = 'tracking-toolbar-btn enable-location-btn';
        button.onclick = function() {
            var mode = nativeBridge.getTrackingMode();
            if (activeNativeModes.includes(mode) || mode === 'providers_disabled') nativeBridge.stopTracking();
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
                if (refreshOwnPhone()) {
                    wakeRound = wakeRound || { remoteUntil: 0, confirmed: '[]' };
                    wakeRound.localAfter = serverNow();
                    wakeRound.localUntil = performance.now() + 28000;
                    scheduleWakeCheck();
                }
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
        if (refresh) refresh.hidden = !activeNativeModes.includes(mode);
        var messages = {
            no_permission: 'Location permission is needed to share',
            notifications_disabled: 'Enable tracking notifications to share',
            providers_disabled: 'Turn on location on this phone',
            family_off: 'Family tracking is off',
            account_off: 'Enable sharing in Privacy & Security',
            signed_out: 'Sign in to share your location',
            approximate: 'Approximate location · enable Precise location for accurate sharing',
            battery_saver: 'Battery Saver · slower location updates'
        };
        nativeTrackingStatus = messages[mode] || '';
        if (nativeTrackingStatus && !localTrackingStatus) {
            document.getElementById('trackingStatus').textContent = nativeTrackingStatus;
        }
        button.textContent = activeNativeModes.includes(mode) || mode === 'providers_disabled'
            ? 'Stop sharing' : (mode === 'paused' ? 'Resume sharing' : 'Enable live location');
        updateTrackingSetup(mode);
    }

    function trackingSetupState(mode) {
        if (mode === 'signed_out' || mode === 'connection_lost') return mode;
        var familyMode = mapPolicy?.mode ?? Number(window.TrackingConfig.settings.mode);
        if (familyMode === 0 || mode === 'family_off') return 'family_off';
        if (mapPolicy?.allowed === false || mode === 'account_off') return 'account_off';
        if (isNativeApp && !nativeBridge) return 'missing_bridge';
        if (!isNativeApp && !browserShareButton) return 'unsupported';
        if (!mapPolicy) return 'loading';
        if (nativeBridge) {
            if (activeNativeModes.includes(mode)) return 'sharing';
            if (['disabled', 'paused', 'no_permission', 'notifications_disabled'].includes(mode)) return 'android';
            return mode || 'loading';
        }
        return browserWatchId === null ? 'browser' : 'browser_sharing';
    }

    function updateTrackingSetup(mode) {
        if (!setupDialog?.open) return;
        var state = trackingSetupState(mode);
        var descriptions = {
            family_off: 'An owner or admin can turn on Family Tracking by setting the family mode to Normal.',
            account_off: 'Turn on Location Sharing in Privacy & Security, then return here to finish setup.',
            signed_out: 'Sign in to Relatives on this phone to share your location.',
            connection_lost: 'Connect to the internet to check whether this phone can share its location.',
            providers_disabled: 'Turn on Location in this phone’s Settings to send updates to your family.',
            sharing: 'This phone is sharing with your family. You can stop sharing in Tracking or from your Location Tracking notification.',
            android: 'Allow location and the Location Tracking notification when asked. Precise location improves your map pin. Choose Allow all the time to receive family refresh requests while the app is closed.',
            browser: 'Allow location when your browser asks. Your location updates while the map is open and visible.',
            browser_sharing: 'This browser is sharing with your family while the map is visible.',
            missing_bridge: 'Update Android System WebView to enable live location sharing.',
            unsupported: 'Open Family Tracking in a browser that supports location access.',
        };
        document.getElementById('trackingSetupDescription').textContent = descriptions[state] || 'Checking location sharing…';
        var start = document.getElementById('trackingSetupStart');
        start.hidden = state !== 'android' && state !== 'browser';
        start.textContent = state === 'browser' ? 'Share my location' : (mode === 'paused' ? 'Resume sharing' : 'Enable live location');
        var settings = document.getElementById('trackingSetupSettings');
        settings.hidden = true;
        if (state === 'account_off') {
            settings.href = '/profile/privacy.php#locationSharing';
            settings.textContent = 'Open Privacy & Security';
            settings.hidden = false;
        } else if (state === 'family_off' && window.TrackingConfig.isAdmin) {
            settings.href = '/tracking/app/settings.php#settingMode';
            settings.textContent = 'Open tracking settings';
            settings.hidden = false;
        }
        return state;
    }

    if (setupDialog && typeof setupDialog.showModal === 'function') {
        document.getElementById('trackingSetupStart').addEventListener('click', function() {
            var state = updateTrackingSetup(nativeBridge?.getTrackingMode());
            if (state === 'android') {
                nativeBridge.startTracking();
                setTimeout(updateNativeSharingButton, 1000);
            } else if (state === 'browser' && !browserShareButton.disabled) {
                browserShareButton.click();
            } else {
                return;
            }
            setupDialog.close();
        });
        setupDialog.showModal();
        updateTrackingSetup(nativeBridge?.getTrackingMode());
    }

    // ── BROWSER GEOLOCATION ──────────────────────────────────────
    // (State vars declared above the geolocation gate — see note there.)

    function uploadPosition(pos) {
        if (browserWatchId === null || document.hidden) return;
        // Always upload the FRESHEST fix: during the 10s throttle window,
        // watchPosition keeps firing; the old code captured the first fix and
        // discarded every newer one, so a moving user uploaded a stale point.
        if (pos && (!latestPos || pos.timestamp >= latestPos.timestamp)) latestPos = pos;
        if (!latestPos) return;
        if (window.TrackingConfig.liveAvailable) {
            if (liveViewer) scheduleBrowserPulse(liveViewer);
            return;
        }
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
        if (browserUpload) return;
        lastUploadTime = now;
        pos = latestPos;
        var upload = new AbortController();
        browserUpload = upload;

        var payload = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy_m: pos.coords.accuracy || null,
            speed_mps: pos.coords.speed || 0,
            bearing_deg: pos.coords.heading || null,
            altitude_m: pos.coords.altitude || null,
            recorded_at_ms: pos.timestamp,
            platform: 'web',
            device_id: browserDeviceId,
            consent_scope: browserShareScope
        };
        fetch(window.TrackingConfig.apiBase + '/location.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload), signal: upload.signal
        }).then(function(r) {
            return r.json().then(function(data) { return { status: r.status, data: data }; });
        }).then(function(res) {
            if (upload.signal.aborted) return;
            var data = res.data || {};
            if (data.error === 'tracking_disabled') {
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
        }).catch(function(e) {
            if (e.name !== 'AbortError') setOwnStatus('Connection lost · sharing will resume when online');
        }).finally(function() {
            if (browserUpload === upload) browserUpload = null;
        });
    }

    // Surface a persistent note in the topbar subtitle when the user's own
    // position can't be shared, so they don't believe they're on the map.
    function setOwnStatus(msg) {
        var el = document.getElementById('trackingStatus');
        localTrackingStatus = msg;
        if (el) el.textContent = msg;
    }

    function startBrowserTracking() {
        if (document.hidden || isNativeApp || !mapPolicy?.allowed || !navigator.geolocation || browserWatchId !== null) return;
        browserWatchId = navigator.geolocation.watchPosition(uploadPosition, function(err) {
            console.warn('[Tracking] Geolocation error:', err.message);
            if (err.code === 1) { // PERMISSION_DENIED
                setOwnStatus('Enable location in your browser settings to share');
                stopBrowserTracking();
            }
            // TIMEOUT / POSITION_UNAVAILABLE: keep the watch, it may recover.
        }, { enableHighAccuracy: !window.TrackingConfig.liveAvailable, timeout: 30000, maximumAge: 10000 });
        if (liveViewer) scheduleBrowserPulse(liveViewer);
        updateBrowserShareButton();
    }

    function stopBrowserTracking() {
        if (liveViewer) {
            clearTimeout(liveViewer.browserTimer);
            liveViewer.browserTimer = null;
        }
        if (browserWatchId !== null) {
            navigator.geolocation.clearWatch(browserWatchId);
            browserWatchId = null;
        }
        if (uploadTimer !== null) {
            clearTimeout(uploadTimer);
            uploadTimer = null;
        }
        if (browserUpload) {
            browserUpload.abort();
            browserUpload = null;
        }
        latestPos = null;
        updateBrowserShareButton();
    }

    // The Android app fetches family positions while the map is visible.
    // Use the web API as a fallback if the native cache stops refreshing.
    var memberPollTimer = null;
    var bridgeCacheTimer = null;
    var ageTimer = null;
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

    function liveViewerOptions(viewer, action) {
        return {
            method: 'POST', keepalive: action === 'leave',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.TrackingConfig.wakeCsrf },
            body: JSON.stringify({ viewer_id: viewer.id, action: action })
        };
    }

    function leaveLiveViewer(viewer) {
        fetch(window.TrackingConfig.apiBase + '/live_session.php', Object.assign({
            credentials: 'same-origin'
        }, liveViewerOptions(viewer, 'leave'))).catch(function() {});
    }

    async function pulseBrowser(viewer) {
        if (isNativeApp || document.hidden || browserWatchId === null || !mapPolicy?.allowed ||
            !viewer.lease || !browserDeviceId.startsWith('web-')) return;
        var id = viewer.lease.id;
        var now = performance.now();
        var previous = lastBrowserPulse?.session === id ? lastBrowserPulse : null;
        var pulse = { session: id, sentAt: now, fixAt: previous?.fixAt || 0 };
        lastBrowserPulse = pulse;
        var fix = latestPos;
        var locations = [];
        if (fix && fix.timestamp > pulse.fixAt && Date.now() - fix.timestamp < 60000) {
            locations.push({
                lat: fix.coords.latitude, lng: fix.coords.longitude, accuracy_m: fix.coords.accuracy,
                speed_mps: fix.coords.speed, bearing_deg: fix.coords.heading, altitude_m: fix.coords.altitude,
                recorded_at_ms: fix.timestamp, platform: 'web', device_id: browserDeviceId,
                consent_scope: browserShareScope
            });
        }
        var reply = await wakeRequest('/batch.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ locations: locations,
                live_pulse: { session_id: id, device_id: browserDeviceId.slice(4) } })
        });
        if (reply?.results?.[0]?.status === 'stored' && lastBrowserPulse === pulse) pulse.fixAt = fix.timestamp;
    }

    function scheduleBrowserPulse(viewer) {
        if (isNativeApp || liveViewer !== viewer || document.hidden || browserWatchId === null ||
            !mapPolicy?.allowed || !browserDeviceId.startsWith('web-') || !viewer.lease ||
            viewer.browserTimer != null) return;
        var now = performance.now();
        var last = lastBrowserPulse?.session === viewer.lease.id ? lastBrowserPulse.sentAt : -Infinity;
        viewer.browserTimer = setTimeout(function() {
            viewer.browserTimer = null;
            if (liveViewer !== viewer || performance.now() >= viewer.expiresAt) return;
            pulseBrowser(viewer);
            scheduleBrowserPulse(viewer);
        }, Math.max(0, last + viewer.lease.pulse_ms - now));
    }

    async function renewLiveViewer(viewer) {
        if (liveViewer !== viewer || document.hidden || viewer.pending) return;
        viewer.pending = true;
        var data = await wakeRequest('/live_session.php', liveViewerOptions(viewer, 'view'));
        viewer.pending = false;
        if (liveViewer !== viewer || document.hidden) {
            leaveLiveViewer(viewer);
            return;
        }
        if (data && data.session?.id !== viewer.lease?.id) {
            viewer.started = performance.now();
            viewer.lease = data.session;
            renderMembers(_members);
            fetchMembers(true);
        } else if (data?.session) {
            viewer.lease = data.session;
        }
        if (data?.session) {
            viewer.expiresAt = performance.now() + data.session.remaining_ms;
            scheduleBrowserPulse(viewer);
            if (typeof nativeBridge?.joinLiveTracking === 'function' && viewer.nativeSession !== data.session.id) {
                viewer.nativeSession = data.session.id;
                nativeBridge.joinLiveTracking();
            }
        }
    }

    function startPolling() {
        stopPolling(true);
        if (document.hidden) return;
        automaticWakePending = true;
        if (!liveViewer && window.TrackingConfig.liveAvailable && window.crypto?.getRandomValues) {
            var viewer = { id: makeWakeId(), lease: null };
            liveViewer = viewer;
            viewer.timer = setInterval(function() { renewLiveViewer(viewer); }, 30000);
            renewLiveViewer(viewer);
        }
        if (!webPollOwner && nativeCacheAllowed) {
            nativeActivationAt = Date.now();
            readBridgeCache();
            bridgeCacheTimer = setInterval(readBridgeCache, 5000);
        }
        fetchMembers(true);
        scheduleMemberPoll();
        ageTimer = setInterval(updateLocationAges, 1000);
    }

    function scheduleMemberPoll() {
        clearTimeout(memberPollTimer);
        if (document.hidden) return;
        var recentMovement = _members.some(function(member) {
            return member.has_location && member.motion_state === 'moving' && serverNow() - captureTime(member) < 45000;
        });
        memberPollTimer = setTimeout(async function() {
            await fetchMembers();
            if (!memberPollTimer || document.hidden) return;
            scheduleMemberPoll();
        }, recentMovement ? 5000 : 15000);
    }

    function stopPolling(restarting) {
        if (!restarting && liveViewer) {
            var viewer = liveViewer;
            liveViewer = null;
            clearInterval(viewer.timer);
            clearTimeout(viewer.browserTimer);
            leaveLiveViewer(viewer);
        }
        clearInterval(ageTimer);
        if (memberPollTimer) { clearTimeout(memberPollTimer); memberPollTimer = null; }
        if (bridgeCacheTimer) { clearInterval(bridgeCacheTimer); bridgeCacheTimer = null; }
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            startPolling();
            startNavigationWatch();
            if (!isNativeApp) resumeBrowserTracking();
            if (wakeRound) checkWakeStatus();
        } else {
            stopPolling();
            if (mapRequest) mapRequest.abort();
            clearTimeout(wakeTimer);
            stopBrowserTracking();
            stopNavigationWatch();
        }
    });

    window.addEventListener('online', function() {
        if (document.hidden) return;
        if (liveViewer) renewLiveViewer(liveViewer);
        fetchMembers(true);
        if (wakeRound) checkWakeStatus();
    });
    window.addEventListener('pagehide', function(event) {
        stopPolling();
        stopBrowserTracking();
        if (event.persisted) clearFamilySnapshot();
    });
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) startPolling();
    });
    startPolling();

})();
</script>
