<?php
/**
 * Holiday Traveling API - Create Vote/Poll
 * POST /api/votes_create.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

HT_Auth::requireLogin();
HT_CSRF::verifyOrDie();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $input = ht_json_input();
    $tripId = (int) ($input['trip_id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('Permission denied', 403);
    }

    // Validate required fields
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $options = $input['options'] ?? [];
    $closesAt = $input['closes_at'] ?? null;

    if (empty($title) || strlen($title) > 255) {
        HT_Response::error('Title is required (max 255 characters)', 400);
    }

    if (!is_array($options) || count($options) < 2) {
        HT_Response::error('At least 2 options are required', 400);
    }

    if (count($options) > 10) {
        HT_Response::error('Maximum 10 options allowed', 400);
    }

    // Format options with IDs
    $formattedOptions = [];
    foreach ($options as $index => $option) {
        $label = is_string($option) ? trim($option) : trim($option['label'] ?? '');
        if (empty($label)) {
            HT_Response::error('All options must have labels', 400);
        }

        $formattedOptions[] = [
            'id' => 'opt_' . ($index + 1),
            'label' => $label,
            'description' => is_array($option) ? ($option['description'] ?? null) : null
        ];
    }

    // Validate closes_at if provided
    $closesAtTimestamp = null;
    if ($closesAt) {
        $closesAtTimestamp = strtotime($closesAt);
        if ($closesAtTimestamp === false || $closesAtTimestamp < time()) {
            HT_Response::error('Closing date must be in the future', 400);
        }
    }

    // Create poll
    $voteId = HT_DB::insert('ht_trip_votes', [
        'trip_id' => $tripId,
        'created_by_user_id' => HT_Auth::userId(),
        'title' => $title,
        'description' => $description ?: null,
        'options_json' => json_encode($formattedOptions),
        'status' => 'open',
        'closes_at' => $closesAtTimestamp ? date('Y-m-d H:i:s', $closesAtTimestamp) : null
    ]);

    // Fetch created poll
    $poll = HT_DB::fetchOne(
        "SELECT v.*, u.name as creator_name
         FROM ht_trip_votes v
         JOIN users u ON v.created_by_user_id = u.id
         WHERE v.id = ?",
        [$voteId]
    );

    $poll['options'] = $formattedOptions;
    unset($poll['options_json']);

    error_log(sprintf(
        'Poll created: Trip=%d, Poll=%d, Options=%d, User=%d',
        $tripId, $voteId, count($formattedOptions), HT_Auth::userId()
    ));

    HT_Response::created([
        'id' => $voteId,
        'poll' => $poll
    ]);

} catch (Exception $e) {
    error_log('Vote create error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
