<?php
/**
 * ============================================
 * SUZI VOICE CHAT API v1.0
 * Full conversational AI with action detection
 * Can answer questions, tell stories, AND control the app
 * ============================================
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/bootstrap.php';

// Get input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['transcript']) || empty(trim($input['transcript']))) {
    echo json_encode([
        'success' => false,
        'response' => 'I didn\'t hear anything. Could you try again?',
        'action' => null
    ]);
    exit;
}

$transcript = trim($input['transcript']);
$conversation = $input['conversation'] ?? [];

// Get user context
session_name('RELATIVES_SESSION');
session_start();
$userId = $_SESSION['user_id'] ?? null;
$userName = '';
$familyId = null;
$familyMembers = [];

if ($userId) {
    try {
        // Get user info
        $stmt = $db->prepare("SELECT full_name, family_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            $userName = $user['full_name'];
            $familyId = $user['family_id'];

            // Get family members for location queries
            if ($familyId) {
                $stmt = $db->prepare("SELECT id, full_name FROM users WHERE family_id = ? AND id != ?");
                $stmt->execute([$familyId, $userId]);
                $familyMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {
        error_log('Voice chat user context error: ' . $e->getMessage());
    }
}

// Build context strings
$userContext = $userName ? "The user's name is {$userName}." : '';
$familyContext = '';
if (!empty($familyMembers)) {
    $names = array_column($familyMembers, 'full_name');
    $familyContext = "Family members: " . implode(', ', $names) . ".";
}

// Get current date/time
$now = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
$dateContext = "Today is " . $now->format('l, F j, Y') . ". The time is " . $now->format('g:i A') . ".";

// OpenAI API Configuration
$apiKey = getenv('OPENAI_API_KEY');
$apiUrl = 'https://api.openai.com/v1/chat/completions';

if (!$apiKey) {
    echo json_encode([
        'success' => false,
        'response' => 'Voice assistant is not configured properly.',
        'action' => null
    ]);
    exit;
}

// Comprehensive system prompt - this is the key to making it work properly
$systemPrompt = <<<PROMPT
You are Suzi, a friendly and helpful voice assistant for the Relatives family app. You can have natural conversations AND help control the app.

CONTEXT:
$userContext
$familyContext
$dateContext

YOUR CAPABILITIES:
1. Answer ANY question - history, science, math, general knowledge, etc.
2. Tell stories, jokes, riddles, fun facts
3. Have natural conversations with follow-up questions
4. Help with the app - shopping lists, notes, calendar, messages, finding family

RESPONSE FORMAT:
Always respond with valid JSON:
{
    "response": "Your natural, conversational answer here",
    "action": null or {"type": "action_type", "data": {...}}
}

ACTIONS (only include if user wants to DO something in the app):
- {"type": "navigate", "data": {"to": "home|shopping|notes|calendar|schedule|weather|messages|tracking|notifications|games|help"}}
- {"type": "add_shopping", "data": {"item": "item name", "category": "dairy|meat|produce|bakery|pantry|frozen|snacks|beverages|household|other"}}
- {"type": "create_note", "data": {"content": "note content"}}
- {"type": "create_event", "data": {"title": "event title", "date": "YYYY-MM-DD", "time": "HH:MM"}}
- {"type": "create_reminder", "data": {"title": "reminder text", "date": "YYYY-MM-DD", "time": "HH:MM"}}
- {"type": "send_message", "data": {"content": "message content"}}
- {"type": "find_member", "data": {"name": "person name"}}

IMPORTANT RULES:
1. Be conversational and natural - you're talking to a real person
2. Give complete, helpful answers - don't be too brief
3. For questions (who/what/why/how/tell me about), just answer - no action needed
4. For stories, actually tell the story - make it engaging
5. Only include "action" when the user wants to DO something in the app
6. If someone asks "who is Nelson Mandela" - ANSWER the question, don't try to navigate anywhere
7. Keep responses under 100 words for speaking, but be complete
8. Be warm, friendly, and helpful - like a good friend

EXAMPLES:
User: "Who is Nelson Mandela?"
{"response": "Nelson Mandela was a South African anti-apartheid revolutionary who served as President of South Africa from 1994 to 1999. He spent 27 years in prison for his activism and became a global symbol of peace and reconciliation. He won the Nobel Peace Prize in 1993.", "action": null}

User: "Add milk to my shopping list"
{"response": "Done! I've added milk to your shopping list.", "action": {"type": "add_shopping", "data": {"item": "milk", "category": "dairy"}}}

User: "Tell me a bedtime story"
{"response": "Once upon a time, in a cozy little forest, there lived a curious little fox named Felix. Every night, Felix would look up at the stars and wonder what adventures awaited beyond the trees. One magical evening, a friendly owl named Oliver flew down and said, 'Come Felix, let me show you the wonders of the night!' Together they soared through the moonlit sky, discovering that the greatest adventures are the ones we share with friends. And Felix learned that home is wherever love is. The end. Sweet dreams!", "action": null}

User: "Open messages"
{"response": "Opening your messages!", "action": {"type": "navigate", "data": {"to": "messages"}}}
PROMPT;

// Build messages array with conversation history
$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];

// Add conversation history (last 10 messages for good context)
if (!empty($conversation)) {
    foreach (array_slice($conversation, -10) as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
    }
}

// Add current message
$messages[] = ['role' => 'user', 'content' => $transcript];

// API request - use GPT-4o for better responses, more tokens
$data = [
    'model' => 'gpt-4o',  // Better model for conversations
    'messages' => $messages,
    'temperature' => 0.7,  // More creative/natural responses
    'max_tokens' => 500    // Enough for full stories and answers
];

// Make API call
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle errors
if ($curlError) {
    error_log('Voice chat curl error: ' . $curlError);
    echo json_encode([
        'success' => false,
        'response' => 'I\'m having trouble connecting. Please try again.',
        'action' => null
    ]);
    exit;
}

if ($httpCode !== 200) {
    error_log('Voice chat HTTP error: ' . $httpCode . ' - ' . $response);
    echo json_encode([
        'success' => false,
        'response' => 'Something went wrong. Please try again.',
        'action' => null
    ]);
    exit;
}

// Parse response
$apiResponse = json_decode($response, true);

if (!isset($apiResponse['choices'][0]['message']['content'])) {
    echo json_encode([
        'success' => false,
        'response' => 'I didn\'t understand that. Could you try again?',
        'action' => null
    ]);
    exit;
}

$content = trim($apiResponse['choices'][0]['message']['content']);

// Clean up potential markdown formatting
$content = preg_replace('/^```json\s*/', '', $content);
$content = preg_replace('/^```\s*/', '', $content);
$content = preg_replace('/\s*```$/', '', $content);
$content = trim($content);

