<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/**
 * Admin operations on sell-number prefixes, suffixes and the shared
 * sequences (next E number, next project number).
 *
 * Both the Easy-mode wizard and the Expert-mode forms call these methods;
 * all validation lives here. Prefix and suffix rows are never deleted:
 * a prefix is retired, a suffix is switched off. Counter changes go
 * through CodeAssigner so the same FOR UPDATE locking applies.
 *
 * Permission checks happen in the HTTP layer; this class trusts $actorId.
 */
final class PrefixAdmin
{
    public const CODE_MAX = 12;
    public const MIN_DIGITS_MAX = 9;
    private const STARTING_NUMBER_MESSAGE = 'Enter the number the first code should get, 1 or more. For a fresh prefix this is usually 1 or 1000.';

    private CodeAssigner $codes;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
        $this->codes = new CodeAssigner($db, $audit);
    }

    // ------------------------------------------------------------------
    // Prefixes: reading
    // ------------------------------------------------------------------

    /**
     * One page of prefixes with usage figures. Active prefixes first, then
     * retired; within each group by sort order then code.
     *
     * @param array{q?:string,status?:string} $filters status: all|active|retired
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function listPrefixes(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.code LIKE ? OR p.label LIKE ? OR p.description LIKE ?)';
            $like = str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = '%' . $like;
        }
        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $where[] = 'p.retired = 0';
        } elseif ($status === 'retired') {
            $where[] = 'p.retired = 1';
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes p' . $sql, $params);
        $rows = $this->db->all(
            'SELECT p.*,
                    (SELECT MAX(s.number) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS last_used,
                    (SELECT COUNT(*) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS code_count
             FROM chroma_code_prefixes p' . $sql . '
             ORDER BY p.retired, p.sort_order, p.code
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        $suffixMap = $this->suffixesByPrefix();
        foreach ($rows as &$r) {
            $r = $this->decoratePrefix($r, $suffixMap[(int) $r['id']] ?? []);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Every prefix row, decorated, for exports. @return list<array<string,mixed>> */
    public function allPrefixes(): array
    {
        return $this->listPrefixes([], 10000, 0)['rows'];
    }

    /** One prefix with usage figures and its allowed suffixes, or null. @return array<string,mixed>|null */
    public function prefix(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT p.*,
                    (SELECT MAX(s.number) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS last_used,
                    (SELECT COUNT(*) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS code_count
             FROM chroma_code_prefixes p WHERE p.id = ?',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $suffixes = $this->db->all(
            'SELECT s.id, s.code, s.label, s.active FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE ps.prefix_id = ? ORDER BY s.sort_order, s.code',
            [$id]
        );
        return $this->decoratePrefix($row, $suffixes);
    }

    /** @return array<string,mixed>|null */
    public function prefixByCode(string $code): ?array
    {
        $id = $this->db->value('SELECT id FROM chroma_code_prefixes WHERE code = ?', [strtoupper(trim($code))]);
        return $id === null ? null : $this->prefix((int) $id);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $suffixes
     * @return array<string,mixed>
     */
    private function decoratePrefix(array $row, array $suffixes): array
    {
        $digits = (int) $row['min_digits'];
        $lastUsed = $row['last_used'] === null ? null : (int) $row['last_used'];
        $row['last_used'] = $lastUsed;
        $row['last_used_display'] = $lastUsed === null ? '' : CodeFormat::sell($row['code'], $lastUsed, $digits);
        $row['next_number_effective'] = max(($lastUsed ?? 0) + 1, (int) $row['next_number']);
        $row['next_display'] = CodeFormat::sell($row['code'], $row['next_number_effective'], $digits);
        $row['code_count'] = (int) $row['code_count'];
        $row['retired'] = (int) $row['retired'];
        $row['suffixes'] = $suffixes;
        $row['suffix_ids'] = array_map(fn($s) => (int) $s['id'], $suffixes);
        $row['suffix_codes'] = array_map(fn($s) => (string) $s['code'], $suffixes);
        $row['example'] = CodeFormat::sell($row['code'], $row['next_number_effective'], $digits, $suffixes[0]['code'] ?? null);
        return $row;
    }

    /** @return array<int,list<array<string,mixed>>> prefix id => suffix rows */
    private function suffixesByPrefix(): array
    {
        $map = [];
        $rows = $this->db->all('SELECT ps.prefix_id, s.id, s.code, s.label, s.active FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id ORDER BY s.sort_order, s.code');
        foreach ($rows as $r) {
            $map[(int) $r['prefix_id']][] = ['id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['label'], 'active' => (int) $r['active']];
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // Prefixes: writing
    // ------------------------------------------------------------------

    /**
     * Add a prefix.
     *
     * @param array{code?:string,label?:string,description?:string,min_digits?:int|string,starting_number?:int|string,sort_order?:int|string,suffix_ids?:list<int|string>} $data
     * @return int the new prefix id
     */
    public function createPrefix(array $data, ?int $actorId): int
    {
        $errors = [];
        $code = $this->checkCode((string) ($data['code'] ?? ''), null, $errors);
        $label = $this->checkLabel((string) ($data['label'] ?? ''), $errors);
        $description = $this->checkDescription((string) ($data['description'] ?? ''), $errors);
        $minDigits = $this->checkMinDigits($data['min_digits'] ?? 4, $errors);
        $start = $this->checkWholeNumber($data['starting_number'] ?? 1, 'starting_number', 1, self::STARTING_NUMBER_MESSAGE, $errors);
        $sortOrder = $this->checkWholeNumber($data['sort_order'] ?? 100, 'sort_order', 0, 'Enter a whole number for the position in the list (smaller comes first).', $errors);
        $suffixIds = $this->checkSuffixIds($data['suffix_ids'] ?? [], $errors);
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        return $this->db->transaction(function (Db $db) use ($code, $label, $description, $minDigits, $start, $sortOrder, $suffixIds, $actorId) {
            // Re-check uniqueness under the transaction so two admins cannot add the same code at once.
            if ($db->value('SELECT COUNT(*) FROM chroma_code_prefixes WHERE code = ? FOR UPDATE', [$code])) {
                throw ValidationException::single('code', sprintf('A prefix called %s already exists. Open it from the prefixes list instead.', $code));
            }
            $now = $db->now();
            $id = $db->insert('chroma_code_prefixes', [
                'code' => $code,
                'label' => $label,
                'description' => $description,
                'next_number' => $start,
                'min_digits' => $minDigits,
                'retired' => 0,
                'retired_at' => null,
                'retired_by' => null,
                'sort_order' => $sortOrder,
                'tag' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($suffixIds as $sid) {
                $db->run('INSERT IGNORE INTO chroma_code_prefix_suffixes (prefix_id, suffix_id) VALUES (?, ?)', [$id, $sid]);
            }
            $after = $this->prefix($id);
            $this->audit->record('prefix.create', 'chroma_code_prefixes', $id, null, $this->snapshot($after),
                sprintf('Added prefix %s (%s), first number %s', $code, $label, CodeFormat::sell($code, $start, $minDigits)), $actorId);
            return $id;
        });
    }

    /**
     * Edit a prefix's details and allowed suffixes. The code can only be
     * changed while the prefix has no codes under it. The next number is
     * handled separately by setNextNumber().
     *
     * @param array{code?:string,label?:string,description?:string,min_digits?:int|string,sort_order?:int|string,suffix_ids?:list<int|string>} $data
     */
    public function updatePrefix(int $id, array $data, ?int $actorId): void
    {
        $this->db->transaction(function (Db $db) use ($id, $data, $actorId) {
            $before = $db->one('SELECT * FROM chroma_code_prefixes WHERE id = ? FOR UPDATE', [$id]);
            if ($before === null) {
                throw ValidationException::general('That prefix no longer exists. Go back to the prefixes list.');
            }
            $errors = [];
            $codeCount = (int) $db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE prefix_id = ?', [$id]);
            $code = $before['code'];
            if (array_key_exists('code', $data)) {
                $wanted = strtoupper(trim((string) $data['code']));
                if ($wanted !== $before['code']) {
                    if ($codeCount > 0) {
                        $errors['code'] = sprintf(
                            'The %s prefix cannot be renamed because %s already carr%s it: changing the letters would change those product codes. Leave the code as it is, or retire this prefix and add a new one with the letters you want.',
                            $before['code'], $codeCount === 1 ? 'one sell number' : number_format($codeCount) . ' sell numbers', $codeCount === 1 ? 'ies' : 'y'
                        );
                    } else {
                        $code = $this->checkCode($wanted, $id, $errors);
                    }
                }
            }
            $label = array_key_exists('label', $data) ? $this->checkLabel((string) $data['label'], $errors) : $before['label'];
            $description = array_key_exists('description', $data) ? $this->checkDescription((string) $data['description'], $errors) : $before['description'];
            $minDigits = array_key_exists('min_digits', $data) ? $this->checkMinDigits($data['min_digits'], $errors) : (int) $before['min_digits'];
            $sortOrder = array_key_exists('sort_order', $data) ? $this->checkWholeNumber($data['sort_order'], 'sort_order', 0, 'Enter a whole number for the position in the list (smaller comes first).', $errors) : (int) $before['sort_order'];
            $suffixIds = array_key_exists('suffix_ids', $data) ? $this->checkSuffixIds($data['suffix_ids'], $errors) : null;
            if ($errors) {
                throw new ValidationException($errors, 'Please fix the highlighted fields.');
            }
            $changes = [];
            foreach (['code' => $code, 'label' => $label, 'description' => $description, 'min_digits' => $minDigits, 'sort_order' => $sortOrder] as $col => $val) {
                if ((string) $before[$col] !== (string) $val) {
                    $changes[$col] = $val;
                }
            }
            $oldSuffixIds = array_map('intval', $db->column('SELECT suffix_id FROM chroma_code_prefix_suffixes WHERE prefix_id = ? ORDER BY suffix_id', [$id]));
            if ($suffixIds !== null) {
                // Links to suffixes that are currently switched off are kept no matter what
                // was posted: the edit form only offers switched-on suffixes, and switching a
                // suffix off must never quietly unlink it from the prefixes that allow it.
                $keptInactive = array_map('intval', $db->column(
                    'SELECT ps.suffix_id FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE ps.prefix_id = ? AND s.active = 0',
                    [$id]
                ));
                $suffixIds = array_values(array_unique(array_merge($suffixIds, $keptInactive)));
                sort($suffixIds);
            }
            $suffixChanged = $suffixIds !== null && $suffixIds !== $oldSuffixIds;
            if (!$changes && !$suffixChanged) {
                return;
            }
            if ($changes) {
                $db->update('chroma_code_prefixes', $changes + ['updated_at' => $db->now()], ['id' => $id]);
            }
            if ($suffixChanged) {
                $db->run('DELETE FROM chroma_code_prefix_suffixes WHERE prefix_id = ?', [$id]);
                foreach ($suffixIds as $sid) {
                    $db->run('INSERT IGNORE INTO chroma_code_prefix_suffixes (prefix_id, suffix_id) VALUES (?, ?)', [$id, $sid]);
                }
            }
            $beforeSnap = $this->snapshot($before + ['suffix_ids' => $oldSuffixIds]);
            $afterSnap = $this->snapshot($this->prefix($id));
            $notes = [];
            foreach (array_keys($changes) as $col) {
                $notes[] = str_replace('_', ' ', $col) . ': "' . $before[$col] . '" → "' . $changes[$col] . '"';
            }
            if ($suffixChanged) {
                $codes = $this->suffixCodes($suffixIds);
                $notes[] = 'allowed suffixes: ' . ($codes ? implode(', ', $codes) : 'none');
            }
            $this->audit->record('prefix.update', 'chroma_code_prefixes', $id, $beforeSnap, $afterSnap, sprintf('Edited prefix %s — %s', $before['code'], implode('; ', $notes)), $actorId);
        });
    }

    /** Move the prefix counter forward. Delegates to CodeAssigner (locks the row, refuses used numbers, audits). */
    public function setNextNumber(int $id, mixed $next, ?int $actorId): void
    {
        if ($next === null || $next === '' || !is_numeric($next) || (string) (int) $next !== (string) $next) {
            throw ValidationException::single('next_number', 'Enter the next number as a whole number, without the prefix letters.');
        }
        $this->codes->setPrefixNextNumber($id, (int) $next, $actorId);
    }

    /**
     * Retire a prefix: it leaves the Assign dropdowns but stays in lookup
     * and the logs. Codes already given out are untouched.
     */
    public function retirePrefix(int $id, ?int $actorId): void
    {
        $this->setRetired($id, true, $actorId);
    }

    /** Make a retired prefix assignable again. */
    public function unretirePrefix(int $id, ?int $actorId): void
    {
        $this->setRetired($id, false, $actorId);
    }

    private function setRetired(int $id, bool $retired, ?int $actorId): void
    {
        $this->db->transaction(function (Db $db) use ($id, $retired, $actorId) {
            $before = $db->one('SELECT * FROM chroma_code_prefixes WHERE id = ? FOR UPDATE', [$id]);
            if ($before === null) {
                throw ValidationException::general('That prefix no longer exists. Go back to the prefixes list.');
            }
            if ((int) $before['retired'] === ($retired ? 1 : 0)) {
                throw ValidationException::general(sprintf('The %s prefix is already %s.', $before['code'], $retired ? 'retired' : 'active'));
            }
            $db->update('chroma_code_prefixes', [
                'retired' => $retired ? 1 : 0,
                'retired_at' => $retired ? $db->now() : null,
                'retired_by' => $retired ? $actorId : null,
                'updated_at' => $db->now(),
            ], ['id' => $id]);
            $after = $db->one('SELECT * FROM chroma_code_prefixes WHERE id = ?', [$id]);
            $this->audit->record(
                $retired ? 'prefix.retire' : 'prefix.unretire',
                'chroma_code_prefixes',
                $id,
                $this->snapshot($before),
                $this->snapshot($after ?? []),
                $retired
                    ? sprintf('Retired prefix %s. Existing codes are untouched; no new %s numbers can be given out.', $before['code'], $before['code'])
                    : sprintf('Un-retired prefix %s. New %s numbers can be given out again.', $before['code'], $before['code']),
                $actorId
            );
        });
    }

    // ------------------------------------------------------------------
    // Suffixes
    // ------------------------------------------------------------------

    /**
     * Every suffix with the prefixes that allow it.
     * @param array{q?:string,status?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function listSuffixes(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $where[] = '(s.code LIKE ? OR s.label LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $where[] = 's.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 's.active = 0';
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_suffixes s' . $sql, $params);
        $rows = $this->db->all(
            'SELECT s.*, (SELECT COUNT(*) FROM chroma_sell_numbers n WHERE n.suffix_id = s.id) AS code_count
             FROM chroma_code_suffixes s' . $sql . '
             ORDER BY s.active DESC, s.sort_order, s.code
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        $uses = $this->db->all('SELECT ps.suffix_id, p.id, p.code, p.label, p.retired FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id JOIN chroma_code_prefixes p ON p.id = ps.prefix_id ORDER BY p.retired, p.sort_order, p.code');
        $byRow = [];
        foreach ($uses as $u) {
            $byRow[(int) $u['suffix_id']][] = ['id' => (int) $u['id'], 'code' => $u['code'], 'label' => $u['label'], 'retired' => (int) $u['retired']];
        }
        foreach ($rows as &$r) {
            $r['active'] = (int) $r['active'];
            $r['code_count'] = (int) $r['code_count'];
            $r['prefixes'] = $byRow[(int) $r['id']] ?? [];
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Active suffixes for checkbox lists. @return list<array<string,mixed>> */
    public function activeSuffixes(): array
    {
        return $this->db->all('SELECT id, code, label FROM chroma_code_suffixes WHERE active = 1 ORDER BY sort_order, code');
    }

    /** @return array<string,mixed>|null */
    public function suffix(int $id): ?array
    {
        $row = $this->db->one('SELECT s.*, (SELECT COUNT(*) FROM chroma_sell_numbers n WHERE n.suffix_id = s.id) AS code_count FROM chroma_code_suffixes s WHERE s.id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $row['active'] = (int) $row['active'];
        $row['code_count'] = (int) $row['code_count'];
        $row['prefixes'] = $this->db->all('SELECT p.id, p.code, p.label, p.retired FROM chroma_code_prefix_suffixes ps JOIN chroma_code_prefixes p ON p.id = ps.prefix_id WHERE ps.suffix_id = ? ORDER BY p.retired, p.sort_order, p.code', [$id]);
        return $row;
    }

    /**
     * @param array{code?:string,label?:string,active?:mixed,sort_order?:int|string} $data
     * @return int the new suffix id
     */
    public function createSuffix(array $data, ?int $actorId): int
    {
        $errors = [];
        $code = $this->checkSuffixCode((string) ($data['code'] ?? ''), null, $errors);
        $label = $this->checkLabel((string) ($data['label'] ?? ''), $errors, 'Give the suffix a short label, like "Energy-curable (UV) version".');
        $sortOrder = $this->checkWholeNumber($data['sort_order'] ?? 100, 'sort_order', 0, 'Enter a whole number for the position in the list (smaller comes first).', $errors);
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        $active = !array_key_exists('active', $data) || self::truthy($data['active']);
        return $this->db->transaction(function (Db $db) use ($code, $label, $sortOrder, $active, $actorId) {
            if ($db->value('SELECT COUNT(*) FROM chroma_code_suffixes WHERE code = ? FOR UPDATE', [$code])) {
                throw ValidationException::single('code', sprintf('A suffix called %s already exists. Open it from the suffixes list instead.', $code));
            }
            $now = $db->now();
            $id = $db->insert('chroma_code_suffixes', [
                'code' => $code, 'label' => $label, 'active' => $active ? 1 : 0, 'sort_order' => $sortOrder, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record('suffix.create', 'chroma_code_suffixes', $id, null, $db->one('SELECT * FROM chroma_code_suffixes WHERE id = ?', [$id]), sprintf('Added suffix %s (%s)', $code, $label), $actorId);
            return $id;
        });
    }

    /**
     * Edit a suffix. Like a prefix code, the letters cannot change once a
     * sell number carries the suffix. Switching a suffix off leaves
     * existing codes untouched; it just stops new codes using it.
     *
     * @param array{code?:string,label?:string,active?:mixed,sort_order?:int|string} $data
     */
    public function updateSuffix(int $id, array $data, ?int $actorId): void
    {
        $this->db->transaction(function (Db $db) use ($id, $data, $actorId) {
            $before = $db->one('SELECT * FROM chroma_code_suffixes WHERE id = ? FOR UPDATE', [$id]);
            if ($before === null) {
                throw ValidationException::general('That suffix no longer exists. Go back to the suffixes list.');
            }
            $errors = [];
            $code = $before['code'];
            if (array_key_exists('code', $data)) {
                $wanted = strtoupper(trim((string) $data['code']));
                if ($wanted !== $before['code']) {
                    $count = (int) $db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE suffix_id = ?', [$id]);
                    if ($count > 0) {
                        $errors['code'] = sprintf(
                            'The %s suffix cannot be renamed because %s already end%s with it: changing the letters would change those product codes. Leave the code as it is, or switch this suffix off and add a new one.',
                            $before['code'], $count === 1 ? 'one sell number' : number_format($count) . ' sell numbers', $count === 1 ? 's' : ''
                        );
                    } else {
                        $code = $this->checkSuffixCode($wanted, $id, $errors);
                    }
                }
            }
            $label = array_key_exists('label', $data) ? $this->checkLabel((string) $data['label'], $errors, 'Give the suffix a short label, like "Energy-curable (UV) version".') : $before['label'];
            $sortOrder = array_key_exists('sort_order', $data) ? $this->checkWholeNumber($data['sort_order'], 'sort_order', 0, 'Enter a whole number for the position in the list (smaller comes first).', $errors) : (int) $before['sort_order'];
            $active = array_key_exists('active', $data) ? (self::truthy($data['active']) ? 1 : 0) : (int) $before['active'];
            if ($errors) {
                throw new ValidationException($errors, 'Please fix the highlighted fields.');
            }
            $changes = [];
            foreach (['code' => $code, 'label' => $label, 'sort_order' => $sortOrder, 'active' => $active] as $col => $val) {
                if ((string) $before[$col] !== (string) $val) {
                    $changes[$col] = $val;
                }
            }
            if (!$changes) {
                return;
            }
            $db->update('chroma_code_suffixes', $changes + ['updated_at' => $db->now()], ['id' => $id]);
            $after = $db->one('SELECT * FROM chroma_code_suffixes WHERE id = ?', [$id]);
            if (array_keys($changes) === ['active']) {
                $note = $active ? sprintf('Switched suffix %s on. New codes may use it again.', $before['code']) : sprintf('Switched suffix %s off. Existing codes are untouched; new codes cannot use it.', $before['code']);
                $this->audit->record($active ? 'suffix.activate' : 'suffix.deactivate', 'chroma_code_suffixes', $id, $before, $after, $note, $actorId);
                return;
            }
            $notes = [];
            foreach ($changes as $col => $val) {
                $notes[] = str_replace('_', ' ', $col) . ': "' . $before[$col] . '" → "' . $val . '"';
            }
            $this->audit->record('suffix.update', 'chroma_code_suffixes', $id, $before, $after, sprintf('Edited suffix %s — %s', $before['code'], implode('; ', $notes)), $actorId);
        });
    }

    /** One-click on/off. */
    public function setSuffixActive(int $id, bool $active, ?int $actorId): void
    {
        $this->updateSuffix($id, ['active' => $active], $actorId);
    }

    // ------------------------------------------------------------------
    // Sequences: next E number, next project number
    // ------------------------------------------------------------------

    /**
     * Where both shared counters stand.
     * @return array{internal:array{highest:int,highest_display:string,stored:int,next:int,next_display:string},project:array{highest:int,stored:int,next:int}}
     */
    public function sequenceStatus(): array
    {
        $maxE = (int) ($this->db->value('SELECT MAX(number) FROM chroma_internal_codes') ?? 0);
        $storedE = (int) ($this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code'") ?? 1);
        $nextE = $this->codes->previewInternal();
        $maxP = (int) ($this->db->value('SELECT MAX(project_number) FROM chroma_projects') ?? 0);
        $storedP = (int) ($this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'project_number'") ?? 101);
        return [
            'internal' => ['highest' => $maxE, 'highest_display' => $maxE > 0 ? 'E' . $maxE : '', 'stored' => $storedE, 'next' => $nextE, 'next_display' => 'E' . $nextE],
            'project' => ['highest' => $maxP, 'stored' => $storedP, 'next' => max($maxP + 1, $storedP)],
        ];
    }

    /** Move the E number counter forward (CodeAssigner locks, validates and audits). */
    public function setInternalNext(mixed $next, ?int $actorId): void
    {
        if ($next === null || $next === '' || !is_numeric($next) || (string) (int) $next !== (string) $next) {
            throw ValidationException::single('next_value', 'Enter the next E number as a whole number, without the E.');
        }
        $this->codes->setInternalNext((int) $next, $actorId);
    }

    /** Move the project number counter forward past the highest project so far. */
    public function setProjectNext(mixed $next, ?int $actorId): void
    {
        if ($next === null || $next === '' || !is_numeric($next) || (string) (int) $next !== (string) $next) {
            throw ValidationException::single('project_next', 'Enter the next project number as a whole number.');
        }
        $next = (int) $next;
        $this->db->transaction(function (Db $db) use ($next, $actorId) {
            $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'project_number' FOR UPDATE");
            if ($seq === null) {
                $db->run("INSERT INTO chroma_sequences (name, next_value, updated_at) VALUES ('project_number', 101, UTC_TIMESTAMP())");
                $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'project_number' FOR UPDATE");
            }
            $current = (int) ($seq['next_value'] ?? 101);
            $max = (int) ($db->value('SELECT MAX(project_number) FROM chroma_projects') ?? 0);
            if ($next < 1) {
                throw ValidationException::single('project_next', 'Enter a number of 1 or more.');
            }
            if ($next <= $max) {
                throw ValidationException::single('project_next', sprintf('The highest project number already used is %d, so the next one must be at least %d. Project numbers are never reused.', $max, $max + 1));
            }
            if ($next === $current) {
                return;
            }
            $db->run("UPDATE chroma_sequences SET next_value = ?, updated_at = UTC_TIMESTAMP() WHERE name = 'project_number'", [$next]);
            $this->audit->record('sequence.set_project', 'chroma_sequences', null, ['next_value' => $current], ['next_value' => $next], "Next project number set to $next (was $current)", $actorId);
        });
    }

    // ------------------------------------------------------------------
    // Validation helpers (plain-language messages)
    // ------------------------------------------------------------------

    /** Early check for a proposed prefix code (used by the wizard's first step). Null when it is fine. */
    public function codeProblem(string $code, ?int $exceptId = null): ?string
    {
        $errors = [];
        $this->checkCode($code, $exceptId, $errors);
        return $errors['code'] ?? null;
    }

    /**
     * Early check of the number-format answers (used by the wizard's format
     * step so Easy and Expert mode give the same words). Empty when fine.
     * @return array<string,string> field => message
     */
    public function formatProblems(mixed $minDigits, mixed $startingNumber): array
    {
        $errors = [];
        $this->checkMinDigits($minDigits, $errors);
        $this->checkWholeNumber($startingNumber, 'starting_number', 1, self::STARTING_NUMBER_MESSAGE, $errors);
        return $errors;
    }

    /** Normalize and check a prefix code. Returns the uppercased code; records problems in $errors. @param array<string,string> $errors */
    private function checkCode(string $code, ?int $exceptId, array &$errors): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            $errors['code'] = 'Enter the letters that start every sell number under this prefix, like Y or BK.';
            return $code;
        }
        if (!preg_match('/^[A-Z]+$/', $code)) {
            $errors['code'] = 'A prefix is letters only (A to Z), with no digits, spaces or punctuation. The number and any suffix are added after it.';
            return $code;
        }
        if (strlen($code) > self::CODE_MAX) {
            $errors['code'] = sprintf('Keep the prefix to %d letters or fewer.', self::CODE_MAX);
            return $code;
        }
        $existing = $this->db->one('SELECT id, retired FROM chroma_code_prefixes WHERE code = ?', [$code]);
        if ($existing && (int) $existing['id'] !== $exceptId) {
            $errors['code'] = (int) $existing['retired'] === 1
                ? sprintf('A prefix called %s already exists but is retired. Un-retire it from the prefixes list instead of adding it again.', $code)
                : sprintf('A prefix called %s already exists. Open it from the prefixes list instead.', $code);
        }
        return $code;
    }

    /** @param array<string,string> $errors */
    private function checkSuffixCode(string $code, ?int $exceptId, array &$errors): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            $errors['code'] = 'Enter the letters that go on the end of the sell number, like UV.';
            return $code;
        }
        if (!preg_match('/^[A-Z]+$/', $code)) {
            $errors['code'] = 'A suffix is letters only (A to Z), with no digits, spaces or punctuation.';
            return $code;
        }
        if (strlen($code) > self::CODE_MAX) {
            $errors['code'] = sprintf('Keep the suffix to %d letters or fewer.', self::CODE_MAX);
            return $code;
        }
        $existing = $this->db->one('SELECT id, active FROM chroma_code_suffixes WHERE code = ?', [$code]);
        if ($existing && (int) $existing['id'] !== $exceptId) {
            $errors['code'] = (int) $existing['active'] === 0
                ? sprintf('A suffix called %s already exists but is switched off. Switch it back on from the suffixes list instead of adding it again.', $code)
                : sprintf('A suffix called %s already exists. Open it from the suffixes list instead.', $code);
        }
        return $code;
    }

    /** @param array<string,string> $errors */
    private function checkLabel(string $label, array &$errors, ?string $emptyMessage = null): string
    {
        $label = trim($label);
        if ($label === '') {
            $errors['label'] = $emptyMessage ?? 'Give the prefix a short name people will recognise, like "Yellow" or "Security ink".';
        } elseif (mb_strlen($label) > 100) {
            $errors['label'] = 'Keep the name to 100 characters or fewer.';
        }
        return $label;
    }

    /** @param array<string,string> $errors */
    private function checkDescription(string $description, array &$errors): string
    {
        $description = trim($description);
        if (mb_strlen($description) > 255) {
            $errors['description'] = 'Keep the description to one line, 255 characters or fewer.';
        }
        return $description;
    }

    /** @param array<string,string> $errors */
    private function checkMinDigits(mixed $v, array &$errors): int
    {
        if ($v === null || $v === '' || !is_numeric($v) || (string) (int) $v !== (string) $v || (int) $v < 1 || (int) $v > self::MIN_DIGITS_MAX) {
            $errors['min_digits'] = sprintf('Enter how many digits the number is padded to, from 1 to %d. Color prefixes use 4 (Y0001); security ink uses 6 (X104043).', self::MIN_DIGITS_MAX);
            return 4;
        }
        return (int) $v;
    }

    /** @param array<string,string> $errors */
    private function checkWholeNumber(mixed $v, string $field, int $min, string $message, array &$errors): int
    {
        if ($v === null || $v === '' || !is_numeric($v) || (string) (int) $v !== (string) $v || (int) $v < $min) {
            $errors[$field] = $message;
            return $min;
        }
        return (int) $v;
    }

    /**
     * Keep only ids of suffixes that exist. Unknown ids are refused rather
     * than silently dropped so a stale form cannot quietly change the list.
     * @param mixed $ids
     * @param array<string,string> $errors
     * @return list<int>
     */
    private function checkSuffixIds(mixed $ids, array &$errors): array
    {
        if (!is_array($ids)) {
            $ids = [];
        }
        $clean = [];
        foreach ($ids as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $clean[(int) $v] = true;
            }
        }
        $clean = array_keys($clean);
        sort($clean);
        if ($clean) {
            $known = array_map('intval', $this->db->column('SELECT id FROM chroma_code_suffixes WHERE id IN (' . implode(',', array_fill(0, count($clean), '?')) . ')', $clean));
            $missing = array_diff($clean, $known);
            if ($missing) {
                $errors['suffix_ids'] = 'One of the ticked suffixes no longer exists. Reload the page and tick the suffixes again.';
            }
        }
        return $clean;
    }

    /** @param list<int> $ids @return list<string> */
    private function suffixCodes(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return array_map('strval', $this->db->column('SELECT code FROM chroma_code_suffixes WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order, code', $ids));
    }

    /** Audit snapshot of the columns an admin can change. @param array<string,mixed>|null $row @return array<string,mixed> */
    private function snapshot(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        $out = [];
        foreach (['code', 'label', 'description', 'next_number', 'min_digits', 'retired', 'retired_at', 'retired_by', 'sort_order', 'tag'] as $k) {
            if (array_key_exists($k, $row)) {
                $out[$k] = $row[$k];
            }
        }
        if (isset($row['suffix_ids'])) {
            $out['suffix_ids'] = array_values($row['suffix_ids']);
        }
        return $out;
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }
}
