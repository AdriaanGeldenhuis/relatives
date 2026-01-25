<?php
/**
 * Holiday Traveling - Create Trip Page
 */
declare(strict_types=1);

require_once __DIR__ . '/routes.php';

// Require authentication
HT_Auth::requireLogin();

// Page setup
$pageTitle = 'Create Trip';
$pageCSS = ['/holiday_traveling/assets/css/holiday.css'];
$pageJS = ['/holiday_traveling/assets/js/ai_worker.js'];

// Render view
ht_view('trip_create', [
    'pageTitle' => $pageTitle,
    'pageCSS' => $pageCSS,
    'pageJS' => $pageJS
]);
