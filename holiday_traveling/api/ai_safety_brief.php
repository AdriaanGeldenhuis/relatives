<?php
/**
 * Holiday Traveling API - AI Safety Brief
 * POST /api/ai_safety_brief.php
 *
 * Generates destination-specific safety information and local tips
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

    // Check access
    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    // Fetch trip data
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    $travelers = $trip['travelers_json'] ? json_decode($trip['travelers_json'], true) : [];

    // Check if we have kids
    $hasKids = false;
    foreach ($travelers as $traveler) {
        if (isset($traveler['age']) && $traveler['age'] < 18) {
            $hasKids = true;
            break;
        }
    }

    // Build safety request
    $safetyRequest = [
        'destination' => $trip['destination'],
        'origin' => $trip['origin'],
        'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
        'start_date' => $trip['start_date'],
        'travelers_count' => $trip['travelers_count'],
        'has_kids' => $hasKids
    ];

    // Generate safety brief
    $safetyBrief = generateSafetyBrief($safetyRequest);

    HT_Response::ok([
        'trip_id' => $tripId,
        'destination' => $trip['destination'],
        'safety_brief' => $safetyBrief
    ]);

} catch (Exception $e) {
    error_log('AI safety brief error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}

/**
 * Generate safety brief (uses AI or fallback)
 */
function generateSafetyBrief(array $request): array {
    // Try AI generation
    try {
        $prompt = "Generate a safety and local tips briefing for {$request['destination']}. ";
        $prompt .= "Include: emergency numbers, common scams, health precautions, cultural etiquette, ";
        $prompt .= "transportation tips, and general safety advice.";

        if ($request['has_kids']) {
            $prompt .= " Include family-friendly safety tips.";
        }

        $plan = HT_AI::generatePlan(['safety_request' => $request], $prompt);

        if (!empty($plan['safety_and_local_tips'])) {
            return [
                'tips' => $plan['safety_and_local_tips'],
                'generated' => true
            ];
        }
    } catch (Exception $e) {
        error_log('AI safety brief failed, using fallback: ' . $e->getMessage());
    }

    // Fallback to destination-specific presets
    return generateFallbackSafetyBrief($request['destination'], $request['has_kids']);
}

/**
 * Fallback safety information
 */
function generateFallbackSafetyBrief(string $destination, bool $hasKids): array {
    $destination = strtolower($destination);

    // Default safety tips
    $tips = [
        'general' => [
            'Keep copies of important documents separate from originals',
            'Share your itinerary with family/friends at home',
            'Register with your embassy if visiting high-risk areas',
            'Keep emergency cash hidden separately',
            'Stay aware of your surroundings, especially at night'
        ],
        'health' => [
            'Carry basic first aid supplies',
            'Know the location of nearest hospital/clinic',
            'Stay hydrated and use sunscreen',
            'Be cautious with street food - eat at busy establishments'
        ],
        'local_tips' => [],
        'emergency_contacts' => []
    ];

    // South Africa specific
    if (strpos($destination, 'cape town') !== false || strpos($destination, 'south africa') !== false) {
        $tips['local_tips'] = [
            'Uber is widely available and safe',
            'Don\'t leave valuables visible in parked cars',
            'Load shedding (power outages) may occur - have a flashlight ready',
            'Tap water is safe to drink in major cities',
            'ATMs inside shopping centers are safer than street ATMs',
            'Tipping: 10-15% at restaurants is customary'
        ];
        $tips['emergency_contacts'] = [
            'Emergency (Police/Fire/Ambulance): 10111',
            'Tourist Police: 0800 222 345',
            'Medical Emergency: 10177'
        ];
    }

    // Kruger/Safari specific
    if (strpos($destination, 'kruger') !== false || strpos($destination, 'safari') !== false) {
        $tips['local_tips'] = array_merge($tips['local_tips'], [
            'Never exit your vehicle in wildlife areas',
            'Keep windows closed near animals',
            'Malaria precautions may be needed - consult your doctor',
            'Carry insect repellent and wear long sleeves at dusk',
            'Stay on designated roads and trails'
        ]);
    }

    // Durban specific
    if (strpos($destination, 'durban') !== false) {
        $tips['local_tips'] = array_merge($tips['local_tips'], [
            'Swim only at lifeguard-patrolled beaches',
            'Beware of strong currents',
            'Humidity is high - stay hydrated',
            'Be cautious on the beachfront after dark'
        ]);
    }

    // Kids-specific tips
    if ($hasKids) {
        $tips['family'] = [
            'Establish a meeting point in case of separation',
            'Consider ID bracelets with contact info for young children',
            'Carry snacks and entertainment for long drives/waits',
            'Pack any children\'s medications you might need',
            'Research kid-friendly restaurants and activities'
        ];
    }

    // Generic tips if no specific destination matched
    if (empty($tips['local_tips'])) {
        $tips['local_tips'] = [
            'Research local customs and etiquette before arrival',
            'Download offline maps of your destination',
            'Learn basic phrases in the local language',
            'Check visa requirements well in advance',
            'Inform your bank of travel dates to avoid card blocks'
        ];
        $tips['emergency_contacts'] = [
            'International emergency: 112 (works in most countries)',
            'Your country\'s embassy contact'
        ];
    }

    return [
        'tips' => array_merge(
            $tips['general'],
            $tips['health'],
            $tips['local_tips'],
            $tips['family'] ?? []
        ),
        'emergency_contacts' => $tips['emergency_contacts'],
        'generated' => false
    ];
}
