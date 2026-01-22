<?php
/**
 * Holiday Traveling API - Get Single Wallet Item
 * GET /api/wallet_get.php?id={item_id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

HT_Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $itemId = (int) ($_GET['id'] ?? 0);

    if (!$itemId) {
        HT_Response::error('Item ID is required', 400);
    }

    // Fetch item
    $item = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_wallet_items WHERE id = ?",
        [$itemId]
    );

    if (!$item) {
        HT_Response::error('Item not found', 404);
    }

    // Check access
    if (!HT_Auth::canAccessTrip($item['trip_id'])) {
        HT_Response::error('Access denied', 403);
    }

    HT_Response::ok($item);

} catch (Exception $e) {
    error_log('Wallet get error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
