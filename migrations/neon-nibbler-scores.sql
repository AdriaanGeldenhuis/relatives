-- =============================================
-- NEON NIBBLER - Score Table Migration
-- Run once to create the neon_scores table
-- =============================================

CREATE TABLE IF NOT EXISTS neon_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    family_id INT NULL,
    score INT NOT NULL DEFAULT 0,
    level_reached INT NOT NULL DEFAULT 1,
    dots_collected INT NOT NULL DEFAULT 0,
    duration_ms INT NOT NULL DEFAULT 0,
    device_id VARCHAR(64) DEFAULT '',
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Performance indexes
    INDEX idx_neon_user_score (user_id, score),
    INDEX idx_neon_family_score (family_id, score),
    INDEX idx_neon_created (created_at),
    INDEX idx_neon_user_date (user_id, created_at),
    INDEX idx_neon_family_date (family_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anti-cheat flags table (optional, for future use)
CREATE TABLE IF NOT EXISTS neon_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    score_id INT NOT NULL,
    user_id INT NOT NULL,
    reason VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_neon_flags_user (user_id),
    CONSTRAINT fk_neon_flags_score FOREIGN KEY (score_id) REFERENCES neon_scores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
