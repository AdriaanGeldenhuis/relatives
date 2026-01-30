<?php
/**
 * Holiday Traveling API - AI Generate Plan
 * POST /api/ai_generate_plan.php
 *
 * Generates a complete travel plan using AI based on trip details
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
    $input = ht_json_input();
    $tripId = (int) ($input['trip_id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check permissions
    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('You do not have permission to generate plans for this trip', 403);
    }

    // Fetch trip data
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Parse existing preferences
    $preferences = $trip['preferences_json'] ? json_decode($trip['preferences_json'], true) : [];
    $travelers = $trip['travelers_json'] ? json_decode($trip['travelers_json'], true) : [];

    // Build trip data for AI
    $tripData = [
        'destination' => $trip['destination'],
        'origin' => $trip['origin'],
        'start_date' => $trip['start_date'],
        'end_date' => $trip['end_date'],
        'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
        'travelers_count' => $trip['travelers_count'],
        'travelers' => $travelers,
        'budget' => [
            'currency' => $trip['budget_currency'],
            'min' => $trip['budget_min'],
            'comfort' => $trip['budget_comfort'],
            'max' => $trip['budget_max']
        ],
        'preferences' => $preferences
    ];

    // Add optional quick prompt if provided
    if (!empty($input['quick_prompt'])) {
        $tripData['additional_instructions'] = $input['quick_prompt'];
    }

    // Generate AI plan
    $plan = HT_AI::generatePlan($tripData);

    // Get next version number
    $lastVersion = HT_DB::fetchColumn(
        "SELECT MAX(version_number) FROM ht_trip_plan_versions WHERE trip_id = ?",
        [$tripId]
    );
    $newVersion = ($lastVersion ?? 0) + 1;

    // Deactivate previous versions
    HT_DB::execute(
        "UPDATE ht_trip_plan_versions SET is_active = 0 WHERE trip_id = ?",
        [$tripId]
    );

    // Save new plan version
    $planVersionId = HT_DB::insert('ht_trip_plan_versions', [
        'trip_id' => $tripId,
        'version_number' => $newVersion,
        'plan_json' => json_encode($plan),
        'summary_text' => generatePlanSummary($plan, $trip),
        'created_by' => 'ai',
        'is_active' => 1
    ]);

    // Update trip status and current version
    HT_DB::update('ht_trips', [
        'current_plan_version' => $newVersion,
        'status' => $trip['status'] === 'draft' ? 'planned' : $trip['status']
    ], 'id = ?', [$tripId]);

    // Log generation
    error_log(sprintf(
        'AI Plan generated: Trip=%d, Version=%d, User=%d',
        $tripId, $newVersion, HT_Auth::userId()
    ));

    // Auto-sync to Google Calendar if connected
    $calendarSync = null;
    $userId = HT_Auth::userId();

    if (HT_GoogleCalendar::isConnected($userId)) {
        try {
            $events = HT_GoogleCalendar::insertTripEvents($userId, $trip, $plan);
            $calendarSync = [
                'success' => true,
                'events_created' => count($events),
                'message' => count($events) . ' events added to Google Calendar'
            ];
            error_log(sprintf(
                'Auto calendar sync: Trip=%d, Events=%d, User=%d',
                $tripId, count($events), $userId
            ));
        } catch (Exception $e) {
            $calendarSync = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            error_log('Auto calendar sync failed: ' . $e->getMessage());
        }
    }

    HT_Response::ok([
        'trip_id' => $tripId,
        'version' => $newVersion,
        'plan' => $plan,
        'summary' => generatePlanSummary($plan, $trip),
        'calendar_sync' => $calendarSync
    ]);

} catch (Exception $e) {
    error_log('AI generate plan error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}

/**
 * Generate human-readable plan summary
 */
function generatePlanSummary(array $plan, array $trip): string {
    $parts = [];

    // Duration
    $days = ht_trip_duration($trip['start_date'], $trip['end_date']);
    $parts[] = "{$days}-day trip to {$trip['destination']}";

    // Activities count
    $activityCount = 0;
    foreach ($plan['itinerary'] ?? [] as $day) {
        $activityCount += count($day['morning'] ?? []);
        $activityCount += count($day['afternoon'] ?? []);
        $activityCount += count($day['evening'] ?? []);
    }
    if ($activityCount > 0) {
        $parts[] = "{$activityCount} activities planned";
    }

    // Accommodation options
    if (!empty($plan['stay_options'])) {
        $parts[] = count($plan['stay_options']) . " stay options";
    }

    // Budget estimate
    if (!empty($plan['budget_breakdown'])) {
        $total = array_sum($plan['budget_breakdown']);
        $parts[] = "Est. " . ht_format_currency($total, $trip['budget_currency']);
    }

    // Pace
    if (!empty($plan['meta']['pace'])) {
        $parts[] = ucfirst($plan['meta']['pace']) . " pace";
    }

    return implode(' • ', $parts);
}
