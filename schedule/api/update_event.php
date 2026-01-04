<?php
/**
 * DEPRECATED: Redirect to unified events.php
 * This endpoint is kept for backwards compatibility only.
 * All new code should use events.php with action=update or action=toggle
 */

// Original behavior was just marking as done, so use toggle
$_POST['action'] = 'toggle';
require_once __DIR__ . '/events.php';
