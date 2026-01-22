<?php
/**
 * Holiday Traveling API - Generate Packing Suggestions
 * POST /api/packing_generate.php
 *
 * Uses trip data to generate AI-powered packing suggestions
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

    // Fetch trip details
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    $userId = HT_Auth::userId();

    // Get trip duration
    $startDate = new DateTime($trip['start_date']);
    $endDate = new DateTime($trip['end_date']);
    $duration = $startDate->diff($endDate)->days + 1;

    // Get traveler info
    $travelers = json_decode($trip['travelers_json'] ?? '[]', true) ?: [];
    $hasKids = false;
    foreach ($travelers as $t) {
        if (isset($t['age']) && (int)$t['age'] < 18) {
            $hasKids = true;
            break;
        }
    }

    // Get preferences
    $prefs = json_decode($trip['preferences_json'] ?? '{}', true) ?: [];

    // Generate standard packing suggestions based on trip parameters
    $suggestions = [];

    // Essentials (always needed)
    $suggestions['essentials'] = [
        'Passport/ID',
        'Wallet',
        'Phone + charger',
        'Medications',
        'First aid kit',
        'Sunscreen',
        'Hand sanitizer'
    ];

    // Clothing based on duration
    $suggestions['clothing'] = [
        'Underwear (' . min($duration + 2, 10) . ')',
        'Socks (' . min($duration + 2, 10) . ')',
        'T-shirts (' . min($duration, 7) . ')',
        'Pants/shorts (' . ceil($duration / 2) . ')',
        'Sleepwear',
        'Comfortable shoes',
        'Sandals/flip-flops'
    ];

    // Toiletries
    $suggestions['toiletries'] = [
        'Toothbrush & toothpaste',
        'Shampoo & conditioner',
        'Deodorant',
        'Razor',
        'Hairbrush/comb'
    ];

    // Electronics
    $suggestions['electronics'] = [
        'Phone charger',
        'Power bank',
        'Camera',
        'Headphones',
        'Travel adapter'
    ];

    // Documents
    $suggestions['documents'] = [
        'Passport/ID',
        'Travel insurance',
        'Booking confirmations',
        'Credit/debit cards',
        'Emergency contacts'
    ];

    // Kids section if applicable
    if ($hasKids) {
        $suggestions['kids'] = [
            'Diapers/wipes',
            'Baby formula/food',
            'Favorite toys',
            'Coloring books',
            'Snacks',
            'Change of clothes',
            'Car seat'
        ];
    }

    // Weather-based suggestions (could be enhanced with API)
    $suggestions['weather'] = [
        'Rain jacket',
        'Umbrella',
        'Sunglasses',
        'Hat/cap'
    ];

    // Count existing items to avoid duplicates
    $existingItems = HT_DB::fetchAll(
        "SELECT category, item_name FROM ht_trip_packing_items WHERE trip_id = ? AND user_id = ?",
        [$tripId, $userId]
    );

    $existingMap = [];
    foreach ($existingItems as $item) {
        $key = strtolower($item['category'] . ':' . $item['item_name']);
        $existingMap[$key] = true;
    }

    // Insert suggestions that don't exist
    $addedCount = 0;
    foreach ($suggestions as $category => $items) {
        foreach ($items as $itemName) {
            $key = strtolower($category . ':' . $itemName);
            if (!isset($existingMap[$key])) {
                // Extract quantity from item name if present (e.g., "T-shirts (5)")
                $quantity = 1;
                if (preg_match('/\((\d+)\)$/', $itemName, $matches)) {
                    $quantity = (int) $matches[1];
                    $itemName = trim(preg_replace('/\s*\(\d+\)$/', '', $itemName));
                }

                HT_DB::insert('ht_trip_packing_items', [
                    'trip_id' => $tripId,
                    'user_id' => $userId,
                    'category' => $category,
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'is_packed' => 0
                ]);
                $addedCount++;
            }
        }
    }

    error_log(sprintf(
        'Packing suggestions generated: Trip=%d, Added=%d, User=%d',
        $tripId, $addedCount, $userId
    ));

    HT_Response::ok([
        'added_count' => $addedCount,
        'categories' => array_keys($suggestions),
        'message' => $addedCount > 0
            ? "Added $addedCount items to your packing list!"
            : "No new items to add - your list already has these essentials."
    ]);

} catch (Exception $e) {
    error_log('Packing generate error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
