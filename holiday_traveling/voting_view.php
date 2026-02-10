<?php
/**
 * Holiday Traveling - Voting/Polls View Page
 * Manage group voting for trip decisions
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
$trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
if (!$trip) {
    header('Location: /holiday_traveling/');
    exit;
}

$canEdit = HT_Auth::canEditTrip($tripId);

// Page setup
$pageTitle = 'Voting - ' . $trip['destination'];
$pageCSS = [];
$pageJS = ['/holiday_traveling/assets/js/voting.js'];

// Render view
ht_view('voting_view', [
    'trip' => $trip,
    'canEdit' => $canEdit,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
