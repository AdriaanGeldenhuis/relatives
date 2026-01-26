<?php
/**
 * COMPATIBILITY SHIM - Old API endpoint
 *
 * Redirects old get_current_locations.php calls to new current.php
 * This allows the Android app to work with the rebuilt tracking system.
 */

require_once __DIR__ . '/current.php';
