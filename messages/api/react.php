<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';
require_once __DIR__ . '/../../core/CSRF.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function reactionResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    reactionResponse(['success' => false, 'message' => 'Use POST to update a reaction.'], 405);
}
if (empty($_SESSION['user_id'])) {
    reactionResponse(['success' => false, 'message' => 'Sign in again to react.'], 401);
}
if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    reactionResponse(['success' => false, 'message' => 'Reload Messages and try again.'], 403);
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/classes/MessageChanges.php';

try {
    $user = (new Auth($db))->getCurrentUser();
    if (!$user) reactionResponse(['success' => false, 'message' => 'Sign in again to react.'], 401);
    session_write_close();

    $input = json_decode(file_get_contents('php://input', false, null, 0, 1024), true);
    $messageId = filter_var($input['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $emoji = is_string($input['emoji'] ?? null) ? trim($input['emoji']) : '';
    if (!$messageId || $emoji === '' || mb_strlen($emoji) > 10) {
        reactionResponse(['success' => false, 'message' => 'Choose a message and a reaction.'], 400);
    }

    $userId = (int)$user['id'];
    $familyId = (int)$user['family_id'];
    if ((new SubscriptionManager($db))->isFamilyLocked($familyId)) {
        reactionResponse(['success' => false, 'error' => 'subscription_locked', 'message' => 'Subscribe to react to messages.'], 402);
    }

    $recordChange = MessageChanges::installed($db);
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT user_id FROM messages WHERE id = ? AND family_id = ? AND deleted_at IS NULL FOR UPDATE');
    $stmt->execute([$messageId, $familyId]);
    $ownerId = (int)$stmt->fetchColumn();
    if (!$ownerId) {
        $db->rollBack();
        reactionResponse(['success' => false, 'message' => 'This message is no longer available.'], 404);
    }

    $stmt = $db->prepare('SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
    $stmt->execute([$messageId, $userId, $emoji]);
    $sameReaction = $stmt->fetchColumn() !== false;
    $db->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?')->execute([$messageId, $userId]);
    if (!$sameReaction) {
        $db->prepare('INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$messageId, $userId, $emoji]);
    }
    if ($recordChange) MessageChanges::recordReaction($db, $familyId, $messageId);

    $stmt = $db->prepare('SELECT user_id, emoji FROM message_reactions WHERE message_id = ? ORDER BY created_at, user_id');
    $stmt->execute([$messageId]);
    $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $db->commit();

    if (!$sameReaction && $ownerId !== $userId) {
        try {
            require_once __DIR__ . '/../../core/NotificationManager.php';
            require_once __DIR__ . '/../../core/NotificationTriggers.php';
            (new NotificationTriggers($db))->onMessageReaction($messageId, $userId, $ownerId, $emoji);
        } catch (Throwable $error) {
            error_log('Reaction notification failed: ' . $error->getMessage());
        }
    }
    reactionResponse(['success' => true, 'action' => $sameReaction ? 'removed' : 'added', 'reactions' => $reactions]);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Reaction update failed: ' . $error->getMessage());
    reactionResponse(['success' => false, 'message' => 'Could not update the reaction.'], 500);
}
