<?php
/**
 * Holiday Traveling API - Close Poll and Determine Winner
 * POST /api/votes_close.php
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

    if ($poll['status'] === 'closed') {
        HT_Response::error('Poll is already closed', 400);
    }

    // Calculate scores for each option
    $options = json_decode($poll['options_json'], true) ?? [];
    $scores = [];

    foreach ($options as $option) {
        $optionId = $option['id'];

        // Get vote counts for this option
        $counts = HT_DB::fetchOne(
            "SELECT
                SUM(CASE WHEN vote_value = 'love' THEN 1 ELSE 0 END) as love_count,
                SUM(CASE WHEN vote_value = 'meh' THEN 1 ELSE 0 END) as meh_count,
                SUM(CASE WHEN vote_value = 'no' THEN 1 ELSE 0 END) as no_count
             FROM ht_trip_vote_responses
             WHERE vote_id = ? AND option_id = ?",
            [$voteId, $optionId]
        );

        $love = (int) ($counts['love_count'] ?? 0);
        $meh = (int) ($counts['meh_count'] ?? 0);
        $no = (int) ($counts['no_count'] ?? 0);

        // Score: love = 2 points, meh = 1 point, no = -1 point
        $score = ($love * 2) + ($meh * 1) + ($no * -1);

        $scores[$optionId] = [
            'score' => $score,
            'love' => $love,
            'meh' => $meh,
            'no' => $no,
            'total' => $love + $meh + $no
        ];
    }

    // Determine winner (highest score, or first in case of tie)
    $winnerId = null;
    $highestScore = PHP_INT_MIN;

    foreach ($scores as $optionId => $data) {
        if ($data['score'] > $highestScore) {
            $highestScore = $data['score'];
            $winnerId = $optionId;
        }
    }

    // Close the poll
    HT_DB::update('ht_trip_votes', [
        'status' => 'closed',
        'winning_option_id' => $winnerId,
        'closed_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$voteId]);

    // Get winning option details
    $winningOption = null;
    foreach ($options as $option) {
        if ($option['id'] === $winnerId) {
            $winningOption = $option;
            $winningOption['votes'] = $scores[$winnerId];
            break;
        }
    }

    error_log(sprintf(
        'Poll closed: Poll=%d, Winner=%s, Score=%d, User=%d',
        $voteId, $winnerId, $highestScore, HT_Auth::userId()
    ));

    HT_Response::ok([
        'vote_id' => $voteId,
        'status' => 'closed',
        'winning_option_id' => $winnerId,
        'winning_option' => $winningOption,
        'all_scores' => $scores
    ]);

} catch (Exception $e) {
    error_log('Vote close error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
