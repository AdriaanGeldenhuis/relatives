<?php
/**
 * Holiday Traveling API - Add Packing Item
 * POST /api/packing_add.php
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

    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    // Validate fields
    $category = trim($input['category'] ?? '');
    $itemName = trim($input['item_name'] ?? '');
    $quantity = (int) ($input['quantity'] ?? 1);

    $validCategories = [
        'essentials', 'clothing', 'toiletries', 'electronics',
        'documents', 'medicine', 'entertainment', 'kids', 'weather', 'other'
    ];

    if (!in_array($category, $validCategories)) {
        HT_Response::error('Invalid category', 400);
    }

    if (empty($itemName) || strlen($itemName) > 255) {
        HT_Response::error('Item name is required (max 255 characters)', 400);
    }

    if ($quantity < 1 || $quantity > 99) {
        $quantity = 1;
    }

    $userId = HT_Auth::userId();

    // Check for duplicate
    $existing = HT_DB::fetchOne(
        "SELECT id FROM ht_trip_packing_items
         WHERE trip_id = ? AND user_id = ? AND category = ? AND item_name = ?",
        [$tripId, $userId, $category, $itemName]
    );

    if ($existing) {
        HT_Response::error('This item already exists in your packing list', 400);
    }

    // Insert item
    $itemId = HT_DB::insert('ht_trip_packing_items', [
        'trip_id' => $tripId,
        'user_id' => $userId,
        'category' => $category,
        'item_name' => $itemName,
        'quantity' => $quantity,
        'is_packed' => 0
    ]);

    error_log(sprintf(
        'Packing item added: Trip=%d, Item=%d, Category=%s, User=%d',
        $tripId, $itemId, $category, $userId
    ));

    HT_Response::created([
        'id' => $itemId,
        'category' => $category,
        'item_name' => $itemName,
        'quantity' => $quantity,
        'is_packed' => false
    ]);

} catch (Exception $e) {
    error_log('Packing add error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
