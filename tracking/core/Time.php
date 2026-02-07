<?php
declare(strict_types=1);

/**
 * Time utilities for tracking module
 */
class Time
{
    /**
     * Current UTC timestamp in MySQL format
     */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Parse an ISO 8601 or MySQL timestamp into a Unix timestamp.
     * Returns current time if input is invalid.
     */
    public static function parse(string $input): int
    {
        $ts = strtotime($input);
        return $ts !== false ? $ts : time();
    }

    /**
     * Format a Unix timestamp as MySQL datetime
     */
    public static function format(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Seconds elapsed since a MySQL datetime string
     */
    public static function elapsed(string $datetime): int
    {
        return max(0, time() - self::parse($datetime));
    }

    /**
     * Check if a MySQL datetime is older than $seconds
     */
    public static function isOlderThan(string $datetime, int $seconds): bool
    {
        return self::elapsed($datetime) > $seconds;
    }
}
