<?php
if (!isset($user, $pageTitle)) {
    http_response_code(404);
    exit;
}
header('Cache-Control: private, no-store');
$pageCSS = ['/admin/css/admin.css?build=' . filemtime(__DIR__ . '/css/admin.css')];
$pageJS = ['/admin/js/admin.js'];
$pageJSVersion = filemtime(__DIR__ . '/js/admin.js');

require __DIR__ . '/../shared/components/header.php';
