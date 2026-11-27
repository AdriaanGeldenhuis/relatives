<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/components/welcome-sky.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$scene = $_GET['scene'] ?? null;
if (!in_array($scene, WELCOME_SKY_SCENES, true)) {
    http_response_code(404);
    exit;
}

$path = welcomeSkyPhotoPath($scene);
if (!is_readable($path)) {
    http_response_code(404);
    exit;
}

$versioned = ($_GET['v'] ?? null) === (string)filemtime($path);
header('Content-Type: image/webp');
header('Cache-Control: ' . ($versioned ? 'public, max-age=31536000, immutable' : 'no-cache'));
header('Content-Length: ' . filesize($path));
if ($method === 'GET') readfile($path);
