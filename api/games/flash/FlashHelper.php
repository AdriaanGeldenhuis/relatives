<?php
declare(strict_types=1);

/**
 * ============================================
 * FLASH CHALLENGE - Helper Functions
 * Core utilities for the Flash Challenge game
 * ============================================
 */

class FlashHelper
{
    private PDO $db;
    private string $timezone = 'Africa/Johannesburg';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get today's date in the configured timezone
     */
    public function getTodayDate(): string
    {
        $tz = new DateTimeZone($this->timezone);
        $today = new DateTime('now', $tz);
        return $today->format('Y-m-d');
    }

    /**
     * Check if a user has already attempted today's challenge
     */
    public function hasAttemptedToday(int $userId): bool
    {
        $today = $this->getTodayDate();
        $stmt = $this->db->prepare("
            SELECT 1 FROM flash_attempts
            WHERE user_id = ? AND challenge_date = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $today]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Get user's existing attempt for today (if any)
     */
    public function getUserAttempt(int $userId, string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM flash_attempts
            WHERE user_id = ? AND challenge_date = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get today's challenge (without exposing valid_answers)
     */
    public function getDailyChallenge(string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                challenge_date,
                question,
                answer_type,
                difficulty,
                category,
                format_hint
            FROM flash_daily_challenges
            WHERE challenge_date = ?
            LIMIT 1
        ");
        $stmt->execute([$date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get full challenge data including answers (for validation)
     */
    public function getFullChallenge(string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM flash_daily_challenges
            WHERE challenge_date = ?
            LIMIT 1
        ");
        $stmt->execute([$date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $result['valid_answers'] = json_decode($result['valid_answers'], true) ?? [];
            $result['partial_rules'] = json_decode($result['partial_rules'], true) ?? [];
        }

        return $result ?: null;
    }

    /**
     * Normalize answer text for comparison
     */
    public function normalizeAnswer(string $answer): string
    {
        // Trim whitespace
        $normalized = trim($answer);
        // Convert to lowercase
        $normalized = mb_strtolower($normalized, 'UTF-8');
        // Remove extra spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        // Remove punctuation (but keep spaces and alphanumerics)
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        // Trim again
        $normalized = trim($normalized);

        return $normalized;
    }

    /**
     * Validate answer against valid answers
     * Returns: ['verdict' => string, 'confidence' => int, 'reason' => string, 'method' => string]
     */
    public function validateAnswer(string $userAnswer, array $validAnswers, array $partialRules): array
    {
        $normalizedUser = $this->normalizeAnswer($userAnswer);

        if (empty($normalizedUser)) {
            return [
                'verdict' => 'incorrect',
                'confidence' => 100,
                'reason' => 'Empty answer provided',
                'method' => 'exact',
                'normalized_answer' => ''
            ];
        }

        // 1. Exact match
        foreach ($validAnswers as $valid) {
            $normalizedValid = $this->normalizeAnswer($valid);
            if ($normalizedUser === $normalizedValid) {
                return [
                    'verdict' => 'correct',
                    'confidence' => 100,
                    'reason' => 'Exact match',
                    'method' => 'exact',
                    'normalized_answer' => $normalizedUser
                ];
            }
        }

        // 2. Fuzzy matching
        $minSimilarity = $partialRules['min_similarity'] ?? 0.85;
        $allowSynonyms = $partialRules['allow_synonyms'] ?? true;
        $allowPlural = $partialRules['allow_plural'] ?? true;

        $bestSimilarity = 0;
        $bestMatch = '';

        foreach ($validAnswers as $valid) {
            $normalizedValid = $this->normalizeAnswer($valid);

            // Levenshtein-based similarity
            $maxLen = max(strlen($normalizedUser), strlen($normalizedValid));
            if ($maxLen > 0) {
                $levenshtein = levenshtein($normalizedUser, $normalizedValid);
                $similarity = 1 - ($levenshtein / $maxLen);

                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $valid;
                }
            }

            // Check plural forms
            if ($allowPlural) {
                // Simple plural check (add/remove 's')
                $userWithS = $normalizedUser . 's';
                $userWithoutS = rtrim($normalizedUser, 's');

                if ($userWithS === $normalizedValid || $userWithoutS === $normalizedValid) {
                    return [
                        'verdict' => 'correct',
                        'confidence' => 95,
                        'reason' => 'Plural form accepted',
                        'method' => 'fuzzy',
                        'normalized_answer' => $normalizedUser
                    ];
                }
            }
        }

        // Determine verdict based on similarity
        if ($bestSimilarity >= $minSimilarity) {
            return [
                'verdict' => 'correct',
                'confidence' => (int) round($bestSimilarity * 100),
                'reason' => 'Close match to: ' . $bestMatch,
                'method' => 'fuzzy',
                'normalized_answer' => $normalizedUser
            ];
        } elseif ($bestSimilarity >= 0.6) {
            return [
                'verdict' => 'partial',
                'confidence' => (int) round($bestSimilarity * 100),
                'reason' => 'Partial match, expected something like: ' . $bestMatch,
                'method' => 'fuzzy',
                'normalized_answer' => $normalizedUser
            ];
        }

        // 3. Check if AI validation should be triggered
        if ($bestSimilarity >= 0.4) {
            // Borderline case - in production, call AI here
            $aiResult = $this->aiValidateAnswer($userAnswer, $validAnswers);
            if ($aiResult !== null) {
                return $aiResult;
            }
        }

        return [
            'verdict' => 'incorrect',
            'confidence' => 100 - (int) round($bestSimilarity * 100),
            'reason' => 'Answer does not match expected answers',
            'method' => 'fuzzy',
            'normalized_answer' => $normalizedUser
        ];
    }

    /**
     * AI-based answer validation (stub - implement with actual AI API)
     */
    private function aiValidateAnswer(string $userAnswer, array $validAnswers): ?array
    {
        // Check if AI is configured
        $aiKey = $_ENV['OPENAI_API_KEY'] ?? $_ENV['AI_API_KEY'] ?? null;

        if (!$aiKey) {
            // No AI configured, return null to use fuzzy result
            return null;
        }

        // AI Validation Prompt
        $prompt = <<<PROMPT
You are validating an answer for a trivia game.
User's answer: "{$userAnswer}"
Valid answers: [" . implode('", "', $validAnswers) . "]

Determine if the user's answer is correct, partially correct, or incorrect.
Consider synonyms, alternate phrasings, and common abbreviations.

Return ONLY a JSON object (no markdown):
{
  "verdict": "correct|partial|incorrect",
  "confidence": 0-100,
  "normalized_answer": "cleaned version of user answer",
  "reason": "brief explanation"
}
PROMPT;

        try {
            // Make AI API call (example with OpenAI-compatible API)
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $_ENV['AI_API_URL'] ?? 'https://api.openai.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $aiKey
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $_ENV['AI_MODEL'] ?? 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a precise trivia answer validator. Return only valid JSON.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 150
                ])
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $content = $data['choices'][0]['message']['content'] ?? '';
                $result = json_decode($content, true);

                if ($result && isset($result['verdict'])) {
                    $result['method'] = 'ai';
                    return $result;
                }
            }
        } catch (Exception $e) {
            error_log('AI validation error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Calculate score based on verdict and timing
     */
    public function calculateScore(string $verdict, int $answeredInMs): array
    {
        $baseScore = 0;
        $speedBonus = 0;
        $totalTime = 30000; // 30 seconds in ms

        switch ($verdict) {
            case 'correct':
                $baseScore = 100;
                break;
            case 'partial':
                $baseScore = 60;
                break;
            case 'incorrect':
            default:
                $baseScore = 0;
                break;
        }

        // Speed bonus: up to 20 points based on remaining time
        if ($baseScore > 0 && $answeredInMs < $totalTime) {
            $remainingMs = $totalTime - $answeredInMs;
            $speedBonus = (int) floor(($remainingMs / $totalTime) * 20);
            $speedBonus = min($speedBonus, 20); // Cap at 20
        }

        $totalScore = $baseScore + $speedBonus;
        $totalScore = min($totalScore, 120); // Cap total at 120

        return [
            'base_score' => $baseScore,
            'speed_bonus' => $speedBonus,
            'score' => $totalScore
        ];
    }

    /**
     * Update user stats after an attempt
     */
    public function updateUserStats(int $userId, string $verdict, int $score, string $challengeDate): void
    {
        // Get current stats
        $stmt = $this->db->prepare("
            SELECT * FROM flash_user_stats WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $today = $this->getTodayDate();
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));

        if (!$stats) {
            // Create new stats record
            $streak = 1;
            $stmt = $this->db->prepare("
                INSERT INTO flash_user_stats (
                    user_id, user_streak, last_played_date,
                    personal_best_score, personal_best_date,
                    total_games, total_correct, total_partial, total_incorrect
                ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $streak,
                $challengeDate,
                $score,
                $challengeDate,
                $verdict === 'correct' ? 1 : 0,
                $verdict === 'partial' ? 1 : 0,
                $verdict === 'incorrect' ? 1 : 0
            ]);
        } else {
            // Update existing stats
            $lastPlayed = $stats['last_played_date'];
            $currentStreak = (int) $stats['user_streak'];
            $personalBest = (int) $stats['personal_best_score'];

            // Calculate new streak
            if ($lastPlayed === $yesterday) {
                $newStreak = $currentStreak + 1;
            } elseif ($lastPlayed === $today) {
                // Already played today, don't change streak
                $newStreak = $currentStreak;
            } else {
                // Streak broken
                $newStreak = 1;
            }

            // Check for new personal best
            $newBestScore = $personalBest;
            $newBestDate = $stats['personal_best_date'];
            if ($score > $personalBest) {
                $newBestScore = $score;
                $newBestDate = $challengeDate;
            }

            $stmt = $this->db->prepare("
                UPDATE flash_user_stats SET
                    user_streak = ?,
                    last_played_date = ?,
                    personal_best_score = ?,
                    personal_best_date = ?,
                    total_games = total_games + 1,
                    total_correct = total_correct + ?,
                    total_partial = total_partial + ?,
                    total_incorrect = total_incorrect + ?
                WHERE user_id = ?
            ");
            $stmt->execute([
                $newStreak,
                $challengeDate,
                $newBestScore,
                $newBestDate,
                $verdict === 'correct' ? 1 : 0,
                $verdict === 'partial' ? 1 : 0,
                $verdict === 'incorrect' ? 1 : 0,
                $userId
            ]);
        }
    }

    /**
     * Get user's current streak
     */
    public function getUserStreak(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT user_streak FROM flash_user_stats WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Get family participation percentage for today
     */
    public function getFamilyParticipation(int $familyId, string $date): array
    {
        // Count total family members
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM users WHERE family_id = ?
        ");
        $stmt->execute([$familyId]);
        $totalMembers = (int) $stmt->fetchColumn();

        // Count members who played today
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT user_id) FROM flash_attempts
            WHERE family_id = ? AND challenge_date = ?
        ");
        $stmt->execute([$familyId, $date]);
        $playedCount = (int) $stmt->fetchColumn();

        $percent = $totalMembers > 0 ? round(($playedCount / $totalMembers) * 100, 1) : 0;

        return [
            'total_members' => $totalMembers,
            'members_played' => $playedCount,
            'participation_percent' => $percent
        ];
    }

    /**
     * Get user's rank for a given date
     */
    public function getUserRanks(int $userId, int $familyId, string $date): array
    {
        // Solo rank (among all their attempts by score)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) + 1 as rank
            FROM flash_attempts a1
            WHERE a1.challenge_date = ?
            AND (a1.score > (SELECT score FROM flash_attempts WHERE user_id = ? AND challenge_date = ?)
                 OR (a1.score = (SELECT score FROM flash_attempts WHERE user_id = ? AND challenge_date = ?)
                     AND a1.answered_in_ms < (SELECT answered_in_ms FROM flash_attempts WHERE user_id = ? AND challenge_date = ?)))
        ");
        $stmt->execute([$date, $userId, $date, $userId, $date, $userId, $date]);
        $globalRank = (int) $stmt->fetchColumn();

        // Family rank
        $familyRank = 0;
        if ($familyId > 0) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) + 1 as rank
                FROM flash_attempts a1
                WHERE a1.challenge_date = ? AND a1.family_id = ?
                AND (a1.score > (SELECT score FROM flash_attempts WHERE user_id = ? AND challenge_date = ?)
                     OR (a1.score = (SELECT score FROM flash_attempts WHERE user_id = ? AND challenge_date = ?)
                         AND a1.answered_in_ms < (SELECT answered_in_ms FROM flash_attempts WHERE user_id = ? AND challenge_date = ?)))
            ");
            $stmt->execute([$date, $familyId, $userId, $date, $userId, $date, $userId, $date]);
            $familyRank = (int) $stmt->fetchColumn();
        }

        return [
            'global_today_rank' => $globalRank,
            'family_today_rank' => $familyRank
        ];
    }

    /**
     * Generate daily challenge using AI
     */
    public function generateDailyChallenge(string $date): ?array
    {
        $aiKey = $_ENV['OPENAI_API_KEY'] ?? $_ENV['AI_API_KEY'] ?? null;

        // AI Generation Prompt
        $prompt = <<<'PROMPT'
Generate a trivia question for a family-friendly 30-second challenge game.

Requirements:
- Family-safe (no adult content, violence, gore, politics)
- Answerable in 30 seconds
- Clear, unambiguous question
- 3-20 valid answers (include common variations, synonyms, spellings)

Pick a random category from: general, kids, family, geography, quick-math, riddles, science, history, movies, music, sports, food, animals, nature

Return ONLY a JSON object (no markdown):
{
  "question": "The trivia question",
  "category": "category_name",
  "difficulty": 1-5,
  "answer_type": "single_word|phrase|list|number",
  "valid_answers": ["answer1", "answer2", "Answer1"],
  "partial_rules": {"allow_synonyms": true, "allow_plural": true, "min_similarity": 0.85},
  "format_hint": "Brief hint about expected format"
}
PROMPT;

        if ($aiKey) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $_ENV['AI_API_URL'] ?? 'https://api.openai.com/v1/chat/completions',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $aiKey
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $_ENV['AI_MODEL'] ?? 'gpt-4o-mini',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a trivia question generator for a family game app. Return only valid JSON.'],
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'temperature' => 0.8,
                        'max_tokens' => 500
                    ])
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $response) {
                    $data = json_decode($response, true);
                    $content = $data['choices'][0]['message']['content'] ?? '';

                    // Clean markdown if present
                    $content = preg_replace('/```json\s*/', '', $content);
                    $content = preg_replace('/```\s*/', '', $content);

                    $challenge = json_decode(trim($content), true);

                    if ($challenge && isset($challenge['question'], $challenge['valid_answers'])) {
                        return $this->saveChallenge($date, $challenge);
                    }
                }
            } catch (Exception $e) {
                error_log('AI challenge generation error: ' . $e->getMessage());
            }
        }

        // Fallback: return a static challenge
        return $this->getFallbackChallenge($date);
    }

    /**
     * Save a generated challenge to the database
     */
    private function saveChallenge(string $date, array $challenge): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO flash_daily_challenges (
                challenge_date, question, answer_type, valid_answers,
                partial_rules, difficulty, category, format_hint
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                question = VALUES(question),
                answer_type = VALUES(answer_type),
                valid_answers = VALUES(valid_answers),
                partial_rules = VALUES(partial_rules),
                difficulty = VALUES(difficulty),
                category = VALUES(category),
                format_hint = VALUES(format_hint)
        ");

        $stmt->execute([
            $date,
            $challenge['question'],
            $challenge['answer_type'] ?? 'single_word',
            json_encode($challenge['valid_answers']),
            json_encode($challenge['partial_rules'] ?? []),
            $challenge['difficulty'] ?? 3,
            $challenge['category'] ?? 'general',
            $challenge['format_hint'] ?? null
        ]);

        return [
            'challenge_date' => $date,
            'question' => $challenge['question'],
            'answer_type' => $challenge['answer_type'] ?? 'single_word',
            'difficulty' => $challenge['difficulty'] ?? 3,
            'category' => $challenge['category'] ?? 'general',
            'format_hint' => $challenge['format_hint'] ?? null
        ];
    }

    /**
     * Get a fallback challenge when AI is unavailable
     */
    private function getFallbackChallenge(string $date): array
    {
        // Pool of fallback challenges
        $fallbacks = [
            [
                'question' => 'What is the largest planet in our solar system?',
                'category' => 'science',
                'difficulty' => 2,
                'answer_type' => 'single_word',
                'valid_answers' => ['Jupiter', 'jupiter'],
                'partial_rules' => ['allow_synonyms' => false, 'allow_plural' => false, 'min_similarity' => 0.85],
                'format_hint' => 'One word'
            ],
            [
                'question' => 'What color do you get when you mix red and blue?',
                'category' => 'kids',
                'difficulty' => 1,
                'answer_type' => 'single_word',
                'valid_answers' => ['Purple', 'purple', 'violet', 'Violet'],
                'partial_rules' => ['allow_synonyms' => true, 'allow_plural' => false, 'min_similarity' => 0.85],
                'format_hint' => 'A color'
            ],
            [
                'question' => 'How many continents are there on Earth?',
                'category' => 'geography',
                'difficulty' => 2,
                'answer_type' => 'number',
                'valid_answers' => ['7', 'seven', 'Seven'],
                'partial_rules' => ['allow_synonyms' => true, 'allow_plural' => false, 'min_similarity' => 0.9],
                'format_hint' => 'A number'
            ],
            [
                'question' => 'What is 15 + 27?',
                'category' => 'quick-math',
                'difficulty' => 2,
                'answer_type' => 'number',
                'valid_answers' => ['42', 'forty-two', 'forty two', 'Forty-two'],
                'partial_rules' => ['allow_synonyms' => true, 'allow_plural' => false, 'min_similarity' => 0.9],
                'format_hint' => 'A number'
            ],
            [
                'question' => 'What animal is known as the "King of the Jungle"?',
                'category' => 'animals',
                'difficulty' => 1,
                'answer_type' => 'single_word',
                'valid_answers' => ['Lion', 'lion', 'lions', 'Lions'],
                'partial_rules' => ['allow_synonyms' => false, 'allow_plural' => true, 'min_similarity' => 0.85],
                'format_hint' => 'An animal'
            ],
            [
                'question' => 'What is the capital city of France?',
                'category' => 'geography',
                'difficulty' => 1,
                'answer_type' => 'single_word',
                'valid_answers' => ['Paris', 'paris'],
                'partial_rules' => ['allow_synonyms' => false, 'allow_plural' => false, 'min_similarity' => 0.85],
                'format_hint' => 'A city name'
            ],
            [
                'question' => 'What fruit is traditionally used to make wine?',
                'category' => 'food',
                'difficulty' => 2,
                'answer_type' => 'single_word',
                'valid_answers' => ['Grapes', 'grapes', 'grape', 'Grape'],
                'partial_rules' => ['allow_synonyms' => false, 'allow_plural' => true, 'min_similarity' => 0.85],
                'format_hint' => 'A fruit'
            ]
        ];

        // Select based on date (deterministic but varied)
        $dayOfYear = (int) date('z', strtotime($date));
        $index = $dayOfYear % count($fallbacks);
        $challenge = $fallbacks[$index];

        return $this->saveChallenge($date, $challenge);
    }

    /**
     * Log validation for debugging
     */
    public function logValidation(int $attemptId, string $method, ?string $aiPrompt = null, ?string $aiResponse = null, ?int $processingTime = null): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO flash_validation_logs (attempt_id, method_used, ai_prompt, ai_response, processing_time_ms)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$attemptId, $method, $aiPrompt, $aiResponse, $processingTime]);
        } catch (Exception $e) {
            error_log('Validation log error: ' . $e->getMessage());
        }
    }
}
