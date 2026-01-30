<?php
/**
 * Holiday Traveling API - AI Refine Plan
 * POST /api/ai_refine_plan.php
 *
 * Refines an existing plan based on user instruction
 * e.g., "Make it more relaxed", "Add more food options", "Swap Day 2 and Day 3"
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
    $instruction = trim($input['instruction'] ?? '');

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (empty($instruction)) {
        HT_Response::error('Refinement instruction is required', 400);
    }

    if (strlen($instruction) > 1000) {
        HT_Response::error('Instruction too long (max 1000 characters)', 400);
    }

    // Check permissions
    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('Permission denied', 403);
    }

    // Fetch trip and current plan
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Get current active plan
    $currentPlanRecord = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1 ORDER BY version_number DESC LIMIT 1",
        [$tripId]
    );

    if (!$currentPlanRecord) {
        HT_Response::error('No existing plan to refine. Generate a plan first.', 400);
    }

    $currentPlan = json_decode($currentPlanRecord['plan_json'], true);
    $preferences = $trip['preferences_json'] ? json_decode($trip['preferences_json'], true) : [];

    // Build refinement request for AI
    $tripData = [
        'destination' => $trip['destination'],
        'origin' => $trip['origin'],
        'start_date' => $trip['start_date'],
        'end_date' => $trip['end_date'],
        'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
        'travelers_count' => $trip['travelers_count'],
        'budget' => [
            'currency' => $trip['budget_currency'],
            'min' => $trip['budget_min'],
            'comfort' => $trip['budget_comfort'],
            'max' => $trip['budget_max']
        ],
        'preferences' => $preferences,
        'current_plan' => $currentPlan,
        'refinement_instruction' => $instruction
    ];

    // Call AI with refinement context
    $refinedPlan = HT_AI::generatePlan($tripData, $instruction);

    // Get next version number
    $newVersion = ((int) $currentPlanRecord['version_number']) + 1;

    // Deactivate previous versions
    HT_DB::execute(
        "UPDATE ht_trip_plan_versions SET is_active = 0 WHERE trip_id = ?",
        [$tripId]
    );

    // Save refined plan
    HT_DB::insert('ht_trip_plan_versions', [
        'trip_id' => $tripId,
        'version_number' => $newVersion,
        'plan_json' => json_encode($refinedPlan),
        'summary_text' => generateRefinementSummary($refinedPlan, $trip, $instruction),
        'created_by' => 'ai',
        'refinement_instruction' => $instruction,
        'is_active' => 1
    ]);

    // Update trip current version
    HT_DB::update('ht_trips', [
        'current_plan_version' => $newVersion
    ], 'id = ?', [$tripId]);

    // Log refinement
    error_log(sprintf(
        'AI Plan refined: Trip=%d, Version=%d, Instruction="%s", User=%d',
        $tripId, $newVersion, substr($instruction, 0, 50), HT_Auth::userId()
    ));

    // Auto-sync to Google Calendar if connected
    $calendarSync = null;
    $userId = HT_Auth::userId();

    if (HT_GoogleCalendar::isConnected($userId)) {
        try {
            $events = HT_GoogleCalendar::insertTripEvents($userId, $trip, $refinedPlan);
            $calendarSync = [
                'success' => true,
                'events_created' => count($events),
                'message' => count($events) . ' events updated in Google Calendar'
            ];
            error_log(sprintf(
                'Auto calendar sync (refine): Trip=%d, Events=%d, User=%d',
                $tripId, count($events), $userId
            ));
        } catch (Exception $e) {
            $calendarSync = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            error_log('Auto calendar sync (refine) failed: ' . $e->getMessage());
        }
    }

    HT_Response::ok([
        'trip_id' => $tripId,
        'version' => $newVersion,
        'plan' => $refinedPlan,
        'instruction' => $instruction,
        'calendar_sync' => $calendarSync
    ]);

} catch (Exception $e) {
    error_log('AI refine plan error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}

/**
 * Generate summary for refined plan
 */
function generateRefinementSummary(array $plan, array $trip, string $instruction): string {
    $days = ht_trip_duration($trip['start_date'], $trip['end_date']);

    // Shorten instruction for summary
    $shortInstruction = strlen($instruction) > 50
        ? substr($instruction, 0, 47) . '...'
        : $instruction;

    $parts = [
        "{$days}-day {$trip['destination']}",
        "Refined: \"{$shortInstruction}\""
    ];

    if (!empty($plan['meta']['pace'])) {
        $parts[] = ucfirst($plan['meta']['pace']) . " pace";
    }

    return implode(' • ', $parts);
}
