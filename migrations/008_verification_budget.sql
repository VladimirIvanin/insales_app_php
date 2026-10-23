CREATE TABLE verification_budgets (
    shop TEXT PRIMARY KEY,
    window_started REAL NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at REAL NOT NULL DEFAULT 0,
    lease_until REAL NOT NULL DEFAULT 0,
    lease_token TEXT
);
