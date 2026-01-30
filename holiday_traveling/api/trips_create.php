<?php
/**
 * Holiday Traveling API - Create Trip
 * POST /api/trips_create.php
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

    // Parse quick prompt if provided (extract destination, dates, etc.)
    $quickPrompt = trim($input['quick_prompt'] ?? '');
    if (!empty($quickPrompt) && empty($input['destination'])) {
        $parsed = parseQuickPrompt($quickPrompt);
        $input = array_merge($input, $parsed);
    }

    // Clean and validate input
    $data = HT_Validators::cleanTripInput($input);
    $errors = HT_Validators::tripData($data);

    if (!empty($errors)) {
        HT_Response::validationError($errors);
    }

    // Generate title if not provided
    if (empty($data['title'])) {
        $data['title'] = generateTripTitle($data['destination'], $data['start_date'], $data['end_date']);
    }

    // Prepare database insert
    $userId = HT_Auth::userId();
    $familyId = HT_Auth::familyId();

    $insertData = [
        'family_id' => $familyId,
        'user_id' => $userId,
        'title' => $data['title'],
        'destination' => $data['destination'],
        'origin' => $data['origin'] ?: null,
        'start_date' => $data['start_date'],
        'end_date' => $data['end_date'],
        'travelers_count' => $data['travelers_count'],
        'travelers_json' => $data['travelers_json'] ? json_encode($data['travelers_json']) : null,
        'budget_currency' => $data['budget_currency'],
        'budget_min' => $data['budget_min'],
        'budget_comfort' => $data['budget_comfort'],
        'budget_max' => $data['budget_max'],
        'preferences_json' => buildPreferencesJson($input),
        'status' => $input['status'] ?? 'draft'
    ];

    // Insert trip
    $tripId = HT_DB::insert('ht_trips', $insertData);

    // Add creator as trip member (owner)
    HT_DB::insert('ht_trip_members', [
        'trip_id' => $tripId,
        'user_id' => $userId,
        'role' => 'owner',
        'status' => 'joined',
        'joined_at' => date('Y-m-d H:i:s')
    ]);

    // Create holiday event in internal calendar
    try {
        HT_DB::insert('events', [
            'family_id' => $familyId,
            'user_id' => $userId,
            'created_by' => $userId,
            'title' => '🏖️ Holiday - ' . $data['destination'],
            'description' => $data['title'],
            'notes' => "Trip ID: {$tripId}",
            'location' => $data['destination'],
            'starts_at' => $data['start_date'] . ' 00:00:00',
            'ends_at' => $data['end_date'] . ' 23:59:59',
            'timezone' => 'Africa/Johannesburg',
            'all_day' => 1,
            'kind' => 'event',
            'color' => '#f39c12',
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log('Holiday calendar event creation failed: ' . $e->getMessage());
    }

    // Generate AI plan if requested
    $planVersion = null;
    if (!empty($input['generate_plan'])) {
        try {
            $tripData = [
                'destination' => $data['destination'],
                'origin' => $data['origin'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'duration_days' => ht_trip_duration($data['start_date'], $data['end_date']),
                'travelers_count' => $data['travelers_count'],
                'travelers' => $data['travelers_json'],
                'budget' => [
                    'min' => $data['budget_min'],
                    'comfort' => $data['budget_comfort'],
                    'max' => $data['budget_max'],
                    'currency' => $data['budget_currency']
                ],
                'preferences' => json_decode($insertData['preferences_json'], true),
                'quick_prompt' => $quickPrompt
            ];

            $plan = HT_AI::generatePlan($tripData);

            // Save plan version
            $planVersionId = HT_DB::insert('ht_trip_plan_versions', [
                'trip_id' => $tripId,
                'version_number' => 1,
                'plan_json' => json_encode($plan),
                'summary_text' => generatePlanSummary($plan),
                'created_by' => 'ai',
                'is_active' => 1
            ]);

            // Update trip with current plan version
            HT_DB::update('ht_trips', [
                'current_plan_version' => 1,
                'status' => 'planned'
            ], 'id = ?', [$tripId]);

            $planVersion = 1;
        } catch (Exception $e) {
            // Log error but don't fail the trip creation
            error_log('AI plan generation failed: ' . $e->getMessage());
        }
    }

    // Return success response
    HT_Response::created([
        'id' => $tripId,
        'title' => $data['title'],
        'destination' => $data['destination'],
        'plan_version' => $planVersion
    ]);

} catch (Exception $e) {
    error_log('Trip creation error: ' . $e->getMessage());
    HT_Response::error('Failed to create trip: ' . $e->getMessage(), 500);
}

/**
 * Parse quick prompt to extract trip details
 */
