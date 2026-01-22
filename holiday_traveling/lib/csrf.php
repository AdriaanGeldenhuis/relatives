<?php
/**
 * Holiday Traveling - CSRF Protection Helper
 * Wraps core CSRF class with module-specific utilities
 */
declare(strict_types=1);

class HT_CSRF {
    /**
     * Generate CSRF token (uses core CSRF class)
     */
    public static function token(): string {
        return CSRF::generate();
    }

    /**
     * Output hidden CSRF input field for forms
     */
    public static function field(): string {
        return '<input type="hidden" name="csrf_token" value="' . self::token() . '">';
    }

    /**
     * Get token for JavaScript (meta tag or data attribute)
     */
    public static function meta(): string {
        return '<meta name="csrf-token" content="' . self::token() . '">';
    }

    /**
     * Verify CSRF token from request
     * Checks both POST data and X-CSRF-Token header
     */
    public static function verify(): bool {
        // Check POST data first
        if (isset($_POST['csrf_token'])) {
            return CSRF::validate($_POST['csrf_token']);
        }

        // Check JSON body
        $input = file_get_contents('php://input');
        if ($input) {
            $data = json_decode($input, true);
            if (isset($data['csrf_token'])) {
                return CSRF::validate($data['csrf_token']);
            }
        }

        // Check header (for AJAX requests)
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($headerToken) {
            return CSRF::validate($headerToken);
        }

        return false;
    }

    /**
     * Verify CSRF or die with error
     * Call this at the top of POST/PUT/DELETE API endpoints
     */
    public static function verifyOrDie(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return; // GET requests don't need CSRF
        }

        if (!self::verify()) {
            HT_Response::error('Invalid or missing CSRF token', 403);
        }
    }
}
