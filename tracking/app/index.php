<?php
/**
 * ============================================
 * FAMILY TRACKING - MAIN DASHBOARD
 * Fullscreen map with family member panel
 * ============================================
 */
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Load tracking settings for the family
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$trackingCache = new TrackingCache($cache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$familyId = (int)$user['family_id'];
$settings = $settingsRepo->get($familyId);

// Load alert rules
$alertsRepo = new AlertsRepo($db);
$alertRules = $alertsRepo->get($familyId);

// Mapbox token from environment
$mapboxToken = $_ENV['MAPBOX_TOKEN'] ?? '';

$pageTitle = 'Family Tracking';
$pageCSS = [
    'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css',
    '/tracking/app/assets/css/tracking.css?v=3.3',
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
                <div class="tracking-topbar-title">Family Tracking</div>
                <div class="tracking-topbar-subtitle" id="trackingStatus">Connecting...</div>
            </div>
        </div>
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
            <div class="tracking-topbar-user" style="background:<?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>" title="<?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User'); ?>">
                <?php echo strtoupper(substr($user['name'] ?? $user['full_name'] ?? 'U', 0, 1)); ?>
            </div>
        </div>
    </div>

    <!-- Map Container -->
    <div id="trackingMap"></div>

    <!-- Family Members Panel -->
    <div class="family-panel" id="familyPanel">
        <div class="family-panel-header" id="familyPanelHeader">
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="family-panel-title">Family</span>
                <span class="family-panel-badge" id="memberCount">0</span>
            </div>
            <button class="family-panel-toggle" id="familyPanelToggle" title="Toggle panel">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
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

    <!-- Directions Info Bar -->
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
        </div>
        <button class="directions-close" id="directionsClose" title="Close directions">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>

</div><!-- .tracking-app -->

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
    window.TrackingConfig = {
        mapboxToken: <?php echo json_encode($mapboxToken); ?>,
        userId: <?php echo (int)$user['id']; ?>,
        familyId: <?php echo $familyId; ?>,
        userName: <?php echo json_encode($user['name'] ?? $user['full_name'] ?? 'User'); ?>,
        userRole: <?php echo json_encode($user['role']); ?>,
        avatarColor: <?php echo json_encode($user['avatar_color'] ?? '#667eea'); ?>,
        settings: <?php echo json_encode($settings); ?>,
        alertRules: <?php echo json_encode($alertRules); ?>,
        apiBase: '/tracking/api',
        isAdmin: <?php echo json_encode(in_array($user['role'], ['owner', 'admin'])); ?>
    };
</script>

<script src="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js"></script>

<?php
$pageJS = [];
require_once __DIR__ . '/../../shared/components/footer.php';
?>

<script src="/tracking/app/assets/js/state.js"></script>
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

    // Initialize Mapbox
    if (!window.TrackingConfig || !window.TrackingConfig.mapboxToken) {
        console.error('[Tracking] No Mapbox token configured');
        return;
    }

    mapboxgl.accessToken = window.TrackingConfig.mapboxToken;

    var mapStyle = 'mapbox://styles/mapbox/dark-v11';
    var savedStyle = (window.TrackingConfig.settings || {}).map_style;
    if (savedStyle === 'streets') mapStyle = 'mapbox://styles/mapbox/streets-v12';
    else if (savedStyle === 'satellite') mapStyle = 'mapbox://styles/mapbox/satellite-streets-v12';
    else if (savedStyle === 'light') mapStyle = 'mapbox://styles/mapbox/light-v11';

    var map = new mapboxgl.Map({
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

    window.trackingMap = map;

    // Force resize after layout settles
    setTimeout(function() { map.resize(); }, 100);
    setTimeout(function() { map.resize(); }, 500);

    // Markers storage
    var markers = {};

    // Fetch family members periodically
    function fetchMembers() {
        fetch(window.TrackingConfig.apiBase + '/current.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.data) {
                    _members = data.data;
                    renderMembers(_members);
                    updateMarkers(_members);
                }
            })
            .catch(function(err) {
                console.error('[Tracking] Fetch error:', err);
            });
    }

    function renderMembers(members) {
        var list = document.getElementById('memberList');
        var empty = document.getElementById('memberEmpty');
        var badge = document.getElementById('memberCount');
        var statusEl = document.getElementById('trackingStatus');

        if (!list || !empty || !badge) return;

        if (!members || members.length === 0) {
            empty.style.display = '';
            badge.textContent = '0';
            if (statusEl) statusEl.textContent = 'No members';
            return;
        }

        empty.style.display = 'none';
        badge.textContent = members.length;
        var online = members.filter(function(m) {
            if (!m.has_location) return false;
            return (Date.now() - parseUTC(m.recorded_at || m.updated_at)) < 300000;
        }).length;
        if (statusEl) statusEl.textContent = online + ' online \u00b7 ' + members.length + ' total';

        var html = '';
        members.forEach(function(m) {
            var initial = (m.name || 'U').charAt(0).toUpperCase();
            var hasLoc = m.has_location && m.lat !== null;
            var statusClass = hasLoc ? getStatusClass(m) : 'offline';
            var timeAgo = hasLoc ? formatTimeAgo(m.recorded_at || m.updated_at) : 'No location';
            var speed = hasLoc ? formatSpeed(m.speed_mps) : '';

            html += '<div class="member-item" data-user-id="' + m.user_id + '"' + (hasLoc ? ' onclick="flyToMember(' + m.user_id + ')"' : '') + '>';
            html += '  <div class="member-avatar" style="background:' + (m.avatar_color || '#667eea') + '">';
            html += '    <span>' + initial + '</span>';
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
        var activeIds = {};
        members.forEach(function(m) {
            // Skip members without a location
            if (!m.has_location || m.lat === null || m.lng === null) return;

            activeIds[m.user_id] = true;
            var lngLat = [parseFloat(m.lng), parseFloat(m.lat)];
            if (markers[m.user_id]) {
                markers[m.user_id].setLngLat(lngLat);
            } else {
                var el = document.createElement('div');
                el.className = 'map-marker';
                var initial = (m.name || 'U').charAt(0).toUpperCase();
                el.innerHTML = '<div class="map-marker-inner" style="background:' + (m.avatar_color || '#667eea') + '">' + initial + '</div>';
                markers[m.user_id] = new mapboxgl.Marker({ element: el })
                    .setLngLat(lngLat)
                    .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(
                        '<strong>' + escapeHtml(m.name || 'Unknown') + '</strong><br>' +
                        '<span style="font-size:12px;opacity:0.7">' + formatTimeAgo(m.recorded_at || m.updated_at) + '</span>'
                    ))
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

    // Parse MySQL datetime as UTC (server stores all timestamps in UTC)
    function parseUTC(dateStr) {
        if (!dateStr) return 0;
        return new Date(dateStr.replace(' ', 'T') + 'Z').getTime();
    }

    function getStatusClass(m) {
        var ts = parseUTC(m.recorded_at || m.updated_at);
        var diffMin = (Date.now() - ts) / 60000;
        if (diffMin < 5) return 'online';
        if (diffMin < 30) return 'idle';
        return 'offline';
    }

    function formatTimeAgo(dateStr) {
        if (!dateStr) return 'unknown';
        var diff = (Date.now() - parseUTC(dateStr)) / 1000;
        if (diff < 0) diff = 0;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
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

    // Fly to member
    window.flyToMember = function(userId) {
        var m = _members.find(function(x) { return x.user_id == userId; });
        if (m) {
            map.flyTo({ center: [parseFloat(m.lng), parseFloat(m.lat)], zoom: 16, duration: 1500 });

            document.querySelectorAll('.member-item').forEach(function(el) {
                el.classList.toggle('active', el.dataset.userId == userId);
            });

            if (markers[userId]) {
                markers[userId].togglePopup();
            }
        }
    };

    // Get directions to member
    window.getDirections = function(userId) {
        if (!navigator.geolocation) return;
        var m = _members.find(function(x) { return x.user_id == userId; });
        if (!m) return;

        navigator.geolocation.getCurrentPosition(function(pos) {
            var url = window.TrackingConfig.apiBase + '/directions.php' +
                '?from_lat=' + pos.coords.latitude +
                '&from_lng=' + pos.coords.longitude +
                '&to_lat=' + m.lat +
                '&to_lng=' + m.lng;

            fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        showDirections(data.data);
                    }
                })
                .catch(function(err) {
                    console.error('[Directions] Error:', err);
                });
        });
    };

    function showDirections(route) {
        var bar = document.getElementById('directionsBar');
        var distM = route.distance_m || 0;
        var durS = route.duration_s || 0;
        document.getElementById('directionsDistance').textContent = distM >= 1000 ? (distM / 1000).toFixed(1) + ' km' : Math.round(distM) + ' m';
        document.getElementById('directionsDuration').textContent = durS >= 3600 ? Math.floor(durS / 3600) + 'h ' + Math.floor((durS % 3600) / 60) + 'm' : Math.floor(durS / 60) + ' min';
        bar.classList.add('active');

        if (route.geometry) {
            if (map.getSource('route')) {
                map.getSource('route').setData({ type: 'Feature', geometry: route.geometry });
            } else {
                map.addSource('route', {
                    type: 'geojson',
                    data: { type: 'Feature', geometry: route.geometry }
                });
                map.addLayer({
                    id: 'route',
                    type: 'line',
                    source: 'route',
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: { 'line-color': '#667eea', 'line-width': 4, 'line-opacity': 0.8 }
                });
            }
        }
    }

    document.getElementById('directionsClose').addEventListener('click', function() {
        document.getElementById('directionsBar').classList.remove('active');
        if (map.getLayer('route')) map.removeLayer('route');
        if (map.getSource('route')) map.removeSource('route');
    });

    // Panel toggle
    var panel = document.getElementById('familyPanel');
    var panelToggle = document.getElementById('familyPanelToggle');
    var panelToggleBtn = document.getElementById('panelToggleBtn');
    var panelHeader = document.getElementById('familyPanelHeader');
    var isMobile = window.innerWidth <= 768;

    function togglePanel() {
        if (isMobile) {
            panel.classList.toggle('expanded');
        } else {
            panel.classList.toggle('collapsed');
        }
    }

    panelToggle.addEventListener('click', togglePanel);
    if (panelToggleBtn) panelToggleBtn.addEventListener('click', togglePanel);

    if (isMobile) {
        panelHeader.addEventListener('click', function(e) {
            if (e.target === panelHeader || e.target.classList.contains('family-panel-title')) {
                panel.classList.toggle('expanded');
            }
        });
    }

    // Wake FAB
    var wakeFab = document.getElementById('wakeFab');
    wakeFab.addEventListener('click', function() {
        fetch(window.TrackingConfig.apiBase + '/wake_devices.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ family_id: window.TrackingConfig.familyId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                wakeFab.classList.add('tracking-active');
                setTimeout(function() { wakeFab.classList.remove('tracking-active'); }, 5000);
            }
        })
        .catch(function() {});
    });

    // Consent dialog logic
    var consentOverlay = document.getElementById('consentOverlay');
    var hasConsent = localStorage.getItem('tracking_consent');

    if (!hasConsent) {
        setTimeout(function() {
            consentOverlay.classList.add('active');
        }, 1500);
    }

    document.getElementById('consentAccept').addEventListener('click', function() {
        localStorage.setItem('tracking_consent', '1');
        consentOverlay.classList.remove('active');
        requestNotificationPermission();
    });

    document.getElementById('consentDecline').addEventListener('click', function() {
        localStorage.setItem('tracking_consent', '0');
        consentOverlay.classList.remove('active');
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

    // Initial fetch then poll
    map.on('load', function() {
        map.resize();
        fetchMembers();
        var pollInterval = ((window.TrackingConfig.settings || {}).keepalive_interval_seconds || 30) * 1000;
        setInterval(fetchMembers, Math.max(pollInterval, 10000));
    });

    // ── TRACKING INTEGRATION ─────────────────────────────────────
    // Detect environment: Android app (TrackingBridge) vs regular browser
    var isNativeApp = typeof window.TrackingBridge !== 'undefined';
    console.log('[Tracking] Environment:', isNativeApp ? 'Android App (native bridge)' : 'Browser');

    if (isNativeApp) {
        // ── NATIVE ANDROID APP ──────────────────────────────────
        // Use the native TrackingBridge for GPS (much better accuracy)
        console.log('[Tracking] Native bridge detected');

        // Tell native side the tracking screen is visible (triggers fast polling)
        try { window.TrackingBridge.onTrackingScreenVisible(); } catch(e) {}

        // Check tracking mode and start if needed
        var mode = 'unknown';
        try { mode = window.TrackingBridge.getTrackingMode(); } catch(e) {}
        console.log('[Tracking] Native tracking mode:', mode);

        if (mode === 'no_permission' || mode === 'disabled') {
            // Auto-start native tracking (triggers PermissionGate → TrackingService)
            console.log('[Tracking] Starting native tracking...');
            try { window.TrackingBridge.startTracking(); } catch(e) {
                console.warn('[Tracking] Failed to start native tracking:', e);
            }
        }

        // Load cached family data immediately from native store
        try {
            var cachedJson = window.TrackingBridge.getCachedFamily();
            if (cachedJson) {
                var cached = JSON.parse(cachedJson);
                if (cached && cached.length > 0) {
                    console.log('[Tracking] Loaded', cached.length, 'cached members from native');
                    _members = cached;
                    renderMembers(_members);
                    updateMarkers(_members);
                }
            }
        } catch(e) { console.warn('[Tracking] Cache read error:', e); }

        // Notify when leaving tracking screen
        window.addEventListener('beforeunload', function() {
            try { window.TrackingBridge.onTrackingScreenHidden(); } catch(e) {}
        });

        // Also use browser geolocation in WebView as backup upload
        if (navigator.geolocation) {
            startBrowserTracking();
        }
    } else {
        // ── REGULAR BROWSER ─────────────────────────────────────
        // Use browser geolocation API (lower accuracy on desktop)
        if (navigator.geolocation) {
            startBrowserTracking();
        }
    }

    // ── BROWSER GEOLOCATION (works in both WebView and browser) ──
    var lastUploadTime = 0;
    var uploadPending = false;

    function uploadPosition(pos) {
        var now = Date.now();
        if (now - lastUploadTime < 10000) {
            if (!uploadPending) {
                uploadPending = true;
                setTimeout(function() { uploadPending = false; uploadPosition(pos); }, 10000 - (now - lastUploadTime));
            }
            return;
        }
        lastUploadTime = now;

        var payload = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy_m: pos.coords.accuracy || null,
            speed_mps: pos.coords.speed || 0,
            bearing_deg: pos.coords.heading || null,
            altitude_m: pos.coords.altitude || null,
            recorded_at: new Date(pos.timestamp).toISOString().slice(0, 19).replace('T', ' '),
            platform: isNativeApp ? 'android-webview' : 'web',
            device_id: isNativeApp ? 'android' : 'browser'
        };
        fetch(window.TrackingConfig.apiBase + '/location.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.success) {
                console.log('[Tracking] Position uploaded:', payload.lat, payload.lng, 'accuracy:', payload.accuracy_m + 'm');
                fetchMembers();
            } else {
                console.warn('[Tracking] Server rejected:', data.error);
            }
        }).catch(function(e) { console.warn('[Tracking] Upload failed:', e); });
    }

    function startBrowserTracking() {
        if (!navigator.geolocation) return;
        navigator.geolocation.watchPosition(uploadPosition, function(err) {
            console.warn('[Tracking] Geolocation error:', err.message);
        }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 10000 });
    }

})();
</script>
