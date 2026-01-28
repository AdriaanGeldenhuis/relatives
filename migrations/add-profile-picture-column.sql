-- Migration: Add profile_picture column to users table
-- This column stores the URL path to user-uploaded profile pictures

ALTER TABLE `users`
ADD COLUMN `profile_picture` VARCHAR(500) DEFAULT NULL
AFTER `avatar_url`;
