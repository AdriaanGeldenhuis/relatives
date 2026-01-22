<?php
/**
 * Holiday Traveling - View Trip Page
 * Displays trip details with tabbed interface
 */
declare(strict_types=1);

require_once __DIR__ . '/routes.php';

// Require authentication
HT_Auth::requireLogin();

// Get trip ID
$tripId = (int) ($_GET['id'] ?? 0);
if (!$tripId) {
    header('Location: /holiday_traveling/');
    exit;
}

// Check access
if (!HT_Auth::canAccessTrip($tripId)) {
    header('Location: /holiday_traveling/');
    exit;
}

// Fetch trip data
$trip = HT_DB::fetchOne(
    "SELECT t.*, u.full_name as creator_name
     FROM ht_trips t
     LEFT JOIN users u ON t.user_id = u.id
     WHERE t.id = ?",
    [$tripId]
);

if (!$trip) {
    header('Location: /holiday_traveling/');
    exit;
}

// Get active plan
$activePlan = null;
$planVersions = [];

if ($trip['current_plan_version']) {
    $planRecord = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_plan_versions
         WHERE trip_id = ? AND is_active = 1
         ORDER BY version_number DESC LIMIT 1",
        [$tripId]
    );

    if ($planRecord) {
        $activePlan = json_decode($planRecord['plan_json'], true);
        $activePlan['_version'] = (int) $planRecord['version_number'];
        $activePlan['_created_at'] = $planRecord['created_at'];
    }
}

// Get all plan versions for history dropdown
$planVersions = HT_DB::fetchAll(
    "SELECT id, version_number, summary_text, created_by, created_at
     FROM ht_trip_plan_versions
     WHERE trip_id = ?
     ORDER BY version_number DESC",
    [$tripId]
);

// Get trip members
$members = HT_DB::fetchAll(
    "SELECT m.*, u.full_name, u.email, u.avatar_color
     FROM ht_trip_members m
     LEFT JOIN users u ON m.user_id = u.id
     WHERE m.trip_id = ?
     ORDER BY m.role = 'owner' DESC, m.joined_at ASC",
    [$tripId]
);

// Check user permissions
$canEdit = HT_Auth::canEditTrip($tripId);

// Parse preferences
$preferences = $trip['preferences_json'] ? json_decode($trip['preferences_json'], true) : [];

// Get AI remaining requests
$aiRemaining = HT_AI::getRemainingRequests();

// Page setup
$pageTitle = $trip['destination'] . ' - Trip';
$pageCSS = ['/holiday_traveling/assets/css/holiday.css'];
$pageJS = ['/holiday_traveling/assets/js/ai_worker.js'];

// Render view
ht_view('trip_view', [
    'trip' => $trip,
    'activePlan' => $activePlan,
    'planVersions' => $planVersions,
    'members' => $members,
    'preferences' => $preferences,
    'canEdit' => $canEdit,
    'aiRemaining' => $aiRemaining,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
