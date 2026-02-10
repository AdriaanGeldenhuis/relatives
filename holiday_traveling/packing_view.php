<?php
/**
 * Holiday Traveling - Packing List Page
 * Manage trip packing checklist
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

// Check if in "late mode" (within 24 hours of trip start)
$startTime = strtotime($trip['start_date']);
$now = time();
$hoursUntilTrip = ($startTime - $now) / 3600;
$isLateMode = $hoursUntilTrip >= 0 && $hoursUntilTrip <= 24;

// Page setup
$pageTitle = 'Packing List - ' . $trip['destination'];
$pageCSS = [];
$pageJS = ['/holiday_traveling/assets/js/packing.js'];

// Render view
ht_view('packing_view', [
    'trip' => $trip,
    'isLateMode' => $isLateMode,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
