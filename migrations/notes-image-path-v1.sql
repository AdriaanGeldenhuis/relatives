-- Migration: Add image_path column to notes table for photo attachments
-- Date: 2026-01-29
-- Description: Allows notes to have attached images saved as WebP in /saves/{user_id}/notes/

ALTER TABLE `notes` ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL AFTER `audio_path`;
