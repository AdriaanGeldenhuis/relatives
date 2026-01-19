<?php
declare(strict_types=1);

/**
 * TrackingAuth - Clean authentication for tracking endpoints
 *
 * Auth priority:
 * 1. Authorization: Bearer <session_token> (preferred for native apps)
 * 2. Existing PHP session (for web)
 *
 * IMPORTANT: Does NOT treat RELATIVES_SESSION cookie value as session_token.
 * The cookie is a PHP session ID, not a session_token.
 */

/**
 * Get Authorization header from request (works across server configs)
 */
function tracking_getAuthorizationHeader(): ?string {
    // Standard location
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    // Apache redirect
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    // Apache mod_rewrite / apache_request_headers
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            return trim($headers['Authorization']);
        }
        // Case-insensitive check
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return trim($value);
            }
        }
    }

    return null;
}

/**
 * Validate Bearer token and return user_id if valid
 */
function tracking_userIdFromBearer(PDO $db): ?int {
    $auth = tracking_getAuthorizationHeader();
    if (!$auth) {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        return null;
    }

    $token = trim($m[1]);
    if ($token === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT s.user_id, u.family_id
        FROM sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.session_token = ?
          AND s.expires_at > NOW()
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Set session vars for compatibility with existing code
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['family_id'] = (int)$row['family_id'];
        return (int)$row['user_id'];
    }

    return null;
}

/**
 * Validate session_token from request body (fallback for problematic clients)
 */
function tracking_userIdFromBody(PDO $db, ?array $input): ?int {
    if (!$input || empty($input['session_token'])) {
        return null;
    }

    $token = trim($input['session_token']);
    if ($token === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT s.user_id, u.family_id
        FROM sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.session_token = ?
          AND s.expires_at > NOW()
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['family_id'] = (int)$row['family_id'];
        return (int)$row['user_id'];
    }

    return null;
}

/**
 * Require authenticated user, exit with 401 if not authenticated
 *
 * Priority:
 * 1. Bearer token (Authorization header)
 * 2. PHP session ($_SESSION['user_id'])
 * 3. session_token in request body (fallback)
 *
 * @return array{user_id: int, auth_method: string}
 */
function tracking_requireUserId(PDO $db, ?array $input = null): array {
    // 1. Try Bearer token (preferred for native apps)
    $uid = tracking_userIdFromBearer($db);
    if ($uid) {
        return ['user_id' => $uid, 'auth_method' => 'bearer'];
    }

    // 2. Try existing PHP session (for web)
    if (isset($_SESSION['user_id'])) {
        return ['user_id' => (int)$_SESSION['user_id'], 'auth_method' => 'session'];
    }

    // 3. Try session_token in body (fallback for problematic clients)
    if ($input !== null) {
        $uid = tracking_userIdFromBody($db, $input);
        if ($uid) {
            return ['user_id' => $uid, 'auth_method' => 'body'];
        }
    }

    // Not authenticated
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'unauthorized',
        'hint' => 'Use Authorization: Bearer <session_token> header or include session_token in body'
    ]);
    exit;
}
