/**
 * 30 Seconds Party - Word Matcher Module
 * Handles forbidden word detection and answer matching
 */

(function() {
    'use strict';

    const MATCHER_CONFIG = {
        MIN_TOKEN_LENGTH_DEFAULT: 4,
        MIN_TOKEN_LENGTH_STRICT: 2,
        NUMBER_WORDS: {
            'one': 1, 'two': 2, 'three': 3, 'four': 4, 'five': 5,
            'first': 1, 'second': 2, 'third': 3, 'fourth': 4, 'fifth': 5,
            '1': 1, '2': 2, '3': 3, '4': 4, '5': 5
        },
        // Common words to ignore in matching
        STOP_WORDS: new Set([
            'a', 'an', 'the', 'is', 'it', 'to', 'of', 'in', 'on', 'at', 'by',
            'for', 'and', 'or', 'but', 'not', 'be', 'are', 'was', 'were',
            'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'could', 'should', 'may', 'might', 'must',
            'this', 'that', 'these', 'those', 'with', 'from', 'as', 'so'
        ])
    };

    window.WordMatcher = {
        // Configuration
        strictMode: false,

        /**
         * Initialize the matcher
         */
        init: function(options = {}) {
            this.strictMode = options.strictMode || false;
        },

        /**
         * Set strict mode
         */
        setStrictMode: function(enabled) {
            this.strictMode = enabled;
        },

        /**
         * Normalize text for comparison
         */
        normalize: function(text) {
            return text
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '') // Remove diacritics
                .replace(/[^a-z0-9\s]/g, ' ')    // Remove punctuation
                .replace(/\s+/g, ' ')             // Normalize whitespace
                .trim();
        },

        /**
         * Tokenize text into words
         */
        tokenize: function(text) {
            return this.normalize(text).split(' ').filter(t => t.length > 0);
        },

        /**
         * Get forbidden tokens from an answer
         */
        getForbiddenTokens: function(answer) {
            const normalized = this.normalize(answer);
            const tokens = this.tokenize(answer);
            const minLength = this.strictMode ?
                MATCHER_CONFIG.MIN_TOKEN_LENGTH_STRICT :
                MATCHER_CONFIG.MIN_TOKEN_LENGTH_DEFAULT;

            const forbidden = new Set();

            // Add the full answer (normalized)
            forbidden.add(normalized);

            // Add all tokens that meet the length requirement
            tokens.forEach(token => {
                if (token.length >= minLength && !MATCHER_CONFIG.STOP_WORDS.has(token)) {
                    forbidden.add(token);
                }
            });

            // Add compound tokens (e.g., "ice cream" -> "icecream")
            if (tokens.length > 1) {
                forbidden.add(tokens.join(''));
            }

            return Array.from(forbidden);
        },

        /**
         * Check if transcript contains any forbidden words
         * Returns array of matched forbidden words
         */
        checkForbiddenWords: function(transcript, forbiddenTokens) {
            const normalizedTranscript = this.normalize(transcript);
            const matches = [];

            forbiddenTokens.forEach(forbidden => {
                // Check for word boundary matches
                const pattern = new RegExp(`\\b${this.escapeRegex(forbidden)}\\b`, 'i');
                if (pattern.test(normalizedTranscript)) {
                    matches.push(forbidden);
                }

                // Also check for no-space compound (e.g., "icecream")
                if (normalizedTranscript.replace(/\s/g, '').includes(forbidden.replace(/\s/g, ''))) {
                    if (!matches.includes(forbidden)) {
                        matches.push(forbidden);
                    }
                }
            });

            return matches;
        },

        /**
         * Check if transcript contains the correct answer
         * Used for experimental guesser mic mode
         */
        checkAnswer: function(transcript, answer) {
            const normalizedTranscript = this.normalize(transcript);
            const normalizedAnswer = this.normalize(answer);

            // Check for exact match
            if (normalizedTranscript.includes(normalizedAnswer)) {
                return { matched: true, confidence: 1.0 };
            }

            // Check for all significant tokens
            const answerTokens = this.tokenize(answer);
            const significantTokens = answerTokens.filter(
                t => t.length >= 3 && !MATCHER_CONFIG.STOP_WORDS.has(t)
            );

            if (significantTokens.length === 0) {
                // All tokens are stop words, use the full answer
                return {
                    matched: normalizedTranscript.includes(normalizedAnswer),
                    confidence: normalizedTranscript.includes(normalizedAnswer) ? 1.0 : 0
                };
            }

            const matchedTokens = significantTokens.filter(token =>
                new RegExp(`\\b${this.escapeRegex(token)}\\b`).test(normalizedTranscript)
            );

            const confidence = matchedTokens.length / significantTokens.length;

            return {
                matched: confidence >= 0.8, // 80% of significant tokens
                confidence: confidence,
                matchedTokens: matchedTokens,
                totalTokens: significantTokens
            };
        },

        /**
         * Detect number commands in transcript
         * Returns detected number (1-5) or null
         */
        detectNumber: function(transcript) {
            const normalized = this.normalize(transcript);
            const tokens = normalized.split(' ');

            // Look for "number X" pattern first
            for (let i = 0; i < tokens.length - 1; i++) {
                if (tokens[i] === 'number') {
                    const nextToken = tokens[i + 1];
                    if (MATCHER_CONFIG.NUMBER_WORDS[nextToken]) {
                        return MATCHER_CONFIG.NUMBER_WORDS[nextToken];
                    }
                }
            }

            // Look for standalone number words at the end of transcript
            // This helps detect when someone says "...number two"
            const lastThreeTokens = tokens.slice(-3);
            for (const token of lastThreeTokens) {
                if (MATCHER_CONFIG.NUMBER_WORDS[token]) {
                    return MATCHER_CONFIG.NUMBER_WORDS[token];
                }
            }

            return null;
        },

        /**
         * Get display string for forbidden words
         */
        getForbiddenDisplay: function(answer) {
            const tokens = this.getForbiddenTokens(answer);
            // Show a subset for display (to not clutter UI)
            const displayTokens = tokens.slice(0, 3);
            if (tokens.length > 3) {
                return displayTokens.join(', ') + '...';
            }
            return displayTokens.join(', ');
        },

        /**
         * Escape special regex characters
         */
        escapeRegex: function(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        /**
         * Calculate Levenshtein distance for fuzzy matching
         */
        levenshteinDistance: function(str1, str2) {
            const m = str1.length;
            const n = str2.length;
            const dp = Array(m + 1).fill(null).map(() => Array(n + 1).fill(0));

            for (let i = 0; i <= m; i++) dp[i][0] = i;
            for (let j = 0; j <= n; j++) dp[0][j] = j;

            for (let i = 1; i <= m; i++) {
                for (let j = 1; j <= n; j++) {
                    if (str1[i - 1] === str2[j - 1]) {
                        dp[i][j] = dp[i - 1][j - 1];
                    } else {
                        dp[i][j] = 1 + Math.min(
                            dp[i - 1][j],     // deletion
                            dp[i][j - 1],     // insertion
                            dp[i - 1][j - 1]  // substitution
                        );
                    }
                }
            }

            return dp[m][n];
        },

        /**
         * Check for fuzzy match (for speech recognition errors)
         */
        fuzzyMatch: function(word, target, threshold = 0.8) {
            const distance = this.levenshteinDistance(
                this.normalize(word),
                this.normalize(target)
            );
            const maxLen = Math.max(word.length, target.length);
            const similarity = 1 - (distance / maxLen);
            return similarity >= threshold;
        },

        /**
         * Check transcript against all items for current card
         * Returns object with strike and potential correct matches
         */
        analyzeTranscript: function(transcript, items, focusedIndex) {
            const result = {
                strikes: [],       // Items where forbidden word was detected
                correctMatches: [] // Items that might have been guessed (experimental)
            };

            items.forEach((item, index) => {
                if (item.status !== 'normal') return;

                // Check for forbidden words (only for focused item in standard mode)
                if (index === focusedIndex) {
                    const forbidden = this.getForbiddenTokens(item.text);
                    const matches = this.checkForbiddenWords(transcript, forbidden);
                    if (matches.length > 0) {
                        result.strikes.push({
                            index: index,
                            matchedWords: matches
                        });
                    }
                }

                // Check for correct answer (experimental guesser mode)
                const answerCheck = this.checkAnswer(transcript, item.text);
                if (answerCheck.matched) {
                    result.correctMatches.push({
                        index: index,
                        confidence: answerCheck.confidence
                    });
                }
            });

            return result;
        }
    };
})();
