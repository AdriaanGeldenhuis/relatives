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

    // Get trip info
    $trip = HT_DB::fetchOne(
        "SELECT budget_currency, travelers_count FROM ht_trips WHERE id = ?",
        [$tripId]
    );

    // Get trip members
    $members = HT_DB::fetchAll(
        "SELECT tm.user_id, u.full_name as name, tm.role
         FROM ht_trip_members tm
         JOIN users u ON tm.user_id = u.id
         WHERE tm.trip_id = ? AND tm.status = 'joined'",
        [$tripId]
    );

    // Add trip owner
    $owner = HT_DB::fetchOne(
        "SELECT t.user_id, u.full_name as name
         FROM ht_trips t
         JOIN users u ON t.user_id = u.id
         WHERE t.id = ?",
        [$tripId]
    );

    // Build members map
    $memberMap = [];
    $memberMap[$owner['user_id']] = [
        'id' => (int) $owner['user_id'],
        'name' => $owner['name'],
        'paid' => 0.0,
        'share' => 0.0,
        'balance' => 0.0
    ];

    foreach ($members as $m) {
        if (!isset($memberMap[$m['user_id']])) {
            $memberMap[$m['user_id']] = [
                'id' => (int) $m['user_id'],
                'name' => $m['name'],
                'paid' => 0.0,
                'share' => 0.0,
                'balance' => 0.0
            ];
        }
    }

    $memberCount = count($memberMap);

    if ($memberCount === 0) {
        HT_Response::ok([
            'currency' => $trip['budget_currency'],
            'total' => 0,
            'per_person' => 0,
            'members' => [],
            'settlements' => [],
            'message' => 'No trip members found'
        ]);
        return;
    }

    // Get all expenses
    $expenses = HT_DB::fetchAll(
        "SELECT * FROM ht_trip_expenses WHERE trip_id = ?",
        [$tripId]
    );

    $total = 0.0;

    // Process each expense
    foreach ($expenses as $expense) {
        $amount = (float) $expense['amount'];
        $paidBy = (int) $expense['paid_by'];
        $splitWith = $expense['split_with_json'] ? json_decode($expense['split_with_json'], true) : null;

        $total += $amount;

        // Add to paid amount
        if (isset($memberMap[$paidBy])) {
            $memberMap[$paidBy]['paid'] += $amount;
        }

        // Calculate shares
        if ($splitWith && is_array($splitWith) && count($splitWith) > 0) {
            // Split with specific people
            $splitMembers = array_filter($splitWith, fn($id) => isset($memberMap[$id]));
            // Include payer in split if not already
            if (!in_array($paidBy, $splitMembers)) {
                $splitMembers[] = $paidBy;
            }
            $sharePerPerson = $amount / count($splitMembers);

            foreach ($splitMembers as $memberId) {
                if (isset($memberMap[$memberId])) {
                    $memberMap[$memberId]['share'] += $sharePerPerson;
                }
            }
        } else {
            // Split evenly among all members
            $sharePerPerson = $amount / $memberCount;
            foreach ($memberMap as $id => &$member) {
                $member['share'] += $sharePerPerson;
            }
        }
    }

    // Calculate balances (positive = owed money, negative = owes money)
    foreach ($memberMap as $id => &$member) {
        $member['balance'] = round($member['paid'] - $member['share'], 2);
        $member['paid'] = round($member['paid'], 2);
        $member['share'] = round($member['share'], 2);
    }

    // Calculate settlement transactions using greedy algorithm
    $settlements = calculateSettlements($memberMap);

    $perPerson = $memberCount > 0 ? round($total / $memberCount, 2) : 0;

    HT_Response::ok([
        'currency' => $trip['budget_currency'],
        'total' => round($total, 2),
        'per_person' => $perPerson,
        'member_count' => $memberCount,
        'members' => array_values($memberMap),
        'settlements' => $settlements
    ]);

} catch (Exception $e) {
    error_log('Settlement calculation error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}

/**
 * Calculate minimum number of transactions to settle balances
 * Uses a greedy algorithm to match debtors with creditors
 */
function calculateSettlements(array $members): array {
    $settlements = [];

    // Separate into debtors (negative balance) and creditors (positive balance)
    $debtors = [];
    $creditors = [];

    foreach ($members as $member) {
        if ($member['balance'] < -0.01) {
            $debtors[] = [
                'id' => $member['id'],
                'name' => $member['name'],
                'amount' => abs($member['balance'])
            ];
        } elseif ($member['balance'] > 0.01) {
            $creditors[] = [
                'id' => $member['id'],
                'name' => $member['name'],
                'amount' => $member['balance']
            ];
        }
    }

    // Sort by amount descending for more efficient matching
    usort($debtors, fn($a, $b) => $b['amount'] <=> $a['amount']);
    usort($creditors, fn($a, $b) => $b['amount'] <=> $a['amount']);

    // Match debtors with creditors
    while (count($debtors) > 0 && count($creditors) > 0) {
        $debtor = &$debtors[0];
        $creditor = &$creditors[0];

        $transferAmount = min($debtor['amount'], $creditor['amount']);

        if ($transferAmount > 0.01) {
            $settlements[] = [
                'from_id' => $debtor['id'],
                'from_name' => $debtor['name'],
                'to_id' => $creditor['id'],
                'to_name' => $creditor['name'],
                'amount' => round($transferAmount, 2)
            ];
        }

        $debtor['amount'] -= $transferAmount;
        $creditor['amount'] -= $transferAmount;

        // Remove settled parties
        if ($debtor['amount'] < 0.01) {
            array_shift($debtors);
        }
        if ($creditor['amount'] < 0.01) {
            array_shift($creditors);
        }
    }

    return $settlements;
}
