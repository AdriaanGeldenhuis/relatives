<?php
/**
 * GET /tracking/api/events_list.php
 *
 * Get tracking events for the family.
 *
 * Parameters:
 * - user_id (optional): filter by user
 * - event_types (optional): comma-separated list of types
 * - start_time (optional): ISO8601 or Unix timestamp
 * - end_time (optional): ISO8601 or Unix timestamp
 * - limit (optional): max events (default 50, max 500)
 * - offset (optional): for pagination
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Parse parameters
$options = [];

if (isset($_GET['user_id'])) {
    $options['user_id'] = (int)$_GET['user_id'];
}

if (isset($_GET['event_types'])) {
    $options['event_types'] = array_filter(explode(',', $_GET['event_types']));
}

if (isset($_GET['start_time'])) {
    $options['start_time'] = is_numeric($_GET['start_time'])
        ? date('Y-m-d H:i:s', (int)$_GET['start_time'])
        : $_GET['start_time'];
}

if (isset($_GET['end_time'])) {
    $options['end_time'] = is_numeric($_GET['end_time'])
        ? date('Y-m-d H:i:s', (int)$_GET['end_time'])
        : $_GET['end_time'];
}

$options['limit'] = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 50;
$options['offset'] = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Initialize services
$eventsRepo = new EventsRepo($db);

// Get events
$events = $eventsRepo->getList($familyId, $options);

// Get event type counts for context
$counts = $eventsRepo->countByType($familyId, $options['start_time'] ?? Time::subSeconds(86400));

jsonSuccess([
    'events' => $events,
    'count' => count($events),
    'type_counts' => $counts,
    'filters' => $options
]);