function parseQuickPrompt(string $prompt): array {
    $data = [];

    // Try to extract destination (first location-like phrase)
    if (preg_match('/(?:to|in|at|visiting?)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i', $prompt, $matches)) {
        $data['destination'] = trim($matches[1]);
    } elseif (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*),/i', $prompt, $matches)) {
        $data['destination'] = trim($matches[1]);
    }

    // Try to extract dates
    $datePatterns = [
        '/(\d{1,2})[-\/](\d{1,2})(?:[-\/](\d{2,4}))?.*?(?:to|-).*?(\d{1,2})[-\/](\d{1,2})(?:[-\/](\d{2,4}))?/i',
        '/(\d{1,2})\s*(?:st|nd|rd|th)?\s*([A-Za-z]+).*?(?:to|-).*?(\d{1,2})\s*(?:st|nd|rd|th)?\s*([A-Za-z]+)/i',
    ];

    // Try to extract number of travelers
    if (preg_match('/(\d+)\s*(?:people|travelers?|adults?|persons?)/i', $prompt, $matches)) {
        $data['travelers_count'] = (int) $matches[1];
    } elseif (preg_match('/family\s+of\s+(\d+)/i', $prompt, $matches)) {
        $data['travelers_count'] = (int) $matches[1];
    } elseif (preg_match('/couple/i', $prompt)) {
        $data['travelers_count'] = 2;
    }

    // Try to extract budget level
    if (preg_match('/budget[-\s]?friendly|cheap|low[-\s]?budget/i', $prompt)) {
        $data['travel_style'] = 'budget';
    } elseif (preg_match('/luxury|premium|high[-\s]?end|5[-\s]?star/i', $prompt)) {
        $data['travel_style'] = 'luxury';
    } elseif (preg_match('/mid[-\s]?(?:range|budget)|moderate/i', $prompt)) {
        $data['travel_style'] = 'balanced';
    }

    return $data;
}

/**
 * Generate automatic trip title
 */
function generateTripTitle(string $destination, string $startDate, string $endDate): string {
    $month = date('F', strtotime($startDate));
    $year = date('Y', strtotime($startDate));
    return "{$destination} Trip - {$month} {$year}";
}

/**
 * Build preferences JSON from input
 */
function buildPreferencesJson(array $input): ?string {
    $prefs = [];

    if (!empty($input['travel_style'])) {
        $prefs['travel_style'] = $input['travel_style'];
    }
    if (!empty($input['pace'])) {
        $prefs['pace'] = $input['pace'];
    }
    if (!empty($input['interests'])) {
        $prefs['interests'] = is_array($input['interests'])
            ? $input['interests']
            : explode(',', $input['interests']);
    }
    if (!empty($input['dietary_prefs'])) {
        $prefs['dietary'] = $input['dietary_prefs'];
    }
    if (!empty($input['mobility_notes'])) {
        $prefs['mobility'] = $input['mobility_notes'];
    }
    if (!empty($input['additional_notes'])) {
        $prefs['notes'] = $input['additional_notes'];
    }

    return !empty($prefs) ? json_encode($prefs) : null;
}

/**
 * Generate human-readable plan summary
 */
function generatePlanSummary(array $plan): string {
    $summary = [];

    if (!empty($plan['meta']['destination'])) {
        $summary[] = "Destination: " . $plan['meta']['destination'];
    }

    if (!empty($plan['itinerary'])) {
        $days = count($plan['itinerary']);
        $summary[] = "{$days} days planned";
    }

    if (!empty($plan['stay_options'])) {
        $summary[] = count($plan['stay_options']) . " accommodation options";
    }

    if (!empty($plan['budget_breakdown'])) {
        $total = array_sum($plan['budget_breakdown']);
        $currency = $plan['meta']['budget']['currency'] ?? 'ZAR';
        $summary[] = "Estimated total: {$currency} " . number_format($total);
    }

    return implode(' | ', $summary);
}
