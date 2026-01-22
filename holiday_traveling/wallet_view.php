<?php
/**
 * Holiday Traveling - Wallet View Page
 * Manage offline travel wallet items
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

// Fetch wallet items
$walletItems = HT_DB::fetchAll(
    "SELECT * FROM ht_trip_wallet_items WHERE trip_id = ? ORDER BY is_essential DESC, sort_order ASC, created_at DESC",
    [$tripId]
);

$canEdit = HT_Auth::canEditTrip($tripId);

// Page setup
$pageTitle = 'Travel Wallet - ' . $trip['destination'];
$pageCSS = ['/holiday_traveling/assets/css/holiday.css'];
$pageJS = ['/holiday_traveling/assets/js/wallet.js'];

// Render view
ht_view('wallet_view', [
    'trip' => $trip,
    'walletItems' => $walletItems,
    'canEdit' => $canEdit,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
