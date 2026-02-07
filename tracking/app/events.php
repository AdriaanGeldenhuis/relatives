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

$pageTitle = 'Tracking Events';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<main class="main-content">
    <div class="container" style="max-width:800px;margin:0 auto;padding:20px;">
        <div class="tracking-page-header">
            <a href="/tracking/app/" class="back-link">&larr; Back to Map</a>
            <h1>Tracking Events</h1>
        </div>

        <div class="events-filter">
            <select id="eventTypeFilter" onchange="loadEvents()">
                <option value="">All Events</option>
                <option value="geofence_enter">Geofence Enter</option>
                <option value="geofence_exit">Geofence Exit</option>
                <option value="session_start">Session Start</option>
                <option value="session_stop">Session Stop</option>
                <option value="low_battery">Low Battery</option>
                <option value="speed_alert">Speed Alert</option>
            </select>
        </div>

        <div class="events-timeline" id="eventsTimeline">
            <div class="panel-loading">Loading events...</div>
        </div>

        <button class="load-more-btn" id="loadMoreBtn" style="display:none;" onclick="loadMore()">Load More</button>
    </div>
</main>

<script>
var eventsOffset = 0;
var eventsLimit = 50;

function loadEvents(append) {
    if (!append) { eventsOffset = 0; }
    var type = document.getElementById('eventTypeFilter').value;
    var url = '/tracking/api/events.php?limit=' + eventsLimit + '&offset=' + eventsOffset;
    if (type) url += '&type=' + type;

    fetch(url, {credentials: 'same-origin'}).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success && data.data) {
            renderEvents(data.data, append);
            document.getElementById('loadMoreBtn').style.display = data.data.length >= eventsLimit ? 'block' : 'none';
        }
    });
}

function loadMore() {
    eventsOffset += eventsLimit;
    loadEvents(true);
}

function renderEvents(events, append) {
    var container = document.getElementById('eventsTimeline');
    if (!append) container.innerHTML = '';

    if (events.length === 0 && !append) {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon">📭</div><p>No events yet</p></div>';
        return;
    }

    events.forEach(function(evt) {
        var icons = {geofence_enter:'📍',geofence_exit:'🚶',session_start:'▶️',session_stop:'⏹️',low_battery:'🔋',speed_alert:'⚡',sos:'🚨',custom:'📋'};
        var icon = icons[evt.event_type] || '📋';
        var time = evt.created_at ? new Date(evt.created_at).toLocaleString() : '';

        var div = document.createElement('div');
        div.className = 'event-item';
        div.innerHTML = '<div class="event-icon">' + icon + '</div>' +
            '<div class="event-content">' +
                '<div class="event-title">' + (evt.title || evt.event_type) + '</div>' +
                '<div class="event-meta">' + (evt.full_name || 'Unknown') + ' &middot; ' + time + '</div>' +
                (evt.description ? '<div class="event-desc">' + evt.description + '</div>' : '') +
            '</div>';
        container.appendChild(div);
    });
}

loadEvents();
</script>

<?php
$pageJS = [];
require_once __DIR__ . '/../../shared/components/footer.php';
?>
