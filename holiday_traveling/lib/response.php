<?php
/**
 * Holiday Traveling - JSON Response Helper
 * Standardized API responses: {success, data, error}
 */
declare(strict_types=1);

class HT_Response {
    /**
     * Send successful JSON response
     *
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     */
    public static function ok(mixed $data = null, ?string $message = null): never {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        self::send($response, 200);
    }

    /**
     * Send error JSON response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param array|null $details Additional error details
     */
    public static function error(string $message, int $code = 400, ?array $details = null): never {
        $response = [
            'success' => false,
            'error' => $message
        ];

        if ($details !== null) {
            $response['details'] = $details;
        }

        self::send($response, $code);
    }

    /**
     * Send validation error response
     *
     * @param array $errors Array of field => error message
     */
    public static function validationError(array $errors): never {
        self::send([
            'success' => false,
            'error' => 'Validation failed',
            'validation_errors' => $errors
        ], 422);
    }

    /**
     * Send created response (HTTP 201)
     *
     * @param mixed $data Created resource data
     */
    public static function created(mixed $data = null): never {
        self::send([
            'success' => true,
            'data' => $data
        ], 201);
    }

    /**
     * Send no content response (HTTP 204)
     */
    public static function noContent(): never {
        http_response_code(204);
        exit;
    }

    /**
     * Send paginated response
     *
     * @param array $items List items
     * @param int $total Total count
     * @param int $page Current page
     * @param int $perPage Items per page
     */
    public static function paginated(array $items, int $total, int $page, int $perPage): never {
        self::send([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total
            ]
        ], 200);
    }

    /**
     * Internal: Send JSON response and exit
     */
    private static function send(array $response, int $status): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('CDN-Cache-Control: no-store');
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
