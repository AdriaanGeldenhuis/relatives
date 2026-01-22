<?php
/**
 * Holiday Traveling API - Delete Wallet Item
 * POST /api/wallet_delete.php
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
    $itemId = (int) ($input['item_id'] ?? 0);

    if (!$itemId) {
        HT_Response::error('Item ID is required', 400);
    }

    // Fetch item and check ownership
    $item = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_wallet_items WHERE id = ?",
        [$itemId]
    );

    if (!$item) {
        HT_Response::error('Item not found', 404);
    }

    if (!HT_Auth::canEditTrip($item['trip_id'])) {
        HT_Response::error('Permission denied', 403);
    }

    // Delete item
    HT_DB::delete('ht_trip_wallet_items', 'id = ?', [$itemId]);

    error_log(sprintf(
        'Wallet item deleted: Item=%d, Trip=%d, User=%d',
        $itemId, $item['trip_id'], HT_Auth::userId()
    ));

    HT_Response::ok([
        'deleted' => true,
        'item_id' => $itemId
    ]);

} catch (Exception $e) {
    error_log('Wallet delete error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
