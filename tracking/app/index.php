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
$alertsRepo = new AlertsRepo($db, $trackingCache);
$alertRules = $alertsRepo->get($familyId);

// Mapbox token from environment
$mapboxToken = $_ENV['MAPBOX_TOKEN'] ?? '';

$pageTitle = 'Family Tracking';
$pageCSS = [
    'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css',
    '/tracking/app/assets/css/tracking.css?v=4.3',
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

    // Initialize Mapbox
    if (!window.TrackingConfig || !window.TrackingConfig.mapboxToken) {
        console.error('[Tracking] No Mapbox token configured');
        return;
    }

    mapboxgl.accessToken = window.TrackingConfig.mapboxToken;

    var mapStyles = {
        dark:      'mapbox://styles/mapbox/dark-v11',
        streets:   'mapbox://styles/mapbox/streets-v12',
        satellite: 'mapbox://styles/mapbox/satellite-streets-v12',
        light:     'mapbox://styles/mapbox/light-v11'
    };
    var styleOrder = ['dark', 'streets', 'satellite', 'light'];
    var currentStyleKey = (window.TrackingConfig.settings || {}).map_style || 'dark';
    if (!mapStyles[currentStyleKey]) currentStyleKey = 'dark';
    var mapStyle = mapStyles[currentStyleKey];

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

    // Map style toggle
    document.getElementById('mapStyleBtn').addEventListener('click', function() {
        var idx = styleOrder.indexOf(currentStyleKey);
        currentStyleKey = styleOrder[(idx + 1) % styleOrder.length];
        map.setStyle(mapStyles[currentStyleKey]);
        showToast('Map: ' + currentStyleKey.charAt(0).toUpperCase() + currentStyleKey.slice(1), 'info');
        // Persist to server
        fetch(window.TrackingConfig.apiBase + '/settings_save.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ map_style: currentStyleKey })
        }).catch(function(e) { console.warn('[MapStyle] Save failed:', e); });
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

    // Fetch family members periodically
    function fetchMembers() {
        fetch(window.TrackingConfig.apiBase + '/current.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.data) {
                    _members = data.data;
                    renderMembers(_members);
                    updateMarkers(_members);
                    if (!initialFitDone) {
                        initialFitDone = true;
                        fitMapToFamily(_members);
                    }
                }
            })
            .catch(function(err) {
                console.error('[Tracking] Fetch error:', err);
            });
    }

    function fitMapToFamily(members) {
        var pts = members.filter(function(m) { return m.has_location && m.lat !== null && m.lng !== null; });
        if (pts.length === 0) return;
        if (pts.length === 1) {
            map.flyTo({ center: [parseFloat(pts[0].lng), parseFloat(pts[0].lat)], zoom: 15, duration: 1000 });
            return;
        }
        var bounds = new mapboxgl.LngLatBounds();
        pts.forEach(function(m) { bounds.extend([parseFloat(m.lng), parseFloat(m.lat)]); });
        map.fitBounds(bounds, { padding: 80, maxZoom: 16, duration: 1000 });
    }

    function renderMembers(members) {
        var list = document.getElementById('memberList');
        var empty = document.getElementById('memberEmpty');
        var badge = document.getElementById('memberCount');
        var statusEl = document.getElementById('trackingStatus');

        if (!list || !empty) return;

        if (!members || members.length === 0) {
            empty.style.display = '';
            if (badge) badge.textContent = '0';
            if (statusEl) statusEl.textContent = 'No members';
            return;
        }

        empty.style.display = 'none';
        if (badge) badge.textContent = members.length;
        var withLocation = members.filter(function(m) {
            return m.has_location && m.lat !== null;
        }).length;
        if (statusEl) statusEl.textContent = withLocation + ' tracking \u00b7 ' + members.length + ' total';

        var html = '';
        members.forEach(function(m) {
            var initial = (m.name || 'U').charAt(0).toUpperCase();
            var hasLoc = m.has_location && m.lat !== null;
            var statusClass = hasLoc ? getStatusClass(m) : 'offline';
            var timeAgo = hasLoc ? formatTimeAgo(m.updated_at || m.recorded_at) : 'No location';
            var speed = hasLoc ? formatSpeed(m.speed_mps) : '';

            html += '<div class="member-item" data-user-id="' + m.user_id + '"' + (hasLoc ? ' onclick="flyToMember(' + m.user_id + ')"' : '') + '>';
            html += '  <div class="member-avatar" style="background:' + (m.avatar_color || '#667eea') + '">';
            if (m.has_avatar) {
                html += '    <img src="/saves/' + m.user_id + '/avatar/avatar.webp" alt="" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\'">';
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
                    el.innerHTML = '<div class="map-marker-inner map-marker-avatar" style="background:' + (m.avatar_color || '#667eea') + '">' +
                        '<img src="/saves/' + m.user_id + '/avatar/avatar.webp" alt="" onerror="this.style.display=\'none\';this.parentNode.textContent=\'' + initial + '\'">' +
                        '</div>';
                } else {
                    el.innerHTML = '<div class="map-marker-inner" style="background:' + (m.avatar_color || '#667eea') + '">' + initial + '</div>';
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

    // Parse MySQL datetime as UTC (server stores all timestamps in UTC)
    function parseUTC(dateStr) {
        if (!dateStr) return 0;
        return new Date(dateStr.replace(' ', 'T') + 'Z').getTime();
    }

    function getStatusClass(m) {
        var ts = parseUTC(m.updated_at || m.recorded_at);
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
        var statusLabel = status === 'online' ? 'Online' : status === 'idle' ? 'Idle' : 'Offline';
        var statusColor = status === 'online' ? '#43e97b' : status === 'idle' ? '#f9d423' : '#718096';
        var timeAgo = formatTimeAgo(m.updated_at || m.recorded_at);
        var speed = formatSpeed(m.speed_mps);
        var motionState = m.motion_state || 'unknown';
        var initial = (m.name || 'U').charAt(0).toUpperCase();

        var s = '<div style="min-width:200px;font-family:inherit">';

        // Header row: avatar + name + status
        s += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
        s += '<div style="width:36px;height:36px;min-width:36px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;background:' + (m.avatar_color || '#667eea') + '">';
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
        if (!geometry) return;
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

        // Start watching position for auto-advance
        if (navigator.geolocation) {
            _navWatchId = navigator.geolocation.watchPosition(onNavPosition, function(e) {
                console.warn('[Nav] Geolocation error:', e.message);
            }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 5000 });
        }

        // Zoom to first step area
        if (_navSteps[0] && _navSteps[0].location) {
            map.flyTo({
                center: _navSteps[0].location,
                zoom: 17,
                pitch: 45,
                duration: 1000
            });
        }
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
        map.easeTo({ center: [lng, lat], duration: 500 });

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
            }

            // Check if arrived (last step and within 50m)
            if (_navCurrentStep === _navSteps.length - 1 && dist < 50) {
                showToast('You have arrived!', 'success');
                endNavigation();
            }
        }
    }

    function endNavigation() {
        _navActive = false;

        if (_navWatchId !== null) {
            navigator.geolocation.clearWatch(_navWatchId);
            _navWatchId = null;
        }

        document.getElementById('navHeader').classList.remove('active');
        document.getElementById('navBottom').classList.remove('active');
        document.getElementById('navStepsPanel').classList.remove('active');
        document.getElementById('trackingToolbar').style.display = '';

        // Clean up route
        if (map.getLayer('route')) map.removeLayer('route');
        if (map.getSource('route')) map.removeSource('route');
        _routeBounds = null;
        _navRoute = null;
        _navSteps = [];

        map.easeTo({ pitch: 0, duration: 500 });
    }

    function clearDirections() {
        document.getElementById('directionsBar').classList.remove('active');
        if (_navActive) endNavigation();
        if (map.getLayer('route')) map.removeLayer('route');
        if (map.getSource('route')) map.removeSource('route');
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

    // Wake FAB
    var wakeFab = document.getElementById('wakeFab');
    var wakeLabel = wakeFab ? wakeFab.querySelector('span') : null;
    if (wakeFab) wakeFab.addEventListener('click', function() {
        if (wakeFab.disabled) return;
        wakeFab.disabled = true;
        if (wakeLabel) wakeLabel.textContent = 'Sending...';

        fetch(window.TrackingConfig.apiBase + '/wake_devices.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ family_id: window.TrackingConfig.familyId })
        })
        .then(function(r) {
            if (!r.ok) throw new Error('Server ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (data.success) {
                wakeFab.classList.add('active');
                if (wakeLabel) wakeLabel.textContent = 'Sent!';
                showToast('Wake signal sent to your family', 'success');
                setTimeout(function() {
                    wakeFab.classList.remove('active');
                    wakeFab.disabled = false;
                    if (wakeLabel) wakeLabel.textContent = 'Wake';
                }, 3000);
            } else {
                showToast('Failed: ' + (data.error || 'unknown'), 'error');
                wakeFab.disabled = false;
                if (wakeLabel) wakeLabel.textContent = 'Wake';
            }
        })
        .catch(function(err) {
            console.error('[Wake] Error:', err);
            showToast('Wake failed: ' + err.message, 'error');
            wakeFab.disabled = false;
            if (wakeLabel) wakeLabel.textContent = 'Wake';
        });
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
        setInterval(fetchMembers, 10000);
    });

    // ── TRACKING INTEGRATION ─────────────────────────────────────
    // Detect environment: Android app (TrackingBridge) vs regular browser
    var isNativeApp = typeof window.TrackingBridge !== 'undefined';
    console.log('[Tracking] Environment:', isNativeApp ? 'Android App (native bridge)' : 'Browser');

    if (isNativeApp) {
        // ── NATIVE ANDROID APP ──────────────────────────────────
        // The native TrackingService handles GPS + upload via batch.php.
        // WebView should NOT start its own geolocation — it conflicts
        // with the native GPS lock and causes timeouts / low-accuracy fallback.
        console.log('[Tracking] Native bridge detected — letting native handle GPS');

        // Tell native side the tracking screen is visible (triggers fast polling)
        try { window.TrackingBridge.onTrackingScreenVisible(); } catch(e) {}

        // Check tracking mode
        var mode = 'unknown';
        try { mode = window.TrackingBridge.getTrackingMode(); } catch(e) {}
        console.log('[Tracking] Native tracking mode:', mode);

        if (mode !== 'enabled') {
            console.log('[Tracking] Tracking not enabled, showing enable button');
            showEnableLocationButton();
        } else {
            console.log('[Tracking] Native tracking active — no browser GPS needed');
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

        // Listen for visibility changes to toggle native polling speed
        document.addEventListener('visibilitychange', function() {
            try {
                if (document.visibilityState === 'visible') {
                    window.TrackingBridge.onTrackingScreenVisible();
                } else {
                    window.TrackingBridge.onTrackingScreenHidden();
                }
            } catch(e) {}
        });

    } else {
        // ── REGULAR BROWSER ─────────────────────────────────────
        // No native app — use browser geolocation for location tracking
        if (navigator.geolocation) {
            startBrowserTracking();
        }
    }

    // Show an "Enable Live Location" button in the tracking toolbar
    function showEnableLocationButton() {
        var toolbar = document.querySelector('.tracking-toolbar');
        if (!toolbar) return;
        var btn = document.createElement('button');
        btn.className = 'tracking-toolbar-btn enable-location-btn';
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" style="margin-right:6px"><circle cx="12" cy="12" r="3"></circle><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 110-16 8 8 0 010 16z"></path></svg> Enable Live Location';
        btn.style.cssText = 'background:#667eea;color:#fff;border:none;padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;';
        btn.onclick = function() {
            try {
                window.TrackingBridge.startTracking();
                btn.textContent = 'Enabling...';
                setTimeout(function() {
                    try {
                        var newMode = window.TrackingBridge.getTrackingMode();
                        if (newMode === 'enabled') {
                            btn.remove();
                            showToast('Live location enabled', 'success');
                        } else {
                            btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" style="margin-right:6px"><circle cx="12" cy="12" r="3"></circle><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 110-16 8 8 0 010 16z"></path></svg> Enable Live Location';
                        }
                    } catch(e) {}
                }, 3000);
            } catch(e) {
                console.warn('[Tracking] startTracking failed:', e);
            }
        };
        toolbar.insertBefore(btn, toolbar.firstChild);
    }

    // ── BROWSER GEOLOCATION (only for web browsers, NOT native app) ──
    var lastUploadTime = 0;
    var uploadPending = false;

    function uploadPosition(pos) {
        var now = Date.now();
        // Use settings interval or default 30s for browser
        var minInterval = ((window.TrackingConfig.settings || {}).moving_interval_seconds || 30) * 1000;
        if (minInterval < 10000) minInterval = 10000; // floor at 10s

        if (now - lastUploadTime < minInterval) {
            if (!uploadPending) {
                uploadPending = true;
                setTimeout(function() { uploadPending = false; uploadPosition(pos); }, minInterval - (now - lastUploadTime));
            }
            return;
        }
        lastUploadTime = now;

        // Reject very inaccurate readings
        var minAccuracy = (window.TrackingConfig.settings || {}).min_accuracy_m || 100;
        if (pos.coords.accuracy > minAccuracy) {
            console.log('[Tracking] Skipping inaccurate position:', pos.coords.accuracy + 'm >', minAccuracy + 'm');
            return;
        }

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
        }, { enableHighAccuracy: false, timeout: 60000, maximumAge: 15000 });
        console.log('[Tracking] Browser geolocation started');
    }

})();
</script>
