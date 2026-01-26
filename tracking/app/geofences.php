<?php
/**
 * Geofences Management Page
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$pageTitle = 'Geofences';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Relatives</title>
    <link rel="stylesheet" href="assets/css/tracking.css">
</head>
<body class="geofences-page">
    <!-- Top Bar -->
    <div class="tracking-topbar">
        <a href="index.php" class="back-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </a>
        <div class="topbar-title">Geofences</div>
        <div class="topbar-actions">
            <a href="events.php" class="icon-btn" title="Events">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 8v4l3 3"/>
                    <circle cx="12" cy="12" r="10"/>
                </svg>
            </a>
        </div>
    </div>

    <div class="geofences-container">
        <!-- Geofences List -->
        <div id="geofences-list">
            <div style="text-align: center; padding: 40px; color: var(--gray-500);">
                Loading geofences...
            </div>
        </div>

        <!-- Add Button -->
        <button id="btn-add" class="add-geofence-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="16"/>
                <line x1="8" y1="12" x2="16" y2="12"/>
            </svg>
            Add Geofence
        </button>
    </div>

    <!-- Add/Edit Modal -->
    <div id="modal" class="modal hidden">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modal-title">Add Geofence</h3>
                <button class="popup-close" id="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" id="geo-name" class="setting-input" style="width: 100%;" placeholder="Home, Work, School...">
                </div>
                <div class="form-group">
                    <label>Center (tap on main map to set)</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" id="geo-lat" class="setting-input" placeholder="Latitude" step="any" style="flex: 1;">
                        <input type="number" id="geo-lng" class="setting-input" placeholder="Longitude" step="any" style="flex: 1;">
                    </div>
                </div>
                <div class="form-group">
                    <label>Radius (meters)</label>
                    <input type="range" id="geo-radius" min="50" max="1000" value="100" style="width: 100%;">
                    <div style="text-align: center; margin-top: 4px;"><span id="radius-display">100</span>m</div>
                </div>
                <div class="form-group">
                    <label>Active</label>
                    <label class="toggle">
                        <input type="checkbox" id="geo-active" checked>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="popup-btn" id="btn-cancel">Cancel</button>
                <button class="popup-btn primary" id="btn-save">Save</button>
            </div>
        </div>
    </div>

    <div id="toast-container" class="toast-container"></div>

    <style>
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal.hidden { display: none; }
        .modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
        }
        .modal-content {
            position: relative;
            background: var(--gray-800);
            border-radius: var(--radius-lg);
            width: calc(100% - 24px);
            max-width: 400px;
            max-height: 90vh;
            overflow: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid var(--gray-700);
        }
        .modal-header h3 {
            font-size: 18px;
            font-weight: 600;
        }
        .modal-body {
            padding: 16px;
        }
        .modal-footer {
            display: flex;
            gap: 8px;
            padding: 16px;
            border-top: 1px solid var(--gray-700);
        }
        .modal-footer button {
            flex: 1;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            color: var(--gray-400);
            margin-bottom: 8px;
        }
    </style>

    <script src="assets/js/format.js"></script>
    <script src="assets/js/api.js"></script>
    <script>
        let geofences = [];
        let editingId = null;

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

        // Load geofences
        async function loadGeofences() {
            const list = document.getElementById('geofences-list');

            try {
                const response = await TrackingAPI.getGeofences();

                if (response.success) {
                    geofences = response.data.geofences;
                    renderGeofences();
                }
            } catch (err) {
                list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--danger);">Failed to load geofences</div>';
            }
        }

        function renderGeofences() {
            const list = document.getElementById('geofences-list');

            if (geofences.length === 0) {
                list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--gray-500);">No geofences yet. Create one to get started.</div>';
                return;
            }

            list.innerHTML = geofences.map(geo => `
                <div class="geofence-card" data-id="${geo.id}">
                    <div class="geofence-header">
                        <div>
                            <div class="geofence-name">${escapeHtml(geo.name)}</div>
                            <div class="geofence-meta">
                                ${geo.radius_m}m radius - ${geo.active ? 'Active' : 'Inactive'}
                            </div>
                        </div>
                        <div class="geofence-actions">
                            <button class="btn-sm btn-edit" data-id="${geo.id}">Edit</button>
                            <button class="btn-sm danger btn-delete" data-id="${geo.id}">Delete</button>
                        </div>
                    </div>
                </div>
            `).join('');

            // Add event listeners
            list.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', () => openEdit(parseInt(btn.dataset.id)));
            });

            list.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', () => deleteGeofence(parseInt(btn.dataset.id)));
            });
        }

        // Modal
        const modal = document.getElementById('modal');
        document.getElementById('btn-add').addEventListener('click', () => openAdd());
        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('btn-cancel').addEventListener('click', closeModal);
        document.querySelector('.modal-backdrop').addEventListener('click', closeModal);

        // Radius slider
        document.getElementById('geo-radius').addEventListener('input', (e) => {
            document.getElementById('radius-display').textContent = e.target.value;
        });

        function openAdd() {
            editingId = null;
            document.getElementById('modal-title').textContent = 'Add Geofence';
            document.getElementById('geo-name').value = '';
            document.getElementById('geo-lat').value = '';
            document.getElementById('geo-lng').value = '';
            document.getElementById('geo-radius').value = 100;
            document.getElementById('radius-display').textContent = '100';
            document.getElementById('geo-active').checked = true;
            modal.classList.remove('hidden');
        }

        function openEdit(id) {
            const geo = geofences.find(g => g.id === id);
            if (!geo) return;

            editingId = id;
            document.getElementById('modal-title').textContent = 'Edit Geofence';
            document.getElementById('geo-name').value = geo.name;
            document.getElementById('geo-lat').value = geo.center_lat;
            document.getElementById('geo-lng').value = geo.center_lng;
            document.getElementById('geo-radius').value = geo.radius_m;
            document.getElementById('radius-display').textContent = geo.radius_m;
            document.getElementById('geo-active').checked = geo.active;
            modal.classList.remove('hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
            editingId = null;
        }

        // Save
        document.getElementById('btn-save').addEventListener('click', async () => {
            const name = document.getElementById('geo-name').value.trim();
            const lat = parseFloat(document.getElementById('geo-lat').value);
            const lng = parseFloat(document.getElementById('geo-lng').value);
            const radius = parseInt(document.getElementById('geo-radius').value);
            const active = document.getElementById('geo-active').checked;

            if (!name) {
                Toast.show('Name is required', 'error');
                return;
            }

            if (isNaN(lat) || isNaN(lng)) {
                Toast.show('Valid coordinates are required', 'error');
                return;
            }

            const data = {
                name,
                center_lat: lat,
                center_lng: lng,
                radius_m: radius,
                active
            };

            try {
                if (editingId) {
                    await TrackingAPI.updateGeofence(editingId, data);
                    Toast.show('Geofence updated', 'success');
                } else {
                    await TrackingAPI.addGeofence(data);
                    Toast.show('Geofence created', 'success');
                }

                closeModal();
                loadGeofences();
            } catch (err) {
                Toast.show(err.message || 'Failed to save', 'error');
            }
        });

        // Delete
        async function deleteGeofence(id) {
            if (!confirm('Delete this geofence?')) return;

            try {
                await TrackingAPI.deleteGeofence(id);
                Toast.show('Geofence deleted', 'success');
                loadGeofences();
            } catch (err) {
                Toast.show(err.message || 'Failed to delete', 'error');
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        loadGeofences();
    </script>
</body>
</html>
