<?php
/**
 * Holiday Traveling API - AI Late Mode
 * POST /api/ai_late_mode.php
 *
 * Adjusts today's schedule when running late
 * Takes into account: how late, energy level, whether to keep dinner plans
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
    $lateMinutes = (int) ($input['late_minutes'] ?? 30);
    $energy = $input['energy'] ?? 'ok'; // tired, ok, keen
    $keepDinner = ($input['keep_dinner'] ?? true) !== false;

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Validate inputs
    $lateMinutes = max(15, min(240, $lateMinutes)); // 15 min to 4 hours
    if (!in_array($energy, ['tired', 'ok', 'keen'])) {
        $energy = 'ok';
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

    // Check if trip is active (today is within trip dates)
    $today = date('Y-m-d');
    if ($today < $trip['start_date'] || $today > $trip['end_date']) {
        HT_Response::error('Late mode is only available during the trip', 400);
    }

    // Get current active plan
    $currentPlanRecord = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1 ORDER BY version_number DESC LIMIT 1",
        [$tripId]
    );

    if (!$currentPlanRecord) {
        HT_Response::error('No plan to adjust. Generate a plan first.', 400);
    }

    $currentPlan = json_decode($currentPlanRecord['plan_json'], true);

    // Find today's day number in the itinerary
    $tripStart = new DateTime($trip['start_date']);
    $todayDate = new DateTime($today);
    $dayNumber = (int) $todayDate->diff($tripStart)->days + 1;

    // Find today's itinerary
    $todayItinerary = null;
    foreach ($currentPlan['itinerary'] ?? [] as $day) {
        if (($day['day'] ?? 0) == $dayNumber || ($day['date'] ?? '') == $today) {
            $todayItinerary = $day;
            break;
        }
    }

    if (!$todayItinerary) {
        HT_Response::error('Could not find today\'s itinerary', 400);
    }

    // Build late mode instruction for AI
    $lateInstruction = buildLateModeInstruction($lateMinutes, $energy, $keepDinner, $todayItinerary);

    $tripData = [
        'destination' => $trip['destination'],
        'start_date' => $trip['start_date'],
        'end_date' => $trip['end_date'],
        'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
        'travelers_count' => $trip['travelers_count'],
        'budget' => [
            'currency' => $trip['budget_currency'],
            'comfort' => $trip['budget_comfort']
        ],
        'current_plan' => $currentPlan,
        'late_mode' => [
            'day_number' => $dayNumber,
            'late_minutes' => $lateMinutes,
            'energy' => $energy,
            'keep_dinner' => $keepDinner,
            'today_itinerary' => $todayItinerary
        ],
        'refinement_instruction' => $lateInstruction
    ];

    // Call AI with late mode context
    $adjustedPlan = HT_AI::generatePlan($tripData, $lateInstruction);

    // Get next version number
    $newVersion = ((int) $currentPlanRecord['version_number']) + 1;

    // Deactivate previous versions
    HT_DB::execute(
        "UPDATE ht_trip_plan_versions SET is_active = 0 WHERE trip_id = ?",
        [$tripId]
    );

    // Save adjusted plan
    HT_DB::insert('ht_trip_plan_versions', [
        'trip_id' => $tripId,
        'version_number' => $newVersion,
        'plan_json' => json_encode($adjustedPlan),
        'summary_text' => "Late mode: {$lateMinutes}min delay on Day {$dayNumber}",
        'created_by' => 'ai',
        'refinement_instruction' => $lateInstruction,
        'is_active' => 1
    ]);

    // Update trip current version
    HT_DB::update('ht_trips', [
        'current_plan_version' => $newVersion
    ], 'id = ?', [$tripId]);

    // Log late mode
    error_log(sprintf(
        'AI Late mode: Trip=%d, Day=%d, Late=%dmin, Energy=%s, User=%d',
        $tripId, $dayNumber, $lateMinutes, $energy, HT_Auth::userId()
    ));

    HT_Response::ok([
        'trip_id' => $tripId,
        'version' => $newVersion,
        'day_number' => $dayNumber,
        'adjustments' => [
            'late_minutes' => $lateMinutes,
            'energy' => $energy,
            'keep_dinner' => $keepDinner
        ],
        'plan' => $adjustedPlan
    ]);

} catch (Exception $e) {
    error_log('AI late mode error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}

/**
 * Build instruction for late mode adjustment
 */
function buildLateModeInstruction(int $minutes, string $energy, bool $keepDinner, array $todayItinerary): string {
    $instruction = "LATE MODE ADJUSTMENT:\n";
    $instruction .= "- Running {$minutes} minutes behind schedule\n";

    switch ($energy) {
        case 'tired':
            $instruction .= "- Energy level is LOW - prioritize rest, remove strenuous activities\n";
            $instruction .= "- Suggest easier alternatives or shorter versions of planned activities\n";
            break;
        case 'keen':
            $instruction .= "- Energy level is HIGH - can handle more activities if compressed\n";
            $instruction .= "- Can run activities back-to-back if needed\n";
            break;
        default:
            $instruction .= "- Energy level is NORMAL - balance activity and rest\n";
    }

    if ($keepDinner) {
        $instruction .= "- KEEP dinner plans as scheduled - adjust earlier activities instead\n";
    } else {
        $instruction .= "- Dinner is FLEXIBLE - can be moved or changed\n";
    }

    $instruction .= "\nRequired adjustments:\n";
    $instruction .= "1. Identify what to DROP or SHORTEN today\n";
    $instruction .= "2. Reorder remaining activities optimally\n";
    $instruction .= "3. Add realistic buffer times between activities\n";
    $instruction .= "4. Note any activities that should be moved to another day\n";

    return $instruction;
}
