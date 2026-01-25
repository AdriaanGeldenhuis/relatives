<?php
/**
 * Holiday Traveling - Share Trip Page
 * Manage trip sharing and member invitations
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
$trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);

if (!$trip) {
    header('Location: /holiday_traveling/');
    exit;
}

// Generate share code if not exists
if (empty($trip['share_code'])) {
    $shareCode = bin2hex(random_bytes(8));
    HT_DB::update('ht_trips', ['share_code' => $shareCode], 'id = ?', [$tripId]);
    $trip['share_code'] = $shareCode;
}

// Get trip members
$members = HT_DB::fetchAll(
    "SELECT m.*, u.full_name, u.email, u.avatar_color
     FROM ht_trip_members m
     LEFT JOIN users u ON m.user_id = u.id
     WHERE m.trip_id = ?
     ORDER BY m.role = 'owner' DESC, m.joined_at ASC",
    [$tripId]
);

// Build share URL
$shareUrl = ($_ENV['APP_URL'] ?? 'https://relatives.app') . '/holiday_traveling/trip_join.php?code=' . $trip['share_code'];

// Page setup
$pageTitle = 'Share Trip - ' . $trip['destination'];
$pageCSS = ['/holiday_traveling/assets/css/holiday.css'];
$pageJS = [];

// Render view
ht_view('trip_share', [
    'trip' => $trip,
    'members' => $members,
    'shareUrl' => $shareUrl,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
