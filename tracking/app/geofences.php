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

$pageTitle = 'Geofences';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<main class="main-content">
    <div class="container" style="max-width:800px;margin:0 auto;padding:20px;">
        <div class="tracking-page-header">
            <a href="/tracking/app/" class="back-link">&larr; Back to Map</a>
            <h1>Geofences</h1>
        </div>

        <button class="action-btn" id="addGeofenceBtn" onclick="showAddForm()">+ Add Geofence</button>

        <!-- Add/Edit Form -->
        <div class="geofence-form" id="geofenceForm" style="display:none;">
            <h3 id="formTitle">Add Geofence</h3>
            <input type="hidden" id="geofenceId">
            <div class="form-group">
                <label>Name</label>
                <input type="text" id="gfName" placeholder="e.g., Home, School, Work" maxlength="100">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Latitude</label>
                    <input type="number" id="gfLat" step="0.0000001" placeholder="-33.9">
                </div>
                <div class="form-group">
                    <label>Longitude</label>
                    <input type="number" id="gfLng" step="0.0000001" placeholder="18.4">
                </div>
            </div>
            <div class="form-group">
                <label>Radius (meters)</label>
                <input type="number" id="gfRadius" value="200" min="50" max="50000">
            </div>
            <div class="form-group">
                <label>Color</label>
                <input type="color" id="gfColor" value="#667eea">
            </div>
            <div class="form-row">
                <label class="consent-toggle">
                    <span class="consent-label"><strong>Notify on Enter</strong></span>
                    <div class="toggle-switch"><input type="checkbox" id="gfNotifyEnter" checked><span class="toggle-slider"></span></div>
                </label>
                <label class="consent-toggle">
                    <span class="consent-label"><strong>Notify on Exit</strong></span>
                    <div class="toggle-switch"><input type="checkbox" id="gfNotifyExit" checked><span class="toggle-slider"></span></div>
                </label>
            </div>
            <div class="form-actions">
                <button class="action-btn" onclick="saveGeofence()">Save</button>
                <button class="action-btn secondary" onclick="hideForm()">Cancel</button>
            </div>
        </div>

        <div class="geofence-list" id="geofenceList">
            <div class="panel-loading">Loading geofences...</div>
        </div>
    </div>
</main>

<script>
function showAddForm() {
    document.getElementById('geofenceForm').style.display = 'block';
    document.getElementById('formTitle').textContent = 'Add Geofence';
    document.getElementById('geofenceId').value = '';
    document.getElementById('gfName').value = '';
    document.getElementById('gfLat').value = '';
    document.getElementById('gfLng').value = '';
    document.getElementById('gfRadius').value = '200';
    document.getElementById('gfColor').value = '#667eea';

    // Try to get current location
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            document.getElementById('gfLat').value = pos.coords.latitude.toFixed(7);
            document.getElementById('gfLng').value = pos.coords.longitude.toFixed(7);
        });
    }
}

function hideForm() {
    document.getElementById('geofenceForm').style.display = 'none';
}

function saveGeofence() {
    var id = document.getElementById('geofenceId').value;
    var payload = {
        name: document.getElementById('gfName').value,
        lat: parseFloat(document.getElementById('gfLat').value),
        lng: parseFloat(document.getElementById('gfLng').value),
        radius_m: parseInt(document.getElementById('gfRadius').value),
        color: document.getElementById('gfColor').value,
        notify_enter: document.getElementById('gfNotifyEnter').checked ? 1 : 0,
        notify_exit: document.getElementById('gfNotifyExit').checked ? 1 : 0,
        type: 'circle'
    };
    if (id) payload.id = parseInt(id);

    fetch('/tracking/api/geofences.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success) {
            hideForm();
            loadGeofences();
        } else {
            alert(data.error || 'Failed to save');
        }
    });
}

function deleteGeofence(id) {
    if (!confirm('Delete this geofence?')) return;
    fetch('/tracking/api/geofence_delete.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({id: id})
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success) loadGeofences();
    });
}

function loadGeofences() {
    fetch('/tracking/api/geofences.php', {credentials: 'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var container = document.getElementById('geofenceList');
            if (!data.success || !data.data || data.data.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon">📍</div><p>No geofences yet. Add one to get started.</p></div>';
                return;
            }
            container.innerHTML = '';
            data.data.forEach(function(gf) {
                var div = document.createElement('div');
                div.className = 'geofence-item';
                div.innerHTML = '<div class="geofence-color" style="background:' + (gf.color || '#667eea') + '"></div>' +
                    '<div class="geofence-info">' +
                        '<div class="geofence-name">' + gf.name + '</div>' +
                        '<div class="geofence-meta">Radius: ' + gf.radius_m + 'm &middot; ' +
                            (gf.notify_enter == 1 ? 'Enter ' : '') + (gf.notify_exit == 1 ? 'Exit' : '') + '</div>' +
                    '</div>' +
                    '<div class="geofence-actions">' +
                        '<button onclick="editGeofence(' + JSON.stringify(gf).replace(/"/g, '&quot;') + ')" class="icon-btn">Edit</button>' +
                        '<button onclick="deleteGeofence(' + gf.id + ')" class="icon-btn danger">Delete</button>' +
                    '</div>';
                container.appendChild(div);
            });
        });
}

function editGeofence(gf) {
    document.getElementById('geofenceForm').style.display = 'block';
    document.getElementById('formTitle').textContent = 'Edit Geofence';
    document.getElementById('geofenceId').value = gf.id;
    document.getElementById('gfName').value = gf.name;
    document.getElementById('gfLat').value = gf.lat;
    document.getElementById('gfLng').value = gf.lng;
    document.getElementById('gfRadius').value = gf.radius_m;
    document.getElementById('gfColor').value = gf.color || '#667eea';
    document.getElementById('gfNotifyEnter').checked = gf.notify_enter == 1;
    document.getElementById('gfNotifyExit').checked = gf.notify_exit == 1;
}

loadGeofences();
</script>

<?php
$pageJS = [];
require_once __DIR__ . '/../../shared/components/footer.php';
?>
