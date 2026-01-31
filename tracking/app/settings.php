<?php
declare(strict_types=1);

/**
 * Tracking Settings Page
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
    error_log('Tracking settings error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$pageTitle = 'Tracking Settings';
$canEdit = in_array($user['role'], ['owner', 'admin']);
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Relatives</title>
    <link rel="stylesheet" href="assets/css/tracking.css?v=<?php echo $cacheVersion; ?>">
</head>
<body class="settings-page">
    <!-- Top Bar -->
    <div class="tracking-topbar">
        <a href="index.php" class="back-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </a>
        <div class="topbar-title">Tracking Settings</div>
        <div class="topbar-actions"></div>
    </div>

    <div class="settings-container">
        <!-- Mode Section -->
        <div class="settings-section">
            <h2>Tracking Mode</h2>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Mode</div>
                    <div class="setting-description">How tracking is triggered</div>
                </div>
                <select id="setting-mode" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="1">Live Session</option>
                    <option value="2">Motion-based</option>
                </select>
            </div>
        </div>

        <!-- Mode 1 Settings -->
        <div class="settings-section" id="mode1-settings">
            <h2>Live Session Settings</h2>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Session Timeout</div>
                    <div class="setting-description">How long session stays active without keepalive</div>
                </div>
                <select id="setting-session-ttl" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="180">3 minutes</option>
                    <option value="300">5 minutes</option>
                    <option value="600">10 minutes</option>
                    <option value="900">15 minutes</option>
                </select>
            </div>
        </div>

        <!-- Mode 2 Settings -->
        <div class="settings-section hidden" id="mode2-settings">
            <h2>Motion-based Settings</h2>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Moving Interval</div>
                    <div class="setting-description">Upload frequency when moving</div>
                </div>
                <select id="setting-moving-interval" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="15">15 seconds</option>
                    <option value="30">30 seconds</option>
                    <option value="60">1 minute</option>
                    <option value="120">2 minutes</option>
                </select>
            </div>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Idle Interval</div>
                    <div class="setting-description">Heartbeat frequency when stationary</div>
                </div>
                <select id="setting-idle-interval" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="300">5 minutes</option>
                    <option value="600">10 minutes</option>
                    <option value="900">15 minutes</option>
                    <option value="1800">30 minutes</option>
                </select>
            </div>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Speed Threshold</div>
                    <div class="setting-description">Speed to consider as "moving"</div>
                </div>
                <select id="setting-speed-threshold" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="0.5">0.5 m/s (walking)</option>
                    <option value="1.0">1.0 m/s (fast walk)</option>
                    <option value="2.0">2.0 m/s (jogging)</option>
                    <option value="5.0">5.0 m/s (cycling)</option>
                </select>
            </div>
        </div>

        <!-- Quality Settings -->
        <div class="settings-section">
            <h2>Quality Settings</h2>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Minimum Accuracy</div>
                    <div class="setting-description">Reject locations worse than this</div>
                </div>
                <select id="setting-min-accuracy" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="50">50 meters (high)</option>
                    <option value="100">100 meters (normal)</option>
                    <option value="200">200 meters (low)</option>
                    <option value="500">500 meters (very low)</option>
                </select>
            </div>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Dedupe Radius</div>
                    <div class="setting-description">Skip points closer than this</div>
                </div>
                <input type="number" id="setting-dedupe-radius" class="setting-input"
                       min="0" max="100" <?= $canEdit ? '' : 'disabled' ?>>
            </div>
        </div>

        <!-- Display Settings -->
        <div class="settings-section">
            <h2>Display Settings</h2>
            <div class="setting-row">
                <div>
                    <div class="setting-label">Units</div>
                </div>
                <select id="setting-units" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="metric">Metric (km)</option>
                    <option value="imperial">Imperial (mi)</option>
                </select>
            </div>
        </div>

        <!-- Retention Settings -->
        <div class="settings-section">
            <h2>Data Retention</h2>
            <div class="setting-row">
                <div>
                    <div class="setting-label">History Retention</div>
                    <div class="setting-description">How long to keep location history</div>
                </div>
                <select id="setting-history-retention" class="setting-select" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="7">7 days</option>
                    <option value="14">14 days</option>
                    <option value="30">30 days</option>
                    <option value="90">90 days</option>
                </select>
            </div>
        </div>

        <?php if ($canEdit): ?>
        <button id="btn-save" class="popup-btn primary" style="width: 100%; margin-top: 20px;">
            Save Settings
        </button>
        <?php else: ?>
        <p style="text-align: center; color: var(--gray-500); margin-top: 20px;">
            Only family admins can change settings
        </p>
        <?php endif; ?>
    </div>

    <div id="toast-container" class="toast-container"></div>

    <script>
        const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
    </script>
    <script src="assets/js/format.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/api.js?v=<?php echo $cacheVersion; ?>"></script>
    <script>
        // Toast helper
        const Toast = {
            container: document.getElementById('toast-container'),
            show(message, type = 'info') {
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.textContent = message;
                this.container.appendChild(toast);
                setTimeout(() => toast.remove(), 3000);
            }
        };

        // Load settings
        async function loadSettings() {
            try {
                const response = await TrackingAPI.getSettings();
                if (response.success) {
                    populateForm(response.data);
                }
            } catch (err) {
                Toast.show('Failed to load settings', 'error');
            }
        }

        function populateForm(settings) {
            document.getElementById('setting-mode').value = settings.mode;
            document.getElementById('setting-session-ttl').value = settings.session_ttl_seconds;
            document.getElementById('setting-moving-interval').value = settings.moving_interval_seconds;
            document.getElementById('setting-idle-interval').value = settings.idle_interval_seconds;
            document.getElementById('setting-speed-threshold').value = settings.speed_threshold_mps.toFixed(1);
            document.getElementById('setting-min-accuracy').value = settings.min_accuracy_m;
            document.getElementById('setting-dedupe-radius').value = settings.dedupe_radius_m;
            document.getElementById('setting-units').value = settings.units;
            document.getElementById('setting-history-retention').value = settings.history_retention_days;

            updateModeVisibility(settings.mode);
        }

        function updateModeVisibility(mode) {
            document.getElementById('mode1-settings').classList.toggle('hidden', mode != 1);
            document.getElementById('mode2-settings').classList.toggle('hidden', mode != 2);
        }

        // Mode change
        document.getElementById('setting-mode').addEventListener('change', (e) => {
            updateModeVisibility(e.target.value);
        });

        // Save
        if (canEdit) {
            document.getElementById('btn-save').addEventListener('click', async () => {
                const settings = {
                    mode: parseInt(document.getElementById('setting-mode').value),
                    session_ttl_seconds: parseInt(document.getElementById('setting-session-ttl').value),
                    moving_interval_seconds: parseInt(document.getElementById('setting-moving-interval').value),
                    idle_interval_seconds: parseInt(document.getElementById('setting-idle-interval').value),
                    speed_threshold_mps: parseFloat(document.getElementById('setting-speed-threshold').value),
                    min_accuracy_m: parseInt(document.getElementById('setting-min-accuracy').value),
                    dedupe_radius_m: parseInt(document.getElementById('setting-dedupe-radius').value),
                    units: document.getElementById('setting-units').value,
                    history_retention_days: parseInt(document.getElementById('setting-history-retention').value)
                };

                try {
                    const response = await TrackingAPI.saveSettings(settings);
                    if (response.success) {
                        Toast.show('Settings saved', 'success');
                    }
                } catch (err) {
                    Toast.show(err.message || 'Failed to save', 'error');
                }
            });
        }

        loadSettings();
    </script>
</body>
</html>
