<?php
/**
 * Run this after deployment to regenerate environment cache
 * php generate-env-cache.php
 */

$envFile = __DIR__ . '/.env';
$cacheFile = __DIR__ . '/core/../.env.cache.php';

if (!file_exists($envFile)) {
    die("ERROR: .env file not found\n");
}

$envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$envVars = [];

foreach ($envLines as $line) {
    $line = trim($line);
    
    if (empty($line) || $line[0] === '#') {
        continue;
    }
    
    if (strpos($line, '=') !== false) {
        list($key, $value) = array_map('trim', explode('=', $line, 2));
        $value = trim($value, '"\'');
        $envVars[$key] = $value;
    }
}

$cacheContent = "<?php\nreturn " . var_export($envVars, true) . ";\n";
file_put_contents($cacheFile, $cacheContent, LOCK_EX);
chmod($cacheFile, 0600);

echo "✅ Environment cache generated successfully\n";
echo "📁 Cache file: {$cacheFile}\n";
echo "🔢 Variables cached: " . count($envVars) . "\n";