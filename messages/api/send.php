<?php
/**
 * SEND MESSAGE API - WITH NOTIFICATIONS
 */

require_once __DIR__ . '/../../core/session_boot.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/NotificationTriggers.php';

require_once __DIR__ . '/classes/MessageChanges.php';
require_once __DIR__ . '/../../core/CSRF.php';

if (file_exists(__DIR__ . '/classes/MessageLimitManager.php')) {
    require_once __DIR__ . '/classes/MessageLimitManager.php';
}

function sendMessageResult(PDO $db, int $messageId, int $familyId): void
{
    $message = MessageChanges::selectRows($db, $familyId, 'AND m.id = ?', [$messageId], '')[0] ?? null;
    Response::json([
        'success' => true, 'message_id' => $messageId, 'message' => 'Message sent successfully',
        'message_type' => $message['message_type'] ?? null, 'media_path' => $message['media_path'] ?? null,
        'sent_message' => $message,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Not authenticated', 401);
}
$postLimit = MessageAttachments::maxPostBytes();
if ($postLimit && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimit) {
    Response::error('These attachments are too large to send together.', 413);
}
$userId = (int) $user['id'];
$familyId = (int) $user['family_id'];
$userName = $user['name'];
$clientId = $_POST['client_message_id'] ?? null;
if (isset($_POST['family_id']) && (string) $familyId !== $_POST['family_id']) {
    Response::error('This chat has changed. Reopen Messages to continue.', 403);
}
if ($clientId !== null && ($clientId = MessageChanges::clientId($clientId)) === null) {
    Response::error('Invalid message request.', 422);
}
if ($clientId !== null && !CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    Response::error('Reload chat and try again.', 403);
}
session_write_close();
$filesCreated = [];
$messageSaved = false;
$lockName = null;
try {
    if ($clientId !== null) {
        if (!MessageChanges::installed($db)) {
            Response::error('Chat is being updated. Please retry this message shortly.', 503);
        }
        $lockName = 'chat:send:' . substr(hash('sha256', $userId . ':' . $clientId), 0, 50);
        $lock = $db->prepare('SELECT GET_LOCK(?, 4)');
        $lock->execute([$lockName]);
        if (!(int) $lock->fetchColumn()) {
            Response::error('This message is still being sent. Try again in a moment.', 409);
        }
        $find = $db->prepare('SELECT message_id FROM message_send_receipts WHERE user_id = ? AND client_id = ?');
        $find->execute([$userId, $clientId]);
        $repeated = $find->fetchColumn();
        if ($repeated) {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            sendMessageResult($db, (int) $repeated, $familyId);
        }
    }
    // ========== SUBSCRIPTION LOCK CHECK ==========
    require_once __DIR__ . '/../../core/SubscriptionManager.php';
    
    $subscriptionManager = new SubscriptionManager($db);
    
    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
        ]);
        exit;
    }
    // ========== END SUBSCRIPTION LOCK ==========
    
    if (class_exists('MessageLimitManager')) {
        try {
            $limitManager = new MessageLimitManager($db, $familyId);
            $deletedCount = $limitManager->enforceLimit();
            
            if ($deletedCount > 0) {
                error_log("MessageLimit: Cleaned up {$deletedCount} messages for family {$familyId}");
            }
        } catch (Exception $e) {
            error_log("MessageLimit enforcement failed: " . $e->getMessage());
        }
    }
    
    $content = $_POST['content'] ?? '';
    if (!is_string($content)) {
        Response::error('Invalid message text.', 422);
    }
    $content = trim($content);
    $replyToMessageId = !empty($_POST['reply_to_message_id']) ? (int)$_POST['reply_to_message_id'] : null;
    
    if ($content === '' && empty($_FILES['media'])) {
        throw new Exception('Message cannot be empty');
    }

    if (mb_strlen($content) > 5000) {
        throw new Exception('Message too long (max 5000 characters)');
    }

    $mediaPath = null;
    $mediaThumbnail = null;
    $messageType = 'text';

    // Handle multiple file uploads (media[])
    if (isset($_FILES['media']['name'][0])) {
        $fileCount = count($_FILES['media']['name']);
        if ($fileCount > 10) {
            throw new Exception('Maximum 10 files allowed');
        }

        $uploadDir = __DIR__ . '/../../uploads/messages/' . date('Y/m/');
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        $attachments = [];
        $fileinfo = new finfo(FILEINFO_MIME_TYPE);

        for ($i = 0; $i < $fileCount; $i++) {
            $uploadError = $_FILES['media']['error'][$i];
            if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new Exception('An attachment is too large.');
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new Exception('One of the attachments could not be uploaded.');
            }

            $fileSize = $_FILES['media']['size'][$i];
            $fileName = $_FILES['media']['name'][$i];
            $fileTmp  = $_FILES['media']['tmp_name'][$i];

            $fileType = $fileinfo->file($fileTmp);
            if (!isset(MessageAttachments::EXTENSIONS[$fileType])) {
                throw new Exception('Invalid file type: ' . $fileName);
            }
            if ($fileSize > MessageAttachments::maxFileBytes()) {
                throw new Exception('An attachment is too large.');
            }

            $extension = MessageAttachments::EXTENSIONS[$fileType];
            $uniqueName = 'msg_' . bin2hex(random_bytes(16)) . '.' . $extension;
            $filepath = $uploadDir . $uniqueName;

            if (!move_uploaded_file($fileTmp, $filepath)) {
                throw new Exception('Failed to upload: ' . $fileName);
            }

            $filesCreated[] = $filepath;
            $webPath = '/uploads/messages/' . date('Y/m/') . $uniqueName;
            $attachment = [
                'path' => $webPath,
                'name' => MessageAttachments::filename($fileName, $uniqueName),
                'type' => $fileType,
                'size' => $fileSize,
            ];

            if (str_starts_with($fileType, 'image/') && ($dimensions = @getimagesize($filepath))) {
                [$attachment['width'], $attachment['height']] = $dimensions;
                if ($i === 0 && $dimensions[0] * $dimensions[1] <= 12000000 && function_exists('imagewebp')) {
                    $thumbnailName = 'thumb_' . pathinfo($uniqueName, PATHINFO_FILENAME) . '.webp';
                    $thumbnailPath = $uploadDir . $thumbnailName;
                    $image = @imagecreatefromstring(file_get_contents($filepath));
                    if ($image && ($thumbnail = imagescale($image, min($dimensions[0], 420)))) {
                        if (@imagewebp($thumbnail, $thumbnailPath, 82)) {
                            $filesCreated[] = $thumbnailPath;
                            $mediaThumbnail = '/uploads/messages/' . date('Y/m/') . $thumbnailName;
                        }
                    }
                }
            }
            $attachments[] = $attachment;
        }

        $mediaPath = json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (count($attachments) === 1) {
            $messageType = match (explode('/', $attachments[0]['type'], 2)[0]) {
                'image' => 'image',
                'video' => 'video',
                'audio' => 'voice',
                default => 'file',
            };
        } else {
            $messageType = 'file';
        }
    }

    if ($content === '' && $mediaPath === null) {
        throw new Exception('Message cannot be empty');
    }

    if ($replyToMessageId) {
        $stmt = $db->prepare("
            SELECT id FROM messages 
            WHERE id = ? AND family_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$replyToMessageId, $familyId]);
        if (!$stmt->fetchColumn()) {
            $replyToMessageId = null;
        }
    }
    
    $db->beginTransaction();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO messages 
            (family_id, user_id, message_type, content, media_path, media_thumbnail, reply_to_message_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $familyId,
            $userId,
            $messageType,
            $content !== '' ? $content : null,
            $mediaPath,
            $mediaThumbnail,
            $replyToMessageId
        ]);
        
        $messageId = $db->lastInsertId();
        
        try {
            $searchContent = $content . ' ' . $userName;
            $hasMedia = (int)!empty($mediaPath);
            
            $stmt = $db->prepare("
                INSERT INTO message_search_index (message_id, family_id, search_content, has_media, indexed_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    search_content = VALUES(search_content),
                    has_media = VALUES(has_media),
                    indexed_at = NOW()
            ");
            $stmt->execute([$messageId, $familyId, $searchContent, $hasMedia]);
        } catch (Exception $e) {
            error_log('Search index error: ' . $e->getMessage());
        }
        
        if ($clientId !== null) {
            $db->prepare('INSERT INTO message_send_receipts (user_id, client_id, message_id) VALUES (?, ?, ?)')
                ->execute([$userId, $clientId, $messageId]);
        }
        $triggers = new NotificationTriggers($db);
        
        $preview = $content;
        if ($preview === '') {
            if ($messageType === 'image') {
                $preview = '📷 Sent a photo';
            } elseif ($messageType === 'video') {
                $preview = '🎥 Sent a video';
            } elseif ($messageType === 'voice') {
                $preview = '🎤 Sent a voice message';
            } elseif ($messageType === 'file') {
                $preview = '📎 Sent a file';
            } else {
                $preview = 'Sent a message';
            }
        }
        
        $triggers->onNewMessage((int) $messageId, $userId, $familyId, $preview);
        $db->commit();
        $messageSaved = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (family_id, user_id, action, entity_type, entity_id, created_at)
            VALUES (?, ?, 'create', 'message', ?, NOW())
        ");
        $stmt->execute([$familyId, $userId, $messageId]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
    
    if ($lockName !== null) {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    sendMessageResult($db, (int) $messageId, $familyId);
} catch (Throwable $e) {
    if (!$messageSaved) {
        foreach ($filesCreated as $file) {
            @unlink($file);
        }
    }
    if ($lockName !== null) {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    error_log('Message send error: ' . $e->getMessage());
    $message = $e instanceof PDOException || !$e instanceof Exception ? 'Could not save the message. Try again.' : $e->getMessage();
    Response::json(['success' => false, 'message' => $message], 400);
}
