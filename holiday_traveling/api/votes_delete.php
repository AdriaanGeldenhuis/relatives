<?php
/**
 * Holiday Traveling API - Delete Poll
 * POST /api/votes_delete.php
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

    if (!$voteId) {
        HT_Response::error('Vote ID is required', 400);
    }

    // Fetch poll
    $poll = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_votes WHERE id = ?",
        [$voteId]
    );

    if (!$poll) {
        HT_Response::error('Poll not found', 404);
    }

    if (!HT_Auth::canEditTrip($poll['trip_id'])) {
        HT_Response::error('Permission denied', 403);
    }

    // Only creator or trip owner can delete
    $userId = HT_Auth::userId();
    $isCreator = (int) $poll['created_by_user_id'] === $userId;
    $isOwner = HT_Auth::isTripOwner($poll['trip_id']);

    if (!$isCreator && !$isOwner) {
        HT_Response::error('Only the poll creator or trip owner can delete this poll', 403);
    }

    // Delete poll (responses will cascade delete)
    HT_DB::delete('ht_trip_votes', 'id = ?', [$voteId]);

    error_log(sprintf(
        'Poll deleted: Poll=%d, Trip=%d, User=%d',
        $voteId, $poll['trip_id'], $userId
    ));

    HT_Response::ok([
        'deleted' => true,
        'vote_id' => $voteId
    ]);

} catch (Exception $e) {
    error_log('Vote delete error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
