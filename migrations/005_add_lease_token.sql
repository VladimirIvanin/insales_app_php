-- Migration 005: Add lease_token for safe job ACK
--
-- Каждому claim() присваивается уникальный lease_token.
-- complete()/fail() проверяют token + running status,
-- предотвращая модификацию завершённых или перевыданных задач поздними воркерами.

ALTER TABLE jobs ADD COLUMN lease_token TEXT;