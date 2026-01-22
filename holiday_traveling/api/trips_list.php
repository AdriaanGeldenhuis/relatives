<?php
/**
 * Holiday Traveling API - List Trips
 * GET /api/trips_list.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication
HT_Auth::requireLogin();

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $userId = HT_Auth::userId();
    $familyId = HT_Auth::familyId();

    // Get filter parameters
    $status = $_GET['status'] ?? null;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    // Build query
    $where = "(t.family_id = ? OR t.user_id = ?)";
    $params = [$familyId, $userId];

    if ($status) {
        $where .= " AND t.status = ?";
        $params[] = $status;
    }

    // Get total count
    $total = (int) HT_DB::fetchColumn(
        "SELECT COUNT(*) FROM ht_trips t WHERE {$where}",
        $params
    );

    // Get trips
    $trips = HT_DB::fetchAll(
        "SELECT t.*,
                u.full_name as creator_name,
                (SELECT COUNT(*) FROM ht_trip_members WHERE trip_id = t.id AND status = 'joined') as member_count,
                (SELECT version_number FROM ht_trip_plan_versions WHERE trip_id = t.id AND is_active = 1 LIMIT 1) as active_plan_version
         FROM ht_trips t
         LEFT JOIN users u ON t.user_id = u.id
         WHERE {$where}
         ORDER BY
            CASE t.status
                WHEN 'active' THEN 1
                WHEN 'planned' THEN 2
                WHEN 'draft' THEN 3
                WHEN 'locked' THEN 4
                WHEN 'completed' THEN 5
                WHEN 'cancelled' THEN 6
            END,
            t.start_date ASC
         LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );

    // Format trips for response
    $formattedTrips = array_map(function($trip) {
        return [
            'id' => (int) $trip['id'],
            'title' => $trip['title'],
            'destination' => $trip['destination'],
            'origin' => $trip['origin'],
            'start_date' => $trip['start_date'],
            'end_date' => $trip['end_date'],
            'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
            'travelers_count' => (int) $trip['travelers_count'],
            'budget' => [
                'currency' => $trip['budget_currency'],
                'min' => $trip['budget_min'] ? (float) $trip['budget_min'] : null,
                'comfort' => $trip['budget_comfort'] ? (float) $trip['budget_comfort'] : null,
                'max' => $trip['budget_max'] ? (float) $trip['budget_max'] : null,
            ],
            'status' => $trip['status'],
            'member_count' => (int) $trip['member_count'],
            'plan_version' => $trip['active_plan_version'] ? (int) $trip['active_plan_version'] : null,
            'creator_name' => $trip['creator_name'],
            'created_at' => $trip['created_at'],
            'updated_at' => $trip['updated_at']
        ];
    }, $trips);

    HT_Response::paginated($formattedTrips, $total, $page, $perPage);

} catch (Exception $e) {
    error_log('Trips list error: ' . $e->getMessage());
    HT_Response::error('Failed to fetch trips', 500);
}
