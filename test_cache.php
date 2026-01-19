<?php
/**
 * Cache & Tracking System Test
 * Delete this file after testing!
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h2>Cache & Tracking System Test</h2>";
echo "<pre>";

// Load environment
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/Cache.php';

// Test 1: PHP Memcached Extension
echo "1. PHP Memcached Extension: ";
if (class_exists('Memcached')) {
    echo "✅ INSTALLED\n";
} else {
    echo "❌ NOT INSTALLED (will use MySQL fallback)\n";
}

// Test 2: Cache Connection
echo "\n2. Cache Connection:\n";
$cache = Cache::init($db);
$cacheType = $cache->getType();
echo "   - Active cache type: " . strtoupper($cacheType) . "\n";

if ($cache->available()) {
    echo "   - Status: ✅ AVAILABLE\n";

    // Test write/read
    echo "   - Testing write: ";
    $testKey = 'test:connection:' . time();
    $result = $cache->set($testKey, 'hello', 60);
    echo ($result ? "✅ OK" : "❌ FAILED") . "\n";

    echo "   - Testing read: ";
    $value = $cache->get($testKey);
    echo ($value === 'hello' ? "✅ OK" : "❌ FAILED (got: " . var_export($value, true) . ")") . "\n";

    echo "   - Cleaning up: ";
    $cache->delete($testKey);
    echo "✅ OK\n";

    // Test location caching
    echo "\n   - Testing location cache:\n";
    $testLocation = [
        'lat' => -26.123,
        'lng' => 28.456,
        'speed' => 45.5,
        'accuracy' => 10,
        'battery' => 85,
        'moving' => true,
        'ts' => date('Y-m-d H:i:s')
    ];
    $cache->setUserLocation(1, 999, $testLocation);
    $retrieved = $cache->getUserLocation(1, 999);

    if ($retrieved && $retrieved['lat'] == -26.123) {
        echo "     ✅ Location cache working\n";
    } else {
        echo "     ❌ Location cache failed\n";
    }
} else {
    echo "   - Status: ❌ NOT AVAILABLE\n";
    echo "   - App will work using direct DB queries\n";
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

    // Check cache table (for MySQL fallback)
    echo "   - cache table: ";
    $stmt = $db->query("SHOW TABLES LIKE 'cache'");
    echo ($stmt->rowCount() > 0 ? "✅ EXISTS" : "⚠️ MISSING (create for MySQL cache fallback)") . "\n";

} catch (Exception $e) {
    echo "   ❌ Database error: " . $e->getMessage() . "\n";
}

// Test 4: Environment variables
echo "\n4. Environment Variables:\n";
echo "   - MEMCACHED_HOST: " . ($_ENV['MEMCACHED_HOST'] ?? '127.0.0.1 (default)') . "\n";
echo "   - MEMCACHED_PORT: " . ($_ENV['MEMCACHED_PORT'] ?? '11211 (default)') . "\n";

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "SUMMARY:\n";
echo str_repeat("=", 50) . "\n";

if ($cacheType === 'memcached') {
    echo "✅ Memcached is active - best performance\n";
} elseif ($cacheType === 'mysql') {
    echo "⚠️ MySQL cache fallback is active\n";
    echo "   Ask Xneelo to install Memcached for better performance\n";
} else {
    echo "⚠️ No cache available - using direct DB queries\n";
    echo "   This still works but is slower\n";
}

echo "\n</pre>";
echo "<p><strong>Delete this file after testing!</strong></p>";
?>
