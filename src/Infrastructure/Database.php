<?php

declare(strict_types=1);

namespace Infrastructure;

/**
 * Database abstraction over PDO SQLite.
 *
 * Supports:
 * - Named parameter binding
 * - Transactions with retry when SQLite is locked
 * - Fetch one / execute
 * - RETURNING support for PostgreSQL-like chaining
 * - SQLite WAL mode for better concurrency
 */
final readonly class Database
{
    private \PDO $pdo;

    public function __construct(string $path)
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create database directory: {$dir}");
            }
        }

        $this->pdo = new \PDO("sqlite:{$path}", options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // WAL mode for better concurrent access
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    /**
     * Execute a query and return the number of affected rows.
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Fetch a single row, or null if not found.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false && $row !== [] ? $row : null;
    }

    /**
     * Fetch all rows.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Execute a callback within a transaction.
     * Retries the whole callback when SQLite reports a lock (HY000).
     * The callback result is returned only after commit, so a rolled-back
     * attempt cannot leak state through by-reference variables.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $this->pdo->beginTransaction();
                try {
                    $result = $callback();
                    $this->pdo->commit();
                    return $result;
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            } catch (\PDOException $e) {
                $attempt++;
                if ($attempt >= $maxRetries || $e->getCode() !== 'HY000') {
                    throw $e;
                }
                usleep(100_000 * $attempt); // 100ms, 200ms, 300ms
            }
        }

        throw new \RuntimeException('Database transaction failed');
    }

    /**
     * Get the underlying PDO instance for complex operations.
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Apply migrations.
     *
     * Использует BEGIN IMMEDIATE для сериализации между процессами.
     * Повторно читает версию внутри транзакции.
     *
     * @param array<int, string> $migrations Array of SQL strings indexed by version
     */
    public function migrate(array $migrations, int $targetVersion = 0): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS _schema_version (version INTEGER PRIMARY KEY, applied_at INTEGER)'
        );

        $maxAttempts = 3;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                // Используем IMMEDIATE (уже открывает транзакцию)
                $this->pdo->exec('BEGIN IMMEDIATE');

                /** @var array{v: int}|null $versionRow */
                $versionRow = $this->fetch('SELECT MAX(version) as v FROM _schema_version');
                $current = $versionRow['v'] ?? 0;
                $target = $targetVersion > 0 ? $targetVersion : \count($migrations);

                $applied = false;
                foreach ($migrations as $version => $sql) {
                    if ($version > $current && $version <= $target) {
                        $this->pdo->exec($sql);
                        $this->pdo->prepare('INSERT INTO _schema_version (version, applied_at) VALUES (:v, :t)')
                            ->execute(['v' => $version, 't' => time()]);
                        $applied = true;
                    }
                }

                $this->pdo->commit();
                return;
            } catch (\PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($attempt >= $maxAttempts - 1) {
                    throw $e;
                }
                usleep(100_000 * ($attempt + 1));
            }
        }
    }
}
