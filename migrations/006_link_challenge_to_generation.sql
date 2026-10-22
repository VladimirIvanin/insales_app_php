-- Migration 006: Link login challenges to installation generation
--
-- Добавляет generation в pending_logins для привязки challenge
-- к конкретному поколению установки. При активации нового поколения
-- старые challenge и сессии отзываются.
--
-- Добавляет поле generation в pending_logins.
-- Для существующих записей generation = 0 (обрабатывается как any).

ALTER TABLE pending_logins ADD COLUMN generation INTEGER NOT NULL DEFAULT 0;