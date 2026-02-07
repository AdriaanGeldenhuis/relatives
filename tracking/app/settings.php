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

$pageTitle = 'Tracking Settings';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<main class="main-content">
    <div class="container" style="max-width:600px;margin:0 auto;padding:20px;">
        <div class="tracking-page-header">
            <a href="/tracking/app/" class="back-link">&larr; Back to Map</a>
            <h1>Tracking Settings</h1>
        </div>

        <div class="settings-card" id="settingsCard">
            <div class="panel-loading">Loading settings...</div>
        </div>
    </div>
</main>

<script>
function loadSettings() {
    fetch('/tracking/api/settings.php', {credentials: 'same-origin'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.data) return;
            var s = data.data;
            var card = document.getElementById('settingsCard');
            card.innerHTML =
                '<div class="form-group"><label>Update Interval (seconds)</label>' +
                '<select id="sInterval"><option value="15"' + (s.update_interval==15?' selected':'') + '>15s (High battery)</option>' +
                '<option value="30"' + (s.update_interval==30?' selected':'') + '>30s (Balanced)</option>' +
                '<option value="60"' + (s.update_interval==60?' selected':'') + '>60s (Low battery)</option>' +
                '<option value="120"' + (s.update_interval==120?' selected':'') + '>120s (Battery saver)</option></select></div>' +

                '<div class="form-group"><label>History Retention (days)</label>' +
                '<input type="number" id="sRetention" value="' + s.history_retention_days + '" min="1" max="365"></div>' +

                '<div class="form-group"><label>Distance Unit</label>' +
                '<select id="sUnit"><option value="km"' + (s.distance_unit=='km'?' selected':'') + '>Kilometers</option>' +
                '<option value="mi"' + (s.distance_unit=='mi'?' selected':'') + '>Miles</option></select></div>' +

                '<div class="form-group"><label>Map Style</label>' +
                '<select id="sMapStyle"><option value="streets"' + (s.map_style=='streets'?' selected':'') + '>Streets</option>' +
                '<option value="satellite"' + (s.map_style=='satellite'?' selected':'') + '>Satellite</option>' +
                '<option value="dark"' + (s.map_style=='dark'?' selected':'') + '>Dark</option>' +
                '<option value="light"' + (s.map_style=='light'?' selected':'') + '>Light</option></select></div>' +

                '<label class="consent-toggle"><span class="consent-label"><strong>Show Speed</strong></span>' +
                '<div class="toggle-switch"><input type="checkbox" id="sShowSpeed"' + (s.show_speed==1?' checked':'') + '><span class="toggle-slider"></span></div></label>' +

                '<label class="consent-toggle"><span class="consent-label"><strong>Show Battery</strong></span>' +
                '<div class="toggle-switch"><input type="checkbox" id="sShowBattery"' + (s.show_battery==1?' checked':'') + '><span class="toggle-slider"></span></div></label>' +

                '<label class="consent-toggle"><span class="consent-label"><strong>Geofence Notifications</strong></span>' +
                '<div class="toggle-switch"><input type="checkbox" id="sGeofenceNotif"' + (s.geofence_notifications==1?' checked':'') + '><span class="toggle-slider"></span></div></label>' +

                '<label class="consent-toggle"><span class="consent-label"><strong>Low Battery Alert</strong></span>' +
                '<div class="toggle-switch"><input type="checkbox" id="sLowBattery"' + (s.low_battery_alert==1?' checked':'') + '><span class="toggle-slider"></span></div></label>' +

                '<div class="form-group"><label>Low Battery Threshold (%)</label>' +
                '<input type="number" id="sLowThreshold" value="' + s.low_battery_threshold + '" min="5" max="50"></div>' +

                '<div class="form-actions"><button class="action-btn" onclick="saveSettings()">Save Settings</button></div>';
        });
}

function saveSettings() {
    var payload = {
        update_interval: parseInt(document.getElementById('sInterval').value),
        history_retention_days: parseInt(document.getElementById('sRetention').value),
        distance_unit: document.getElementById('sUnit').value,
        map_style: document.getElementById('sMapStyle').value,
        show_speed: document.getElementById('sShowSpeed').checked ? 1 : 0,
        show_battery: document.getElementById('sShowBattery').checked ? 1 : 0,
        geofence_notifications: document.getElementById('sGeofenceNotif').checked ? 1 : 0,
        low_battery_alert: document.getElementById('sLowBattery').checked ? 1 : 0,
        low_battery_threshold: parseInt(document.getElementById('sLowThreshold').value)
    };

    fetch('/tracking/api/settings.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success) {
            alert('Settings saved!');
        }
    });
}

loadSettings();
</script>

<?php
$pageJS = [];
require_once __DIR__ . '/../../shared/components/footer.php';
?>
