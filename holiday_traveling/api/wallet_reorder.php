<?php
/**
 * Holiday Traveling API - Reorder Wallet Items
 * POST /api/wallet_reorder.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

HT_Auth::requireLogin();
HT_CSRF::verifyOrDie();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $input = ht_json_input();
    $tripId = (int) ($input['trip_id'] ?? 0);
    $order = $input['order'] ?? [];

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!is_array($order) || empty($order)) {
        HT_Response::error('Order array is required', 400);
    }

    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('Permission denied', 403);
    }

    // Verify all items belong to this trip
    $itemIds = array_map('intval', $order);
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $existingItems = HT_DB::fetchAll(
        "SELECT id FROM ht_trip_wallet_items WHERE id IN ({$placeholders}) AND trip_id = ?",
        array_merge($itemIds, [$tripId])
    );

    if (count($existingItems) !== count($itemIds)) {
        HT_Response::error('Invalid item IDs', 400);
    }

    // Update sort order for each item
    foreach ($itemIds as $index => $itemId) {
        HT_DB::update('ht_trip_wallet_items', [
            'sort_order' => $index + 1
        ], 'id = ?', [$itemId]);
    }

    error_log(sprintf(
        'Wallet items reordered: Trip=%d, Items=%d, User=%d',
        $tripId, count($itemIds), HT_Auth::userId()
    ));

    HT_Response::ok([
        'reordered' => true,
        'count' => count($itemIds)
    ]);

} catch (Exception $e) {
    error_log('Wallet reorder error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
