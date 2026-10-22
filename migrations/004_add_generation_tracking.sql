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

-- Миграция данных: для существующих кандидатов без generation заполняем нулём
-- (они будут обработаны при verify, generation начнётся с 1 после активации)