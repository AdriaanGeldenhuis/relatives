<?php
/**
 * SEARCH MESSAGES API
 * GET: query, user_id, date_from, date_to, type, has_media, limit
 * Advanced search with multiple filters
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = $_SESSION['user_id'];
    
    // Get user's family
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $familyId = $user['family_id'];
    
    // Get search parameters
    $query = trim($_GET['query'] ?? '');
    $filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $messageType = $_GET['type'] ?? null;
    $hasMedia = isset($_GET['has_media']) ? (bool)$_GET['has_media'] : null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    // Build query
    $sql = "
        SELECT 
            m.id,
            m.user_id,
            m.message_type,
            m.content,
            m.media_path,
            m.media_thumbnail,
            m.reply_to_message_id,
            m.pinned,
            m.edited_at,
            m.edit_count,
            m.created_at,
            u.full_name,
            u.avatar_color
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.family_id = ?
          AND m.deleted_at IS NULL
    ";
    
    $params = [$familyId];
    
    // Add full-text search if query provided
    if (!empty($query)) {
        $sql .= " AND (
            m.content LIKE ? OR
            u.full_name LIKE ?
        )";
        $searchTerm = '%' . $query . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // Filter by user
    if ($filterUserId) {
        $sql .= " AND m.user_id = ?";
        $params[] = $filterUserId;
    }
    
    // Filter by date range
    if ($dateFrom) {
        $sql .= " AND m.created_at >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND m.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    
    // Filter by message type
    if ($messageType) {
        $sql .= " AND m.message_type = ?";
        $params[] = $messageType;
    }
    
    // Filter by has media
    if ($hasMedia !== null) {
        if ($hasMedia) {
            $sql .= " AND m.media_path IS NOT NULL";
        } else {
            $sql .= " AND m.media_path IS NULL";
        }
    }
    
    // Add ordering and pagination
    $sql .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countSql = "
        SELECT COUNT(*)
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.family_id = ?
          AND m.deleted_at IS NULL
    ";
    
    $countParams = [$familyId];
    
    // Add same filters to count query
    if (!empty($query)) {
        $countSql .= " AND (m.content LIKE ? OR u.full_name LIKE ?)";
        $countParams[] = '%' . $query . '%';
        $countParams[] = '%' . $query . '%';
    }
    
    if ($filterUserId) {
        $countSql .= " AND m.user_id = ?";
        $countParams[] = $filterUserId;
    }
    
    if ($dateFrom) {
        $countSql .= " AND m.created_at >= ?";
        $countParams[] = $dateFrom;
    }
    
    if ($dateTo) {
        $countSql .= " AND m.created_at <= ?";
        $countParams[] = $dateTo . ' 23:59:59';
    }
    
    if ($messageType) {
        $countSql .= " AND m.message_type = ?";
        $countParams[] = $messageType;
    }
    
    if ($hasMedia !== null) {
        if ($hasMedia) {
            $countSql .= " AND m.media_path IS NOT NULL";
        } else {
            $countSql .= " AND m.media_path IS NULL";
        }
    }
    
    $stmt = $db->prepare($countSql);
    $stmt->execute($countParams);
    $totalCount = (int)$stmt->fetchColumn();
    
    // Highlight search terms in results
    if (!empty($query) && !empty($results)) {
        foreach ($results as &$result) {
            if ($result['content']) {
                $result['content_highlighted'] = highlightSearchTerm($result['content'], $query);
            }
        }
    }
    
    // Log search
    try {
        $stmt = $db->prepare("
            INSERT INTO search_history (user_id, family_id, query, results_count, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $familyId, $query, count($results)]);
    } catch (Exception $e) {
        // Search history logging failed, continue
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results),
        'total' => $totalCount,
        'has_more' => ($offset + $limit) < $totalCount,
        'query' => $query,
        'filters' => [
            'user_id' => $filterUserId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'type' => $messageType,
            'has_media' => $hasMedia
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Highlight search term in text
 */
function highlightSearchTerm($text, $query) {
    if (empty($query)) return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // HTML-escape the content first to prevent XSS, then wrap matches in <mark>
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $pattern = '/(' . preg_quote(htmlspecialchars($query, ENT_QUOTES, 'UTF-8'), '/') . ')/i';
    return preg_replace($pattern, '<mark>$1</mark>', $escaped);
}