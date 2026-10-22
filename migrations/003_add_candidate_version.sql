-- Migration 003: Add candidate_version for race-safe activation ordering
--
-- candidate_version — монотонно возрастающий номер для каждого store_id.
-- Позволяет detect-ить устаревших кандидатов при активации:
-- если другой worker уже активировал candidate с тем же store_id и большей
-- версией, текущий кандидат считается устаревшим и не должен активироваться.
--
-- Нулевая миграция: для существующих кандидатов проставляем version = 1.

CREATE TABLE IF NOT EXISTS install_candidates_v3 (
    id TEXT PRIMARY KEY,
    store_id TEXT NOT NULL,
    shop TEXT NOT NULL,
    encrypted_password TEXT NOT NULL,
    candidate_version INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL
);

INSERT OR IGNORE INTO install_candidates_v3 (id, store_id, shop, encrypted_password, candidate_version, created_at)
    SELECT id, store_id, shop, encrypted_password, 1, created_at
    FROM install_candidates;

DROP TABLE IF EXISTS install_candidates;

ALTER TABLE install_candidates_v3 RENAME TO install_candidates;

CREATE INDEX IF NOT EXISTS idx_install_candidates_store ON install_candidates(store_id);
CREATE INDEX IF NOT EXISTS idx_install_candidates_version ON install_candidates(store_id, candidate_version);