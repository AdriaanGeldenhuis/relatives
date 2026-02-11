<?php
/**
 * Notes Photo Upload API
 * Uploads photos to /saves/{user_id}/notes/ as webp
 */

session_name('RELATIVES_SESSION');
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No photo uploaded']);
    exit;
}

$file = $_FILES['photo'];
$maxSize = 10 * 1024 * 1024; // 10MB

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
    exit;
}

// Create directory if not exists
$uploadDir = __DIR__ . '/../../saves/' . $userId . '/notes/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$filename = 'photo_' . time() . '_' . uniqid() . '.webp';
$filepath = $uploadDir . $filename;

// Convert to webp
$image = null;

switch ($mimeType) {
    case 'image/jpeg':
        $image = imagecreatefromjpeg($file['tmp_name']);
        break;
    case 'image/png':
        $image = imagecreatefrompng($file['tmp_name']);
        break;
    case 'image/gif':
        $image = imagecreatefromgif($file['tmp_name']);
        break;
    case 'image/webp':
        $image = imagecreatefromwebp($file['tmp_name']);
        break;
    default:
        // Try with imagick for HEIC/HEIF
        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick($file['tmp_name']);
                $imagick->setImageFormat('webp');
                $imagick->setImageCompressionQuality(85);
                $imagick->writeImage($filepath);
                $imagick->destroy();

                echo json_encode([
                    'success' => true,
                    'url' => '/saves/' . $userId . '/notes/' . $filename,
                    'filename' => $filename
                ]);
                exit;
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to convert image']);
                exit;
            }
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unsupported image format']);
        exit;
}

if (!$image) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to process image']);
    exit;
}

// Preserve transparency for PNG
if ($mimeType === 'image/png') {
    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);
}

// Save as webp with 85% quality
$success = imagewebp($image, $filepath, 85);
imagedestroy($image);

if (!$success) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save image']);
    exit;
}

echo json_encode([
    'success' => true,
    'url' => '/saves/' . $userId . '/notes/' . $filename,
    'filename' => $filename
]);
