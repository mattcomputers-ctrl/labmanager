<?php
declare(strict_types=1);

namespace Chroma\Core;

/** Applies db/migrations/*.sql in name order, once each, then runs seeders. */
final class Migrator
{
    public function __construct(private readonly Db $db, private readonly string $dir)
    {
    }

    /** @return list<string> names applied in this run */
    public function run(bool $verbose = false): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS chroma_migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $done = array_flip($this->db->column('SELECT name FROM chroma_migrations'));
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file, '.sql');
            if (isset($done[$name])) {
                continue;
            }
            if ($verbose) {
                echo "Applying $name ...\n";
            }
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $this->db->exec($sql);
            }
            $this->db->run('INSERT INTO chroma_migrations (name, applied_at) VALUES (?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE applied_at = applied_at', [$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $done = [];
        if ($this->db->tableExists('chroma_migrations')) {
            $done = array_flip($this->db->column('SELECT name FROM chroma_migrations'));
        }
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $file) {
            $name = basename($file, '.sql');
            if (!isset($done[$name])) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Split a .sql file into statements. Migrations are DDL only (no stored
     * routines), so splitting on a semicolon at end of line is enough.
     * Lines starting with -- are comments.
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $lines[] = $line;
        }
        $out = [];
        foreach (preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $out[] = $chunk;
            }
        }
        return $out;
    }
}
