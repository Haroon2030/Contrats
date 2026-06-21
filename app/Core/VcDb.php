<?php

declare(strict_types=1);

/**
 * طبقة توافق mysqli فوق PostgreSQL (PDO) لدعم الكود الحالي.
 */
final class VcDb
{
    public string $error = '';
    public int $errno = 0;
    public int $insert_id = 0;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function fromDatabaseUrl(string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'postgresql') {
            throw new InvalidArgumentException('DATABASE_URL يجب أن يكون postgresql://...');
        }

        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5432;
        $db = ltrim($parts['path'] ?? '/neondb', '/');
        $user = rawurldecode($parts['user'] ?? '');
        $pass = rawurldecode($parts['pass'] ?? '');

        parse_str($parts['query'] ?? '', $query);
        $sslmode = $query['sslmode'] ?? 'require';

        $options = '';
        if (str_contains($host, '.neon.tech')) {
            $endpointHost = explode('.', $host)[0];
            $endpointId = preg_replace('/-pooler$/', '', $endpointHost) ?? $endpointHost;
            $options = ';options=endpoint=' . $endpointId;
        } elseif (!empty($query['options'])) {
            $options = ';options=' . (string) $query['options'];
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s%s',
            $host,
            (int) $port,
            $db,
            $sslmode,
            $options
        );

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec("SET TIME ZONE 'Asia/Riyadh'");

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function set_charset(string $charset): bool
    {
        return true;
    }

    public function close(): void
    {
    }

    public function prepare(string $sql): VcDbStmt|false
    {
        try {
            $sql = self::translateSql($sql);
            $stmt = $this->pdo->prepare($sql);

            return new VcDbStmt($stmt, $this, $sql);
        } catch (PDOException $e) {
            $this->setError($e);

            return false;
        }
    }

    public function query(string $sql): VcDbResult|false
    {
        try {
            $sql = self::translateSql($sql);
            $sql = self::translateShowColumns($sql);

            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return false;
            }

            if (preg_match('/^\s*INSERT\s+/i', $sql)) {
                $this->refreshInsertId();
            }

            return new VcDbResult($stmt);
        } catch (PDOException $e) {
            $this->setError($e);

            return false;
        }
    }

    public function begin_transaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function real_escape_string(string $value): string
    {
        return substr($this->pdo->quote($value), 1, -1);
    }

    public function refreshInsertId(): void
    {
        try {
            $val = $this->pdo->query('SELECT LASTVAL()')->fetchColumn();
            $this->insert_id = (int) $val;
        } catch (Throwable) {
            $this->insert_id = 0;
        }
    }

    public function setError(PDOException $e): void
    {
        $this->error = $e->getMessage();
        $this->errno = (int) ($e->errorInfo[0] ?? 0);
    }

    public static function translateShowColumns(string $sql): string
    {
        if (preg_match(
            '/^SHOW\s+COLUMNS\s+FROM\s+[`"]?(\w+)[`"]?\s+LIKE\s+[\'"](\w+)[\'"]\s*;?$/i',
            trim($sql),
            $m
        )) {
            return sprintf(
                "SELECT column_name AS \"Field\" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '%s' AND column_name = '%s'",
                strtolower($m[1]),
                strtolower($m[2])
            );
        }

        return $sql;
    }

    public static function translateSql(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);

        $sql = preg_replace('/`([^`]+)`/', '$1', $sql) ?? $sql;

        $sql = preg_replace('/\bINFORMATION_SCHEMA\.(COLUMNS|TABLES)\b/i', 'information_schema.$1', $sql) ?? $sql;
        $sql = str_replace('TABLE_SCHEMA = DATABASE()', "table_schema = current_schema()", $sql);
        $sql = preg_replace('/\bTABLE_NAME\b/', 'table_name', $sql) ?? $sql;
        $sql = preg_replace('/\bCOLUMN_NAME\b/', 'column_name', $sql) ?? $sql;

        $sql = preg_replace('/\bTINYINT\s*\(\s*\d+\s*\)/i', 'SMALLINT', $sql) ?? $sql;
        $sql = preg_replace('/\bTINYINT\b/i', 'SMALLINT', $sql) ?? $sql;
        $sql = preg_replace('/\bINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $sql) ?? $sql;
        $sql = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bMEDIUMTEXT\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bDOUBLE\b/i', 'DOUBLE PRECISION', $sql) ?? $sql;
        $sql = preg_replace("/'0000-00-00'/", 'NULL', $sql) ?? $sql;
        $sql = preg_replace("/'0000-00-00 00:00:00'/", 'NULL', $sql) ?? $sql;

        $sql = preg_replace('/\s+ENGINE\s*=\s*InnoDB[^;]*/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+COLLATE\s*=\s*[\w_]+/i', '', $sql) ?? $sql;

        $sql = preg_replace('/\b(\w+)\s+INT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i', '$1 SERIAL', $sql) ?? $sql;
        $sql = preg_replace('/\b(\w+)\s+INT\s+AUTO_INCREMENT\b/i', '$1 SERIAL', $sql) ?? $sql;
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', '', $sql) ?? $sql;

        $sql = preg_replace('/\s+AFTER\s+\w+/i', '', $sql) ?? $sql;

        $sql = preg_replace('/\bMODIFY\s+(\w+)\s+ENUM\s*\([^)]+\)/i', 'ALTER COLUMN $1 TYPE VARCHAR(50)', $sql) ?? $sql;
        $sql = preg_replace('/\bMODIFY\s+/i', 'ALTER COLUMN ', $sql) ?? $sql;

        $sql = preg_replace('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', 'ADD COLUMN IF NOT EXISTS', $sql) ?? $sql;

        if (stripos($sql, 'user_page_order') !== false && stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $sql = preg_replace('/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT (user_id, page_name) DO UPDATE SET', $sql) ?? $sql;
        }

        if (stripos($sql, 'payment_request_approvals') !== false && stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $sql = preg_replace('/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT (request_id, step_key) DO UPDATE SET', $sql) ?? $sql;
        }

        $sql = preg_replace('/VALUES\s*\(\s*([`"]?\w+[`"]?)\s*\)/i', 'EXCLUDED.$1', $sql) ?? $sql;

        $sql = preg_replace("/'0000-00-00'/", 'NULL', $sql) ?? $sql;
        $sql = preg_replace("/'0000-00-00 00:00:00'/", 'NULL', $sql) ?? $sql;

        $sql = preg_replace('/\bCURRENT_TIMESTAMP\(\)/i', 'CURRENT_TIMESTAMP', $sql) ?? $sql;

        return $sql;
    }
}

