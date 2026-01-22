<?php
/**
 * Holiday Traveling API - List Votes/Polls
 * GET /api/votes_list.php?trip_id={id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

HT_Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $tripId = (int) ($_GET['trip_id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    $userId = HT_Auth::userId();

    // Get all polls with creator info
    $polls = HT_DB::fetchAll(
        "SELECT v.*, u.name as creator_name
         FROM ht_trip_votes v
         JOIN users u ON v.created_by_user_id = u.id
         WHERE v.trip_id = ?
         ORDER BY v.created_at DESC",
        [$tripId]
    );

    // Get vote counts and user's votes for each poll
    foreach ($polls as &$poll) {
        $poll['id'] = (int) $poll['id'];
        $poll['options'] = json_decode($poll['options_json'], true) ?? [];

        // Get all responses for this poll
        $responses = HT_DB::fetchAll(
            "SELECT vr.*, u.name as user_name
             FROM ht_trip_vote_responses vr
             JOIN users u ON vr.user_id = u.id
             WHERE vr.vote_id = ?",
            [$poll['id']]
        );

        // Organize responses by option
        $optionVotes = [];
        $userVotes = [];

        foreach ($poll['options'] as $option) {
            $optionId = $option['id'];
            $optionVotes[$optionId] = [
                'love' => 0,
                'meh' => 0,
                'no' => 0,
                'voters' => []
            ];
        }

        foreach ($responses as $r) {
            $optionId = $r['option_id'];
            if (isset($optionVotes[$optionId])) {
                $optionVotes[$optionId][$r['vote_value']]++;
                $optionVotes[$optionId]['voters'][] = [
                    'user_id' => (int) $r['user_id'],
                    'name' => $r['user_name'],
                    'vote' => $r['vote_value'],
                    'comment' => $r['comment']
                ];
            }

            if ((int) $r['user_id'] === $userId) {
                $userVotes[$optionId] = [
                    'vote' => $r['vote_value'],
                    'comment' => $r['comment']
                ];
            }
        }

        // Calculate scores for each option (love=2, meh=1, no=-1)
        foreach ($poll['options'] as &$option) {
            $optionId = $option['id'];
            $votes = $optionVotes[$optionId] ?? ['love' => 0, 'meh' => 0, 'no' => 0];
            $option['votes'] = $votes;
            $option['score'] = ($votes['love'] * 2) + ($votes['meh'] * 1) + ($votes['no'] * -1);
            $option['total_votes'] = $votes['love'] + $votes['meh'] + $votes['no'];
        }

        $poll['user_votes'] = $userVotes;
        $poll['is_expired'] = $poll['closes_at'] && strtotime($poll['closes_at']) < time();

        unset($poll['options_json']); // Remove raw JSON
    }

    HT_Response::ok([
        'trip_id' => $tripId,
        'polls' => $polls,
        'count' => count($polls)
    ]);

} catch (Exception $e) {
    error_log('Votes list error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
