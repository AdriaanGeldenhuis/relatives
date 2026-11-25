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

    $totals = HT_DB::fetchAll("SELECT currency, COUNT(*) AS count, SUM(amount) AS total
        FROM ht_trip_expenses WHERE trip_id = ? GROUP BY currency ORDER BY currency", [$tripId]);
    $byCategory = HT_DB::fetchAll("SELECT currency, category, SUM(amount) AS total, COUNT(*) AS count
        FROM ht_trip_expenses WHERE trip_id = ? GROUP BY currency, category ORDER BY currency, total DESC", [$tripId]);
    $budgetGroup = array_values(array_filter($totals, static fn(array $group): bool => $group['currency'] === $currency))[0] ?? [];
    HT_Response::ok([
        'currency' => $currency,
        'total' => (float) ($budgetGroup['total'] ?? 0),
        'count' => array_sum(array_column($totals, 'count')),
        'by_category' => $byCategory,
        'currencies' => $totals,
    ]);

} catch (Exception $e) {
    error_log('Expenses summary error: ' . $e->getMessage());
    HT_Response::error('Failed to fetch expenses summary', 500);
}
