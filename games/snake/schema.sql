-- Snake Classic Game - Database Schema
-- MySQL/MariaDB compatible
-- All timestamps stored in UTC

-- Main scores table
CREATE TABLE IF NOT EXISTS snake_scores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- User and family references
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NULL,

    -- Score data
    score INT UNSIGNED NOT NULL DEFAULT 0,
    mode VARCHAR(20) NOT NULL DEFAULT 'classic',

    -- Run timing (for anti-cheat)
    run_started_at DATETIME NOT NULL,
    run_ended_at DATETIME NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,

    -- Device tracking
    device_id VARCHAR(64) NOT NULL,

    -- Weekly seed (for fairness tracking)
    seed VARCHAR(16) NULL,

    -- Anti-cheat flags
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    flag_reason VARCHAR(255) NULL,

    -- Metadata
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Indexes for efficient queries
    INDEX idx_user_id (user_id),
    INDEX idx_family_id (family_id),
    INDEX idx_created_at (created_at),
    INDEX idx_family_created (family_id, created_at),
    INDEX idx_score (score DESC),
    INDEX idx_flagged (flagged),

    -- Composite indexes for leaderboard queries
    INDEX idx_family_date_score (family_id, created_at, score DESC),
    INDEX idx_global_date_score (created_at, score DESC),
    INDEX idx_user_date_score (user_id, created_at, score DESC)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Optional: Separate table for detailed anti-cheat flags
-- Use this if you want to track multiple flags per score
CREATE TABLE IF NOT EXISTS snake_score_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    score_id BIGINT UNSIGNED NOT NULL,
    flag_type VARCHAR(50) NOT NULL,
    flag_details TEXT NULL,
    severity ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_score_id (score_id),
    INDEX idx_flag_type (flag_type),

    FOREIGN KEY (score_id)
        REFERENCES snake_scores(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Example query: Get today's global top 10
-- SELECT user_id, MAX(score) as best
-- FROM snake_scores
-- WHERE flagged = 0
--   AND created_at >= CURDATE()
--   AND created_at < CURDATE() + INTERVAL 1 DAY
-- GROUP BY user_id
-- ORDER BY best DESC
-- LIMIT 10;

-- Example query: Get this week's family top 10
-- SELECT user_id, MAX(score) as best
-- FROM snake_scores
-- WHERE family_id = ?
--   AND flagged = 0
--   AND created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
--   AND created_at < DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) + INTERVAL 7 DAY
-- GROUP BY user_id
-- ORDER BY best DESC
-- LIMIT 10;