// Parse JSON response
$result = json_decode($content, true);

if (!$result || !isset($result['response'])) {
    // If JSON parsing failed, try to extract response
    if (preg_match('/\{[^{}]*"response"\s*:\s*"([^"]+)"[^{}]*\}/s', $content, $matches)) {
        $result = [
            'response' => $matches[1],
            'action' => null
        ];
    } else {
        // Last resort - use the raw content as response
        $result = [
            'response' => $content,
            'action' => null
        ];
    }
}

// Process date in action if present
if (isset($result['action']['data']['date'])) {
    $result['action']['data']['date'] = processDateString($result['action']['data']['date']);
}

// Log for analytics
if ($userId) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'voice_command_log'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $db->prepare("
                INSERT INTO voice_command_log
                (user_id, transcript, intent, slots, response_text, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $transcript,
                $result['action']['type'] ?? 'conversation',
                json_encode($result['action']['data'] ?? []),
                $result['response'] ?? ''
            ]);
        }
    } catch (Exception $e) {
        error_log('Voice log error: ' . $e->getMessage());
    }
}

// Return result
echo json_encode([
    'success' => true,
    'response' => $result['response'],
    'action' => $result['action'] ?? null
]);

/**
 * Process date strings into YYYY-MM-DD format
 */
function processDateString($dateStr) {
    if (!$dateStr) return date('Y-m-d');

    $dateStr = strtolower(trim($dateStr));
    $today = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));

    if ($dateStr === 'today') {
        return $today->format('Y-m-d');
    }

    if ($dateStr === 'tomorrow') {
        $today->modify('+1 day');
        return $today->format('Y-m-d');
    }

    $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    if (strpos($dateStr, 'next ') === 0) {
        $dayName = str_replace('next ', '', $dateStr);
        if (in_array($dayName, $days)) {
            $today->modify('next ' . $dayName);
            return $today->format('Y-m-d');
        }
    }

    if (in_array($dateStr, $days)) {
        $currentDayIndex = (int)$today->format('w');
        $targetDayIndex = array_search($dateStr, $days);
        $daysUntil = $targetDayIndex - $currentDayIndex;
        if ($daysUntil <= 0) $daysUntil += 7;
        $today->modify("+{$daysUntil} days");
        return $today->format('Y-m-d');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return $dateStr;
    }

    try {
        $parsed = new DateTime($dateStr, new DateTimeZone('Africa/Johannesburg'));
        return $parsed->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}
