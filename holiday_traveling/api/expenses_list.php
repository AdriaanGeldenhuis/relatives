<?php
/**
 * Holiday Traveling API - List Expenses
 * GET /api/expenses_list.php?trip_id={id}
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

    // Fetch expenses with payer info
    $expenses = HT_DB::fetchAll(
        "SELECT e.*, u.name as paid_by_name
         FROM ht_trip_expenses e
         LEFT JOIN users u ON e.paid_by = u.id
         WHERE e.trip_id = ?
         ORDER BY e.expense_date DESC, e.created_at DESC",
        [$tripId]
    );

    // Format expenses
    $formatted = array_map(function($e) {
        return [
            'id' => (int) $e['id'],
            'trip_id' => (int) $e['trip_id'],
            'category' => $e['category'],
            'description' => $e['description'],
            'amount' => (float) $e['amount'],
            'currency' => $e['currency'],
            'expense_date' => $e['expense_date'],
            'paid_by' => (int) $e['paid_by'],
            'paid_by_name' => $e['paid_by_name'] ?? 'Unknown',
            'split_with' => $e['split_with_json'] ? json_decode($e['split_with_json'], true) : [],
            'receipt_path' => $e['receipt_path'],
            'notes' => $e['notes'],
            'created_at' => $e['created_at']
        ];
    }, $expenses);

    HT_Response::ok($formatted);

} catch (Exception $e) {
    error_log('Expenses list error: ' . $e->getMessage());
    HT_Response::error('Failed to fetch expenses', 500);
}