final class VcDbStmt
{
    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';
    public int $errno = 0;

    private string $types = '';
    /** @var array<int, mixed> */
    private array $bound = [];

    public function __construct(
        private PDOStatement $stmt,
        private VcDb $db,
        private string $sql
    ) {
    }

    public function bind_param(string $types, &...$vars): bool
    {
        $this->types = $types;
        $this->bound = [];
        foreach ($vars as $i => &$var) {
            $this->bound[$i] = &$var;
        }

        return true;
    }

    public function execute(): bool
    {
        try {
            $params = $this->buildParams();
            $this->stmt->execute($params);
            $this->affected_rows = $this->stmt->rowCount();

            if (preg_match('/^\s*INSERT\s+/i', $this->sql)) {
                $this->db->refreshInsertId();
                $this->insert_id = $this->db->insert_id;
            }

            return true;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int) ($e->errorInfo[0] ?? 0);
            $this->db->setError($e);

            return false;
        }
    }

    public function get_result(): VcDbResult
    {
        return new VcDbResult($this->stmt);
    }

    public function close(): void
    {
    }

    /** @return list<mixed> */
    private function buildParams(): array
    {
        $params = [];
        $len = strlen($this->types);

        for ($i = 0; $i < $len; $i++) {
            $params[] = $this->bound[$i] ?? null;
        }

        return $params;
    }
}

final class VcDbResult
{
    public int $num_rows = 0;

    public function __construct(private PDOStatement $stmt)
    {
        if ($this->stmt->columnCount() > 0) {
            $rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->num_rows = count($rows);
            $this->buffered = $rows;
            $this->index = 0;
        }
    }

    /** @var list<array<string, mixed>> */
    private array $buffered = [];
    private int $index = 0;

    public function fetch_assoc(): ?array
    {
        if ($this->buffered !== []) {
            if ($this->index >= count($this->buffered)) {
                return null;
            }

            return $this->buffered[$this->index++];
        }

        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetch_all(int $mode = 0): array
    {
        if ($this->buffered !== []) {
            return $this->buffered;
        }

        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
