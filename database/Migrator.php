<?php

declare(strict_types=1);

/**
 * تشغيل migrations تدريجية — تطبّق التغييرات الجديدة فقط دون مسح البيانات.
 */
final class Migrator
{
    private string $migrationsDir;

    public function __construct(
        private readonly PDO $pdo,
        ?string $migrationsDir = null,
    ) {
        $this->migrationsDir = $migrationsDir ?? dirname(__FILE__) . '/migrations';
    }

    public function ensureMigrationsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL DEFAULT 1,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /** @return list<string> */
    public function getApplied(): array
    {
        $this->ensureMigrationsTable();
        $rows = $this->pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $rows ?: []));
    }

    /** @return list<string> */
    public function getPending(): array
    {
        $applied = array_flip($this->getApplied());
        $pending = [];

        foreach ($this->discoverMigrationFiles() as $file) {
            $name = basename($file);
            if (!isset($applied[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    public function runPending(): int
    {
        $pending = $this->getPending();
        if ($pending === []) {
            return 0;
        }

        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();
        $count = 0;

        foreach ($pending as $name) {
            $path = $this->migrationsDir . '/' . $name;
            $sql = is_file($path) ? (string) file_get_contents($path) : '';
            $statements = $this->extractStatements($sql);

            $this->pdo->beginTransaction();
            try {
                foreach ($statements as $statement) {
                    $this->pdo->exec($statement);
                }

                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)');
                $stmt->execute([$name, $batch]);
                $this->pdo->commit();
                $count++;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration failed: {$name} — " . $e->getMessage(), 0, $e);
            }
        }

        return $count;
    }

    /** تسجيل كل الملفات الحالية كمُطبّقة دون تنفيذها (لقاعدة إنتاج موجودة مسبقاً). */
    public function baseline(): int
    {
        $this->ensureMigrationsTable();
        $applied = array_flip($this->getApplied());
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();
        $count = 0;

        foreach ($this->discoverMigrationFiles() as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }

            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)');
            $stmt->execute([$name, $batch]);
            $count++;
        }

        return $count;
    }

    /** @return list<string> */
    private function discoverMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function extractStatements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $sql = trim($sql);

        if ($sql === '') {
            return [];
        }

        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        $statements = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!str_ends_with($part, ';')) {
                $part .= ';';
            }
            $statements[] = $part;
        }

        return $statements;
    }
}
