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

// System prompt
$systemPrompt = <<<PROMPT
You are Suzi, a friendly and helpful AI voice assistant for the Relatives family app.
$userContext
$dateContext

You help families stay organized with shopping lists, notes, calendars, schedules, messages, weather, and location tracking.

**RESPONSE FORMAT:**
Always respond with ONLY a valid JSON object (no markdown, no explanation):
{
  "intent": "intent_name",
  "slots": { ... },
  "response_text": "Your friendly spoken response"
}

**AVAILABLE INTENTS:**

🏠 NAVIGATION
- navigate: Go to any app area
  slots: {"destination": "home|shopping|notes|calendar|schedule|weather|messages|tracking|notifications|help"}

🛒 SHOPPING
- add_shopping_item: Add item to shopping list
  slots: {"item": "item name", "quantity": "optional amount", "category": "dairy|meat|produce|bakery|pantry|frozen|snacks|beverages|household|other"}
- view_shopping: Open shopping list
- clear_bought: Clear purchased items

📝 NOTES
- create_note: Create a new note
  slots: {"title": "optional title", "content": "note content"}
- search_notes: Search notes
  slots: {"query": "search term"}

📅 CALENDAR
- create_event: Create calendar event
  slots: {"title": "event name", "date": "YYYY-MM-DD or today/tomorrow/day_name", "time": "HH:MM"}
- show_calendar: View calendar
  slots: {"date": "YYYY-MM-DD or today/tomorrow"}
- next_event: Show upcoming events

⏰ SCHEDULE
- create_schedule: Create a scheduled task/reminder
  slots: {"title": "task name", "date": "YYYY-MM-DD", "time": "HH:MM", "type": "study|work|todo"}
- show_schedule: View schedule
  slots: {"date": "today|tomorrow|YYYY-MM-DD"}

🌤️ WEATHER
- get_weather_today: Today's weather
- get_weather_tomorrow: Tomorrow's forecast
- get_weather_week: Week forecast

💬 MESSAGES
- send_message: Send family message
  slots: {"content": "message text"}
- read_messages: View messages

📍 TRACKING
- show_location: Show all family locations
- find_member: Find specific family member
  slots: {"member_name": "name"}

🔔 NOTIFICATIONS
- check_notifications: View notifications
- mark_all_read: Mark all as read

💬 CONVERSATION
- smalltalk: General chat, questions, jokes, facts
  (for greetings, jokes, general knowledge, app help, etc.)

**PARSING RULES:**
1. Dates: "today", "tomorrow", "monday", "next friday", "jan 15" → Convert to appropriate format
2. Times: "3pm", "15:00", "noon", "morning" → Convert to HH:MM (24h)
3. Categories: Infer from item (milk=dairy, bread=bakery, chicken=meat, apples=produce)
4. Be flexible with phrasing - understand natural language

**RESPONSE GUIDELINES:**
1. Be conversational, warm, and helpful
2. Use the user's name occasionally if known
3. Confirm actions clearly
4. Keep responses concise but friendly (1-2 sentences)
5. For smalltalk, be engaging and personable
6. If something is unclear, ask for clarification naturally

**EXAMPLES:**

User: "add eggs to shopping"
{
  "intent": "add_shopping_item",
  "slots": {"item": "eggs", "category": "dairy"},
  "response_text": "I've added eggs to your shopping list!"
}

User: "what's the weather like tomorrow"
{
  "intent": "get_weather_tomorrow",
  "slots": {},
  "response_text": "Let me check tomorrow's forecast for you."
}

User: "remind me to call mom tomorrow at 2pm"
{
  "intent": "create_schedule",
  "slots": {"title": "Call mom", "date": "tomorrow", "time": "14:00", "type": "todo"},
  "response_text": "I've set a reminder to call mom tomorrow at 2 PM."
}

User: "where's dad"
{
  "intent": "find_member",
  "slots": {"member_name": "dad"},
  "response_text": "Let me find dad's location for you."
}

User: "tell me a joke"
{
  "intent": "smalltalk",
  "slots": {},
  "response_text": "Why don't scientists trust atoms? Because they make up everything!"
}

User: "what can you do"
{
  "intent": "smalltalk",
  "slots": {},
  "response_text": "I can help you with shopping lists, notes, calendar events, reminders, weather, messages, and finding your family members. Just ask!"
}

User: "hi suzi"
{
  "intent": "smalltalk",
  "slots": {},
  "response_text": "Hey there! What can I help you with?"
}

User: "thanks"
{
  "intent": "smalltalk",
  "slots": {},
  "response_text": "You're welcome! Anything else I can help with?"
}

Return ONLY the JSON object. No markdown code blocks. No explanations.
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

// API request data
$data = [
    'model' => 'gpt-4o-mini',
    'messages' => $messages,
    'temperature' => 0.7,
    'max_tokens' => 500
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
    CURLOPT_TIMEOUT => 15
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
