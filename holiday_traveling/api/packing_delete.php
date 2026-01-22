<?php
/**
 * Holiday Traveling API - Delete Packing Item
 * POST /api/packing_delete.php
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

    $userId = HT_Auth::userId();

    // Fetch item and verify ownership
    $item = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_packing_items WHERE id = ? AND user_id = ?",
        [$itemId, $userId]
    );

    if (!$item) {
        HT_Response::error('Item not found', 404);
    }

    // Delete item
    HT_DB::delete('ht_trip_packing_items', 'id = ?', [$itemId]);

    error_log(sprintf(
        'Packing item deleted: Item=%d, Trip=%d, User=%d',
        $itemId, $item['trip_id'], $userId
    ));

    HT_Response::ok([
        'deleted' => true,
        'item_id' => $itemId
    ]);

} catch (Exception $e) {
    error_log('Packing delete error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
