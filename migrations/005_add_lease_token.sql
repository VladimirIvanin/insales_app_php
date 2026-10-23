-- Migration 005: legacy column on the unused jobs table.
--
-- Очередь и worker удалены. Колонка остаётся, чтобы уже накатанные
-- базы получали ту же схему; приложение её не читает.

ALTER TABLE jobs ADD COLUMN lease_token TEXT;