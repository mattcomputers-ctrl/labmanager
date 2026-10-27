<?php
declare(strict_types=1);

namespace Chroma\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. All tables are prefixed chroma_ so the schema can share
 * a database with Precision Ink's other systems later.
 */
final class Db
{
    private ?PDO $pdo = null;
    private int $txDepth = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $pass,
    ) {
    }

    public static function fromConfig(Config $cfg): self
    {
        $host = $cfg->get('DB_HOST', '127.0.0.1');
        $port = $cfg->int('DB_PORT', 3306);
        $name = $cfg->get('DB_NAME', 'chroma');
        $sock = $cfg->get('DB_SOCKET');
        $dsn = $sock
            ? "mysql:unix_socket=$sock;dbname=$name;charset=utf8mb4"
            : "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        return new self($dsn, (string) $cfg->get('DB_USER', 'chroma'), (string) $cfg->get('DB_PASS', ''));
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        }
        return $this->pdo;
    }

    /** Drop the current connection; the next query reconnects. Needed after pcntl_fork. */
    public function reconnect(): void
    {
        $this->pdo = null;
        $this->txDepth = 0;
    }

    /** Open a second, independent connection (used by tests and the stress tool). */
    public function fresh(): self
    {
        return new self($this->dsn, $this->user, $this->pass);
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<int|string,mixed> $params @return array<int,mixed> */
    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<int|string,mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn($c) => "`$c`", $cols)),
            implode(', ', array_map(fn($c) => ":$c", $cols)),
        );
        $this->run($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }
        $set = [];
        $params = [];
        foreach ($data as $c => $v) {
            $set[] = "`$c` = :set_$c";
            $params["set_$c"] = $v;
        }
        $cond = [];
        foreach ($where as $c => $v) {
            $cond[] = "`$c` = :where_$c";
            $params["where_$c"] = $v;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $cond));
        return $this->exec($sql, $params);
    }

    /**
     * Run $fn inside a transaction. Nested calls join the outer transaction
     * (no savepoints) so domain services can compose freely.
     *
     * @template T
     * @param callable(Db):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($this->txDepth === 0) {
            $pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function tableExists(string $table): bool
    {
        return (bool) $this->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
    }
}
