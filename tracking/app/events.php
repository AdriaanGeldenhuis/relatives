<?php
declare(strict_types=1);

/**
 * Tracking Events Page
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
    error_log('Tracking events error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$pageTitle = 'Tracking Events';
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Relatives</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/tracking.css?v=<?php echo $cacheVersion; ?>">
</head>
<body class="events-page">
    <!-- Top Bar -->
    <div class="tracking-topbar">
        <a href="index.php" class="back-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </a>
        <div class="topbar-title">Events</div>
        <div class="topbar-actions">
            <a href="geofences.php" class="icon-btn" title="Geofences">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <circle cx="12" cy="12" r="4"/>
                </svg>
            </a>
        </div>
    </div>

    <div class="events-container">
        <!-- Filters -->
        <div class="events-filters">
            <button class="filter-btn active" data-filter="all">All</button>
            <button class="filter-btn" data-filter="geofence">Geofence</button>
            <button class="filter-btn" data-filter="place">Places</button>
            <button class="filter-btn" data-filter="session">Sessions</button>
        </div>

        <!-- Events List -->
        <div id="events-list" class="events-list">
            <div style="text-align: center; padding: 40px; color: var(--gray-500);">
                Loading events...
            </div>
        </div>

        <!-- Load More -->
        <button id="btn-load-more" class="popup-btn" style="width: 100%; margin-top: 16px; display: none;">
            Load More
        </button>
    </div>

    <div id="toast-container" class="toast-container"></div>

    <script src="assets/js/format.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/api.js?v=<?php echo $cacheVersion; ?>"></script>
    <script>
        let currentFilter = 'all';
        let offset = 0;
        const limit = 30;

        // Filter button clicks
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentFilter = btn.dataset.filter;
                offset = 0;
                loadEvents(true);
            });
        });

        // Load more
        document.getElementById('btn-load-more').addEventListener('click', () => {
            loadEvents(false);
        });

        async function loadEvents(replace = true) {
            const list = document.getElementById('events-list');
            const loadMoreBtn = document.getElementById('btn-load-more');

            if (replace) {
                list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--gray-500);">Loading...</div>';
            }

            const params = { limit, offset };

            if (currentFilter === 'geofence') {
                params.event_types = 'enter_geofence,exit_geofence';
            } else if (currentFilter === 'place') {
                params.event_types = 'arrive_place,leave_place';
            } else if (currentFilter === 'session') {
                params.event_types = 'session_on,session_off';
            }

            try {
                const response = await TrackingAPI.getEvents(params);

                if (response.success) {
                    const events = response.data.events;

                    if (replace) {
                        list.innerHTML = '';
                    }

                    if (events.length === 0 && offset === 0) {
                        list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--gray-500);">No events found</div>';
                        loadMoreBtn.style.display = 'none';
                        return;
                    }

                    events.forEach(event => {
                        list.appendChild(createEventItem(event));
                    });

                    offset += events.length;
                    loadMoreBtn.style.display = events.length < limit ? 'none' : 'block';
                }
            } catch (err) {
                list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--danger);">Failed to load events</div>';
            }
        }

        function createEventItem(event) {
            const div = document.createElement('div');
            div.className = 'event-item';

            const iconClass = getIconClass(event.event_type);
            const icon = getIcon(event.event_type);
            const title = getEventTitle(event);

            div.innerHTML = `
                <div class="event-icon ${iconClass}">
                    ${icon}
                </div>
                <div class="event-content">
                    <div class="event-title">${escapeHtml(title)}</div>
                    <div class="event-meta">
                        ${event.user_name ? escapeHtml(event.user_name) + ' - ' : ''}
                        ${Format.timeAgo(event.occurred_at)}
                    </div>
                </div>
            `;

            return div;
        }

        function getIconClass(type) {
            if (type.includes('enter') || type.includes('arrive') || type === 'session_on') return 'enter';
            if (type.includes('exit') || type.includes('leave') || type === 'session_off') return 'exit';
            return 'session';
        }

        function getIcon(type) {
            if (type.includes('geofence')) {
                return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>';
            }
            if (type.includes('place')) {
                return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
            }
            return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
        }

        function getEventTitle(event) {
            const meta = event.meta || {};

            switch (event.event_type) {
                case 'enter_geofence':
                    return `Entered ${meta.geofence_name || 'geofence'}`;
                case 'exit_geofence':
                    return `Left ${meta.geofence_name || 'geofence'}`;
                case 'arrive_place':
                    return `Arrived at ${meta.place_label || 'place'}`;
                case 'leave_place':
                    return `Left ${meta.place_label || 'place'}`;
                case 'session_on':
                    return 'Tracking session started';
                case 'session_off':
                    return 'Tracking session ended';
                case 'alert_triggered':
                    return `Alert: ${meta.rule_type || 'unknown'}`;
                default:
                    return event.event_type.replace(/_/g, ' ');
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Initial load
        loadEvents(true);
    </script>
</body>
</html>
