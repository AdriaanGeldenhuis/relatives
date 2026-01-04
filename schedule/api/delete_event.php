<?php
/**
 * DEPRECATED: Redirect to unified events.php
 * This endpoint is kept for backwards compatibility only.
 * All new code should use events.php with action=delete
 */

$_POST['action'] = 'delete';
require_once __DIR__ . '/events.php';
