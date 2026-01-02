<?php
declare(strict_types=1);

/**
 * ============================================
 * AVATAR UPLOAD API - FIXED FOR NATIVE APP
 * Handles both multipart/form-data AND base64
 * Saves to /saves/{user_id}/avatar/avatar.webp
 * ============================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cookie, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = (int)$_SESSION['user_id'];
    
    $tempFile = null;
    $mimeType = null;
    $fileSize = 0;
    
    // ========================================
    // METHOD 1: Standard multipart/form-data
    // ========================================
    if (isset($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        error_log("upload_avatar.php: Processing multipart upload for user $userId");
        
        $file = $_FILES['avatar'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload blocked by extension',
            ];
            
            $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
            error_log("upload_avatar.php: Upload error {$file['error']}: $errorMsg");
            throw new Exception($errorMsg);
        }
        
        $tempFile = $file['tmp_name'];
        $fileSize = $file['size'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tempFile);
        finfo_close($finfo);
        
    }
    // ========================================
    // METHOD 2: Base64 encoded (for native apps)
    // ========================================
    elseif (isset($_POST['avatar_base64']) && !empty($_POST['avatar_base64'])) {
        error_log("upload_avatar.php: Processing base64 upload for user $userId");
        
        $base64Data = $_POST['avatar_base64'];
        
        // Remove data:image/...;base64, prefix if present
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $imageType = $matches[1];
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            
            // Map image type to MIME type
            $mimeMap = [
                'jpeg' => 'image/jpeg',
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp'
            ];
            $mimeType = $mimeMap[$imageType] ?? 'image/jpeg';
        }
        
        $base64Data = base64_decode($base64Data, true);
        
        if ($base64Data === false) {
            throw new Exception('Invalid base64 data');
        }
        
        $fileSize = strlen($base64Data);
        
        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'avatar_');
        if (!$tempFile) {
            throw new Exception('Failed to create temporary file');
        }
        
        if (file_put_contents($tempFile, $base64Data) === false) {
            throw new Exception('Failed to write temporary file');
        }
        
        // Detect MIME type if not set
        if (!$mimeType) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempFile);
            finfo_close($finfo);
        }
        
        error_log("upload_avatar.php: Base64 decoded, size: $fileSize bytes, mime: $mimeType");
    }
    // ========================================
    // METHOD 3: Raw POST body (alternative for native)
    // ========================================
    elseif ($_SERVER['CONTENT_TYPE'] && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        error_log("upload_avatar.php: Processing JSON upload for user $userId");
        
        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data');
        }
        
        if (!isset($json['avatar_base64']) || empty($json['avatar_base64'])) {
            throw new Exception('No avatar data in JSON');
        }
        
        $base64Data = $json['avatar_base64'];
        
        // Remove data:image/...;base64, prefix if present
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $imageType = $matches[1];
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            
            $mimeMap = [
                'jpeg' => 'image/jpeg',
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp'
            ];
            $mimeType = $mimeMap[$imageType] ?? 'image/jpeg';
        }
        
        $base64Data = base64_decode($base64Data, true);
        
        if ($base64Data === false) {
            throw new Exception('Invalid base64 data');
        }
        
        $fileSize = strlen($base64Data);
        
        $tempFile = tempnam(sys_get_temp_dir(), 'avatar_');
        if (!$tempFile) {
            throw new Exception('Failed to create temporary file');
        }
        
        if (file_put_contents($tempFile, $base64Data) === false) {
            throw new Exception('Failed to write temporary file');
        }
        
        if (!$mimeType) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempFile);
            finfo_close($finfo);
        }
        
        error_log("upload_avatar.php: JSON base64 decoded, size: $fileSize bytes, mime: $mimeType");
    }
    else {
        error_log('upload_avatar.php: No valid upload method detected');
        error_log('POST: ' . print_r($_POST, true));
        error_log('FILES: ' . print_r($_FILES, true));
        error_log('Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
        throw new Exception('No file uploaded. Please send via multipart/form-data or base64.');
    }
    
    // ========================================
    // VALIDATE FILE
    // ========================================
    if ($fileSize === 0) {
        throw new Exception('Uploaded file is empty');
    }
    
    if ($fileSize > 5 * 1024 * 1024) {
        throw new Exception('File too large (max 5MB)');
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedTypes)) {
        error_log("upload_avatar.php: Invalid MIME type: $mimeType");
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP allowed.');
    }
    
    error_log("upload_avatar.php: Processing {$mimeType} file ({$fileSize} bytes) for user $userId");
    
    // ========================================
    // CREATE DIRECTORY
    // ========================================
    $avatarDir = __DIR__ . "/../../saves/{$userId}/avatar";
    if (!file_exists($avatarDir)) {
        if (!mkdir($avatarDir, 0755, true)) {
            error_log("upload_avatar.php: Failed to create directory: $avatarDir");
            throw new Exception('Failed to create avatar directory');
        }
        error_log("upload_avatar.php: Created directory: $avatarDir");
    }
    
    // ========================================
    // LOAD & PROCESS IMAGE
    // ========================================
    switch ($mimeType) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($tempFile);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($tempFile);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($tempFile);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($tempFile);
            break;
        default:
            throw new Exception('Unsupported image type');
    }
    
    if (!$image) {
        error_log("upload_avatar.php: Failed to load image from $tempFile");
        throw new Exception('Failed to load image. File may be corrupted.');
    }
    
    $origWidth = imagesx($image);
    $origHeight = imagesy($image);
    
    error_log("upload_avatar.php: Original dimensions: {$origWidth}x{$origHeight}");
    
    // ========================================
    // CREATE SQUARE CROP (400x400)
    // ========================================
    $size = 400;
    $cropSize = min($origWidth, $origHeight);
    $x = ($origWidth - $cropSize) / 2;
    $y = ($origHeight - $cropSize) / 2;
    
    $newImage = imagecreatetruecolor($size, $size);
    
    if (!$newImage) {
        imagedestroy($image);
        throw new Exception('Failed to create resized image');
    }
    
    // Preserve transparency
    imagealphablending($newImage, false);
    imagesavealpha($newImage, true);
    
    $resampled = imagecopyresampled(
        $newImage, $image,
        0, 0,
        (int)$x, (int)$y,
        $size, $size,
        $cropSize, $cropSize
    );
    
    if (!$resampled) {
        imagedestroy($image);
        imagedestroy($newImage);
        throw new Exception('Failed to resize image');
    }
    
    // ========================================
    // SAVE AS WEBP
    // ========================================
    $avatarPath = $avatarDir . '/avatar.webp';
    $saved = imagewebp($newImage, $avatarPath, 85);
    
    imagedestroy($image);
    imagedestroy($newImage);
    
    if (!$saved) {
        error_log("upload_avatar.php: Failed to save WebP to: $avatarPath");
        throw new Exception('Failed to save avatar');
    }
    
    chmod($avatarPath, 0644);
    
    $finalFileSize = filesize($avatarPath);
    error_log("upload_avatar.php: Successfully saved avatar to $avatarPath ({$finalFileSize} bytes)");
    
    // ========================================
    // UPDATE DATABASE
    // ========================================
    $stmt = $db->prepare("
        UPDATE users 
        SET has_avatar = 1, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    
    $avatarUrl = "/saves/{$userId}/avatar/avatar.webp?" . time();
    
    echo json_encode([
        'success' => true,
        'avatar_url' => $avatarUrl,
        'file_size' => $finalFileSize,
        'message' => 'Avatar uploaded successfully'
    ]);
    
    // Cleanup temporary file if created
    if ($tempFile && file_exists($tempFile) && strpos($tempFile, sys_get_temp_dir()) === 0) {
        @unlink($tempFile);
    }
    
} catch (Exception $e) {
    error_log('upload_avatar.php ERROR: ' . $e->getMessage());
    error_log('upload_avatar.php TRACE: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}