<?php
/**
 * Holiday Traveling API - Update Expense
 * POST /api/expenses_update.php
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

    // Build update data
    $updateData = [];

    // Category
    if (isset($input['category'])) {
        $validCategories = ['food', 'fuel', 'transport', 'stay', 'activity', 'shopping', 'tips', 'other'];
        if (!in_array($input['category'], $validCategories)) {
            HT_Response::error('Invalid category', 400);
        }
        $updateData['category'] = $input['category'];
    }

    // Description
    if (isset($input['description'])) {
        $description = trim($input['description']);
        if (empty($description) || strlen($description) > 255) {
            HT_Response::error('Description is required (max 255 characters)', 400);
        }
        $updateData['description'] = $description;
    }

    // Amount
    if (isset($input['amount'])) {
        $amount = (float) $input['amount'];
        if ($amount <= 0 || $amount > 9999999.99) {
            HT_Response::error('Amount must be between 0.01 and 9,999,999.99', 400);
        }
        $updateData['amount'] = $amount;
    }

    // Date
    if (isset($input['expense_date'])) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['expense_date'])) {
            $updateData['expense_date'] = $input['expense_date'];
        }
    }

    // Paid by
    if (isset($input['paid_by'])) {
        $updateData['paid_by'] = (int) $input['paid_by'];
    }

    // Split with
    if (array_key_exists('split_with', $input)) {
        if ($input['split_with'] && is_array($input['split_with'])) {
            $updateData['split_with_json'] = json_encode(array_map('intval', $input['split_with']));
        } else {
            $updateData['split_with_json'] = null;
        }
    }

    // Notes
    if (array_key_exists('notes', $input)) {
        $updateData['notes'] = trim($input['notes']) ?: null;
    }

    if (empty($updateData)) {
        HT_Response::error('No fields to update', 400);
    }

    // Update expense
    HT_DB::update('ht_trip_expenses', $updateData, 'id = ?', [$expenseId]);

    // Fetch updated expense
    $updatedExpense = HT_DB::fetchOne(
        "SELECT e.*, u.name as paid_by_name
         FROM ht_trip_expenses e
         LEFT JOIN users u ON e.paid_by = u.id
         WHERE e.id = ?",
        [$expenseId]
    );

    error_log(sprintf(
        'Expense updated: Expense=%d, User=%d',
        $expenseId, HT_Auth::userId()
    ));

    HT_Response::ok([
        'id' => $expenseId,
        'expense' => $updatedExpense
    ]);

} catch (Exception $e) {
    error_log('Expense update error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
