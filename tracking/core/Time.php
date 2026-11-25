<?php
declare(strict_types=1);

/**
 * Time utilities for tracking module
 */
class Time
{
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Parse supported capture timestamps without treating bad input as a fresh fix. */
    public static function captureMs(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value < 100000000000 ? $value * 1000 : $value;
        }
        if (!is_string($value) || !preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2}| UTC| GMT)?$/iD',
            $value
        )) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            return ($errors['warning_count'] ?? 0) || ($errors['error_count'] ?? 0) ? null : (int) $time->format('Uv');
        } catch (Exception) {
            return null;
        }
    }

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
