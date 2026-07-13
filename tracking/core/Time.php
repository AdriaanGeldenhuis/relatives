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
     *
     * Tracking timestamps (recorded_at, occurred_at via Time::now, geofence
     * state) are stored as UTC wall-clock strings with NO zone marker. The
     * PHP process runs in Africa/Johannesburg (+02:00), so a bare strtotime()
     * read them as SAST and every elapsed/dedupe/staleness computation was
     * 2 hours off — the dedupe window never triggered and the geofence
     * recompute job treated every fresh fix as stale. A naive "Y-m-d H:i:s"
     * string is therefore interpreted as UTC; anything already carrying a
     * zone (ISO 8601 with Z/offset) is left to strtotime.
     */
    public static function parse(string $input): int
    {
        $input = trim($input);
        if ($input === '') {
            return time();
        }
        // No timezone designator and no 'T' → treat as UTC MySQL datetime.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $input)) {
            $input .= ' UTC';
        }
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
