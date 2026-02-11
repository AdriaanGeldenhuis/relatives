<?php
/**
 * ============================================
 * FAMILY TRACKING - EVENTS TIMELINE
 * Shows tracking events with filters and
 * infinite scroll / load more
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

$familyId = (int)$user['family_id'];

// Load initial events
$eventsRepo = new EventsRepo($db);
$filter = isset($_GET['type']) ? trim($_GET['type']) : null;
$allowedTypes = ['enter_geofence', 'exit_geofence', 'arrive_place', 'leave_place'];
if ($filter && !in_array($filter, $allowedTypes, true)) {
    $filter = null;
}
$events = $eventsRepo->list($familyId, 30, 0, $filter);

$pageTitle = 'Tracking Events';
$pageCSS = ['/tracking/app/assets/css/tracking.css?v=3.7'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<div class="tracking-content">

    <a href="/tracking/app/" class="tracking-back">Back to Map</a>

    <div class="tracking-content-header">
        <div>
            <h1 class="tracking-content-title">Events</h1>
            <p class="tracking-content-subtitle">Recent tracking activity for your family.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="event-filters">
        <a href="/tracking/app/events.php"
           class="event-filter-btn <?php echo !$filter ? 'active' : ''; ?>">All</a>
        <a href="/tracking/app/events.php?type=enter_geofence"
           class="event-filter-btn <?php echo $filter === 'enter_geofence' ? 'active' : ''; ?>">Enter Geofence</a>
        <a href="/tracking/app/events.php?type=exit_geofence"
           class="event-filter-btn <?php echo $filter === 'exit_geofence' ? 'active' : ''; ?>">Exit Geofence</a>
        <a href="/tracking/app/events.php?type=arrive_place"
           class="event-filter-btn <?php echo $filter === 'arrive_place' ? 'active' : ''; ?>">Arrive Place</a>
        <a href="/tracking/app/events.php?type=leave_place"
           class="event-filter-btn <?php echo $filter === 'leave_place' ? 'active' : ''; ?>">Leave Place</a>
    </div>

    <!-- Timeline -->
    <?php if (empty($events)): ?>
        <div class="tracking-empty">
            <div class="tracking-empty-icon"></div>
            <div class="tracking-empty-title">No events yet</div>
            <div class="tracking-empty-text">Events will appear here when family members enter or leave geofences and places.</div>
        </div>
    <?php else: ?>
        <div class="events-timeline" id="eventsTimeline">
            <?php foreach ($events as $ev): ?>
            <?php
                $meta = $ev['meta_json'] ? json_decode($ev['meta_json'], true) : [];
                $nodeClass = 'enter';
                $label = 'Event';
                switch ($ev['event_type']) {
                    case 'enter_geofence':
                        $nodeClass = 'enter';
                        $label = 'Entered Geofence';
                        break;
                    case 'exit_geofence':
                        $nodeClass = 'exit';
                        $label = 'Exited Geofence';
                        break;
                    case 'arrive_place':
                        $nodeClass = 'arrive';
                        $label = 'Arrived at Place';
                        break;
                    case 'leave_place':
                        $nodeClass = 'leave';
                        $label = 'Left Place';
                        break;
                }
                $userName = e($meta['user_name'] ?? $ev['user_name'] ?? 'Unknown');
                $targetName = e($meta['geofence_name'] ?? $meta['place_name'] ?? $meta['name'] ?? 'Unknown');
                $time = $ev['occurred_at'] ?? $ev['created_at'] ?? '';
                $timeFormatted = $time ? date('M j, g:i A', strtotime($time)) : '';
                $timeAgo = '';
                if ($time) {
                    $diff = time() - strtotime($time);
                    if ($diff < 60) $timeAgo = 'just now';
                    elseif ($diff < 3600) $timeAgo = floor($diff / 60) . 'm ago';
                    elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . 'h ago';
                    else $timeAgo = floor($diff / 86400) . 'd ago';
                }
            ?>
            <div class="event-item trk-slide-up">
                <div class="event-node <?php echo $nodeClass; ?>"></div>
                <div class="event-header">
                    <span class="event-type-label"><?php echo $label; ?></span>
                    <span class="event-time" title="<?php echo e($timeFormatted); ?>"><?php echo e($timeAgo); ?></span>
                </div>
                <div class="event-body">
                    <span class="event-user"><?php echo $userName; ?></span>
                    <?php if ($ev['event_type'] === 'enter_geofence' || $ev['event_type'] === 'arrive_place'): ?>
                        entered
                    <?php else: ?>
                        left
                    <?php endif; ?>
                    <span class="event-target"><?php echo $targetName; ?></span>
                    <?php if ($timeFormatted): ?>
                        <span style="display:block;margin-top:4px;font-size:11px;color:rgba(255,255,255,0.4)"><?php echo e($timeFormatted); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Load More -->
        <div class="events-load-more" id="loadMoreContainer">
            <button class="events-load-btn" id="loadMoreBtn">Load More</button>
        </div>

        <div class="events-loading" id="eventsLoading">
            <div class="spinner"></div>
        </div>
    <?php endif; ?>

</div>

<script>
    window.TrackingConfig = window.TrackingConfig || {};
    window.TrackingConfig.apiBase = '/tracking/api';
    window.TrackingConfig.familyId = <?php echo $familyId; ?>;
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

    var offset = 30;
    var limit = 30;
    var loading = false;
    var noMore = false;
    var filter = <?php echo json_encode($filter); ?>;
    var loadBtn = document.getElementById('loadMoreBtn');
    var loadingEl = document.getElementById('eventsLoading');
    var timeline = document.getElementById('eventsTimeline');
    var loadContainer = document.getElementById('loadMoreContainer');

    if (!loadBtn || !timeline) return;

    loadBtn.addEventListener('click', loadMore);

    // Infinite scroll
    window.addEventListener('scroll', function() {
        if (loading || noMore) return;
        var scrollBottom = window.innerHeight + window.scrollY;
        var docHeight = document.documentElement.scrollHeight;
        if (scrollBottom >= docHeight - 300) {
            loadMore();
        }
    });

    function loadMore() {
        if (loading || noMore) return;
        loading = true;
        loadBtn.disabled = true;
        loadingEl.classList.add('active');

        var url = window.TrackingConfig.apiBase + '/events_list.php' +
            '?limit=' + limit + '&offset=' + offset;
        if (filter) url += '&type=' + encodeURIComponent(filter);

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loading = false;
                loadBtn.disabled = false;
                loadingEl.classList.remove('active');

                if (!data.ok || !data.events || data.events.length === 0) {
                    noMore = true;
                    loadContainer.style.display = 'none';
                    return;
                }

                offset += data.events.length;

                data.events.forEach(function(ev) {
                    var meta = {};
                    if (ev.meta_json) {
                        try { meta = JSON.parse(ev.meta_json); } catch(e) {}
                    }

                    var nodeClass = 'enter';
                    var label = 'Event';
                    var action = 'entered';
                    switch (ev.event_type) {
                        case 'enter_geofence': nodeClass = 'enter'; label = 'Entered Geofence'; action = 'entered'; break;
                        case 'exit_geofence': nodeClass = 'exit'; label = 'Exited Geofence'; action = 'left'; break;
                        case 'arrive_place': nodeClass = 'arrive'; label = 'Arrived at Place'; action = 'entered'; break;
                        case 'leave_place': nodeClass = 'leave'; label = 'Left Place'; action = 'left'; break;
                    }

                    var userName = escapeHtml(meta.user_name || ev.user_name || 'Unknown');
                    var targetName = escapeHtml(meta.geofence_name || meta.place_name || meta.name || 'Unknown');
                    var time = ev.occurred_at || ev.created_at || '';
                    var timeAgo = formatTimeAgo(time);

                    var html = '<div class="event-item trk-slide-up">' +
                        '<div class="event-node ' + nodeClass + '"></div>' +
                        '<div class="event-header">' +
                        '<span class="event-type-label">' + label + '</span>' +
                        '<span class="event-time">' + timeAgo + '</span>' +
                        '</div>' +
                        '<div class="event-body">' +
                        '<span class="event-user">' + userName + '</span> ' + action + ' ' +
                        '<span class="event-target">' + targetName + '</span>' +
                        '</div></div>';

                    timeline.insertAdjacentHTML('beforeend', html);
                });

                if (data.events.length < limit) {
                    noMore = true;
                    loadContainer.style.display = 'none';
                }
            })
            .catch(function(err) {
                loading = false;
                loadBtn.disabled = false;
                loadingEl.classList.remove('active');
                console.error('[Events] Load error:', err);
            });
    }

    function formatTimeAgo(dateStr) {
        if (!dateStr) return '';
        var diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
</script>
