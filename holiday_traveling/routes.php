<?php
/**
 * Holiday Traveling - Bootstrap / Routes
 * Loads all module dependencies
 */
declare(strict_types=1);

// Ensure session is started first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load core bootstrap if not already loaded
if (!class_exists('DB')) {
    require_once __DIR__ . '/../core/bootstrap.php';
}

// Load CSRF class if not already loaded (needed for HT_CSRF)
if (!class_exists('CSRF')) {
    require_once __DIR__ . '/../core/CSRF.php';
}

// Load module libraries (order matters due to dependencies)
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/validators.php';
require_once __DIR__ . '/lib/cache.php';
require_once __DIR__ . '/lib/rate_limit.php';
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/ics.php';
require_once __DIR__ . '/lib/calendar_google.php';
require_once __DIR__ . '/lib/calendar_internal.php';

/**
 * Helper function to load a view with layout
 *
 * @param string $viewName View filename (without .php)
 * @param array $data Variables to pass to view
 */
function ht_view(string $viewName, array $data = []): void {
    // Extract data to variables
    extract($data);

    // Start output buffering
    ob_start();

    // Include the view
    require __DIR__ . '/views/' . $viewName . '.php';

    // Get the content
    $pageContent = ob_get_clean();

    // Include layout
    require __DIR__ . '/views/layout.php';
}

/**
 * Helper to get JSON input from request body
 */
function ht_json_input(): array {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return $_POST;
    }
    $decoded = json_decode($input, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Helper to get request parameter (POST > GET)
 */
function ht_input(string $key, mixed $default = null): mixed {
    $jsonInput = ht_json_input();
    return $jsonInput[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
}

/**
 * Helper to format date for display
 */
function ht_format_date(string $date, string $format = 'M j, Y'): string {
    return date($format, strtotime($date));
}

/**
 * Helper to calculate trip duration
 */
function ht_trip_duration(string $startDate, string $endDate): int {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    return (int) $end->diff($start)->days + 1;
}

/**
 * Helper to format currency
 */
function ht_format_currency(float $amount, string $currency = 'ZAR'): string {
    $symbols = [
        'ZAR' => 'R',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

/**
 * Get trip status badge HTML
 */
function ht_status_badge(string $status): string {
    $classes = [
        'draft' => 'badge-draft',
        'planned' => 'badge-planned',
        'locked' => 'badge-locked',
        'active' => 'badge-active',
        'completed' => 'badge-completed',
        'cancelled' => 'badge-cancelled',
    ];
    $class = $classes[$status] ?? 'badge-draft';
    return '<span class="ht-badge ' . $class . '">' . ucfirst($status) . '</span>';
}
