-- Migration 007: heal generations missed by an earlier 004 and remember uninstalls.
--
-- Базы, которые уже применили 004 без backfill, не выполняют тот файл повторно.
-- Здесь та же привязка кандидатов и магазинов к поколениям, плюс tombstone
-- повторного uninstall: хранится только keyed-хеш пароля, не сам пароль.

INSERT INTO installation_generations (store_id, generation, status, candidate_id, created_at, activated_at)
SELECT s.store_id, COALESCE(s.generation, 1), 'active', NULL, s.installed_at, s.installed_at
FROM stores s
WHERE NOT EXISTS (
    SELECT 1 FROM installation_generations ig
    WHERE ig.store_id = s.store_id AND ig.generation = COALESCE(s.generation, 1)
);

INSERT INTO installation_generations (store_id, generation, status, candidate_id, created_at)
SELECT c.store_id,
       COALESCE((SELECT MAX(ig.generation) FROM installation_generations ig WHERE ig.store_id = c.store_id), 0)
           + ROW_NUMBER() OVER (PARTITION BY c.store_id ORDER BY c.candidate_version, c.created_at, c.id),
       'reserved',
       c.id,
       c.created_at
FROM install_candidates c
WHERE NOT EXISTS (
    SELECT 1 FROM installation_generations ig WHERE ig.candidate_id = c.id
);

CREATE TABLE IF NOT EXISTS uninstall_tombstones (
    store_id TEXT NOT NULL,
    shop TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (store_id, shop, password_hash)
);

CREATE INDEX IF NOT EXISTS idx_uninstall_tombstones_lookup
    ON uninstall_tombstones(store_id, shop, created_at);
