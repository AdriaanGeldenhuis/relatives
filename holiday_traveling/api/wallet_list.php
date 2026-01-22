<?php
/**
 * Holiday Traveling API - List Wallet Items
 * GET /api/wallet_list.php?trip_id={id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

HT_Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $tripId = (int) ($_GET['trip_id'] ?? 0);
    $limit = min(100, (int) ($_GET['limit'] ?? 50));

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    $items = HT_DB::fetchAll(
        "SELECT id, type, label, content, file_path, is_essential, created_at
         FROM ht_trip_wallet_items
         WHERE trip_id = ?
         ORDER BY is_essential DESC, sort_order ASC, created_at DESC
         LIMIT ?",
        [$tripId, $limit]
    );

    HT_Response::ok($items);

} catch (Exception $e) {
    error_log('Wallet list error: ' . $e->getMessage());
    HT_Response::error('Failed to fetch wallet items', 500);
}
