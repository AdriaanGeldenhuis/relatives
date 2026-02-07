<?php
declare(strict_types=1);

// Session MUST start before bootstrap (login stores under PHPSESSID, bootstrap changes to RELATIVES_SESSION)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/bootstrap.php';

// Load tracking utilities
require_once __DIR__ . '/SiteContext.php';
require_once __DIR__ . '/Time.php';
require_once __DIR__ . '/Validator.php';

// Load GeoUtils
$geoUtilsPath = __DIR__ . '/../../core/GeoUtils.php';
if (file_exists($geoUtilsPath)) {
    require_once $geoUtilsPath;
}

// Load NotificationManager and Triggers
$notifPath = __DIR__ . '/../../core/NotificationManager.php';
$trigPath = __DIR__ . '/../../core/NotificationTriggers.php';
if (file_exists($notifPath)) require_once $notifPath;
if (file_exists($trigPath)) require_once $trigPath;

// Autoload tracking services
$servicesDir = __DIR__ . '/services/';
if (is_dir($servicesDir)) {
    foreach (glob($servicesDir . '*.php') as $file) {
        require_once $file;
    }
}

// Autoload tracking repos
$reposDir = __DIR__ . '/repos/';
if (is_dir($reposDir)) {
    foreach (glob($reposDir . '*.php') as $file) {
        require_once $file;
    }
}
