<?php
/**
 * Holiday Traveling API - Delete Expense
 * POST /api/expenses_delete.php
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
    $expenseId = (int) ($input['expense_id'] ?? 0);

    if (!$expenseId) {
        HT_Response::error('Expense ID is required', 400);
    }

    // Fetch expense
    $expense = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_expenses WHERE id = ?",
        [$expenseId]
    );

    if (!$expense) {
        HT_Response::error('Expense not found', 404);
    }

    if (!HT_Auth::canEditTrip($expense['trip_id'])) {
        HT_Response::error('Permission denied', 403);
    }

    // Delete expense
    HT_DB::delete('ht_trip_expenses', 'id = ?', [$expenseId]);

    error_log(sprintf(
        'Expense deleted: Expense=%d, Trip=%d, User=%d',
        $expenseId, $expense['trip_id'], HT_Auth::userId()
    ));

    HT_Response::ok([
        'deleted' => true,
        'expense_id' => $expenseId
    ]);

} catch (Exception $e) {
    error_log('Expense delete error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
