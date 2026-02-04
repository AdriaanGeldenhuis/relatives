<?php
/**
 * Holiday Traveling - Expenses View Page
 * Manage trip expense tracking
 */
declare(strict_types=1);

require_once __DIR__ . '/routes.php';

// Require authentication
HT_Auth::requireLogin();

// Get trip ID
$tripId = (int) ($_GET['id'] ?? 0);
if (!$tripId) {
    header('Location: /holiday_traveling/');
    exit;
}

// Check access
if (!HT_Auth::canAccessTrip($tripId)) {
    header('Location: /holiday_traveling/');
    exit;
}

// Fetch trip data
$trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
if (!$trip) {
    header('Location: /holiday_traveling/');
    exit;
}

// Fetch expenses
$expenses = HT_DB::fetchAll(
    "SELECT e.*, u.full_name as paid_by_name
     FROM ht_trip_expenses e
     LEFT JOIN users u ON e.paid_by = u.id
     WHERE e.trip_id = ?
     ORDER BY e.expense_date DESC, e.created_at DESC",
    [$tripId]
);

// Calculate totals by category
$categoryTotals = [];
$grandTotal = 0;
foreach ($expenses as $exp) {
    $category = $exp['category'];
    if (!isset($categoryTotals[$category])) {
        $categoryTotals[$category] = 0;
    }
    $categoryTotals[$category] += (float) $exp['amount'];
    $grandTotal += (float) $exp['amount'];
}

$canEdit = HT_Auth::canEditTrip($tripId);

// Page setup
$pageTitle = 'Expenses - ' . $trip['destination'];
$pageCSS = ['/holiday_traveling/assets/css/holiday.css'];
$pageJS = ['/holiday_traveling/assets/js/expenses.js'];

// Render view
ht_view('expenses_view', [
    'trip' => $trip,
    'expenses' => $expenses,
    'categoryTotals' => $categoryTotals,
    'grandTotal' => $grandTotal,
    'canEdit' => $canEdit,
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
