<?php
/**
 * Holiday Traveling API - Submit/Update Vote Response
 * POST /api/votes_respond.php
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
    $voteId = (int) ($input['vote_id'] ?? 0);
    $optionId = trim($input['option_id'] ?? '');
    $voteValue = trim($input['vote_value'] ?? '');
    $comment = trim($input['comment'] ?? '');

    if (!$voteId) {
        HT_Response::error('Vote ID is required', 400);
    }

    if (empty($optionId)) {
        HT_Response::error('Option ID is required', 400);
    }

    $validValues = ['love', 'meh', 'no'];
    if (!in_array($voteValue, $validValues)) {
        HT_Response::error('Vote value must be love, meh, or no', 400);
    }

    // Fetch poll
    $poll = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_votes WHERE id = ?",
        [$voteId]
    );

    if (!$poll) {
        HT_Response::error('Poll not found', 404);
    }

    if (!HT_Auth::canAccessTrip($poll['trip_id'])) {
        HT_Response::error('Access denied', 403);
    }

    if ($poll['status'] !== 'open') {
        HT_Response::error('This poll is closed', 400);
    }

    if ($poll['closes_at'] && strtotime($poll['closes_at']) < time()) {
        HT_Response::error('This poll has expired', 400);
    }

    // Validate option exists
    $options = json_decode($poll['options_json'], true) ?? [];
    $validOption = false;
    foreach ($options as $opt) {
        if ($opt['id'] === $optionId) {
            $validOption = true;
            break;
        }
    }

    if (!$validOption) {
        HT_Response::error('Invalid option ID', 400);
    }

    $userId = HT_Auth::userId();

    // Check for existing response
    $existing = HT_DB::fetchOne(
        "SELECT id FROM ht_trip_vote_responses WHERE vote_id = ? AND user_id = ? AND option_id = ?",
        [$voteId, $userId, $optionId]
    );

    if ($existing) {
        // Update existing response
        HT_DB::update('ht_trip_vote_responses', [
            'vote_value' => $voteValue,
            'comment' => $comment ?: null
        ], 'id = ?', [$existing['id']]);

        $responseId = (int) $existing['id'];
        $action = 'updated';
    } else {
        // Insert new response
        $responseId = HT_DB::insert('ht_trip_vote_responses', [
            'vote_id' => $voteId,
            'user_id' => $userId,
            'option_id' => $optionId,
            'vote_value' => $voteValue,
            'comment' => $comment ?: null
        ]);
        $action = 'submitted';
    }

    error_log(sprintf(
        'Vote response %s: Poll=%d, Option=%s, Value=%s, User=%d',
        $action, $voteId, $optionId, $voteValue, $userId
    ));

    HT_Response::ok([
        'id' => $responseId,
        'action' => $action,
        'vote_id' => $voteId,
        'option_id' => $optionId,
        'vote_value' => $voteValue
    ]);

} catch (Exception $e) {
    error_log('Vote respond error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
