<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';
require_once __DIR__ . '/../store.php';

header('Cache-Control: private, no-store');
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
$user = (new Session($db))->validate();
if (!$user) {
    unset($_SESSION['user_id']);
    http_response_code(401);
    exit;
}
session_write_close();

$id = eventNoteId($_GET['note'] ?? null);
$name = $_GET['file'] ?? null;
if (!is_string($name)) {
    http_response_code(404);
    exit;
}
$note = $id ? notesSelect($db, (int)$user['family_id'], $id) : null;
$paths = $note ? [...notesImagePaths($note['image_path'] ?? null), $note['audio_path'] ?? ''] : [];
if ($id === null && isset($_GET['collage']) && preg_match('/^[a-f0-9]{12}\.webp$/D', $name)) {
    $paths = ['/saves/' . (int) $user['id'] . '/notes/' . $name];
}
$path = null;
foreach ($paths as $candidate) {
    if (notesIsPrivateMedia($candidate) && basename($candidate) === $name) {
        $path = __DIR__ . '/../..' . $candidate;
        break;
    }
}
$file = $path && is_file($path) ? @fopen($path, 'rb') : false;
if (!$file) {
    http_response_code(404);
    exit;
}

$size = fstat($file)['size'];
$start = 0;
$end = $size - 1;
header('Accept-Ranges: bytes');
$range = $_SERVER['HTTP_RANGE'] ?? null;
if ($range !== null) {
    if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) && ($parts[1] !== '' || $parts[2] !== '')) {
        if ($parts[1] === '') {
            $start = $size - min($size, (int)$parts[2]);
        } else {
            $start = (int)$parts[1];
            if ($parts[2] !== '') {
                $end = min($end, (int)$parts[2]);
            }
        }
    } else {
        $start = $size;
    }
    if ($start > $end) {
        header("Content-Range: bytes */$size");
        http_response_code(416);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$types = ['webp' => 'image/webp', 'webm' => 'audio/webm', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg'];
$length = $end - $start + 1;
$types += ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'mp3' => 'audio/mpeg'];
header('Content-Type: ' . ($types[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . $length);
if ($method === 'GET') {
    stream_copy_to_stream($file, fopen('php://output', 'wb'), $length, $start);
}
