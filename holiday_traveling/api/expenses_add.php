<?php
/**
 * Holiday Traveling API - Add Expense
 * POST /api/expenses_add.php
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

    // Get trip for currency
    $trip = HT_DB::fetchOne("SELECT budget_currency FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Validate required fields
    $category = trim($input['category'] ?? '');
    $description = trim($input['description'] ?? '');
    $amount = (float) ($input['amount'] ?? 0);

    $validCategories = ['food', 'fuel', 'transport', 'stay', 'activity', 'shopping', 'tips', 'other'];
    if (!in_array($category, $validCategories)) {
        HT_Response::error('Invalid category', 400);
    }

    if (empty($description) || strlen($description) > 255) {
        HT_Response::error('Description is required (max 255 characters)', 400);
    }

    if ($amount <= 0 || $amount > 9999999.99) {
        HT_Response::error('Amount must be between 0.01 and 9,999,999.99', 400);
    }

    // Optional fields
    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $currency = $input['currency'] ?? $trip['budget_currency'];
    $paidBy = (int) ($input['paid_by'] ?? HT_Auth::userId());
    $splitWith = $input['split_with'] ?? null;
    $notes = trim($input['notes'] ?? '');

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
        $expenseDate = date('Y-m-d');
    }

    // Validate split_with if provided
    $splitWithJson = null;
    if ($splitWith && is_array($splitWith)) {
        // Should be array of user IDs
        $splitWith = array_map('intval', $splitWith);
        $splitWithJson = json_encode($splitWith);
    }

    // Insert expense
    $expenseId = HT_DB::insert('ht_trip_expenses', [
        'trip_id' => $tripId,
        'category' => $category,
        'description' => $description,
        'amount' => $amount,
        'currency' => $currency,
        'expense_date' => $expenseDate,
        'paid_by' => $paidBy,
        'split_with_json' => $splitWithJson,
        'notes' => $notes ?: null
    ]);

    // Fetch created expense
    $expense = HT_DB::fetchOne(
        "SELECT e.*, u.name as paid_by_name
         FROM ht_trip_expenses e
         LEFT JOIN users u ON e.paid_by = u.id
         WHERE e.id = ?",
        [$expenseId]
    );

    error_log(sprintf(
        'Expense added: Trip=%d, Expense=%d, Amount=%.2f, User=%d',
        $tripId, $expenseId, $amount, HT_Auth::userId()
    ));

    HT_Response::created([
        'id' => $expenseId,
        'expense' => $expense
    ]);

} catch (Exception $e) {
    error_log('Expense add error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
