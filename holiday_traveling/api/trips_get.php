<?php
/**
 * Holiday Traveling API - Get Single Trip
 * GET /api/trips_get.php?id={id}
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
    $tripId = (int) ($_GET['id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check if user can access this trip
    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('You do not have permission to view this trip', 403);
    }

    // Get trip with related data
    $trip = HT_DB::fetchOne(
        "SELECT t.*,
                u.full_name as creator_name,
                u.email as creator_email
         FROM ht_trips t
         LEFT JOIN users u ON t.user_id = u.id
         WHERE t.id = ?",
        [$tripId]
    );

    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Get trip members
    $members = HT_DB::fetchAll(
        "SELECT m.*, u.full_name, u.email, u.avatar_color
         FROM ht_trip_members m
         LEFT JOIN users u ON m.user_id = u.id
         WHERE m.trip_id = ?
         ORDER BY m.role = 'owner' DESC, m.joined_at ASC",
        [$tripId]
    );

    // Get active plan
    $activePlan = null;
    if ($trip['current_plan_version']) {
        $planRecord = HT_DB::fetchOne(
            "SELECT * FROM ht_trip_plan_versions
             WHERE trip_id = ? AND is_active = 1
             ORDER BY version_number DESC LIMIT 1",
            [$tripId]
        );

        if ($planRecord) {
            $activePlan = [
                'version' => (int) $planRecord['version_number'],
                'plan' => json_decode($planRecord['plan_json'], true),
                'summary' => $planRecord['summary_text'],
                'created_by' => $planRecord['created_by'],
                'created_at' => $planRecord['created_at']
            ];
        }
    }

    // Get plan version history (just metadata, not full plans)
    $planVersions = HT_DB::fetchAll(
        "SELECT id, version_number, summary_text, created_by, refinement_instruction, is_active, created_at
         FROM ht_trip_plan_versions
         WHERE trip_id = ?
         ORDER BY version_number DESC",
        [$tripId]
    );

    // Format response
    $response = [
        'id' => (int) $trip['id'],
        'title' => $trip['title'],
        'destination' => $trip['destination'],
        'origin' => $trip['origin'],
        'start_date' => $trip['start_date'],
        'end_date' => $trip['end_date'],
        'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
        'travelers_count' => (int) $trip['travelers_count'],
        'travelers' => $trip['travelers_json'] ? json_decode($trip['travelers_json'], true) : null,
        'budget' => [
            'currency' => $trip['budget_currency'],
            'min' => $trip['budget_min'] ? (float) $trip['budget_min'] : null,
            'comfort' => $trip['budget_comfort'] ? (float) $trip['budget_comfort'] : null,
            'max' => $trip['budget_max'] ? (float) $trip['budget_max'] : null,
        ],
        'preferences' => $trip['preferences_json'] ? json_decode($trip['preferences_json'], true) : null,
        'status' => $trip['status'],
        'share_code' => $trip['share_code'],
        'creator' => [
            'id' => (int) $trip['user_id'],
            'name' => $trip['creator_name'],
            'email' => $trip['creator_email']
        ],
        'members' => array_map(function($m) {
            return [
                'id' => (int) $m['id'],
                'user_id' => $m['user_id'] ? (int) $m['user_id'] : null,
                'name' => $m['full_name'],
                'email' => $m['email'] ?: $m['invited_email'],
                'avatar_color' => $m['avatar_color'],
                'role' => $m['role'],
                'status' => $m['status']
            ];
        }, $members),
        'plan' => $activePlan,
        'plan_versions' => array_map(function($v) {
            return [
                'id' => (int) $v['id'],
                'version' => (int) $v['version_number'],
                'summary' => $v['summary_text'],
                'created_by' => $v['created_by'],
                'refinement' => $v['refinement_instruction'],
                'is_active' => (bool) $v['is_active'],
                'created_at' => $v['created_at']
            ];
        }, $planVersions),
        'can_edit' => HT_Auth::canEditTrip($tripId),
        'created_at' => $trip['created_at'],
        'updated_at' => $trip['updated_at']
    ];

    HT_Response::ok($response);

} catch (Exception $e) {
    error_log('Trip get error: ' . $e->getMessage());
    HT_Response::error('Failed to fetch trip', 500);
}
