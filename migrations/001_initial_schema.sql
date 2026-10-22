-- Migration 001: Initial schema for InSales Partner Application
-- Applied automatically by Database::migrate()

-- Active store connections (verified installations)
CREATE TABLE IF NOT EXISTS stores (
    store_id TEXT PRIMARY KEY,         -- insales_id from InSales
    shop TEXT NOT NULL,                 -- normalized shop domain (e.g., demo.myinsales.ru)
    encrypted_password TEXT NOT NULL,   -- encrypted store password (XChaCha20-Poly1305)
    title TEXT NOT NULL DEFAULT 'Store',-- store title from account API
    generation INTEGER NOT NULL DEFAULT 1, -- incremented on each reinstall
    installed_at INTEGER NOT NULL       -- unix timestamp
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_stores_shop ON stores(shop);

-- Install candidates (awaiting verification)
-- Магазина в момент /install ещё нет, поэтому FOREIGN KEY не ставится.
-- Связь появляется только после сверки account.id.
CREATE TABLE IF NOT EXISTS install_candidates (
    id TEXT PRIMARY KEY,                 -- random candidate ID
    store_id TEXT NOT NULL,              -- claimed insales_id
    shop TEXT NOT NULL,                  -- normalized shop domain
    encrypted_password TEXT NOT NULL,    -- encrypted password for verification
    created_at INTEGER NOT NULL          -- unix timestamp
);

CREATE INDEX IF NOT EXISTS idx_install_candidates_store ON install_candidates(store_id);

-- Pending login challenges (nonce-based, single-use)
CREATE TABLE IF NOT EXISTS pending_logins (
    session_id TEXT PRIMARY KEY,         -- server-side session ID for the challenge
    store_id TEXT NOT NULL,              -- store ID
    nonce TEXT NOT NULL,                 -- random nonce sent to InSales
    expires_at INTEGER NOT NULL,        -- TTL unix timestamp
    created_at INTEGER NOT NULL,
    FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pending_logins_store ON pending_logins(store_id);
CREATE INDEX IF NOT EXISTS idx_pending_logins_expires ON pending_logins(expires_at);

-- Authenticated user sessions
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,                 -- random session token
    store_id TEXT NOT NULL,              -- tenant scope
    user_id TEXT NOT NULL DEFAULT '0',   -- InSales user ID
    generation INTEGER NOT NULL DEFAULT 1, -- matched with store.generation on validation
    expires_at INTEGER NOT NULL,        -- TTL unix timestamp
    created_at INTEGER NOT NULL,
    FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sessions_store ON sessions(store_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- Job queue for async operations (install verification, etc.)
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default',
    type TEXT NOT NULL,                  -- job type (e.g., 'verify_install')
    payload TEXT NOT NULL DEFAULT '{}',  -- JSON payload
    store_id TEXT,                       -- optional store scope for isolation
    status TEXT NOT NULL DEFAULT 'pending', -- pending | running | completed | failed
    retries_left INTEGER NOT NULL DEFAULT 3,
    error TEXT,                          -- last error message (truncated)
    priority INTEGER NOT NULL DEFAULT 0,
    scheduled_at INTEGER,                -- delayed execution (unix timestamp)
    claimed_at INTEGER,                  -- when job was claimed for processing
    created_at INTEGER NOT NULL,
    completed_at INTEGER                 -- when job finished
);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_status ON jobs(queue, status);
CREATE INDEX IF NOT EXISTS idx_jobs_scheduled ON jobs(scheduled_at);
CREATE INDEX IF NOT EXISTS idx_jobs_store ON jobs(store_id);