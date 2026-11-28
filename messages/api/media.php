<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';
header('Cache-Control: private, no-store');
header('CDN-Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/PrivateMedia.php';
require_once __DIR__ . '/classes/MessageAttachments.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    http_response_code(401);
    exit;
}
session_write_close();

$family = (int) $user['family_id'];
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$entry = null;
if (str_starts_with($requestPath, '/uploads/messages/')) {
    $path = MessageAttachments::storagePath($requestPath);
    if ($path !== null) {
        $entry = MessageAttachments::findLegacy($db, $family, $path);
    }
} else {
    $id = filter_input(INPUT_GET, 'message', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $index = filter_input(INPUT_GET, 'file', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9]]);
    if (is_int($id) && is_int($index)) {
        $stmt = $db->prepare('SELECT media_path, media_thumbnail, message_type FROM messages
            WHERE family_id = ? AND id = ? AND deleted_at IS NULL');
        $stmt->execute([$family, $id]);
        if ($message = $stmt->fetch()) {
            $entry = MessageAttachments::entries($message)[$index] ?? null;
            if ($entry && $index === 0 && ($_GET['thumb'] ?? null) === '1' && str_starts_with($entry['type'], 'image/')) {
                $thumbnail = MessageAttachments::storagePath($message['media_thumbnail']);
                if ($thumbnail && is_file(__DIR__ . '/../..' . $thumbnail)) {
                    $entry['path'] = $thumbnail;
                }
            }
        }
    }
}

$root = realpath(__DIR__ . '/../../uploads/messages');
$path = $entry ? realpath(__DIR__ . '/../..' . $entry['path']) : false;
if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}
$type = $entry['type'];
if (str_starts_with(basename($path), 'thumb_') && str_starts_with($type, 'image/')) {
    $type = @getimagesize($path)['mime'] ?? $type;
}
$inline = preg_match('~^(image|video|audio)/~', $type);
$filename = $inline && ($_GET['download'] ?? null) !== '1' ? null : $entry['name'];
PrivateMedia::stream($path, $type, $filename);
