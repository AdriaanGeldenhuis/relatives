<?php
/**
 * Holiday Traveling API - Add Wallet Item
 * POST /api/wallet_add.php
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

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('Permission denied', 403);
    }

    // Validate required fields
    $type = trim($input['type'] ?? '');
    $label = trim($input['label'] ?? '');

    $validTypes = ['ticket', 'booking', 'doc', 'note', 'qr', 'link', 'contact', 'insurance', 'visa'];
    if (!in_array($type, $validTypes)) {
        HT_Response::error('Invalid item type', 400);
    }

    if (empty($label) || strlen($label) > 255) {
        HT_Response::error('Label is required (max 255 characters)', 400);
    }

    // Optional fields
    $content = $input['content'] ?? null;
    $filePath = $input['file_path'] ?? null;
    $isEssential = !empty($input['is_essential']) ? 1 : 0;

    // Limit content size
    if ($content && strlen($content) > 65535) {
        HT_Response::error('Content too large (max 64KB)', 400);
    }

    // Get next sort order
    $maxOrder = HT_DB::fetchColumn(
        "SELECT MAX(sort_order) FROM ht_trip_wallet_items WHERE trip_id = ?",
        [$tripId]
    );
    $sortOrder = ($maxOrder ?? 0) + 1;

    // Insert wallet item
    $itemId = HT_DB::insert('ht_trip_wallet_items', [
        'trip_id' => $tripId,
        'user_id' => HT_Auth::userId(),
        'type' => $type,
        'label' => $label,
        'content' => $content,
        'file_path' => $filePath,
        'is_essential' => $isEssential,
        'sort_order' => $sortOrder
    ]);

    // Fetch created item
    $item = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_wallet_items WHERE id = ?",
        [$itemId]
    );

    error_log(sprintf(
        'Wallet item added: Trip=%d, Item=%d, Type=%s, User=%d',
        $tripId, $itemId, $type, HT_Auth::userId()
    ));

    HT_Response::created([
        'id' => $itemId,
        'item' => $item
    ]);

} catch (Exception $e) {
    error_log('Wallet add error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
