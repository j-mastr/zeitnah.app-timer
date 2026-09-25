<?php

namespace App\Race;

/**
 * Thin PDO wrapper: lazy connection, driver-aware transactions and schema installation.
 * Supports SQLite, MySQL/MariaDB and PostgreSQL.
 */
class Database
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
    ) {
    }

    public function pdo(): \PDO
    {
        if (null === $this->pdo) {
            $this->pdo = new \PDO($this->dsn, $this->user ?: null, $this->password ?: null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            if ('sqlite' === $this->driver()) {
                $this->pdo->exec('PRAGMA busy_timeout = 5000');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        }

        return $this->pdo;
    }

    public function driver(): string
    {
        return strtolower(strtok($this->dsn, ':'));
    }

    /**
     * SQLite needs BEGIN IMMEDIATE so that concurrent writers (web requests and the
     * WebSocket server) queue on the write lock instead of failing on lock upgrade.
     */
    public function begin(): void
    {
        if ('sqlite' === $this->driver()) {
            $this->pdo()->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo()->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ('sqlite' === $this->driver()) {
            $this->pdo()->exec('COMMIT');
        } else {
            $this->pdo()->commit();
        }
    }

    public function rollback(): void
    {
        try {
            if ('sqlite' === $this->driver()) {
                $this->pdo()->exec('ROLLBACK');
            } elseif ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        } catch (\PDOException) {
            // no transaction active
        }
    }

    /** Row-lock suffix for SELECTs inside a write transaction (not needed on SQLite). */
    public function forUpdate(): string
    {
        return 'sqlite' === $this->driver() ? '' : ' FOR UPDATE';
    }

    /**
     * Creates the tables if they don't exist yet. Returns the executed statements.
     *
     * @return list<string>
     */
    public function installSchema(): array
    {
        $statements = match ($this->driver()) {
            'sqlite' => [
                'PRAGMA journal_mode = WAL',
                'CREATE TABLE IF NOT EXISTS race (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code VARCHAR(16) NOT NULL UNIQUE,
                    seq INTEGER NOT NULL DEFAULT 0,
                    state TEXT NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    updated_at VARCHAR(32) NOT NULL
                )',
                'CREATE TABLE IF NOT EXISTS race_event (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    race_id INTEGER NOT NULL REFERENCES race(id) ON DELETE CASCADE,
                    seq INTEGER NOT NULL,
                    op_id VARCHAR(64) NOT NULL,
                    op TEXT NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    UNIQUE (race_id, seq),
                    UNIQUE (race_id, op_id)
                )',
                'CREATE TABLE IF NOT EXISTS access_code (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code VARCHAR(16) NOT NULL UNIQUE,
                    target_type VARCHAR(16) NOT NULL,
                    target_id INTEGER NOT NULL,
                    rules TEXT NOT NULL,
                    source VARCHAR(16) NOT NULL,
                    source_ref VARCHAR(64) NULL,
                    created_at VARCHAR(32) NOT NULL,
                    revoked_at VARCHAR(32) NULL,
                    UNIQUE (target_type, target_id, source, source_ref)
                )',
            ],
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS race (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(16) NOT NULL UNIQUE,
                    seq INT NOT NULL DEFAULT 0,
                    state LONGTEXT NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    updated_at VARCHAR(32) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS race_event (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    race_id INT NOT NULL,
                    seq INT NOT NULL,
                    op_id VARCHAR(64) NOT NULL,
                    op LONGTEXT NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    UNIQUE KEY uniq_seq (race_id, seq),
                    UNIQUE KEY uniq_op (race_id, op_id),
                    CONSTRAINT fk_event_race FOREIGN KEY (race_id) REFERENCES race(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS access_code (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(16) NOT NULL UNIQUE,
                    target_type VARCHAR(16) NOT NULL,
                    target_id INT NOT NULL,
                    rules TEXT NOT NULL,
                    source VARCHAR(16) NOT NULL,
                    source_ref VARCHAR(64) NULL,
                    created_at VARCHAR(32) NOT NULL,
                    revoked_at VARCHAR(32) NULL,
                    UNIQUE KEY uniq_source (target_type, target_id, source, source_ref)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            'pgsql' => [
                'CREATE TABLE IF NOT EXISTS race (
                    id SERIAL PRIMARY KEY,
                    code VARCHAR(16) NOT NULL UNIQUE,
                    seq INTEGER NOT NULL DEFAULT 0,
                    state TEXT NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    updated_at VARCHAR(32) NOT NULL
                )',
                'CREATE TABLE IF NOT EXISTS race_event (
                    id BIGSERIAL PRIMARY KEY,
                    race_id INTEGER NOT NULL REFERENCES race(id) ON DELETE CASCADE,
                    seq INTEGER NOT NULL,
                    op_id VARCHAR(64) NOT NULL,
                    op TEXT NOT NULL,
                    created_at VARCHAR(32) NOT NULL,
                    UNIQUE (race_id, seq),
                    UNIQUE (race_id, op_id)
                )',
                'CREATE TABLE IF NOT EXISTS access_code (
                    id SERIAL PRIMARY KEY,
                    code VARCHAR(16) NOT NULL UNIQUE,
                    target_type VARCHAR(16) NOT NULL,
                    target_id INTEGER NOT NULL,
                    rules TEXT NOT NULL,
                    source VARCHAR(16) NOT NULL,
                    source_ref VARCHAR(64) NULL,
                    created_at VARCHAR(32) NOT NULL,
                    revoked_at VARCHAR(32) NULL,
                    UNIQUE (target_type, target_id, source, source_ref)
                )',
            ],
            default => throw new \RuntimeException(sprintf('Unsupported database driver "%s".', $this->driver())),
        };

        // Every race code grants full access to its race; races created before access codes
        // existed get their row here (idempotent).
        $statements[] = "INSERT INTO access_code (code, target_type, target_id, rules, source, source_ref, created_at)
            SELECT r.code, 'race', r.id, '[\"*\"]', 'race', NULL, r.created_at FROM race r
            WHERE NOT EXISTS (SELECT 1 FROM access_code a WHERE a.code = r.code)";

        foreach ($statements as $sql) {
            $this->pdo()->exec($sql);
        }

        return $statements;
    }
}
