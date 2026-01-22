<?php
/**
 * Holiday Traveling API - Update Trip
 * POST /api/trips_update.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication
HT_Auth::requireLogin();

// Verify CSRF
HT_CSRF::verifyOrDie();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    HT_Response::error('Method not allowed', 405);
}

try {
    // Get input data
    $input = ht_json_input();

    // Get trip ID
    $tripId = (int) ($input['id'] ?? 0);
    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check edit permissions
    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('You do not have permission to edit this trip', 403);
    }

    // Verify trip exists
    $existingTrip = HT_DB::fetchOne("SELECT id, status FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$existingTrip) {
        HT_Response::error('Trip not found', 404);
    }

    // Clean and validate input
    $data = HT_Validators::cleanTripInput($input);
    $errors = HT_Validators::tripData($data);

    if (!empty($errors)) {
        HT_Response::validationError($errors);
    }

    // Validate status
    $validStatuses = ['draft', 'planned', 'locked', 'active', 'completed', 'cancelled'];
    $status = $input['status'] ?? $existingTrip['status'];
    if (!in_array($status, $validStatuses)) {
        $status = $existingTrip['status'];
    }

    // Build preferences JSON
    $preferences = [];
    if (!empty($input['travel_style'])) {
        $preferences['travel_style'] = $input['travel_style'];
    }
    if (!empty($input['pace'])) {
        $preferences['pace'] = $input['pace'];
    }
    if (!empty($input['interests'])) {
        $preferences['interests'] = is_array($input['interests'])
            ? $input['interests']
            : array_filter(explode(',', $input['interests']));
    }
    if (!empty($input['dietary_prefs'])) {
        $preferences['dietary'] = $input['dietary_prefs'];
    }
    if (!empty($input['mobility_notes'])) {
        $preferences['mobility'] = $input['mobility_notes'];
    }
    if (!empty($input['additional_notes'])) {
        $preferences['notes'] = $input['additional_notes'];
    }

    // Update trip
    $updateData = [
        'title' => $data['title'],
        'destination' => $data['destination'],
        'origin' => $data['origin'] ?: null,
        'start_date' => $data['start_date'],
        'end_date' => $data['end_date'],
        'travelers_count' => $data['travelers_count'],
        'budget_currency' => $data['budget_currency'],
        'budget_min' => $data['budget_min'],
        'budget_comfort' => $data['budget_comfort'],
        'budget_max' => $data['budget_max'],
        'preferences_json' => !empty($preferences) ? json_encode($preferences) : null,
        'status' => $status
    ];

    HT_DB::update('ht_trips', $updateData, 'id = ?', [$tripId]);

    // Log the update
    error_log(sprintf(
        'Trip updated: ID=%d, Title=%s, by User=%d',
        $tripId,
        $data['title'],
        HT_Auth::userId()
    ));

    // Return success
    HT_Response::ok([
        'id' => $tripId,
        'title' => $data['title'],
        'destination' => $data['destination'],
        'status' => $status,
        'message' => 'Trip updated successfully'
    ]);

} catch (Exception $e) {
    error_log('Trip update error: ' . $e->getMessage());
    HT_Response::error('Failed to update trip: ' . $e->getMessage(), 500);
}
