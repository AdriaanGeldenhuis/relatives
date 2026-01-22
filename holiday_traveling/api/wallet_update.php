<?php
/**
 * Holiday Traveling API - Update Wallet Item
 * POST /api/wallet_update.php
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

    // Build update data
    $updateData = [];

    // Type (if provided)
    if (isset($input['type'])) {
        $validTypes = ['ticket', 'booking', 'doc', 'note', 'qr', 'link', 'contact', 'insurance', 'visa'];
        if (!in_array($input['type'], $validTypes)) {
            HT_Response::error('Invalid item type', 400);
        }
        $updateData['type'] = $input['type'];
    }

    // Label (if provided)
    if (isset($input['label'])) {
        $label = trim($input['label']);
        if (empty($label) || strlen($label) > 255) {
            HT_Response::error('Label is required (max 255 characters)', 400);
        }
        $updateData['label'] = $label;
    }

    // Content (if provided)
    if (array_key_exists('content', $input)) {
        if ($input['content'] && strlen($input['content']) > 65535) {
            HT_Response::error('Content too large (max 64KB)', 400);
        }
        $updateData['content'] = $input['content'];
    }

    // File path (if provided)
    if (array_key_exists('file_path', $input)) {
        $updateData['file_path'] = $input['file_path'];
    }

    // Essential flag (if provided)
    if (isset($input['is_essential'])) {
        $updateData['is_essential'] = !empty($input['is_essential']) ? 1 : 0;
    }

    // Sort order (if provided)
    if (isset($input['sort_order'])) {
        $updateData['sort_order'] = (int) $input['sort_order'];
    }

    if (empty($updateData)) {
        HT_Response::error('No fields to update', 400);
    }

    // Update item
    HT_DB::update('ht_trip_wallet_items', $updateData, 'id = ?', [$itemId]);

    // Fetch updated item
    $updatedItem = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_wallet_items WHERE id = ?",
        [$itemId]
    );

    error_log(sprintf(
        'Wallet item updated: Item=%d, User=%d',
        $itemId, HT_Auth::userId()
    ));

    HT_Response::ok([
        'id' => $itemId,
        'item' => $updatedItem
    ]);

} catch (Exception $e) {
    error_log('Wallet update error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
