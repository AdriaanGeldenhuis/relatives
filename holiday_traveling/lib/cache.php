<?php
/**
 * Holiday Traveling - Simple Cache Helper
 * Wraps core Cache class if available, or uses session/file fallback
 */
declare(strict_types=1);

class HT_Cache {
    private const CACHE_DIR = '/tmp/ht_cache/';

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public static function get(string $key, mixed $default = null): mixed {
        // Try core cache first
        if (class_exists('Cache') && method_exists('Cache', 'get')) {
            $value = Cache::get('ht:' . $key);
            if ($value !== null) {
                return $value;
            }
        }

        // Session cache fallback
        $sessionKey = 'ht_cache_' . md5($key);
        if (isset($_SESSION[$sessionKey])) {
            $cached = $_SESSION[$sessionKey];
            if ($cached['expires'] > time()) {
                return $cached['value'];
            }
            unset($_SESSION[$sessionKey]);
        }

        // File cache fallback
        $filePath = self::getFilePath($key);
        if (file_exists($filePath)) {
            $cached = unserialize(file_get_contents($filePath));
            if ($cached && $cached['expires'] > time()) {
                return $cached['value'];
            }
            @unlink($filePath);
        }

        return $default;
    }

    /**
     * Set cached value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (default 1 hour)
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): void {
        $expires = time() + $ttl;

        // Try core cache first
        if (class_exists('Cache') && method_exists('Cache', 'set')) {
            Cache::set('ht:' . $key, $value, $ttl);
        }

        // Session cache
        $sessionKey = 'ht_cache_' . md5($key);
        $_SESSION[$sessionKey] = [
            'value' => $value,
            'expires' => $expires
        ];

        // File cache for persistence across sessions
        $filePath = self::getFilePath($key);
        self::ensureCacheDir();
        file_put_contents($filePath, serialize([
            'value' => $value,
            'expires' => $expires
        ]));
    }

    /**
     * Check if key exists in cache
     */
    public static function has(string $key): bool {
        return self::get($key) !== null;
    }

    /**
     * Delete cached value
     */
    public static function delete(string $key): void {
        // Core cache
        if (class_exists('Cache') && method_exists('Cache', 'delete')) {
            Cache::delete('ht:' . $key);
        }

        // Session cache
        $sessionKey = 'ht_cache_' . md5($key);
        unset($_SESSION[$sessionKey]);

        // File cache
        $filePath = self::getFilePath($key);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Get or set cache (convenience method)
     *
     * @param string $key Cache key
     * @param callable $callback Function to generate value if not cached
     * @param int $ttl Time to live
     * @return mixed Cached or generated value
     */
    public static function remember(string $key, callable $callback, int $ttl = 3600): mixed {
        $value = self::get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    /**
     * Clear all module cache
     */
    public static function flush(): void {
        // Clear session cache
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, 'ht_cache_')) {
                unset($_SESSION[$key]);
            }
        }

        // Clear file cache
        if (is_dir(self::CACHE_DIR)) {
            $files = glob(self::CACHE_DIR . '*.cache');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Get file path for cache key
     */
    private static function getFilePath(string $key): string {
        return self::CACHE_DIR . md5($key) . '.cache';
    }

    /**
     * Ensure cache directory exists
     */
    private static function ensureCacheDir(): void {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }
    }
}
