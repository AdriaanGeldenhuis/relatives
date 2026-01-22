<?php
/**
 * Holiday Traveling API - AI Packing List
 * POST /api/ai_packing_list.php
 *
 * Generates a packing list based on:
 * - Destination and dates (weather-aware)
 * - Planned activities
 * - Traveler profiles (kids, special needs)
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
        HT_Response::error('Permission denied', 403);
    }

    // Fetch trip data
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    $preferences = $trip['preferences_json'] ? json_decode($trip['preferences_json'], true) : [];
    $travelers = $trip['travelers_json'] ? json_decode($trip['travelers_json'], true) : [];

    // Get current plan if exists
    $planRecord = HT_DB::fetchOne(
        "SELECT plan_json FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1 LIMIT 1",
        [$tripId]
    );
    $currentPlan = $planRecord ? json_decode($planRecord['plan_json'], true) : null;

    // Build packing list request
    $packingRequest = buildPackingRequest($trip, $preferences, $travelers, $currentPlan);

    // Generate packing list via AI
    $packingList = generatePackingList($packingRequest);

    // Save packing items to database
    $userId = HT_Auth::userId();

    // Clear existing packing items for this trip/user
    HT_DB::delete('ht_trip_packing_items', 'trip_id = ? AND user_id = ?', [$tripId, $userId]);

    // Insert new items
    foreach ($packingList as $category => $items) {
        foreach ($items as $item) {
            HT_DB::insert('ht_trip_packing_items', [
                'trip_id' => $tripId,
                'user_id' => $userId,
                'category' => $category,
                'item_name' => is_array($item) ? ($item['name'] ?? $item) : $item,
                'quantity' => is_array($item) ? ($item['quantity'] ?? 1) : 1,
                'is_packed' => 0
            ]);
        }
    }

    // Log generation
    error_log(sprintf(
        'AI Packing list generated: Trip=%d, Items=%d, User=%d',
        $tripId, array_sum(array_map('count', $packingList)), $userId
    ));

    HT_Response::ok([
        'trip_id' => $tripId,
        'packing_list' => $packingList,
        'total_items' => array_sum(array_map('count', $packingList))
    ]);

} catch (Exception $e) {
    error_log('AI packing list error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}

/**
 * Build packing request context
 */
function buildPackingRequest(array $trip, array $preferences, array $travelers, ?array $plan): array {
    // Extract activities from plan
    $activities = [];
    if ($plan && !empty($plan['itinerary'])) {
        foreach ($plan['itinerary'] as $day) {
            foreach (['morning', 'afternoon', 'evening'] as $period) {
                foreach ($day[$period] ?? [] as $activity) {
                    $activityName = is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? '') : $activity;
                    if ($activityName) {
                        $activities[] = strtolower($activityName);
                    }
                }
            }
        }
    }

    // Determine if kids are involved
    $hasKids = false;
    $kidAges = [];
    foreach ($travelers as $traveler) {
        $age = $traveler['age'] ?? null;
        if ($age !== null && $age < 18) {
            $hasKids = true;
            $kidAges[] = $age;
        }
    }

    // Determine season based on dates and destination
    $month = (int) date('n', strtotime($trip['start_date']));
    $season = determineSeason($trip['destination'], $month);

    return [
        'destination' => $trip['destination'],
        'duration_days' => ht_trip_duration($trip['start_date'], $trip['end_date']),
        'season' => $season,
        'activities' => array_unique($activities),
        'interests' => $preferences['interests'] ?? [],
        'travel_style' => $preferences['travel_style'] ?? 'balanced',
        'has_kids' => $hasKids,
        'kid_ages' => $kidAges,
        'travelers_count' => $trip['travelers_count'],
        'dietary' => $preferences['dietary'] ?? null,
        'mobility' => $preferences['mobility'] ?? null
    ];
}

/**
 * Determine season for destination
 */
