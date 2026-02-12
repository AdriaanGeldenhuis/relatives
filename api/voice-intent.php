<?php
/**
 * ============================================
 * SUZI VOICE INTENT PROCESSOR v6.0
 * Complete AI-powered voice command system
 * ============================================
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/bootstrap.php';

// Get input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['transcript']) || empty(trim($input['transcript']))) {
    echo json_encode([
        'intent' => 'error',
        'slots' => [],
        'response_text' => 'I didn\'t hear anything. Could you try again?'
    ]);
    exit;
}

$transcript = trim($input['transcript']);
$currentPage = $input['page'] ?? '/';
$conversation = $input['conversation'] ?? [];

// Get user context
session_name('RELATIVES_SESSION');
session_start();
$userId = $_SESSION['user_id'] ?? null;
$userName = '';
$familyId = null;

if ($userId) {
    try {
        $stmt = $db->prepare("SELECT full_name, family_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            $userName = $user['full_name'];
            $familyId = $user['family_id'];
        }
    } catch (Exception $e) {
        error_log('Voice user context error: ' . $e->getMessage());
    }
}

// Build user context string
$userContext = $userName ? "The user's name is {$userName}." : '';

// Get current date/time context
$now = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
$dateContext = "Current date: " . $now->format('l, F j, Y') . ". Current time: " . $now->format('g:i A') . ".";

// OpenAI API Configuration
$apiKey = getenv('OPENAI_API_KEY');
$apiUrl = 'https://api.openai.com/v1/chat/completions';

if (!$apiKey) {
    echo json_encode([
        'intent' => 'error',
        'slots' => [],
        'response_text' => 'Voice assistant is not configured. Please contact support.'
    ]);
    exit;
}

// Optimized system prompt - smaller for faster API response
$systemPrompt = <<<PROMPT
You are Suzi, a helpful voice assistant for the Relatives family app. $userContext $dateContext

Respond with ONLY valid JSON (no markdown):
{"intent": "intent_name", "slots": {...}, "response_text": "Brief friendly response"}

INTENTS:
- navigate: slots: {destination: home|shopping|notes|calendar|schedule|weather|messages|tracking|notifications}
- add_shopping_item: slots: {item, quantity?, category: dairy|meat|produce|bakery|pantry|frozen|snacks|beverages|household|other}
- view_shopping, clear_bought
- create_note: slots: {title?, content}
- search_notes: slots: {query}
- create_event: slots: {title, date, time?}
- show_calendar: slots: {date?}
- create_schedule: slots: {title, date, time?, type: study|work|todo}
- show_schedule: slots: {date?}
- get_weather_today, get_weather_tomorrow, get_weather_week
- send_message: slots: {content}
- read_messages
- show_location
- find_member: slots: {member_name}
- check_notifications, mark_all_read
- smalltalk (greetings, jokes, questions, help)

RULES:
- Dates: today/tomorrow/monday/next friday → keep as-is or YYYY-MM-DD
- Times: 3pm → 15:00
- Categories: milk→dairy, bread→bakery, chicken→meat
- Keep response_text SHORT (1 sentence max)
- Return ONLY JSON, no explanation
PROMPT;

// Build messages array
$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];

// Add conversation history (last 4 exchanges)
if (!empty($conversation)) {
    foreach (array_slice($conversation, -4) as $msg) {
        $messages[] = $msg;
    }
}

// Add current command
$messages[] = ['role' => 'user', 'content' => $transcript];

// API request data - optimized for speed
$data = [
    'model' => 'gpt-4o-mini',
    'messages' => $messages,
    'temperature' => 0.5, // Lower for more predictable responses
    'max_tokens' => 200   // Reduced from 500 - we need short responses
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
    CURLOPT_TIMEOUT => 8,        // Reduced from 15
    CURLOPT_CONNECTTIMEOUT => 3  // Fast connection timeout
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle errors
if ($curlError) {
    error_log('Voice API curl error: ' . $curlError);
    echo json_encode([
        'intent' => 'error',
        'slots' => [],
        'response_text' => 'I\'m having trouble connecting. Please try again.'
    ]);
    exit;
}

if ($httpCode !== 200) {
    error_log('Voice API HTTP error: ' . $httpCode . ' - ' . $response);
    echo json_encode([
        'intent' => 'error',
        'slots' => [],
        'response_text' => 'Something went wrong. Could you try that again?'
    ]);
    exit;
}

// Parse response
$apiResponse = json_decode($response, true);

if (!isset($apiResponse['choices'][0]['message']['content'])) {
    echo json_encode([
        'intent' => 'smalltalk',
        'slots' => [],
        'response_text' => 'I\'m not sure I understood that. Could you rephrase?'
    ]);
    exit;
}

$intentJson = trim($apiResponse['choices'][0]['message']['content']);

// Clean up potential markdown formatting
$intentJson = preg_replace('/^```json\s*/', '', $intentJson);
$intentJson = preg_replace('/^```\s*/', '', $intentJson);
$intentJson = preg_replace('/\s*```$/', '', $intentJson);
$intentJson = trim($intentJson);

// Parse JSON
$result = json_decode($intentJson, true);

if (!$result || !isset($result['intent'])) {
    // Try to extract JSON from response if wrapped in text
    if (preg_match('/\{[^{}]*"intent"[^{}]*\}/s', $intentJson, $matches)) {
        $result = json_decode($matches[0], true);
    }
    
    if (!$result || !isset($result['intent'])) {
        echo json_encode([
            'intent' => 'smalltalk',
            'slots' => [],
            'response_text' => 'I didn\'t quite catch that. What would you like me to do?'
        ]);
        exit;
    }
}

// Ensure slots exist
if (!isset($result['slots'])) {
    $result['slots'] = [];
}

// Process date slots
if (isset($result['slots']['date'])) {
    $result['slots']['date'] = processDateString($result['slots']['date']);
}

// Log voice command for analytics
if ($userId) {
    try {
        // Check if table exists first
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
                $result['intent'],
                json_encode($result['slots']),
                $result['response_text'] ?? ''
            ]);
        }
    } catch (Exception $e) {
        // Non-critical error, just log it
        error_log('Voice log error: ' . $e->getMessage());
    }
}

// Return result
echo json_encode($result);

/**
 * Process date strings into YYYY-MM-DD format
 */
function processDateString($dateStr) {
    if (!$dateStr) return date('Y-m-d');
    
    $dateStr = strtolower(trim($dateStr));
    $today = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
    
    // Handle common words
    if ($dateStr === 'today') {
        return $today->format('Y-m-d');
    }
    
    if ($dateStr === 'tomorrow') {
        $today->modify('+1 day');
        return $today->format('Y-m-d');
    }
    
    // Handle day names
    $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    
    // Check for "next [day]"
    if (strpos($dateStr, 'next ') === 0) {
        $dayName = str_replace('next ', '', $dateStr);
        if (in_array($dayName, $days)) {
            $today->modify('next ' . $dayName);
            return $today->format('Y-m-d');
        }
    }
    
    // Check for day name only
    if (in_array($dateStr, $days)) {
        $currentDayIndex = (int)$today->format('w');
        $targetDayIndex = array_search($dateStr, $days);
        
        $daysUntil = $targetDayIndex - $currentDayIndex;
        if ($daysUntil <= 0) $daysUntil += 7;
        
        $today->modify("+{$daysUntil} days");
        return $today->format('Y-m-d');
    }
    
    // If already in YYYY-MM-DD format
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return $dateStr;
    }
    
    // Try to parse other formats
    try {
        $parsed = new DateTime($dateStr, new DateTimeZone('Africa/Johannesburg'));
        return $parsed->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}
