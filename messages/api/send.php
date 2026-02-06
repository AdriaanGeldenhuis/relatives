<?php
/**
 * SEND MESSAGE API - WITH NOTIFICATIONS
 */

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
    
    if (!empty($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['media'];
        
        $allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 
            'video/mp4', 'video/quicktime', 'video/webm',
            'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/wav'
        ];
        
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Invalid file type. Allowed: images, videos, audio');
        }
        
        if ($file['size'] > 50 * 1024 * 1024) {
            throw new Exception('File too large (max 50MB)');
        }
        
        $uploadDir = __DIR__ . '/../../uploads/messages/' . date('Y/m/');
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (empty($extension)) {
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'video/mp4' => 'mp4',
                'video/quicktime' => 'mov',
                'video/webm' => 'webm',
                'audio/webm' => 'webm',
                'audio/ogg' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/wav' => 'wav'
            ];
            $extension = $mimeToExt[$file['type']] ?? 'bin';
        }
        
        $filename = uniqid('msg_') . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to upload file');
        }
        
        $mediaPath = '/uploads/messages/' . date('Y/m/') . $filename;
        
        if (strpos($file['type'], 'image/') === 0) {
            $messageType = 'image';
            
            try {
                $thumbnailPath = $uploadDir . 'thumb_' . $filename;
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
                    } else {
                        imagejpeg($thumb, $thumbnailPath, 85);
                    }
                    
                    imagedestroy($image);
                    imagedestroy($thumb);
                    
                    $mediaThumbnail = '/uploads/messages/' . date('Y/m/') . 'thumb_' . $filename;
                }
            } catch (Exception $e) {
                error_log('Thumbnail creation failed: ' . $e->getMessage());
            }
        } elseif (strpos($file['type'], 'video/') === 0) {
            $messageType = 'video';
        } elseif (strpos($file['type'], 'audio/') === 0) {
            $messageType = 'audio';
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