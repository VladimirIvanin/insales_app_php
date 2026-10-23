-- Migration 004: Add installation_generations table for reliable generation tracking
--
-- Предыдущая схема использовала candidate_version для упорядочивания кандидатов.
-- При удалении кандидата (после активации) версия терялась.
-- Новая схема вводит отдельную таблицу с монотонным поколением для каждого магазина.

-- Таблица поколений установки
CREATE TABLE IF NOT EXISTS installation_generations (
    store_id TEXT NOT NULL,          -- insales_id
    generation INTEGER NOT NULL,     -- номер поколения (монотонно возрастающий)
    status TEXT NOT NULL DEFAULT 'reserved',  -- reserved | active | cancelled | expired
    candidate_id TEXT,               -- ссылка на install_candidates (опционально)
    created_at INTEGER NOT NULL,
    activated_at INTEGER,            -- когда поколение стало активным
    PRIMARY KEY (store_id, generation)
);

CREATE INDEX IF NOT EXISTS idx_gen_store_status ON installation_generations(store_id, status);

-- Добавляем generation в install_candidates для привязки к поколению
-- Поле candidate_version сохраняется для обратной совместимости
-- generation указывает на installation_generations.generation

-- Backfill: активное поколение для уже проверенных магазинов.
INSERT INTO installation_generations (store_id, generation, status, candidate_id, created_at, activated_at)
SELECT s.store_id, COALESCE(s.generation, 1), 'active', NULL, s.installed_at, s.installed_at
FROM stores s
WHERE NOT EXISTS (
    SELECT 1 FROM installation_generations ig
    WHERE ig.store_id = s.store_id AND ig.generation = COALESCE(s.generation, 1)
);

-- Backfill: отдельное reserved-поколение каждому ещё не связанному кандидату.
-- ROW_NUMBER считается до INSERT, поэтому номера не сталкиваются внутри одного store_id.
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
