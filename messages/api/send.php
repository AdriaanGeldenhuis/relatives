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
$userId = (int) $user['id'];
$familyId = (int) $user['family_id'];
$userName = $user['name'];
$clientId = $_POST['client_message_id'] ?? null;
if ($clientId !== null && (!is_string($clientId)
    || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $clientId))) {
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
        $clientId = strtolower($clientId);
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
    
    $content = trim($_POST['content'] ?? '');
    $replyToMessageId = !empty($_POST['reply_to_message_id']) ? (int)$_POST['reply_to_message_id'] : null;
    
    if (empty($content) && empty($_FILES['media'])) {
        throw new Exception('Message cannot be empty');
    }

    if (strlen($content) > 5000) {
        throw new Exception('Message too long (max 5000 characters)');
    }

    $mediaPath = null;
    $mediaThumbnail = null;
    $messageType = 'text';

    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/quicktime', 'video/webm',
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/wav',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'application/rtf'
    ];

    $mimeToExt = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt', 'text/csv' => 'csv', 'application/rtf' => 'rtf'
    ];

    // Handle multiple file uploads (media[])
    if (!empty($_FILES['media']['name'][0])) {
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

        $uploadedPaths = [];
        $firstType = null;

        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['media']['error'][$i] !== UPLOAD_ERR_OK) {
                throw new Exception('One of the attachments could not be uploaded.');
            }

            $fileSize = $_FILES['media']['size'][$i];
            $fileName = $_FILES['media']['name'][$i];
            $fileTmp  = $_FILES['media']['tmp_name'][$i];

            // Server-side MIME validation (don't trust client-provided type)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $fileType = $finfo->file($fileTmp);
            if (!in_array($fileType, $allowedTypes)) {
                throw new Exception('Invalid file type: ' . $fileName);
            }
            if ($fileSize > 50 * 1024 * 1024) {
                throw new Exception('File too large (max 50MB): ' . $fileName);
            }

            $extension = $mimeToExt[$fileType];
            $uniqueName = 'msg_' . bin2hex(random_bytes(16)) . '.' . $extension;
            $filepath = $uploadDir . $uniqueName;

            if (!move_uploaded_file($fileTmp, $filepath)) {
                throw new Exception('Failed to upload: ' . $fileName);
            }

            $filesCreated[] = $filepath;
            $webPath = '/uploads/messages/' . date('Y/m/') . $uniqueName;
            $uploadedPaths[] = ['path' => $webPath, 'name' => $fileName, 'type' => $fileType, 'size' => $fileSize];

            if ($firstType === null) $firstType = $fileType;

            // Generate thumbnail for first image
            if ($i === 0 && strpos($fileType, 'image/') === 0) {
                try {
                    $thumbnailPath = $uploadDir . 'thumb_' . $uniqueName;
                    $image = imagecreatefromstring(file_get_contents($filepath));
                    if ($image) {
                        $width = imagesx($image);
                        $height = imagesy($image);
                        $thumbWidth = 200;
                        $thumbHeight = (int)(($height / $width) * $thumbWidth);
                        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
                        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
                        if ($extension === 'png') {
                            imagepng($thumb, $thumbnailPath, 8);
                        } elseif ($extension === 'webp' && function_exists('imagewebp')) {
                            imagewebp($thumb, $thumbnailPath, 85);
                        } else {
                            imagejpeg($thumb, $thumbnailPath, 85);
                        }
                        imagedestroy($image);
                        imagedestroy($thumb);
                        $filesCreated[] = $thumbnailPath;
                        $mediaThumbnail = '/uploads/messages/' . date('Y/m/') . 'thumb_' . $uniqueName;
                    }
                } catch (Exception $e) {
                    error_log('Thumbnail creation failed: ' . $e->getMessage());
                }
            }
        }

        if (count($uploadedPaths) === 1) {
            // Single file - store path directly for backward compatibility
            $mediaPath = $uploadedPaths[0]['path'];
            if (strpos($firstType, 'image/') === 0) $messageType = 'image';
            elseif (strpos($firstType, 'video/') === 0) $messageType = 'video';
            elseif (strpos($firstType, 'audio/') === 0) $messageType = 'voice';
            else $messageType = 'file';
        } elseif (count($uploadedPaths) > 1) {
            // Multiple files - store as JSON array
            $mediaPath = json_encode($uploadedPaths);
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
            $content ?: null,
            $mediaPath,
            $mediaThumbnail,
            $replyToMessageId
        ]);
        
        $messageId = $db->lastInsertId();
        
        try {
            $searchContent = $content . ' ' . $userName;
            $hasMedia = !empty($mediaPath);
            
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
        if (empty($preview)) {
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
