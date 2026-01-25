-- ============================================
-- HOLIDAY TRAVELING MODULE - DATABASE SCHEMA
-- Version: 1.1.0
-- Fixed: Use BIGINT UNSIGNED to match users/families tables
-- ============================================

-- Trips table (main entity)
CREATE TABLE IF NOT EXISTS ht_trips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    origin VARCHAR(255) DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    travelers_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    travelers_json JSON DEFAULT NULL COMMENT 'Array of {name, age, notes}',
    budget_currency VARCHAR(10) NOT NULL DEFAULT 'ZAR',
    budget_min DECIMAL(12,2) DEFAULT NULL,
    budget_comfort DECIMAL(12,2) DEFAULT NULL,
    budget_max DECIMAL(12,2) DEFAULT NULL,
    preferences_json JSON DEFAULT NULL COMMENT '{style, food_prefs, mobility, pace, etc}',
    status ENUM('draft', 'planned', 'locked', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    current_plan_version INT UNSIGNED DEFAULT NULL,
    share_code VARCHAR(32) DEFAULT NULL COMMENT 'For sharing trip with non-family',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_family (family_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_share_code (share_code),

    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trip plan versions (AI-generated plans with full history)
CREATE TABLE IF NOT EXISTS ht_trip_plan_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    plan_json JSON NOT NULL COMMENT 'Full AI-generated plan structure',
    summary_text TEXT DEFAULT NULL COMMENT 'Human-readable summary',
    created_by ENUM('user', 'ai', 'system') NOT NULL DEFAULT 'ai',
    refinement_instruction TEXT DEFAULT NULL COMMENT 'What user asked AI to change',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_trip (trip_id),
    INDEX idx_version (trip_id, version_number),
    INDEX idx_active (trip_id, is_active),

    FOREIGN KEY (trip_id) REFERENCES ht_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trip members (for group trips with multiple participants)
CREATE TABLE IF NOT EXISTS ht_trip_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL if invited but not yet joined',
    invited_email VARCHAR(255) DEFAULT NULL,
    invited_phone VARCHAR(50) DEFAULT NULL,
    role ENUM('owner', 'editor', 'viewer') NOT NULL DEFAULT 'viewer',
    status ENUM('invited', 'accepted', 'declined') NOT NULL DEFAULT 'invited',
    invite_token VARCHAR(64) DEFAULT NULL,
    invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    joined_at TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_trip (trip_id),
    INDEX idx_user (user_id),
    INDEX idx_invite_token (invite_token),

    FOREIGN KEY (trip_id) REFERENCES ht_trips(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trip votes (for group decision making on itinerary options)
CREATE TABLE IF NOT EXISTS ht_trip_votes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    options_json JSON NOT NULL COMMENT 'Array of {id, label, plan_summary}',
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    winning_option_id VARCHAR(50) DEFAULT NULL,
    closes_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_trip (trip_id),
    INDEX idx_status (status),

    FOREIGN KEY (trip_id) REFERENCES ht_trips(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual vote responses
CREATE TABLE IF NOT EXISTS ht_trip_vote_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vote_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    option_id VARCHAR(50) NOT NULL,
    vote_value ENUM('love', 'meh', 'no') NOT NULL,
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_vote_user_option (vote_id, user_id, option_id),
    INDEX idx_vote (vote_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (vote_id) REFERENCES ht_trip_votes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offline travel wallet items
CREATE TABLE IF NOT EXISTS ht_trip_wallet_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('ticket', 'booking', 'doc', 'note', 'qr', 'link', 'contact', 'insurance', 'visa') NOT NULL,
    label VARCHAR(255) NOT NULL,
    content TEXT DEFAULT NULL COMMENT 'Text content, JSON, or URL',
    file_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded file',
    file_type VARCHAR(50) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    is_essential TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Show prominently offline',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_trip (trip_id),
    INDEX idx_type (trip_id, type),
    INDEX idx_essential (trip_id, is_essential),

    FOREIGN KEY (trip_id) REFERENCES ht_trips(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense tracking
CREATE TABLE IF NOT EXISTS ht_trip_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    paid_by BIGINT UNSIGNED NOT NULL COMMENT 'User who paid',
    category ENUM('food', 'fuel', 'transport', 'stay', 'activity', 'shopping', 'tips', 'other') NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ZAR',
    expense_date DATE NOT NULL,
    receipt_path VARCHAR(500) DEFAULT NULL,
    split_with_json JSON DEFAULT NULL COMMENT 'Array of user_ids to split with',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_trip (trip_id),
    INDEX idx_paid_by (paid_by),
    INDEX idx_category (trip_id, category),
    INDEX idx_date (trip_id, expense_date),

    FOREIGN KEY (trip_id) REFERENCES ht_trips(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI rate limiting and caching
CREATE TABLE IF NOT EXISTS ht_ai_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prompt_hash VARCHAR(64) NOT NULL COMMENT 'SHA256 of prompt',
    response_json JSON NOT NULL,
    model VARCHAR(50) NOT NULL,
    tokens_used INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,

    UNIQUE KEY uk_prompt_hash (prompt_hash),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI rate limits per user
CREATE TABLE IF NOT EXISTS ht_ai_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    requests_count INT UNSIGNED NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_user (user_id),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Google Calendar OAuth tokens
CREATE TABLE IF NOT EXISTS ht_user_calendar_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('google', 'microsoft', 'apple') NOT NULL DEFAULT 'google',
    access_token TEXT NOT NULL,
    refresh_token TEXT DEFAULT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    scope TEXT DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_user_provider (user_id, provider),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packing list items (user-specific checklist state)
CREATE TABLE IF NOT EXISTS ht_trip_packing_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL COMMENT 'essentials, weather, activities, kids, etc',
    item_name VARCHAR(255) NOT NULL,
    quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_packed TINYINT(1) NOT NULL DEFAULT 0,
    packed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_trip_user (trip_id, user_id),
    INDEX idx_category (trip_id, category),

    FOREIGN KEY (trip_id) REFERENCES ht_trips(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
