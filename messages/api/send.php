<?php
/**
 * SEND MESSAGE API - WITH NOTIFICATIONS
 */

session_name('RELATIVES_SESSION');
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/NotificationTriggers.php';

if (file_exists(__DIR__ . '/classes/MessageLimitManager.php')) {
    require_once __DIR__ . '/classes/MessageLimitManager.php';
}

try {
    $userId = $_SESSION['user_id'];
    
    $stmt = $db->prepare("SELECT family_id, full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $familyId = $user['family_id'];
    $userName = $user['full_name'];
    
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
    
    $toFamily = isset($_POST['to_family']) && $_POST['to_family'] == '1';
    
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
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        $uploadedPaths = [];
        $firstType = null;

        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['media']['error'][$i] !== UPLOAD_ERR_OK) continue;

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

            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (empty($extension)) {
                $extension = $mimeToExt[$fileType] ?? 'bin';
            }

            $uniqueName = uniqid('msg_') . '_' . time() . '_' . $i . '.' . $extension;
            $filepath = $uploadDir . $uniqueName;

            if (!move_uploaded_file($fileTmp, $filepath)) {
                throw new Exception('Failed to upload: ' . $fileName);
            }

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
            elseif (strpos($firstType, 'audio/') === 0) $messageType = 'audio';
            else $messageType = 'document';
        } elseif (count($uploadedPaths) > 1) {
            // Multiple files - store as JSON array
            $mediaPath = json_encode($uploadedPaths);
            $messageType = 'multi';
        }
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
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
    // ==================== SEND NOTIFICATIONS ====================
    try {
        $triggers = new NotificationTriggers($db);
        
        $preview = $content;
        if (empty($preview)) {
            if ($messageType === 'image') {
                $preview = '📷 Sent a photo';
            } elseif ($messageType === 'video') {
                $preview = '🎥 Sent a video';
            } elseif ($messageType === 'audio') {
                $preview = '🎤 Sent a voice message';
            } else {
                $preview = 'Sent a message';
            }
        }
        
        $triggers->onNewMessage($messageId, $userId, $familyId, $preview);
        
    } catch (Exception $e) {
        error_log('Failed to send message notification: ' . $e->getMessage());
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
    
    // Fetch the full sent message to return to client for instant display
    $stmt = $db->prepare("
        SELECT
            m.id,
            m.user_id,
            m.content,
            m.message_type,
            m.media_path,
            m.reply_to_message_id,
            m.created_at,
            m.edited_at,
            u.full_name,
            u.avatar_color,
            u.has_avatar,
            (SELECT content FROM messages WHERE id = m.reply_to_message_id LIMIT 1) as reply_to_content
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$messageId]);
    $sentMessage = $stmt->fetch(PDO::FETCH_ASSOC);
    $sentMessage['reactions'] = [];

    echo json_encode([
        'success' => true,
        'message_id' => $messageId,
        'message' => 'Message sent successfully',
        'message_type' => $messageType,
        'media_path' => $mediaPath,
        'sent_message' => $sentMessage
    ]);
    
} catch (Exception $e) {
    error_log('Message send error: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}