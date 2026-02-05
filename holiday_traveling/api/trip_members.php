<?php
/**
 * Holiday Traveling API - Get Trip Members
 * GET /api/trip_members.php?trip_id={id}
 *
 * Returns all members of a trip including the owner
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

    // Get trip owner
    $owner = HT_DB::fetchOne(
        "SELECT t.user_id, u.full_name as name, u.email
         FROM ht_trips t
         JOIN users u ON t.user_id = u.id
         WHERE t.id = ?",
        [$tripId]
    );

    if (!$owner) {
        HT_Response::error('Trip not found', 404);
    }

    $members = [];

    // Add owner first
    $members[] = [
        'id' => (int) $owner['user_id'],
        'name' => $owner['name'],
        'email' => $owner['email'],
        'role' => 'owner'
    ];

    // Get joined trip members
    $tripMembers = HT_DB::fetchAll(
        "SELECT tm.user_id, u.full_name as name, u.email, tm.role
         FROM ht_trip_members tm
         JOIN users u ON tm.user_id = u.id
         WHERE tm.trip_id = ? AND tm.status = 'joined'
         ORDER BY u.full_name",
        [$tripId]
    );

    foreach ($tripMembers as $m) {
        // Avoid duplicates if owner is also in members table
        if ((int) $m['user_id'] !== (int) $owner['user_id']) {
            $members[] = [
                'id' => (int) $m['user_id'],
                'name' => $m['name'],
                'email' => $m['email'],
                'role' => $m['role']
            ];
        }
    }

    HT_Response::ok([
        'trip_id' => $tripId,
        'members' => $members,
        'count' => count($members)
    ]);

} catch (Exception $e) {
    error_log('Trip members error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
