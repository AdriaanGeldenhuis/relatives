<?php
/**
 * ============================================
 * FAMILY TRACKING - GEOFENCE MANAGEMENT
 * List, create, edit, and delete geofences
 * ============================================
 */
declare(strict_types=1);

session_name('RELATIVES_SESSION');
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

require_once __DIR__ . '/../core/bootstrap_tracking.php';

$trackingCache = new TrackingCache($cache);
$familyId = (int)$user['family_id'];
$isAdmin = in_array($user['role'], ['owner', 'admin']);

// Load geofences
$geoRepo = new GeofenceRepo($db, $trackingCache);
$geofences = $geoRepo->listAll($familyId);

$mapboxToken = $_ENV['MAPBOX_TOKEN'] ?? '';

$pageTitle = 'Geofences';
$pageCSS = ['/tracking/app/assets/css/tracking.css?v=3.7'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<link href="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css" rel="stylesheet">

<div class="tracking-content">

    <a href="/tracking/app/" class="tracking-back">Back to Map</a>

    <div class="tracking-content-header">
        <div>
            <h1 class="tracking-content-title">Geofences</h1>
            <p class="tracking-content-subtitle">Set up zones to get alerts when family members enter or leave.</p>
        </div>
        <?php if ($isAdmin): ?>
        <button class="tracking-add-btn" id="addGeofenceBtn">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Geofence
        </button>
        <?php endif; ?>
    </div>

    <!-- Add Geofence Form (hidden by default) -->
    <?php if ($isAdmin): ?>
    <div class="geofence-form trk-hidden" id="geofenceForm">
        <h3 class="geofence-form-title" id="geofenceFormTitle">Add New Geofence</h3>

        <div class="form-group">
            <label class="form-label">Name</label>
            <input type="text" class="form-input" id="gfName" placeholder="e.g. Home, School, Office" maxlength="100">
        </div>

        <div class="form-group">
            <label class="form-label">Type</label>
            <div class="geofence-type-selector">
                <div class="geofence-type-option active" data-type="circle" id="typeCircle">
                    <div class="geofence-type-badge circle" style="margin:0 auto 6px"></div>
                    Circle
                </div>
                <div class="geofence-type-option" data-type="polygon" id="typePolygon">
                    <div class="geofence-type-badge polygon" style="margin:0 auto 6px"></div>
                    Polygon
                </div>
            </div>
        </div>

        <div class="form-group" id="radiusGroup">
            <label class="form-label">Radius (meters)</label>
            <input type="number" class="form-input" id="gfRadius" value="200" min="50" max="50000" step="50">
        </div>

        <div class="form-group">
            <label class="form-label">
                Location
                <span class="form-label-desc">Click on the map to place the geofence center</span>
            </label>
            <div class="geofence-form-map" id="geofenceFormMap"></div>
            <input type="hidden" id="gfLat">
            <input type="hidden" id="gfLng">
            <input type="hidden" id="gfPolygonJson">
            <input type="hidden" id="gfEditId">
        </div>

        <div class="consent-actions">
            <button class="consent-btn consent-btn-secondary" id="gfCancel">Cancel</button>
            <button class="consent-btn consent-btn-primary" id="gfSave">Save Geofence</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Geofence List -->
    <div class="geofence-list" id="geofenceList">
        <?php if (empty($geofences)): ?>
            <div class="tracking-empty">
                <div class="tracking-empty-icon"></div>
                <div class="tracking-empty-title">No geofences yet</div>
                <div class="tracking-empty-text">Create geofences to get alerts when family members enter or leave specific areas.</div>
            </div>
        <?php else: ?>
            <?php foreach ($geofences as $gf): ?>
            <div class="geofence-card" data-id="<?php echo (int)$gf['id']; ?>">
                <div class="geofence-card-header">
                    <div class="geofence-card-name">
                        <span class="geofence-type-badge <?php echo e($gf['type']); ?>"></span>
                        <?php echo e($gf['name']); ?>
                        <span class="geofence-active-badge <?php echo $gf['active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $gf['active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="geofence-card-actions">
                        <button class="geofence-action-btn edit-gf-btn"
                                data-id="<?php echo (int)$gf['id']; ?>"
                                data-name="<?php echo e($gf['name']); ?>"
                                data-type="<?php echo e($gf['type']); ?>"
                                data-lat="<?php echo e((string)$gf['center_lat']); ?>"
                                data-lng="<?php echo e((string)$gf['center_lng']); ?>"
                                data-radius="<?php echo e((string)$gf['radius_m']); ?>"
                                data-polygon="<?php echo e($gf['polygon_json'] ?? ''); ?>"
                                data-active="<?php echo $gf['active'] ? '1' : '0'; ?>"
                                title="Edit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="geofence-action-btn delete delete-gf-btn"
                                data-id="<?php echo (int)$gf['id']; ?>"
                                data-name="<?php echo e($gf['name']); ?>"
                                title="Delete">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="geofence-card-meta">
                    <?php if ($gf['type'] === 'circle'): ?>
                        <span class="geofence-card-meta-item">Radius: <?php echo number_format((float)$gf['radius_m']); ?>m</span>
                    <?php endif; ?>
                    <span class="geofence-card-meta-item">Created: <?php echo date('M j, Y', strtotime($gf['created_at'])); ?></span>
                </div>
                <div class="geofence-card-map" id="gfMap<?php echo (int)$gf['id']; ?>"
                     data-lat="<?php echo e((string)$gf['center_lat']); ?>"
                     data-lng="<?php echo e((string)$gf['center_lng']); ?>"
                     data-type="<?php echo e($gf['type']); ?>"
                     data-radius="<?php echo e((string)$gf['radius_m']); ?>"
                     data-polygon="<?php echo e($gf['polygon_json'] ?? ''); ?>">
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
    window.TrackingConfig = window.TrackingConfig || {};
    window.TrackingConfig.mapboxToken = <?php echo json_encode($mapboxToken); ?>;
    window.TrackingConfig.apiBase = '/tracking/api';
    window.TrackingConfig.familyId = <?php echo $familyId; ?>;
    window.TrackingConfig.isAdmin = <?php echo json_encode($isAdmin); ?>;
</script>

<script src="https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js"></script>

<?php
$pageJS = [
    '/tracking/app/assets/js/state.js',
];
require_once __DIR__ . '/../../shared/components/footer.php';
?>

<script>
(function() {
    'use strict';

    var token = window.TrackingConfig.mapboxToken;
    if (!token) return;

    mapboxgl.accessToken = token;

    // Initialize mini-maps for each geofence card
    document.querySelectorAll('.geofence-card-map').forEach(function(el) {
        var lat = parseFloat(el.dataset.lat);
        var lng = parseFloat(el.dataset.lng);
        var type = el.dataset.type;
        var radius = parseFloat(el.dataset.radius) || 200;

        if (!lat || !lng) return;

        var miniMap = new mapboxgl.Map({
            container: el,
            style: 'mapbox://styles/mapbox/dark-v11',
            center: [lng, lat],
            zoom: type === 'circle' ? Math.max(13, 16 - Math.log2(radius / 100)) : 14,
            interactive: false,
            attributionControl: false
        });

        miniMap.on('load', function() {
            if (type === 'circle') {
                var circleGeoJson = createCircleGeoJSON(lng, lat, radius);
                miniMap.addSource('geofence', { type: 'geojson', data: circleGeoJson });
                miniMap.addLayer({
                    id: 'geofence-fill',
                    type: 'fill',
                    source: 'geofence',
                    paint: { 'fill-color': '#667eea', 'fill-opacity': 0.2 }
                });
                miniMap.addLayer({
                    id: 'geofence-line',
                    type: 'line',
                    source: 'geofence',
                    paint: { 'line-color': '#667eea', 'line-width': 2 }
                });
            } else if (type === 'polygon' && el.dataset.polygon) {
                try {
                    var coords = JSON.parse(el.dataset.polygon);
                    if (coords.length >= 3) {
                        var closed = coords.slice();
                        closed.push(closed[0]);
                        miniMap.addSource('geofence', {
                            type: 'geojson',
                            data: {
                                type: 'Feature',
                                geometry: { type: 'Polygon', coordinates: [closed.map(function(c) { return [c[1], c[0]]; })] }
                            }
                        });
                        miniMap.addLayer({
                            id: 'geofence-fill',
                            type: 'fill',
                            source: 'geofence',
                            paint: { 'fill-color': '#f093fb', 'fill-opacity': 0.2 }
                        });
                        miniMap.addLayer({
                            id: 'geofence-line',
                            type: 'line',
                            source: 'geofence',
                            paint: { 'line-color': '#f093fb', 'line-width': 2 }
                        });
                    }
                } catch(e) {}
            }

            // Center marker
            new mapboxgl.Marker({ color: '#667eea' }).setLngLat([lng, lat]).addTo(miniMap);
        });
    });

    // Create circle GeoJSON
    function createCircleGeoJSON(lng, lat, radiusM) {
        var points = 64;
        var coords = [];
        var km = radiusM / 1000;
        for (var i = 0; i <= points; i++) {
            var angle = (i / points) * 2 * Math.PI;
            var dx = km * Math.cos(angle);
            var dy = km * Math.sin(angle);
            var dLng = dx / (111.32 * Math.cos(lat * Math.PI / 180));
            var dLat = dy / 110.574;
            coords.push([lng + dLng, lat + dLat]);
        }
        return {
            type: 'Feature',
            geometry: { type: 'Polygon', coordinates: [coords] }
        };
    }

    // Add geofence UI
    var formEl = document.getElementById('geofenceForm');
    var addBtn = document.getElementById('addGeofenceBtn');
    var formMap = null;
    var formMarker = null;
    var formCircle = null;
    var selectedType = 'circle';
    var polygonPoints = [];

    if (addBtn) {
        addBtn.addEventListener('click', function() {
            resetForm();
            document.getElementById('geofenceFormTitle').textContent = 'Add New Geofence';
            formEl.classList.remove('trk-hidden');
            formEl.scrollIntoView({ behavior: 'smooth' });
            initFormMap();
        });
    }

    document.querySelectorAll('.geofence-type-option').forEach(function(el) {
        el.addEventListener('click', function() {
            document.querySelectorAll('.geofence-type-option').forEach(function(e) { e.classList.remove('active'); });
            el.classList.add('active');
            selectedType = el.dataset.type;
            document.getElementById('radiusGroup').style.display = selectedType === 'circle' ? '' : 'none';
            polygonPoints = [];
            if (formMap) clearFormMapLayers();
        });
    });

    function initFormMap() {
        if (formMap) {
            formMap.resize();
            return;
        }
        formMap = new mapboxgl.Map({
            container: 'geofenceFormMap',
            style: 'mapbox://styles/mapbox/dark-v11',
            center: [28.0473, -26.2041],
            zoom: 12,
            attributionControl: false
        });
        formMap.addControl(new mapboxgl.NavigationControl(), 'top-right');

        formMap.on('click', function(e) {
            var lngLat = e.lngLat;
            if (selectedType === 'circle') {
                document.getElementById('gfLat').value = lngLat.lat.toFixed(6);
                document.getElementById('gfLng').value = lngLat.lng.toFixed(6);
                drawFormCircle(lngLat.lng, lngLat.lat);
            } else {
                polygonPoints.push([lngLat.lat, lngLat.lng]);
                document.getElementById('gfPolygonJson').value = JSON.stringify(polygonPoints);
                if (polygonPoints.length === 1) {
                    document.getElementById('gfLat').value = lngLat.lat.toFixed(6);
                    document.getElementById('gfLng').value = lngLat.lng.toFixed(6);
                }
                drawFormPolygon();
            }
        });
    }

    function drawFormCircle(lng, lat) {
        var radius = parseFloat(document.getElementById('gfRadius').value) || 200;
        var data = createCircleGeoJSON(lng, lat, radius);
        clearFormMapLayers();

        formMap.addSource('form-geofence', { type: 'geojson', data: data });
        formMap.addLayer({ id: 'form-gf-fill', type: 'fill', source: 'form-geofence', paint: { 'fill-color': '#667eea', 'fill-opacity': 0.2 } });
        formMap.addLayer({ id: 'form-gf-line', type: 'line', source: 'form-geofence', paint: { 'line-color': '#667eea', 'line-width': 2 } });

        if (formMarker) formMarker.remove();
        formMarker = new mapboxgl.Marker({ color: '#667eea' }).setLngLat([lng, lat]).addTo(formMap);
    }

    function drawFormPolygon() {
        clearFormMapLayers();
        if (polygonPoints.length < 2) {
            if (polygonPoints.length === 1) {
                if (formMarker) formMarker.remove();
                formMarker = new mapboxgl.Marker({ color: '#f093fb' })
                    .setLngLat([polygonPoints[0][1], polygonPoints[0][0]])
                    .addTo(formMap);
            }
            return;
        }
        var coords = polygonPoints.map(function(p) { return [p[1], p[0]]; });
        var closed = coords.slice();
        closed.push(closed[0]);

        formMap.addSource('form-geofence', {
            type: 'geojson',
            data: { type: 'Feature', geometry: { type: 'Polygon', coordinates: [closed] } }
        });
        formMap.addLayer({ id: 'form-gf-fill', type: 'fill', source: 'form-geofence', paint: { 'fill-color': '#f093fb', 'fill-opacity': 0.2 } });
        formMap.addLayer({ id: 'form-gf-line', type: 'line', source: 'form-geofence', paint: { 'line-color': '#f093fb', 'line-width': 2 } });
    }

    function clearFormMapLayers() {
        if (!formMap) return;
        try { formMap.removeLayer('form-gf-fill'); } catch(e) {}
        try { formMap.removeLayer('form-gf-line'); } catch(e) {}
        try { formMap.removeSource('form-geofence'); } catch(e) {}
    }

    function resetForm() {
        if (document.getElementById('gfName')) document.getElementById('gfName').value = '';
        if (document.getElementById('gfRadius')) document.getElementById('gfRadius').value = '200';
        if (document.getElementById('gfLat')) document.getElementById('gfLat').value = '';
        if (document.getElementById('gfLng')) document.getElementById('gfLng').value = '';
        if (document.getElementById('gfPolygonJson')) document.getElementById('gfPolygonJson').value = '';
        if (document.getElementById('gfEditId')) document.getElementById('gfEditId').value = '';
        selectedType = 'circle';
        polygonPoints = [];
        document.querySelectorAll('.geofence-type-option').forEach(function(e) { e.classList.remove('active'); });
        var circleOpt = document.getElementById('typeCircle');
        if (circleOpt) circleOpt.classList.add('active');
        var rg = document.getElementById('radiusGroup');
        if (rg) rg.style.display = '';
        if (formMap) clearFormMapLayers();
        if (formMarker) { formMarker.remove(); formMarker = null; }
    }

    // Cancel button
    var cancelBtn = document.getElementById('gfCancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            formEl.classList.add('trk-hidden');
            resetForm();
        });
    }

    // Radius change re-draws circle
    var radiusInput = document.getElementById('gfRadius');
    if (radiusInput) {
        radiusInput.addEventListener('input', function() {
            var lat = parseFloat(document.getElementById('gfLat').value);
            var lng = parseFloat(document.getElementById('gfLng').value);
            if (lat && lng && selectedType === 'circle') {
                drawFormCircle(lng, lat);
            }
        });
    }

    // Save geofence
    var saveBtn = document.getElementById('gfSave');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            var name = document.getElementById('gfName').value.trim();
            var lat = document.getElementById('gfLat').value;
            var lng = document.getElementById('gfLng').value;
            var radius = document.getElementById('gfRadius').value;
            var polygonJson = document.getElementById('gfPolygonJson').value;
            var editId = document.getElementById('gfEditId').value;

            if (!name) { alert('Please enter a name'); return; }
            if (!lat || !lng) { alert('Please click on the map to set a location'); return; }
            if (selectedType === 'polygon' && polygonPoints.length < 3 && !polygonJson) {
                alert('Please click at least 3 points on the map for a polygon');
                return;
            }

            var payload = {
                name: name,
                type: selectedType,
                center_lat: parseFloat(lat),
                center_lng: parseFloat(lng),
                radius_m: selectedType === 'circle' ? parseFloat(radius) : 0,
                polygon_json: selectedType === 'polygon' ? (polygonJson || JSON.stringify(polygonPoints)) : null
            };

            var url = window.TrackingConfig.apiBase;
            var method = 'POST';

            if (editId) {
                url += '/geofences_update.php';
                payload.id = parseInt(editId);
            } else {
                url += '/geofences_add.php';
            }

            saveBtn.disabled = true;
            fetch(url, {
                method: method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                saveBtn.disabled = false;
                if (data.ok) {
                    location.reload();
                } else {
                    alert(data.error || 'Failed to save geofence');
                }
            })
            .catch(function(err) {
                saveBtn.disabled = false;
                alert('Network error');
            });
        });
    }

    // Edit buttons
    document.querySelectorAll('.edit-gf-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('geofenceFormTitle').textContent = 'Edit Geofence';
            document.getElementById('gfEditId').value = btn.dataset.id;
            document.getElementById('gfName').value = btn.dataset.name;
            document.getElementById('gfLat').value = btn.dataset.lat;
            document.getElementById('gfLng').value = btn.dataset.lng;
            document.getElementById('gfRadius').value = btn.dataset.radius || '200';

            selectedType = btn.dataset.type || 'circle';
            document.querySelectorAll('.geofence-type-option').forEach(function(e) { e.classList.remove('active'); });
            var opt = document.querySelector('[data-type="' + selectedType + '"]');
            if (opt) opt.classList.add('active');
            document.getElementById('radiusGroup').style.display = selectedType === 'circle' ? '' : 'none';

            if (btn.dataset.polygon) {
                document.getElementById('gfPolygonJson').value = btn.dataset.polygon;
                try { polygonPoints = JSON.parse(btn.dataset.polygon); } catch(e) { polygonPoints = []; }
            }

            formEl.classList.remove('trk-hidden');
            formEl.scrollIntoView({ behavior: 'smooth' });
            initFormMap();

            setTimeout(function() {
                var lat = parseFloat(btn.dataset.lat);
                var lng = parseFloat(btn.dataset.lng);
                if (formMap && lat && lng) {
                    formMap.flyTo({ center: [lng, lat], zoom: 14 });
                    if (selectedType === 'circle') {
                        drawFormCircle(lng, lat);
                    } else if (polygonPoints.length >= 2) {
                        drawFormPolygon();
                    }
                }
            }, 500);
        });
    });

    // Delete buttons
    document.querySelectorAll('.delete-gf-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Delete geofence "' + btn.dataset.name + '"?')) return;

            fetch(window.TrackingConfig.apiBase + '/geofences_delete.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(btn.dataset.id) })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    location.reload();
                } else {
                    alert(data.error || 'Failed to delete');
                }
            })
            .catch(function() { alert('Network error'); });
        });
    });

})();
</script>
