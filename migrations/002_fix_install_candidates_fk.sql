-- Migration 002: Remove FK from install_candidates that referenced stores
-- (older versions had ON DELETE SET NULL with NOT NULL store_id)
-- This migration safely recreates the table without FK constraints.

-- SQLite doesn't support DROP FK, so we recreate the table
CREATE TABLE IF NOT EXISTS install_candidates_v2 (
    id TEXT PRIMARY KEY,
    store_id TEXT NOT NULL,
    shop TEXT NOT NULL,
    encrypted_password TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

-- Copy existing data
INSERT OR IGNORE INTO install_candidates_v2 (id, store_id, shop, encrypted_password, created_at)
    SELECT id, store_id, shop, encrypted_password, created_at
    FROM install_candidates;

-- Drop old table
DROP TABLE IF EXISTS install_candidates;

-- Rename v2 to original name
ALTER TABLE install_candidates_v2 RENAME TO install_candidates;

-- Recreate index
CREATE INDEX IF NOT EXISTS idx_install_candidates_store ON install_candidates(store_id);