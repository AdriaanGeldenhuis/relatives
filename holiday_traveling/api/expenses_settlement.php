<?php
/**
 * Holiday Traveling API - Calculate Expense Settlement
 * GET /api/expenses_settlement.php?trip_id={id}
 *
 * Calculates who owes who based on expenses and split arrangements
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

    require_once __DIR__ . '/../lib/expense-totals.php';
    $trip = HT_DB::fetchOne('SELECT budget_currency, user_id FROM ht_trips WHERE id = ?', [$tripId]);
    $members = HT_DB::fetchAll("SELECT DISTINCT u.id AS user_id, u.full_name AS name
        FROM users u LEFT JOIN ht_trip_members tm ON tm.user_id = u.id AND tm.trip_id = ? AND tm.status IN ('accepted', 'joined')
        WHERE u.id = ? OR tm.user_id IS NOT NULL ORDER BY u.id", [$tripId, $trip['user_id']]);
    $expenses = HT_DB::fetchAll("SELECT e.*, u.full_name AS paid_by_name FROM ht_trip_expenses e
        LEFT JOIN users u ON u.id = e.paid_by WHERE e.trip_id = ? ORDER BY e.id", [$tripId]);
    $currencies = array_keys(ht_expense_totals($expenses)) ?: [$trip['budget_currency']];
    $groups = array_map(static fn(string $currency): array => ht_expense_settlement($expenses, $members, $currency), $currencies);
    $primary = count($groups) === 1 ? $groups[0] : ht_expense_settlement($expenses, $members, $trip['budget_currency']);
    HT_Response::ok($primary + ['currencies' => $groups]);
} catch (Throwable $error) {
    error_log('Expense settlement could not be calculated: ' . $error->getMessage());
    HT_Response::error('Failed to calculate settlement', 500);
}
