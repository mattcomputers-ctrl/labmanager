<?php
declare(strict_types=1);

namespace Chroma\Domain\Lists;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Db\Seeder;
use Chroma\Domain\Audit\AuditLog;

/**
 * The simple admin lists (ink series, coatings, substrates) and the
 * customer list. Entries that are referenced by a project, an item or a
 * code are never deleted: they are deactivated so they disappear from
 * pickers but old work still reads correctly.
 */
final class ListAdmin
{
    /** kind => [table, singular label, plural label, columns that reference it] */
    public const KINDS = [
        'ink-series' => [
            'table' => 'chroma_ink_series', 'singular' => 'ink series', 'plural' => 'Ink series',
            'refs' => [['chroma_project_items', 'ink_series_id', 'colors or items'], ['chroma_projects', 'default_ink_series_id', 'projects']],
        ],
        'coatings' => [
            'table' => 'chroma_coatings', 'singular' => 'coating', 'plural' => 'Coatings',
            'refs' => [['chroma_project_items', 'coating_id', 'colors or items'], ['chroma_projects', 'default_coating_id', 'projects']],
        ],
        'substrates' => [
            'table' => 'chroma_substrates', 'singular' => 'substrate', 'plural' => 'Substrates',
            'refs' => [['chroma_project_items', 'substrate_id', 'colors or items'], ['chroma_projects', 'default_substrate_id', 'projects']],
        ],
    ];

    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
    }

    // ------------------------------------------------------- simple lists

    /** @return array{table:string,singular:string,plural:string,refs:list<array{0:string,1:string,2:string}>} */
    public static function kind(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw ValidationException::general('There is no list called "' . $kind . '".');
        }
        return self::KINDS[$kind];
    }

    /** All entries of a list with how many things use each. @return list<array<string,mixed>> */
    public function entries(string $kind, bool $activeOnly = false): array
    {
        $k = self::kind($kind);
        $rows = $this->db->all('SELECT * FROM ' . $k['table'] . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, sort_order, name');
        foreach ($rows as &$r) {
            $r['usage_count'] = $this->usageCount($kind, (int) $r['id']);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function entry(string $kind, int $id): ?array
    {
        $k = self::kind($kind);
        return $this->db->one('SELECT * FROM ' . $k['table'] . ' WHERE id = ?', [$id]);
    }

    /** How many projects / items point at this entry. */
    public function usageCount(string $kind, int $id): int
    {
        $k = self::kind($kind);
        $n = 0;
        foreach ($k['refs'] as [$table, $col]) {
            $n += (int) $this->db->value("SELECT COUNT(*) FROM $table WHERE $col = ?", [$id]);
        }
        return $n;
    }

    /** @param array<string,mixed> $input name, notes, sort_order */
    public function add(string $kind, array $input): int
    {
        $k = self::kind($kind);
        $this->validateEntry($input);
        $name = trim(preg_replace('/\s+/', ' ', (string) $input['name']) ?? '');
        if ($this->db->value('SELECT id FROM ' . $k['table'] . ' WHERE name = ?', [$name]) !== null) {
            throw ValidationException::single('name', "\"$name\" is already in the " . $k['singular'] . ' list.');
        }
        $now = $this->db->now();
        $id = $this->db->insert($k['table'], [
            'name' => $name,
            'active' => 1,
            'sort_order' => $this->intOr($input['sort_order'] ?? null, 100),
            'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 255),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record('list.add', $k['table'], $id, null, $this->entry($kind, $id), 'Added ' . $k['singular'] . ' ' . $name);
        return $id;
    }

    /** Rename, re-sort, change notes or the active flag. @param array<string,mixed> $input name, notes, sort_order, active */
    public function update(string $kind, int $id, array $input): void
    {
        $k = self::kind($kind);
        $before = $this->entry($kind, $id);
        if ($before === null) {
            throw ValidationException::general('That entry no longer exists.');
        }
        $this->validateEntry($input);
        $name = trim(preg_replace('/\s+/', ' ', (string) $input['name']) ?? '');
        $dup = $this->db->value('SELECT id FROM ' . $k['table'] . ' WHERE name = ? AND id <> ?', [$name, $id]);
        if ($dup !== null) {
            throw ValidationException::single('name', "\"$name\" is already in the " . $k['singular'] . ' list.');
        }
        $data = [
            'name' => $name,
            'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 255),
            'sort_order' => $this->intOr($input['sort_order'] ?? null, (int) $before['sort_order']),
            'updated_at' => $this->db->now(),
        ];
        if (array_key_exists('active', $input)) {
            $data['active'] = in_array((string) $input['active'], ['1', 'on', 'true'], true) ? 1 : 0;
        }
        $this->db->update($k['table'], $data, ['id' => $id]);
        $this->audit->record('list.update', $k['table'], $id, $before, $this->entry($kind, $id), 'Edited ' . $k['singular'] . ' ' . $before['name']);
    }

    public function deactivate(string $kind, int $id): void
    {
        $this->setActive($kind, $id, 0);
    }

    public function reactivate(string $kind, int $id): void
    {
        $this->setActive($kind, $id, 1);
    }

    /** Hard delete, only when nothing references the entry. Otherwise refuses and explains. */
    public function delete(string $kind, int $id): void
    {
        $k = self::kind($kind);
        $before = $this->entry($kind, $id);
        if ($before === null) {
            throw ValidationException::general('That entry no longer exists.');
        }
        $used = $this->usageCount($kind, $id);
        if ($used > 0) {
            throw ValidationException::general(sprintf(
                '"%s" is used by %s, so it cannot be deleted. Deactivate it instead: it disappears from the pickers but old projects keep showing it.',
                $before['name'], $used === 1 ? '1 project or color' : number_format($used) . ' projects or colors'
            ));
        }
        $this->db->exec('DELETE FROM ' . $k['table'] . ' WHERE id = ?', [$id]);
        $this->audit->record('list.delete', $k['table'], $id, $before, null, 'Deleted ' . $k['singular'] . ' ' . $before['name']);
    }

    private function setActive(string $kind, int $id, int $active): void
    {
        $k = self::kind($kind);
        $before = $this->entry($kind, $id);
        if ($before === null) {
            throw ValidationException::general('That entry no longer exists.');
        }
        if ((int) $before['active'] === $active) {
            return;
        }
        $this->db->update($k['table'], ['active' => $active, 'updated_at' => $this->db->now()], ['id' => $id]);
        $this->audit->record($active ? 'list.reactivate' : 'list.deactivate', $k['table'], $id, ['active' => (int) $before['active']], ['active' => $active], ($active ? 'Reactivated ' : 'Deactivated ') . $k['singular'] . ' ' . $before['name']);
    }

    /** @param array<string,mixed> $input */
    private function validateEntry(array $input): void
    {
        $v = new Validator($input);
        $v->required('name', 'Type a name for this entry.')->maxLength('name', 100);
        $v->maxLength('notes', 255);
        $v->integer('sort_order', -100000, 100000, 'Sort order is a whole number; lower numbers come first.');
        $v->fail();
    }

    private function intOr(mixed $v, int $default): int
    {
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }

    // ------------------------------------------------------------ customers

    /**
     * Paged customer list. Filters: q (name prefix, normalized), status (active|inactive|all), source.
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function customers(array $filters, int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $join = '';
        $q = Seeder::normalizeName((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Three indexed lookups joined as one set: the normalized name starts with what was typed, the CMS id is
            // exactly what was typed, or every typed word starts a word of the name ("labels" finds "ACME Labels, Inc.")
            // through the FULLTEXT index. A derived table keeps each part on its own index; an OR across them would
            // make the optimizer scan the whole table.
            $parts = [
                'SELECT id FROM chroma_customers WHERE name_normalized LIKE ?',
                'SELECT id FROM chroma_customers WHERE cms_customer_id = ?',
            ];
            $params[] = $q . '%';
            $params[] = trim((string) ($filters['q'] ?? ''));
            $match = self::fulltextQuery($q);
            if ($match !== '') {
                $parts[] = 'SELECT id FROM chroma_customers WHERE MATCH (name_normalized) AGAINST (? IN BOOLEAN MODE)';
                $params[] = $match;
            }
            $join = ' JOIN (' . implode(' UNION ', $parts) . ') m ON m.id = c.id';
        }
        $status = (string) ($filters['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = 'c.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'c.active = 0';
        }
        if (!empty($filters['source'])) {
            $where[] = 'c.source = ?';
            $params[] = (string) $filters['source'];
        }
        $sql = $join . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers c' . $sql, $params);
        $rows = $this->db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM chroma_projects p WHERE p.customer_id = c.id) AS project_count,
                    (SELECT COUNT(*) FROM chroma_internal_codes i WHERE i.original_customer_id = c.id) + (SELECT COUNT(*) FROM chroma_sell_numbers s WHERE s.original_customer_id = c.id) AS code_count
             FROM chroma_customers c' . $sql . ' ORDER BY c.active DESC, c.name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * "+acme* +lab*" for a normalized search string: every word must start a word of the name. Words shorter than
     * the FULLTEXT minimum (3) are not indexed, so they are left out; an empty result means "prefix match only".
     */
    public static function fulltextQuery(string $normalized): string
    {
        $terms = [];
        foreach (explode(' ', $normalized) as $w) {
            if (strlen($w) >= 3) {
                $terms[] = '+' . $w . '*';
            }
        }
        return implode(' ', $terms);
    }

    public function customer(int $id): ?array
    {
        $c = $this->db->one('SELECT * FROM chroma_customers WHERE id = ?', [$id]);
        if ($c === null) {
            return null;
        }
        $c['project_count'] = (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE customer_id = ?', [$id]);
        $c['code_count'] = (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes WHERE original_customer_id = ?', [$id])
            + (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE original_customer_id = ?', [$id]);
        return $c;
    }

    /** Possible duplicates of a customer: same normalized name, or sharing the first word. @return list<array<string,mixed>> */
    public function similarCustomers(int $id, int $limit = 10): array
    {
        $c = $this->customer($id);
        if ($c === null) {
            return [];
        }
        $first = explode(' ', (string) $c['name_normalized'])[0] ?? '';
        if ($first === '') {
            return [];
        }
        return $this->db->all(
            'SELECT id, name, active, source, cms_customer_id FROM chroma_customers WHERE id <> ? AND (name_normalized = ? OR name_normalized LIKE ?) ORDER BY (name_normalized = ?) DESC, name LIMIT ' . (int) $limit,
            [$id, $c['name_normalized'], $first . '%', $c['name_normalized']]
        );
    }

    /** @param array<string,mixed> $input name, notes */
    public function addCustomer(array $input): int
    {
        $this->validateCustomer($input);
        $name = trim(preg_replace('/\s+/', ' ', (string) $input['name']) ?? '');
        $norm = Seeder::normalizeName($name);
        $existing = $this->db->one('SELECT id, name, active FROM chroma_customers WHERE name_normalized = ?', [$norm]);
        if ($existing) {
            throw ValidationException::single('name', sprintf('"%s" is already in the customer list%s. Open it instead of adding it again.', $existing['name'], (int) $existing['active'] ? '' : ' (deactivated)'));
        }
        $now = $this->db->now();
        $id = $this->db->insert('chroma_customers', [
            'name' => mb_substr($name, 0, 200),
            'name_normalized' => mb_substr($norm, 0, 200),
            'cms_customer_id' => null,
            'active' => 1,
            'source' => 'manual',
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record('customer.add', 'chroma_customers', $id, null, $this->customer($id), 'Added customer ' . $name);
        return $id;
    }

    /** Rename or change notes. @param array<string,mixed> $input name, notes */
    public function updateCustomer(int $id, array $input): void
    {
        $before = $this->customer($id);
        if ($before === null) {
            throw ValidationException::general('That customer no longer exists.');
        }
        $this->validateCustomer($input);
        $name = trim(preg_replace('/\s+/', ' ', (string) $input['name']) ?? '');
        $norm = Seeder::normalizeName($name);
        $dup = $this->db->one('SELECT id, name FROM chroma_customers WHERE name_normalized = ? AND id <> ?', [$norm, $id]);
        if ($dup) {
            throw ValidationException::single('name', sprintf('"%s" is already in the customer list. If these are the same company, merge them instead of renaming.', $dup['name']));
        }
        $this->db->update('chroma_customers', [
            'name' => mb_substr($name, 0, 200),
            'name_normalized' => mb_substr($norm, 0, 200),
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            'updated_at' => $this->db->now(),
        ], ['id' => $id]);
        $this->audit->record('customer.update', 'chroma_customers', $id, ['name' => $before['name'], 'notes' => $before['notes']], ['name' => $name, 'notes' => $input['notes'] ?? null], 'Edited customer ' . $before['name']);
    }

    public function setCustomerActive(int $id, bool $active): void
    {
        $before = $this->customer($id);
        if ($before === null) {
            throw ValidationException::general('That customer no longer exists.');
        }
        if ((int) $before['active'] === (int) $active) {
            return;
        }
        $this->db->update('chroma_customers', ['active' => (int) $active, 'updated_at' => $this->db->now()], ['id' => $id]);
        $this->audit->record($active ? 'customer.reactivate' : 'customer.deactivate', 'chroma_customers', $id, ['active' => (int) $before['active']], ['active' => (int) $active], ($active ? 'Reactivated ' : 'Deactivated ') . 'customer ' . $before['name']);
    }

    /**
     * Merge a duplicate into the customer to keep: every project and code that pointed at the duplicate now
     * points at the kept one, the duplicate is deactivated (never deleted) and the move is logged.
     * @return array{projects:int,internal_codes:int,sell_numbers:int}
     */
    public function mergeCustomers(int $keepId, int $duplicateId): array
    {
        if ($keepId === $duplicateId) {
            throw ValidationException::single('duplicate_id', 'Pick a different customer to merge: a customer cannot be merged into itself.');
        }
        $keep = $this->customer($keepId);
        $dup = $this->customer($duplicateId);
        if ($keep === null || $dup === null) {
            throw ValidationException::general('One of those customers no longer exists. Reload the page and try again.');
        }
        if ((int) $keep['active'] !== 1) {
            throw ValidationException::single('keep_id', 'The customer you keep must be active. Reactivate "' . $keep['name'] . '" first, or merge the other way round.');
        }
        return $this->db->transaction(function () use ($keep, $dup, $keepId, $duplicateId) {
            $moved = [
                'projects' => $this->db->exec('UPDATE chroma_projects SET customer_id = ?, updated_at = ? WHERE customer_id = ?', [$keepId, $this->db->now(), $duplicateId]),
                'internal_codes' => $this->db->exec('UPDATE chroma_internal_codes SET original_customer_id = ? WHERE original_customer_id = ?', [$keepId, $duplicateId]),
                'sell_numbers' => $this->db->exec('UPDATE chroma_sell_numbers SET original_customer_id = ? WHERE original_customer_id = ?', [$keepId, $duplicateId]),
            ];
            $keepData = ['updated_at' => $this->db->now()];
            $dupData = ['active' => 0, 'updated_at' => $this->db->now(),
                'notes' => trim((string) ($dup['notes'] ?? '') . "\nMerged into \"" . $keep['name'] . '" on ' . gmdate('Y-m-d') . '.')];
            // The CMS id follows the kept customer when it has none, so the sync keeps matching the right row.
            if ($dup['cms_customer_id'] !== null && $keep['cms_customer_id'] === null) {
                $dupData['cms_customer_id'] = null;
                $this->db->update('chroma_customers', $dupData, ['id' => $duplicateId]);
                $keepData['cms_customer_id'] = $dup['cms_customer_id'];
                if ($keep['source'] === 'manual') {
                    $keepData['source'] = 'cms';
                }
                $this->db->update('chroma_customers', $keepData, ['id' => $keepId]);
            } else {
                $this->db->update('chroma_customers', $dupData, ['id' => $duplicateId]);
                $this->db->update('chroma_customers', $keepData, ['id' => $keepId]);
            }
            $note = sprintf('Merged "%s" into "%s": moved %d projects, %d E numbers, %d sell numbers', $dup['name'], $keep['name'], $moved['projects'], $moved['internal_codes'], $moved['sell_numbers']);
            $this->audit->record('customer.merge', 'chroma_customers', $keepId, ['duplicate' => $dup], ['kept' => $this->customer($keepId), 'moved' => $moved], $note);
            $this->audit->record('customer.merged_away', 'chroma_customers', $duplicateId, $dup, $this->customer($duplicateId), $note);
            return $moved;
        });
    }

    /** @param array<string,mixed> $input */
    private function validateCustomer(array $input): void
    {
        $v = new Validator($input);
        $v->required('name', 'Type the customer\'s name as it should appear on projects.')->maxLength('name', 200);
        $v->custom('name', fn($n) => Seeder::normalizeName((string) $n) !== '', 'The name needs at least one letter or number.');
        $v->fail();
    }
}
