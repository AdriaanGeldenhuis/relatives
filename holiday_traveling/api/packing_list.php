<?php
/**
 * Holiday Traveling API - Get Packing List
 * GET /api/packing_list.php?trip_id={id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

HT_Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $tripId = (int) ($_GET['trip_id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    $userId = HT_Auth::userId();

    // Get user's packing items for this trip
    $items = HT_DB::fetchAll(
        "SELECT * FROM ht_trip_packing_items
         WHERE trip_id = ? AND user_id = ?
         ORDER BY category, item_name",
        [$tripId, $userId]
    );

    // Group by category
    $byCategory = [];
    $packedCount = 0;
    $totalCount = count($items);

    foreach ($items as $item) {
        $category = $item['category'];
        if (!isset($byCategory[$category])) {
            $byCategory[$category] = [];
        }
        $byCategory[$category][] = [
            'id' => (int) $item['id'],
            'name' => $item['item_name'],
            'quantity' => (int) $item['quantity'],
            'is_packed' => (bool) $item['is_packed'],
            'packed_at' => $item['packed_at']
        ];

        if ($item['is_packed']) {
            $packedCount++;
        }
    }

    HT_Response::ok([
        'trip_id' => $tripId,
        'items' => $items,
        'by_category' => $byCategory,
        'stats' => [
            'total' => $totalCount,
            'packed' => $packedCount,
            'remaining' => $totalCount - $packedCount,
            'percent' => $totalCount > 0 ? round(($packedCount / $totalCount) * 100) : 0
        ]
    ]);

} catch (Exception $e) {
    error_log('Packing list error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
