<?php
/**
 * Holiday Traveling - Authentication Helper
 * Provides auth utilities for the module
 */
declare(strict_types=1);

class HT_Auth {
    /**
     * Require user to be logged in
     * Redirects to login page or returns JSON error for API calls
     */
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            if (self::isApiRequest()) {
                HT_Response::error('Authentication required', 401);
            }
            header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }

    /**
     * Check if user is logged in
     */
    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Get current user ID
     */
    public static function userId(): ?int {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Get current family ID
     */
    public static function familyId(): ?int {
        // Check direct session variable first
        if (isset($_SESSION['family_id'])) {
            return (int) $_SESSION['family_id'];
        }

        // Check user_data structure (main app stores it here)
        if (isset($_SESSION['user_data']['family_id'])) {
            return (int) $_SESSION['user_data']['family_id'];
        }

        // Fallback: get from database if user is logged in
        if (self::isLoggedIn()) {
            $user = HT_DB::fetchOne(
                "SELECT family_id FROM users WHERE id = ?",
                [self::userId()]
            );
            if ($user && $user['family_id']) {
                // Cache it for future calls
                $_SESSION['family_id'] = (int) $user['family_id'];
                return (int) $user['family_id'];
            }
        }

        return null;
    }

    /**
     * Get current user data
     */
    public static function user(): ?array {
        if (!self::isLoggedIn()) {
            return null;
        }

        // Use cached user data if available
        if (isset($_SESSION['user_cache']) && isset($_SESSION['user_cache_time'])) {
            if (time() - $_SESSION['user_cache_time'] < 300) { // 5 min cache
                return $_SESSION['user_cache'];
            }
        }

        $user = HT_DB::fetchOne(
            "SELECT id, family_id, email, full_name, role, avatar_color, status
             FROM users WHERE id = ? AND status = 'active'",
            [self::userId()]
        );

        if ($user) {
            $_SESSION['user_cache'] = $user;
            $_SESSION['user_cache_time'] = time();
        }

        return $user;
    }

    /**
     * Check if request is an API request (expects JSON)
     */
    private static function isApiRequest(): bool {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        return strpos($acceptHeader, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false
            || strpos($requestUri, '/api/') !== false;
    }

    /**
     * Check if user can access a trip
     */
    public static function canAccessTrip(int $tripId): bool {
        if (!self::isLoggedIn()) {
            return false;
        }

        $userId = self::userId();
        $familyId = self::familyId();

        // Check if user owns the trip or is in the same family
        $trip = HT_DB::fetchOne(
            "SELECT id FROM ht_trips WHERE id = ? AND (user_id = ? OR family_id = ?)",
            [$tripId, $userId, $familyId]
        );

        if ($trip) {
            return true;
        }

        // Check if user is a trip member
        $member = HT_DB::fetchOne(
            "SELECT id FROM ht_trip_members WHERE trip_id = ? AND user_id = ? AND status = 'joined'",
            [$tripId, $userId]
        );

        return $member !== null;
    }

    /**
     * Check if user can edit a trip
     */
    public static function canEditTrip(int $tripId): bool {
        if (!self::isLoggedIn()) {
            return false;
        }

        $userId = self::userId();
        $familyId = self::familyId();

        // Check if user owns the trip
        $trip = HT_DB::fetchOne(
            "SELECT id FROM ht_trips WHERE id = ? AND user_id = ?",
            [$tripId, $userId]
        );

        if ($trip) {
            return true;
        }

        // Check if user is an editor member
        $member = HT_DB::fetchOne(
            "SELECT id FROM ht_trip_members
             WHERE trip_id = ? AND user_id = ? AND status = 'joined' AND role IN ('owner', 'editor')",
            [$tripId, $userId]
        );

        return $member !== null;
    }
}
