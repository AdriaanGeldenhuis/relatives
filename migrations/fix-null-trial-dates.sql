-- =============================================
-- MIGRATION: Fix NULL trial dates for existing families
-- Purpose: Set trial dates for families that registered without them
-- Date: 2026-01-06
-- =============================================

-- Fix families with subscription_status='trial' but NULL trial dates
-- Set their trial to start from their created_at date
UPDATE families
SET
    trial_started_at = created_at,
    trial_ends_at = DATE_ADD(created_at, INTERVAL 3 DAY)
WHERE
    subscription_status = 'trial'
    AND trial_started_at IS NULL
    AND trial_ends_at IS NULL;

-- Verify the fix
SELECT id, name, subscription_status, trial_started_at, trial_ends_at, created_at
FROM families
WHERE subscription_status = 'trial';
