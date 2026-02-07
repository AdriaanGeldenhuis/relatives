<?php
/**
 * ============================================
 * FAMILY TRACKING - SETTINGS
 * Configure tracking behavior, alert rules,
 * map preferences, and data retention.
 * ============================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

$session = new Session($db);
$user = $session->validate();
if (!$user) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap_tracking.php';

$trackingCache = new TrackingCache($db);
$familyId = (int)$user['family_id'];
$isAdmin = in_array($user['role'], ['owner', 'admin']);

// Load settings and alert rules
$settingsRepo = new SettingsRepo($db, $trackingCache);
$settings = $settingsRepo->get($familyId);

$alertsRepo = new AlertsRepo($db);
$alertRules = $alertsRepo->get($familyId);

$pageTitle = 'Tracking Settings';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<div class="settings-container">

    <a href="/tracking/app/" class="tracking-back">Back to Map</a>

    <div class="tracking-content-header">
        <div>
            <h1 class="tracking-content-title">Settings</h1>
            <p class="tracking-content-subtitle">Configure tracking behavior for your family.</p>
        </div>
    </div>

    <?php if (!$isAdmin): ?>
    <div class="settings-readonly-notice">
        Only family owners and admins can modify tracking settings. You are viewing read-only.
    </div>
    <?php endif; ?>

    <form id="settingsForm" autocomplete="off">

        <!-- Tracking Mode -->
        <div class="settings-card">
            <h3 class="settings-card-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                Tracking Mode
            </h3>

            <div class="form-group">
                <label class="form-label">Mode
                    <span class="form-label-desc">Controls how aggressively the app tracks location</span>
                </label>
                <select class="form-select" name="mode" id="settingMode" <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <option value="0" <?php echo $settings['mode'] == 0 ? 'selected' : ''; ?>>Off - No tracking</option>
                    <option value="1" <?php echo $settings['mode'] == 1 ? 'selected' : ''; ?>>Normal - Balanced battery/accuracy</option>
                    <option value="2" <?php echo $settings['mode'] == 2 ? 'selected' : ''; ?>>Active - Higher frequency updates</option>
                </select>
            </div>
        </div>

        <!-- Intervals & Thresholds -->
        <div class="settings-card">
            <h3 class="settings-card-title">
                <svg viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                Intervals &amp; Thresholds
            </h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Moving Interval (sec)
                        <span class="form-label-desc">Update frequency when moving</span>
                    </label>
                    <input type="number" class="form-input" name="moving_interval_seconds"
                           value="<?php echo (int)$settings['moving_interval_seconds']; ?>"
                           min="5" max="600" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Idle Interval (sec)
                        <span class="form-label-desc">Update frequency when stationary</span>
                    </label>
                    <input type="number" class="form-input" name="idle_interval_seconds"
                           value="<?php echo (int)$settings['idle_interval_seconds']; ?>"
                           min="30" max="3600" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Speed Threshold (m/s)
                        <span class="form-label-desc">Minimum speed to consider "moving"</span>
                    </label>
                    <input type="number" class="form-input" name="speed_threshold_mps"
                           value="<?php echo (float)$settings['speed_threshold_mps']; ?>"
                           min="0.1" max="10" step="0.1" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Distance Threshold (m)
                        <span class="form-label-desc">Minimum distance change to record</span>
                    </label>
                    <input type="number" class="form-input" name="distance_threshold_m"
                           value="<?php echo (int)$settings['distance_threshold_m']; ?>"
                           min="5" max="500" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Min Accuracy (m)
                        <span class="form-label-desc">Reject readings less accurate than this</span>
                    </label>
                    <input type="number" class="form-input" name="min_accuracy_m"
                           value="<?php echo (int)$settings['min_accuracy_m']; ?>"
                           min="10" max="500" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Rate Limit (sec)
                        <span class="form-label-desc">Minimum time between API calls</span>
                    </label>
                    <input type="number" class="form-input" name="rate_limit_seconds"
                           value="<?php echo (int)$settings['rate_limit_seconds']; ?>"
                           min="1" max="60" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Session TTL (sec)
                        <span class="form-label-desc">How long before a session expires</span>
                    </label>
                    <input type="number" class="form-input" name="session_ttl_seconds"
                           value="<?php echo (int)$settings['session_ttl_seconds']; ?>"
                           min="60" max="3600" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Keepalive Interval (sec)
                        <span class="form-label-desc">Ping frequency to maintain session</span>
                    </label>
                    <input type="number" class="form-input" name="keepalive_interval_seconds"
                           value="<?php echo (int)$settings['keepalive_interval_seconds']; ?>"
                           min="10" max="300" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Dedupe Radius (m)
                        <span class="form-label-desc">Ignore points within this distance</span>
                    </label>
                    <input type="number" class="form-input" name="dedupe_radius_m"
                           value="<?php echo (int)$settings['dedupe_radius_m']; ?>"
                           min="1" max="100" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Dedupe Time (sec)
                        <span class="form-label-desc">Ignore points within this time window</span>
                    </label>
                    <input type="number" class="form-input" name="dedupe_time_seconds"
                           value="<?php echo (int)$settings['dedupe_time_seconds']; ?>"
                           min="5" max="300" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>
        </div>

        <!-- Display Preferences -->
        <div class="settings-card">
            <h3 class="settings-card-title">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                Display Preferences
            </h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Units</label>
                    <select class="form-select" name="units" <?php echo $isAdmin ? '' : 'disabled'; ?>>
                        <option value="metric" <?php echo $settings['units'] === 'metric' ? 'selected' : ''; ?>>Metric (km, m/s)</option>
                        <option value="imperial" <?php echo $settings['units'] === 'imperial' ? 'selected' : ''; ?>>Imperial (mi, mph)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Map Style</label>
                    <select class="form-select" name="map_style" <?php echo $isAdmin ? '' : 'disabled'; ?>>
                        <option value="streets" <?php echo $settings['map_style'] === 'streets' ? 'selected' : ''; ?>>Streets</option>
                        <option value="dark" <?php echo $settings['map_style'] === 'dark' ? 'selected' : ''; ?>>Dark</option>
                        <option value="satellite" <?php echo $settings['map_style'] === 'satellite' ? 'selected' : ''; ?>>Satellite</option>
                        <option value="light" <?php echo $settings['map_style'] === 'light' ? 'selected' : ''; ?>>Light</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Data Retention -->
        <div class="settings-card">
            <h3 class="settings-card-title">
                <svg viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 9 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                Data Retention
            </h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Location History (days)
                        <span class="form-label-desc">How long to keep location trail data</span>
                    </label>
                    <input type="number" class="form-input" name="history_retention_days"
                           value="<?php echo (int)$settings['history_retention_days']; ?>"
                           min="1" max="365" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Events Retention (days)
                        <span class="form-label-desc">How long to keep event logs</span>
                    </label>
                    <input type="number" class="form-input" name="events_retention_days"
                           value="<?php echo (int)$settings['events_retention_days']; ?>"
                           min="1" max="365" <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>
        </div>

        <!-- Alert Rules -->
        <div class="settings-card">
            <h3 class="settings-card-title">
                <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                Alert Rules
            </h3>

            <div class="form-toggle-row">
                <div>
                    <div class="form-toggle-label">Alerts Enabled</div>
                    <div class="form-toggle-desc">Master switch for all tracking alerts</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="alert_enabled" <?php echo $alertRules['enabled'] ? 'checked' : ''; ?> <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="form-toggle-row">
                <div>
                    <div class="form-toggle-label">Arrive at Place</div>
                    <div class="form-toggle-desc">Notify when a member arrives at a saved place</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="arrive_place_enabled" <?php echo $alertRules['arrive_place_enabled'] ? 'checked' : ''; ?> <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="form-toggle-row">
                <div>
                    <div class="form-toggle-label">Leave Place</div>
                    <div class="form-toggle-desc">Notify when a member leaves a saved place</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="leave_place_enabled" <?php echo $alertRules['leave_place_enabled'] ? 'checked' : ''; ?> <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="form-toggle-row">
                <div>
                    <div class="form-toggle-label">Enter Geofence</div>
                    <div class="form-toggle-desc">Notify when a member enters a geofence zone</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="enter_geofence_enabled" <?php echo $alertRules['enter_geofence_enabled'] ? 'checked' : ''; ?> <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="form-toggle-row">
                <div>
                    <div class="form-toggle-label">Exit Geofence</div>
                    <div class="form-toggle-desc">Notify when a member exits a geofence zone</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="exit_geofence_enabled" <?php echo $alertRules['exit_geofence_enabled'] ? 'checked' : ''; ?> <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="form-group" style="margin-top:16px">
                <label class="form-label">Alert Cooldown (seconds)
                    <span class="form-label-desc">Minimum time between repeated alerts for the same rule</span>
                </label>
                <input type="number" class="form-input" name="cooldown_seconds"
                       value="<?php echo (int)$alertRules['cooldown_seconds']; ?>"
                       min="0" max="86400" <?php echo $isAdmin ? '' : 'readonly'; ?>>
            </div>

            <div class="form-row" style="margin-top:16px">
                <div class="form-group">
                    <label class="form-label">Quiet Hours Start
                        <span class="form-label-desc">No alerts after this time</span>
                    </label>
                    <input type="time" class="form-input" name="quiet_hours_start"
                           value="<?php echo e($alertRules['quiet_hours_start'] ?? ''); ?>"
                           <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Quiet Hours End
                        <span class="form-label-desc">Resume alerts after this time</span>
                    </label>
                    <input type="time" class="form-input" name="quiet_hours_end"
                           value="<?php echo e($alertRules['quiet_hours_end'] ?? ''); ?>"
                           <?php echo $isAdmin ? '' : 'readonly'; ?>>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <?php if ($isAdmin): ?>
        <button type="submit" class="settings-save-btn" id="saveSettingsBtn">Save Settings</button>
        <?php endif; ?>

    </form>

</div>

<script>
    window.TrackingConfig = window.TrackingConfig || {};
    window.TrackingConfig.apiBase = '/tracking/api';
    window.TrackingConfig.familyId = <?php echo $familyId; ?>;
    window.TrackingConfig.isAdmin = <?php echo json_encode($isAdmin); ?>;
</script>

<?php
$pageJS = [
    '/tracking/app/assets/js/state.js',
];
require_once __DIR__ . '/../../shared/components/footer.php';
?>

<script>
(function() {
    'use strict';

    var form = document.getElementById('settingsForm');
    var saveBtn = document.getElementById('saveSettingsBtn');

    if (!form || !saveBtn) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!window.TrackingConfig.isAdmin) return;

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        // Collect settings values
        var settingsPayload = {};
        var settingsFields = [
            'mode', 'moving_interval_seconds', 'idle_interval_seconds',
            'speed_threshold_mps', 'distance_threshold_m', 'min_accuracy_m',
            'rate_limit_seconds', 'session_ttl_seconds', 'keepalive_interval_seconds',
            'dedupe_radius_m', 'dedupe_time_seconds',
            'units', 'map_style',
            'history_retention_days', 'events_retention_days'
        ];

        settingsFields.forEach(function(field) {
            var el = form.querySelector('[name="' + field + '"]');
            if (el) {
                var val = el.value;
                if (el.type === 'number') val = parseFloat(val);
                settingsPayload[field] = val;
            }
        });

        // Collect alert rules
        var alertsPayload = {
            enabled: form.querySelector('[name="alert_enabled"]').checked ? 1 : 0,
            arrive_place_enabled: form.querySelector('[name="arrive_place_enabled"]').checked ? 1 : 0,
            leave_place_enabled: form.querySelector('[name="leave_place_enabled"]').checked ? 1 : 0,
            enter_geofence_enabled: form.querySelector('[name="enter_geofence_enabled"]').checked ? 1 : 0,
            exit_geofence_enabled: form.querySelector('[name="exit_geofence_enabled"]').checked ? 1 : 0,
            cooldown_seconds: parseInt(form.querySelector('[name="cooldown_seconds"]').value) || 900,
            quiet_hours_start: form.querySelector('[name="quiet_hours_start"]').value || null,
            quiet_hours_end: form.querySelector('[name="quiet_hours_end"]').value || null
        };

        // Save both in parallel
        var base = window.TrackingConfig.apiBase;

        Promise.all([
            fetch(base + '/settings_save.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(settingsPayload)
            }).then(function(r) { return r.json(); }),

            fetch(base + '/alerts_rules_save.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(alertsPayload)
            }).then(function(r) { return r.json(); })
        ])
        .then(function(results) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Settings';

            var allOk = results.every(function(r) { return r.ok; });
            if (allOk) {
                showToast('Settings saved successfully', 'success');
            } else {
                var errors = results.filter(function(r) { return !r.ok; }).map(function(r) { return r.error || 'Unknown error'; });
                showToast('Error: ' + errors.join(', '), 'error');
            }
        })
        .catch(function(err) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Settings';
            showToast('Network error. Please try again.', 'error');
        });
    });

    function showToast(message, type) {
        var existing = document.querySelector('.tracking-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'tracking-toast ' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);

        requestAnimationFrame(function() {
            toast.classList.add('show');
        });

        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 400);
        }, 3000);
    }

})();
</script>
