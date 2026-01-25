<?php
/**
 * Holiday Traveling - AI Worker Helper
 * Handles communication with AI provider for travel plan generation
 * Full implementation in Phase 3
 */
declare(strict_types=1);

class HT_AI {
    private const MAX_REQUESTS_PER_HOUR = 20;
    private const CACHE_TTL_SECONDS = 3600; // 1 hour

    /**
     * System prompt for travel planning AI
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a practical travel planner. Produce strict JSON only. No markdown. No prose explanations.
Plans must be realistic with time buffers between activities.
Avoid overpacking days - quality over quantity.
Include local safety tips and logistics.
Provide 3 accommodation tiers (budget, comfort, treat).
If user input is missing details, make reasonable defaults and list your assumptions in safety_and_local_tips.
PROMPT;

    /**
     * Generate a travel plan from trip data
     * @param array $tripData Trip details
     * @param string|null $instruction Optional refinement instruction
     * @return array Generated plan JSON
     */
    public static function generatePlan(array $tripData, ?string $instruction = null): array {
        // Check rate limit
        if (!self::checkRateLimit()) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        // Build prompt
        $userPrompt = self::buildPrompt($tripData, $instruction);

        // Check cache
        $cacheKey = self::getCacheKey($userPrompt);
        $cached = self::getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Call AI provider
        $response = self::callAI($userPrompt);

        // Parse and validate response
        $plan = self::parseResponse($response);

        // Cache result
        self::saveToCache($cacheKey, $plan);

        // Increment rate limit counter
        self::incrementRateLimit();

        return $plan;
    }

    /**
     * Build the user prompt from trip data
     */
    private static function buildPrompt(array $tripData, ?string $instruction): string {
        $prompt = "Generate a travel plan for:\n";
        $prompt .= json_encode($tripData, JSON_PRETTY_PRINT);

        if ($instruction) {
            $prompt .= "\n\nAdditional instruction: " . $instruction;
        }

        $prompt .= "\n\nReturn ONLY valid JSON matching this structure:\n";
        $prompt .= self::getOutputSchema();

        return $prompt;
    }

    /**
     * Get the expected JSON output schema
     */
    public static function getOutputSchema(): string {
        return <<<'SCHEMA'
{
  "meta": {
    "destination": "",
    "dates": { "start": "YYYY-MM-DD", "end": "YYYY-MM-DD" },
    "travel_style": "",
    "pace": "relaxed|balanced|packed",
    "budget": { "min": 0, "comfort": 0, "max": 0, "currency": "ZAR" }
  },
  "stay_options": [
    { "tier": "budget|comfort|treat", "area": "", "type": "", "price_per_night": 0, "notes": "", "pros": [], "cons": [] }
  ],
  "itinerary": [
    { "day": 1, "date": "YYYY-MM-DD", "morning": [], "afternoon": [], "evening": [], "buffers": [], "backup_weather": [] }
  ],
  "food_plan": [
    { "day": 1, "breakfast": [], "lunch": [], "dinner": [] }
  ],
  "transport": {
    "recommended": "uber|car|mix",
    "notes": [],
    "estimated_costs": []
  },
  "safety_and_local_tips": [],
  "packing_list": { "essentials": [], "weather": [], "activities": [], "kids": [] },
  "budget_breakdown": {
    "stay_total": 0,
    "food_estimate": 0,
    "activities_estimate": 0,
    "transport_estimate": 0,
    "buffer_estimate": 0
  },
  "reality_check": {
    "packed_score": 0,
    "cost_score": 0,
    "travel_time_score": 0,
    "kid_friendly_score": 0,
    "notes": []
  }
}
SCHEMA;
    }

