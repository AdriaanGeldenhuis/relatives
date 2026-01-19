<?php
/**
 * Redis Connection Test
 * Delete this file after testing!
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>Redis & Tracking System Test</h2>";
echo "<pre>";

// Load environment
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/RedisClient.php';

// Test 1: PHP Redis Extension
echo "1. PHP Redis Extension: ";
if (extension_loaded('redis')) {
    echo "✅ INSTALLED\n";
} else {
    echo "❌ NOT INSTALLED (will use MySQL fallback)\n";
}

// Test 2: Redis Connection
echo "\n2. Redis Cloud Connection: ";
$redis = RedisClient::getInstance();
if ($redis->isAvailable()) {
    echo "✅ CONNECTED\n";

    // Test write/read
    echo "   - Testing write: ";
    $testKey = 'test:connection:' . time();
    $result = $redis->set($testKey, 'hello', 60);
    echo ($result ? "✅ OK" : "❌ FAILED") . "\n";

    echo "   - Testing read: ";
    $value = $redis->get($testKey);
    echo ($value === 'hello' ? "✅ OK" : "❌ FAILED") . "\n";

    echo "   - Cleaning up: ";
    $redis->delete($testKey);
    echo "✅ OK\n";
} else {
    echo "❌ NOT CONNECTED\n";
    echo "   Check your .env REDIS_HOST, REDIS_PORT, REDIS_PASS\n";
}

// Test 3: Database Migration
echo "\n3. Database Migration Check:\n";

try {
    // Check tracking_geofence_queue table
    echo "   - tracking_geofence_queue table: ";
    $stmt = $db->query("SHOW TABLES LIKE 'tracking_geofence_queue'");
    echo ($stmt->rowCount() > 0 ? "✅ EXISTS" : "❌ MISSING - run migration!") . "\n";

    // Check client_event_id column
    echo "   - tracking_locations.client_event_id: ";
    $stmt = $db->query("SHOW COLUMNS FROM tracking_locations LIKE 'client_event_id'");
    echo ($stmt->rowCount() > 0 ? "✅ EXISTS" : "❌ MISSING - run migration!") . "\n";

    // Check device state columns
    echo "   - tracking_devices.network_status: ";
    $stmt = $db->query("SHOW COLUMNS FROM tracking_devices LIKE 'network_status'");
    echo ($stmt->rowCount() > 0 ? "✅ EXISTS" : "❌ MISSING - run migration!") . "\n";

} catch (Exception $e) {
    echo "   ❌ Database error: " . $e->getMessage() . "\n";
}

// Test 4: Environment variables
echo "\n4. Environment Variables:\n";
echo "   - REDIS_HOST: " . ($_ENV['REDIS_HOST'] ?? 'NOT SET') . "\n";
echo "   - REDIS_PORT: " . ($_ENV['REDIS_PORT'] ?? 'NOT SET') . "\n";
echo "   - REDIS_PASS: " . (isset($_ENV['REDIS_PASS']) ? '******** (set)' : 'NOT SET') . "\n";

echo "\n</pre>";
echo "<p><strong>Delete this file after testing!</strong></p>";
?>