function determineSeason(string $destination, int $month): string {
    // South Africa (Southern Hemisphere)
    $southernHemisphere = ['cape town', 'johannesburg', 'durban', 'kruger', 'south africa', 'australia', 'new zealand'];

    $isSouthern = false;
    foreach ($southernHemisphere as $place) {
        if (stripos($destination, $place) !== false) {
            $isSouthern = true;
            break;
        }
    }

    if ($isSouthern) {
        // Southern hemisphere seasons
        if ($month >= 12 || $month <= 2) return 'summer';
        if ($month >= 3 && $month <= 5) return 'autumn';
        if ($month >= 6 && $month <= 8) return 'winter';
        return 'spring';
    } else {
        // Northern hemisphere seasons
        if ($month >= 3 && $month <= 5) return 'spring';
        if ($month >= 6 && $month <= 8) return 'summer';
        if ($month >= 9 && $month <= 11) return 'autumn';
        return 'winter';
    }
}

/**
 * Generate packing list (uses AI or fallback)
 */
function generatePackingList(array $request): array {
    // Try AI generation first
    try {
        $prompt = buildPackingPrompt($request);
        $plan = HT_AI::generatePlan(['packing_list_request' => $request], $prompt);

        if (!empty($plan['packing_list'])) {
            return $plan['packing_list'];
        }
    } catch (Exception $e) {
        error_log('AI packing list failed, using fallback: ' . $e->getMessage());
    }

    // Fallback to rule-based packing list
    return generateFallbackPackingList($request);
}

/**
 * Build AI prompt for packing list
 */
function buildPackingPrompt(array $request): string {
    $prompt = "Generate a practical packing list for:\n";
    $prompt .= "- Destination: {$request['destination']}\n";
    $prompt .= "- Duration: {$request['duration_days']} days\n";
    $prompt .= "- Season: {$request['season']}\n";
    $prompt .= "- Travelers: {$request['travelers_count']}\n";

    if ($request['has_kids']) {
        $prompt .= "- Includes children ages: " . implode(', ', $request['kid_ages']) . "\n";
    }

    if (!empty($request['activities'])) {
        $prompt .= "- Activities: " . implode(', ', array_slice($request['activities'], 0, 10)) . "\n";
    }

    $prompt .= "\nReturn packing list in categories: essentials, weather, activities, kids (if applicable)";

    return $prompt;
}

/**
 * Fallback rule-based packing list
 */
function generateFallbackPackingList(array $request): array {
    $list = [
        'essentials' => [
            'Passport/ID',
            'Wallet & cards',
            'Phone & charger',
            'Medications',
            'Travel documents',
            'Cash (local currency)',
            'Hand sanitizer',
            'Masks'
        ],
        'weather' => [],
        'activities' => [],
        'kids' => []
    ];

    // Weather-based items
    switch ($request['season']) {
        case 'summer':
            $list['weather'] = ['Sunscreen', 'Sunglasses', 'Hat', 'Light clothing', 'Swimwear', 'Sandals'];
            break;
        case 'winter':
            $list['weather'] = ['Warm jacket', 'Layers', 'Scarf', 'Gloves', 'Warm socks', 'Boots'];
            break;
        case 'spring':
        case 'autumn':
            $list['weather'] = ['Light jacket', 'Layers', 'Umbrella', 'Comfortable walking shoes'];
            break;
    }

    // Activity-based items
    $activityItems = [
        'beach' => ['Swimwear', 'Beach towel', 'Flip flops', 'Beach bag'],
        'hike' => ['Hiking boots', 'Backpack', 'Water bottle', 'First aid kit'],
        'safari' => ['Binoculars', 'Neutral colored clothing', 'Camera', 'Insect repellent'],
        'snorkel' => ['Snorkel gear', 'Waterproof bag', 'Reef-safe sunscreen']
    ];

    foreach ($activityItems as $activity => $items) {
        if (in_array($activity, $request['activities']) || in_array($activity, $request['interests'] ?? [])) {
            $list['activities'] = array_merge($list['activities'], $items);
        }
    }
    $list['activities'] = array_unique($list['activities']);

    // Kids items
    if ($request['has_kids']) {
        $list['kids'] = [
            'Snacks',
            'Entertainment (books, games)',
            'Extra clothes',
            'Favorite toy/comfort item'
        ];

        // Age-specific items
        foreach ($request['kid_ages'] as $age) {
            if ($age < 3) {
                $list['kids'] = array_merge($list['kids'], ['Diapers', 'Wipes', 'Formula/baby food', 'Stroller']);
            } elseif ($age < 10) {
                $list['kids'] = array_merge($list['kids'], ['Coloring books', 'Tablet with games']);
            }
        }
        $list['kids'] = array_unique($list['kids']);
    }

    return $list;
}
