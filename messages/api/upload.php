<?php
/**
 * STANDALONE MEDIA UPLOAD API
 * POST: file (multipart/form-data)
 * Returns media URL for use in messages
 * 
 * SECTION 6: Audio support added
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded');
    }
    
    $file = $_FILES['file'];
    
    // 🎤 SECTION 6: Added audio MIME types
    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/quicktime',
        'audio/webm', 'audio/ogg', 'audio/mpeg'
    ];
    
    // Server-side MIME validation (don't trust client-provided type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedType = $finfo->file($file['tmp_name']);
    if (!in_array($detectedType, $allowedTypes)) {
        throw new Exception('Invalid file type');
    }
    
    // Check file size (50MB max)
    if ($file['size'] > 50 * 1024 * 1024) {
        throw new Exception('File too large (max 50MB)');
    }
    
    // Create upload directory
    $uploadDir = __DIR__ . '/../../uploads/messages/' . date('Y/m/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('upload_') . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }
    
    $mediaUrl = '/uploads/messages/' . date('Y/m/') . $filename;
    
    // Create thumbnail for images
    $thumbnailUrl = null;
    if (strpos($detectedType, 'image/') === 0) {
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
                } elseif ($extension === 'webp' && function_exists('imagewebp')) {
                    imagewebp($thumb, $thumbnailPath, 85);
                } else {
                    imagejpeg($thumb, $thumbnailPath, 85);
                }
                
                imagedestroy($image);
                imagedestroy($thumb);
                
                $thumbnailUrl = '/uploads/messages/' . date('Y/m/') . 'thumb_' . $filename;
            }
        } catch (Exception $e) {
            // Thumbnail creation failed, continue without it
        }
    }
    
    echo json_encode([
        'success' => true,
        'url' => $mediaUrl,
        'thumbnail' => $thumbnailUrl,
        'type' => explode('/', $detectedType)[0] // image, video, audio
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}