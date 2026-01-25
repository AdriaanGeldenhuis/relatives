<?php
/**
 * Holiday Traveling API - Toggle Packing Item Status
 * POST /api/packing_toggle.php
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

    // Toggle status
    $newStatus = !$item['is_packed'];
    $packedAt = $newStatus ? date('Y-m-d H:i:s') : null;

    HT_DB::update('ht_trip_packing_items', [
        'is_packed' => $newStatus ? 1 : 0,
        'packed_at' => $packedAt
    ], 'id = ?', [$itemId]);

    HT_Response::ok([
        'id' => $itemId,
        'is_packed' => $newStatus,
        'packed_at' => $packedAt
    ]);

} catch (Exception $e) {
    error_log('Packing toggle error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