    /**
     * Call the AI provider (OpenAI compatible)
     * TODO: Full implementation in Phase 3
     */
    private static function callAI(string $userPrompt): string {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if (empty($apiKey)) {
            throw new Exception('AI API key not configured');
        }

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'response_format' => ['type' => 'json_object']
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("AI API error: HTTP {$httpCode} - {$response}");
            throw new Exception('AI service temporarily unavailable');
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Parse and validate AI response
     */
    private static function parseResponse(string $response): array {
        $plan = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid AI response format');
        }

        // Basic structure validation
        $required = ['meta', 'itinerary', 'stay_options'];
        foreach ($required as $key) {
            if (!isset($plan[$key])) {
                throw new Exception("AI response missing required section: {$key}");
            }
        }

        return $plan;
    }

    /**
     * Check if user is within rate limit
     */
    private static function checkRateLimit(): bool {
        $userId = HT_Auth::userId();
        if (!$userId) return false;

        $record = HT_DB::fetchOne(
            "SELECT requests_count, window_start FROM ht_ai_rate_limits WHERE user_id = ?",
            [$userId]
        );

        if (!$record) {
            return true; // No record = no requests yet
        }

        $windowStart = strtotime($record['window_start']);
        $now = time();

        // Reset window if more than 1 hour has passed
        if ($now - $windowStart > 3600) {
            return true;
        }

        return $record['requests_count'] < self::MAX_REQUESTS_PER_HOUR;
    }

    /**
     * Increment rate limit counter
     */
    private static function incrementRateLimit(): void {
        $userId = HT_Auth::userId();
        if (!$userId) return;

        $record = HT_DB::fetchOne(
            "SELECT id, window_start FROM ht_ai_rate_limits WHERE user_id = ?",
            [$userId]
        );

        $now = date('Y-m-d H:i:s');

        if (!$record) {
            HT_DB::insert('ht_ai_rate_limits', [
                'user_id' => $userId,
                'requests_count' => 1,
                'window_start' => $now
            ]);
        } else {
            $windowStart = strtotime($record['window_start']);
            if (time() - $windowStart > 3600) {
                // Reset window
                HT_DB::update('ht_ai_rate_limits',
                    ['requests_count' => 1, 'window_start' => $now],
                    'user_id = ?', [$userId]
                );
            } else {
                // Increment
                HT_DB::execute(
                    "UPDATE ht_ai_rate_limits SET requests_count = requests_count + 1 WHERE user_id = ?",
                    [$userId]
                );
            }
        }
    }

    /**
     * Get cache key for prompt
     */
    private static function getCacheKey(string $prompt): string {
        return hash('sha256', $prompt);
    }

    /**
     * Get cached AI response
     */
    private static function getFromCache(string $key): ?array {
        $cached = HT_DB::fetchOne(
            "SELECT response_json FROM ht_ai_cache WHERE prompt_hash = ? AND expires_at > NOW()",
            [$key]
        );

        if ($cached) {
            return json_decode($cached['response_json'], true);
        }

        return null;
    }

    /**
     * Save AI response to cache
     */
    private static function saveToCache(string $key, array $response): void {
        $expiresAt = date('Y-m-d H:i:s', time() + self::CACHE_TTL_SECONDS);

        // Upsert
        HT_DB::execute(
            "INSERT INTO ht_ai_cache (prompt_hash, response_json, model, expires_at)
             VALUES (?, ?, 'gpt-4o-mini', ?)
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), expires_at = VALUES(expires_at)",
            [$key, json_encode($response), $expiresAt]
        );
    }

    /**
     * Get remaining AI requests for current user
     */
    public static function getRemainingRequests(): int {
        $userId = HT_Auth::userId();
        if (!$userId) return 0;

        $record = HT_DB::fetchOne(
            "SELECT requests_count, window_start FROM ht_ai_rate_limits WHERE user_id = ?",
            [$userId]
        );

        if (!$record) {
            return self::MAX_REQUESTS_PER_HOUR;
        }

        $windowStart = strtotime($record['window_start']);
        if (time() - $windowStart > 3600) {
            return self::MAX_REQUESTS_PER_HOUR;
        }

        return max(0, self::MAX_REQUESTS_PER_HOUR - $record['requests_count']);
    }
}
