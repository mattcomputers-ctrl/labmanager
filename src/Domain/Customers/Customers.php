<?php
declare(strict_types=1);

namespace Chroma\Domain\Customers;

use Chroma\Core\Db;
use Chroma\Db\Seeder;

/** Customer lookup and create-if-missing, shared by projects, quick assign, import and the CMS sync. */
final class Customers
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM chroma_customers WHERE id = ?', [$id]);
    }

    /** Match on normalized name (case/punctuation-insensitive). @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        $norm = Seeder::normalizeName($name);
        if ($norm === '') {
            return null;
        }
        return $this->db->one('SELECT * FROM chroma_customers WHERE name_normalized = ? ORDER BY active DESC, id LIMIT 1', [$norm]);
    }

    /** Find by name or create (source: manual | import | cms). Returns the id, or null for a blank name. */
    public function findOrCreate(string $name, string $source = 'manual'): ?int
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return null;
        }
        $existing = $this->findByName($name);
        if ($existing) {
            return (int) $existing['id'];
        }
        $now = $this->db->now();
        return $this->db->insert('chroma_customers', [
            'name' => mb_substr($name, 0, 200),
            'name_normalized' => mb_substr(Seeder::normalizeName($name), 0, 200),
            'cms_customer_id' => null,
            'active' => 1,
            'source' => $source,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** The internal customer used for R&D projects. */
    public function internalRndId(string $name = 'Precision Ink R&D'): int
    {
        return (int) $this->findOrCreate($name, 'system');
    }
}
