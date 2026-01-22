<?php
/**
 * Holiday Traveling API - Expenses Summary
 * GET /api/expenses_summary.php?trip_id={id}
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

    // Get trip currency
    $trip = HT_DB::fetchOne("SELECT budget_currency FROM ht_trips WHERE id = ?", [$tripId]);
    $currency = $trip['budget_currency'] ?? 'ZAR';

    // Get totals
    $totals = HT_DB::fetchOne(
        "SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total
         FROM ht_trip_expenses
         WHERE trip_id = ?",
        [$tripId]
    );

    // Get by category
    $byCategory = HT_DB::fetchAll(
        "SELECT category, SUM(amount) as total, COUNT(*) as count
         FROM ht_trip_expenses
         WHERE trip_id = ?
         GROUP BY category
         ORDER BY total DESC",
        [$tripId]
    );

    HT_Response::ok([
        'currency' => $currency,
        'total' => (float) $totals['total'],
        'count' => (int) $totals['count'],
        'by_category' => $byCategory
    ]);

} catch (Exception $e) {
    error_log('Expenses summary error: ' . $e->getMessage());
    HT_Response::error('Failed to fetch expenses summary', 500);
}
