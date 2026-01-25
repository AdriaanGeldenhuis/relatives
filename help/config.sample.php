<?php
/**
 * Help System Configuration
 * Copy this file to config.php and configure as needed
 */

return [
    // OpenAI API key from environment
    'openai_api_key' => getenv('OPENAI_API_KEY'),

    // Model to use
    'model' => 'gpt-4o-mini',

    // Response settings
    'max_tokens' => 500,
    'temperature' => 0.7,

    // Rate limiting (per user)
    'rate_limit_requests' => 20,      // Max requests
    'rate_limit_window' => 3600,      // Per hour (in seconds)

    // Instruction file path
    'instruction_file' => __DIR__ . '/instruction.txt',

    // Logging
    'log_conversations' => true,
    'log_file' => __DIR__ . '/logs/conversations.log'
];
