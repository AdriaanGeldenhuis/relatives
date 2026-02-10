<?php
/**
 * Holiday Traveling - Main Entry Point
 * Displays the dashboard with trip list
 */
declare(strict_types=1);

require_once __DIR__ . '/routes.php';

// Require authentication
HT_Auth::requireLogin();

// Get current user info
$userId = HT_Auth::userId();
$familyId = HT_Auth::familyId();

// Fetch user's trips
$trips = HT_DB::fetchAll(
    "SELECT t.*,
            (SELECT COUNT(*) FROM ht_trip_members WHERE trip_id = t.id AND status = 'joined') as member_count,
            (SELECT version_number FROM ht_trip_plan_versions WHERE trip_id = t.id AND is_active = 1 LIMIT 1) as active_plan_version
     FROM ht_trips t
     WHERE t.family_id = ? OR t.user_id = ?
     ORDER BY
        CASE t.status
            WHEN 'active' THEN 1
            WHEN 'planned' THEN 2
            WHEN 'draft' THEN 3
            WHEN 'locked' THEN 4
            WHEN 'completed' THEN 5
            WHEN 'cancelled' THEN 6
        END,
        t.start_date ASC",
    [$familyId, $userId]
);

// Separate trips by status for display
$activeTrips = array_filter($trips, fn($t) => $t['status'] === 'active');
$upcomingTrips = array_filter($trips, fn($t) => in_array($t['status'], ['draft', 'planned', 'locked']));
$pastTrips = array_filter($trips, fn($t) => in_array($t['status'], ['completed', 'cancelled']));

// Page setup
$pageTitle = 'Holiday & Traveling';
$pageCSS = [];
$pageJS = [];

// Render dashboard view
ht_view('dashboard', [
    'trips' => $trips,
    'activeTrips' => $activeTrips,
    'upcomingTrips' => $upcomingTrips,
    'pastTrips' => $pastTrips,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
