<?php
/**
 * Holiday Traveling - Edit Trip Page
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

// Check edit permissions
if (!HT_Auth::canEditTrip($tripId)) {
    header('Location: /holiday_traveling/');
    exit;
}

// Fetch trip data
$trip = HT_DB::fetchOne(
    "SELECT * FROM ht_trips WHERE id = ?",
    [$tripId]
);

if (!$trip) {
    header('Location: /holiday_traveling/');
    exit;
}

// Parse JSON fields
$preferences = $trip['preferences_json'] ? json_decode($trip['preferences_json'], true) : [];
$travelers = $trip['travelers_json'] ? json_decode($trip['travelers_json'], true) : [];

// Page setup
$pageTitle = 'Edit Trip - ' . $trip['destination'];
$pageCSS = ['/holiday_traveling/assets/css/holiday.css'];
$pageJS = [];

// Render view
ht_view('trip_edit', [
    'trip' => $trip,
    'preferences' => $preferences,
    'travelers' => $travelers,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
