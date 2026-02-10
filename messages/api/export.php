<?php
/**
 * EXPORT MESSAGES API
 * GET: format (json, csv, txt), date_from, date_to
 * Export family messages in various formats
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = $_SESSION['user_id'];
    
    // Get user's family
    $stmt = $db->prepare("SELECT family_id, full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $familyId = $user['family_id'];
    
    // Get export parameters
    $format = strtolower($_GET['format'] ?? 'json');
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    
    // Validate format
    if (!in_array($format, ['json', 'csv', 'txt'])) {
        throw new Exception('Invalid export format. Use: json, csv, txt');
    }
    
    // Build query
    $sql = "
        SELECT 
            m.id,
            m.message_type,
            m.content,
            m.media_path,
            m.created_at,
            m.edited_at,
            u.full_name as sender_name,
            (SELECT content FROM messages WHERE id = m.reply_to_message_id LIMIT 1) as reply_to_content
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.family_id = ?
          AND m.deleted_at IS NULL
    ";
    
    $params = [$familyId];
    
    if ($dateFrom) {
        $sql .= " AND m.created_at >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND m.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    
    $sql .= " ORDER BY m.created_at ASC LIMIT 10000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate filename
    $filename = 'family_chat_export_' . date('Y-m-d_His');
    
    // Export based on format
    switch ($format) {
        case 'json':
            exportAsJson($messages, $filename);
            break;
        
        case 'csv':
            exportAsCsv($messages, $filename);
            break;
        
        case 'txt':
            exportAsTxt($messages, $filename);
            break;
    }
    
    // Log export
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (family_id, user_id, action, entity_type, created_at)
            VALUES (?, ?, 'export', 'messages', NOW())
        ");
        $stmt->execute([$familyId, $userId]);
    } catch (Exception $e) {
        // Audit log failed, continue
    }
    
} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Export as JSON
 */
function exportAsJson($messages, $filename) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    
    echo json_encode([
        'export_date' => date('Y-m-d H:i:s'),
        'message_count' => count($messages),
        'messages' => $messages
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Export as CSV
 */
function exportAsCsv($messages, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header
    fputcsv($output, ['Date', 'Time', 'Sender', 'Type', 'Message', 'Has Media', 'Edited']);
    
    // Write data
    foreach ($messages as $msg) {
        $datetime = new DateTime($msg['created_at']);
        fputcsv($output, [
            $datetime->format('Y-m-d'),
            $datetime->format('H:i:s'),
            $msg['sender_name'],
            $msg['message_type'],
            $msg['content'] ?: '[No text content]',
            $msg['media_path'] ? 'Yes' : 'No',
            $msg['edited_at'] ? 'Yes' : 'No'
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * Export as plain text
 */
function exportAsTxt($messages, $filename) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
    
    echo "FAMILY CHAT EXPORT\n";
    echo "==================\n";
    echo "Export Date: " . date('Y-m-d H:i:s') . "\n";
    echo "Total Messages: " . count($messages) . "\n";
    echo "==================\n\n";
    
    foreach ($messages as $msg) {
        $datetime = new DateTime($msg['created_at']);
        echo "[" . $datetime->format('Y-m-d H:i:s') . "] ";
        echo $msg['sender_name'] . ": ";
        
        if ($msg['content']) {
            echo $msg['content'];
        } else {
            echo "[" . ucfirst($msg['message_type']) . " message]";
        }
        
        if ($msg['edited_at']) {
            echo " (edited)";
        }
        
        echo "\n";
        
        if ($msg['reply_to_content']) {
            echo "  ↳ Reply to: " . substr($msg['reply_to_content'], 0, 50) . "...\n";
        }
        
        echo "\n";
    }
    
    exit;
}