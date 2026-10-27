<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/**
 * The ONLY place that creates rows in chroma_internal_codes and
 * chroma_sell_numbers.
 *
 * Every assignment runs in a transaction that locks the counter row with
 * SELECT … FOR UPDATE (chroma_sequences for E numbers, chroma_code_prefixes
 * for sell numbers). The next number is MAX(number)+1 for that counter or
 * the admin-set next value, whichever is higher. Numbers are never reused:
 * voided rows stay and still count toward MAX.
 *
 * Permission checks happen in the HTTP layer; this class trusts $actorId.
 */
final class CodeAssigner
{
    public const REASONS = [
        'relabel' => 'Relabel of an existing product',
        'customer_code' => 'Customer-specific code',
        'new_version' => 'New version of the formula',
        'resale' => 'Purchased for resale',
        'other' => 'Other',
    ];

    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
    }

    // ------------------------------------------------------------------
    // Previews (no locking; informational only)
    // ------------------------------------------------------------------

    public function previewInternal(): int
    {
        $max = (int) ($this->db->value('SELECT MAX(number) FROM chroma_internal_codes') ?? 0);
        $next = (int) ($this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code'") ?? 1);
        return max($max + 1, $next);
    }

    public function previewSell(int $prefixId): int
    {
        $max = (int) ($this->db->value('SELECT MAX(number) FROM chroma_sell_numbers WHERE prefix_id = ?', [$prefixId]) ?? 0);
        $next = (int) ($this->db->value('SELECT next_number FROM chroma_code_prefixes WHERE id = ?', [$prefixId]) ?? 1);
        return max($max + 1, $next);
    }

    // ------------------------------------------------------------------
    // Assigning
    // ------------------------------------------------------------------

    /**
     * Reserve the next E number.
     *
     * @param array{description?:string,resale?:bool,vendor_name?:?string,vendor_code?:?string,original_customer_id?:?int,notes?:?string,project_item_id?:?int,date_assigned?:?string,legacy_handschy_number?:?string} $data
     * @return array<string,mixed> the new chroma_internal_codes row plus 'display'
     */
    public function assignInternal(array $data, ?int $actorId): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::single('description', 'Give the E number a short description so people can find it later.');
        }
        return $this->db->transaction(function (Db $db) use ($data, $description, $actorId) {
            $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code' FOR UPDATE");
            if ($seq === null) {
                $db->run("INSERT INTO chroma_sequences (name, next_value, updated_at) VALUES ('internal_code', 1, UTC_TIMESTAMP())");
                $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code' FOR UPDATE");
            }
            $max = (int) ($db->value('SELECT MAX(number) FROM chroma_internal_codes') ?? 0);
            $number = max($max + 1, (int) $seq['next_value']);
            $id = $this->insertInternal($db, $number, '', $description, $data, $actorId, 'assigned');
            $db->run("UPDATE chroma_sequences SET next_value = ?, updated_at = UTC_TIMESTAMP() WHERE name = 'internal_code'", [$number + 1]);
            $row = $this->internalRow($id);
            $this->audit->record('code.assign_internal', 'chroma_internal_codes', $id, null, $row, 'Assigned ' . $row['display'], $actorId);
            $this->linkItem($db, $data['project_item_id'] ?? null, 'internal_code_id', $id, $row['display'], $actorId);
            return $row;
        });
    }

    /**
     * Reserve the next sell number under a prefix. Suffixes never start a
     * new sequence; Y1715 and Y1715UV come from the same counter.
     *
     * @param array{prefix_id:int,suffix_id?:?int,description?:string,internal_code_id?:?int,original_customer_id?:?int,reason?:?string,reason_text?:?string,notes?:?string,project_item_id?:?int,date_assigned?:?string} $data
     * @return array<string,mixed> the new chroma_sell_numbers row plus 'display'
     */
    public function assignSell(array $data, ?int $actorId): array
    {
        $prefixId = (int) ($data['prefix_id'] ?? 0);
        $suffixId = isset($data['suffix_id']) && $data['suffix_id'] !== '' && $data['suffix_id'] !== null ? (int) $data['suffix_id'] : null;
        $description = trim((string) ($data['description'] ?? ''));
        if ($prefixId <= 0) {
            throw ValidationException::single('prefix_id', 'Choose a prefix first. The prefix says what kind of product this is.');
        }
        if ($description === '') {
            throw ValidationException::single('description', 'Give the sell number a short description so people can find it later.');
        }
        $this->checkReason($data);
        return $this->db->transaction(function (Db $db) use ($prefixId, $suffixId, $description, $data, $actorId) {
            $prefix = $db->one('SELECT * FROM chroma_code_prefixes WHERE id = ? FOR UPDATE', [$prefixId]);
            if ($prefix === null) {
                throw ValidationException::single('prefix_id', 'That prefix does not exist any more. Pick one from the list.');
            }
            if ((int) $prefix['retired'] === 1) {
                throw ValidationException::single('prefix_id', sprintf('The %s prefix is retired, so no new numbers can be given out under it. Pick another prefix, or ask an administrator to un-retire it.', $prefix['code']));
            }
            $suffix = $this->checkSuffix($db, $prefix, $suffixId);
            $max = (int) ($db->value('SELECT MAX(number) FROM chroma_sell_numbers WHERE prefix_id = ?', [$prefixId]) ?? 0);
            $number = max($max + 1, (int) $prefix['next_number']);
            $this->checkInternalExists($db, $data['internal_code_id'] ?? null);
            $id = $this->insertSell($db, $prefix, $number, $suffix, $description, $data, $actorId, 'assigned');
            $db->update('chroma_code_prefixes', ['next_number' => $number + 1, 'updated_at' => $db->now()], ['id' => $prefixId]);
            $row = $this->sellRow($id);
            $this->audit->record('code.assign_sell', 'chroma_sell_numbers', $id, null, $row, 'Assigned ' . $row['display'], $actorId);
            $this->linkItem($db, $data['project_item_id'] ?? null, 'sell_number_id', $id, $row['display'], $actorId);
            return $row;
        });
    }

    // ------------------------------------------------------------------
    // Manual entry (permission codes.manual_entry, checked by the caller)
    // ------------------------------------------------------------------

    /**
     * Enter a specific E number (legacy items, odd cases). Serialized with
     * normal assignment via the same sequence lock.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function manualInternal(string $code, array $data, ?int $actorId, string $source = 'manual'): array
    {
        $parsed = CodeFormat::parseInternal($code);
        if ($parsed === null) {
            throw ValidationException::single('code', 'Enter the E number as E followed by digits, with an optional version after a dash, like E6374-10.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::single('description', 'Give the E number a short description so people can find it later.');
        }
        return $this->db->transaction(function (Db $db) use ($parsed, $description, $data, $actorId, $source) {
            $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code' FOR UPDATE");
            $existing = $db->one('SELECT id FROM chroma_internal_codes WHERE number = ? AND version = ?', [$parsed['number'], $parsed['version']]);
            if ($existing) {
                $disp = CodeFormat::internal($parsed['number'], $parsed['version']);
                throw ValidationException::single('code', "$disp already exists. Search for it instead, or enter a different number.");
            }
            $id = $this->insertInternal($db, $parsed['number'], $parsed['version'], $description, $data, $actorId, $source);
            $row = $this->internalRow($id);
            $this->audit->record('code.manual_internal', 'chroma_internal_codes', $id, null, $row, 'Entered by hand: ' . $row['display'], $actorId);
            $this->linkItem($db, $data['project_item_id'] ?? null, 'internal_code_id', $id, $row['display'], $actorId);
            return $row;
        });
    }

    /**
     * Enter a specific sell number. The prefix must exist (retired is
     * allowed for legacy items); the suffix, if any, must be a known suffix.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function manualSell(string $code, array $data, ?int $actorId, string $source = 'manual', bool $allowRetired = true): array
    {
        $parsed = CodeFormat::parseSell($code);
        if ($parsed === null) {
            throw ValidationException::single('code', 'Enter the sell number as prefix, number and optional suffix with no spaces, like Y1714UV or X104043.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::single('description', 'Give the sell number a short description so people can find it later.');
        }
        $this->checkReason($data);
        return $this->db->transaction(function (Db $db) use ($parsed, $description, $data, $actorId, $source, $allowRetired, $code) {
            $prefix = $db->one('SELECT * FROM chroma_code_prefixes WHERE code = ? FOR UPDATE', [$parsed['prefix']]);
            if ($prefix === null) {
                throw ValidationException::single('code', sprintf('There is no prefix called %s. Check the spelling, or ask an administrator to add it under Admin → Prefixes.', $parsed['prefix']));
            }
            if (!$allowRetired && (int) $prefix['retired'] === 1) {
                throw ValidationException::single('code', sprintf('The %s prefix is retired. Ask an administrator to un-retire it first.', $prefix['code']));
            }
            $suffix = null;
            if ($parsed['suffix'] !== '') {
                $suffix = $db->one('SELECT * FROM chroma_code_suffixes WHERE code = ?', [$parsed['suffix']]);
                if ($suffix === null) {
                    throw ValidationException::single('code', sprintf('%s is not a known suffix. Known suffixes are managed under Admin → Suffixes.', $parsed['suffix']));
                }
            }
            $existing = $db->one('SELECT s.id, s.internal_code_id FROM chroma_sell_numbers s WHERE s.sell_number_normalized = ?', [$parsed['normalized']]);
            if ($existing) {
                throw ValidationException::single('code', $this->alreadyUsedMessage($db, $parsed['normalized'], (int) $existing['id']));
            }
            $this->checkInternalExists($db, $data['internal_code_id'] ?? null);
            $data['legacy_text'] = $data['legacy_text'] ?? (trim($code) !== $parsed['normalized'] ? trim($code) : null);
            $id = $this->insertSell($db, $prefix, $parsed['number'], $suffix, $description, $data, $actorId, $source);
            if ($parsed['number'] >= (int) $prefix['next_number']) {
                $db->update('chroma_code_prefixes', ['next_number' => $parsed['number'] + 1, 'updated_at' => $db->now()], ['id' => (int) $prefix['id']]);
            }
            $row = $this->sellRow($id);
            $this->audit->record('code.manual_sell', 'chroma_sell_numbers', $id, null, $row, 'Entered by hand: ' . $row['display'], $actorId);
            $this->linkItem($db, $data['project_item_id'] ?? null, 'sell_number_id', $id, $row['display'], $actorId);
            return $row;
        });
    }

    // ------------------------------------------------------------------
    // Void, link
    // ------------------------------------------------------------------

    public function voidInternal(int $id, string $reason, ?int $actorId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'Say why this E number is being voided. The reason is kept in the log.');
        }
        $this->db->transaction(function (Db $db) use ($id, $reason, $actorId) {
            $before = $db->one('SELECT * FROM chroma_internal_codes WHERE id = ? FOR UPDATE', [$id]);
            if ($before === null) {
                throw ValidationException::general('That E number no longer exists.');
            }
            if ((int) $before['voided'] === 1) {
                throw ValidationException::general(CodeFormat::internal((int) $before['number'], $before['version']) . ' is already voided.');
            }
            $db->update('chroma_internal_codes', ['voided' => 1, 'void_reason' => mb_substr($reason, 0, 500), 'voided_at' => $db->now(), 'voided_by' => $actorId, 'updated_at' => $db->now()], ['id' => $id]);
            $after = $db->one('SELECT * FROM chroma_internal_codes WHERE id = ?', [$id]);
            $this->audit->record('code.void_internal', 'chroma_internal_codes', $id, $before, $after, $reason, $actorId);
        });
    }

    public function voidSell(int $id, string $reason, ?int $actorId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'Say why this sell number is being voided. The reason is kept in the log.');
        }
        $this->db->transaction(function (Db $db) use ($id, $reason, $actorId) {
            $before = $db->one('SELECT * FROM chroma_sell_numbers WHERE id = ? FOR UPDATE', [$id]);
            if ($before === null) {
                throw ValidationException::general('That sell number no longer exists.');
            }
            if ((int) $before['voided'] === 1) {
                throw ValidationException::general('That sell number is already voided.');
            }
            $db->update('chroma_sell_numbers', ['voided' => 1, 'void_reason' => mb_substr($reason, 0, 500), 'voided_at' => $db->now(), 'voided_by' => $actorId, 'updated_at' => $db->now()], ['id' => $id]);
            $after = $db->one('SELECT * FROM chroma_sell_numbers WHERE id = ?', [$id]);
            $this->audit->record('code.void_sell', 'chroma_sell_numbers', $id, $before, $after, $reason, $actorId);
        });
    }

    /** Point a sell number at an E number (or clear the link with null). A sell number has at most one E#. */
    public function linkSellToInternal(int $sellId, ?int $internalId, ?int $actorId): void
    {
        $this->db->transaction(function (Db $db) use ($sellId, $internalId, $actorId) {
            $before = $db->one('SELECT * FROM chroma_sell_numbers WHERE id = ? FOR UPDATE', [$sellId]);
            if ($before === null) {
                throw ValidationException::general('That sell number no longer exists.');
            }
            if ($internalId !== null) {
                $this->checkInternalExists($db, $internalId);
            }
            if ((int) $before['internal_code_id'] === (int) $internalId) {
                return;
            }
            $db->update('chroma_sell_numbers', ['internal_code_id' => $internalId, 'updated_at' => $db->now()], ['id' => $sellId]);
            $after = $db->one('SELECT * FROM chroma_sell_numbers WHERE id = ?', [$sellId]);
            $note = $internalId ? 'Linked to ' . $this->internalRow($internalId)['display'] : 'Link to E number removed';
            $this->audit->record('code.link', 'chroma_sell_numbers', $sellId, $before, $after, $note, $actorId);
        });
    }

    // ------------------------------------------------------------------
    // Counters (admin.sequences)
    // ------------------------------------------------------------------

    /** Move a prefix counter forward. Refuses any number that is already used or not past the highest used. */
    public function setPrefixNextNumber(int $prefixId, int $next, ?int $actorId): void
    {
        $this->db->transaction(function (Db $db) use ($prefixId, $next, $actorId) {
            $prefix = $db->one('SELECT * FROM chroma_code_prefixes WHERE id = ? FOR UPDATE', [$prefixId]);
            if ($prefix === null) {
                throw ValidationException::general('That prefix does not exist.');
            }
            $max = (int) ($db->value('SELECT MAX(number) FROM chroma_sell_numbers WHERE prefix_id = ?', [$prefixId]) ?? 0);
            if ($next < 1) {
                throw ValidationException::single('next_number', 'Enter a number of 1 or more.');
            }
            if ($next <= $max) {
                throw ValidationException::single('next_number', sprintf(
                    'The highest %s number already used is %s, so the next number must be at least %d. Numbers are never reused.',
                    $prefix['code'], CodeFormat::sell($prefix['code'], $max, (int) $prefix['min_digits']), $max + 1
                ));
            }
            if ($next === (int) $prefix['next_number']) {
                return;
            }
            $db->update('chroma_code_prefixes', ['next_number' => $next, 'updated_at' => $db->now()], ['id' => $prefixId]);
            $this->audit->record('prefix.set_next', 'chroma_code_prefixes', $prefixId, ['next_number' => (int) $prefix['next_number']], ['next_number' => $next],
                sprintf('Next %s number set to %d (was %d)', $prefix['code'], $next, (int) $prefix['next_number']), $actorId);
        });
    }

    /** Move the E number counter forward past the highest existing one. */
    public function setInternalNext(int $next, ?int $actorId): void
    {
        $this->db->transaction(function (Db $db) use ($next, $actorId) {
            $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code' FOR UPDATE");
            $current = (int) ($seq['next_value'] ?? 1);
            $max = (int) ($db->value('SELECT MAX(number) FROM chroma_internal_codes') ?? 0);
            if ($next <= $max) {
                throw ValidationException::single('next_value', sprintf('The highest E number already used is E%d, so the next one must be at least E%d. Numbers are never reused.', $max, $max + 1));
            }
            if ($next < 1) {
                throw ValidationException::single('next_value', 'Enter a number of 1 or more.');
            }
            if ($next === $current) {
                return;
            }
            $db->run("INSERT INTO chroma_sequences (name, next_value, updated_at) VALUES ('internal_code', ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE next_value = VALUES(next_value), updated_at = UTC_TIMESTAMP()", [$next]);
            $this->audit->record('sequence.set_internal', 'chroma_sequences', null, ['next_value' => $current], ['next_value' => $next], "Next E number set to E$next (was E$current)", $actorId);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function internalRow(int $id): array
    {
        $row = $this->db->one('SELECT * FROM chroma_internal_codes WHERE id = ?', [$id]);
        if ($row === null) {
            throw ValidationException::general('That E number no longer exists.');
        }
        $row['display'] = CodeFormat::internal((int) $row['number'], $row['version']);
        return $row;
    }

    /** @return array<string,mixed> */
    public function sellRow(int $id): array
    {
        $row = $this->db->one(
            'SELECT s.*, p.code AS prefix_code, p.min_digits, x.code AS suffix_code
             FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id
             WHERE s.id = ?',
            [$id]
        );
        if ($row === null) {
            throw ValidationException::general('That sell number no longer exists.');
        }
        $row['display'] = CodeFormat::sell($row['prefix_code'], (int) $row['number'], (int) $row['min_digits'], $row['suffix_code']);
        return $row;
    }

    /** @param array<string,mixed> $data */
    private function checkReason(array $data): void
    {
        $reason = $data['reason'] ?? null;
        if ($reason !== null && $reason !== '' && !isset(self::REASONS[$reason])) {
            throw ValidationException::single('reason', 'Pick a reason from the list.');
        }
        if ($reason === 'other' && trim((string) ($data['reason_text'] ?? '')) === '') {
            throw ValidationException::single('reason_text', 'Say in a few words why this code is needed.');
        }
    }

    /** @param array<string,mixed> $prefix @return array<string,mixed>|null */
    private function checkSuffix(Db $db, array $prefix, ?int $suffixId): ?array
    {
        if ($suffixId === null) {
            return null;
        }
        $suffix = $db->one('SELECT * FROM chroma_code_suffixes WHERE id = ?', [$suffixId]);
        if ($suffix === null) {
            throw ValidationException::single('suffix_id', 'That suffix does not exist any more. Pick one from the list or leave it blank.');
        }
        if ((int) $suffix['active'] !== 1) {
            throw ValidationException::single('suffix_id', sprintf('The %s suffix is switched off. Leave the suffix blank or pick another one.', $suffix['code']));
        }
        $allowed = $db->value('SELECT COUNT(*) FROM chroma_code_prefix_suffixes WHERE prefix_id = ? AND suffix_id = ?', [(int) $prefix['id'], $suffixId]);
        if (!$allowed) {
            throw ValidationException::single('suffix_id', sprintf('The %s prefix does not allow the %s suffix. Leave the suffix blank, or ask an administrator to allow it under Admin → Prefixes.', $prefix['code'], $suffix['code']));
        }
        return $suffix;
    }

    private function checkInternalExists(Db $db, mixed $internalId): void
    {
        if ($internalId === null || $internalId === '' || (int) $internalId === 0) {
            return;
        }
        if ($db->value('SELECT COUNT(*) FROM chroma_internal_codes WHERE id = ?', [(int) $internalId]) == 0) {
            throw ValidationException::single('internal_code_id', 'That E number does not exist. Search for it again.');
        }
    }

    /** @param array<string,mixed> $data */
    private function insertInternal(Db $db, int $number, string $version, string $description, array $data, ?int $actorId, string $source): int
    {
        $now = $db->now();
        return $db->insert('chroma_internal_codes', [
            'number' => $number,
            'version' => $version,
            'description' => mb_substr($description, 0, 500),
            'resale' => !empty($data['resale']) ? 1 : 0,
            'vendor_name' => self::nullable($data['vendor_name'] ?? null, 200),
            'vendor_code' => self::nullable($data['vendor_code'] ?? null, 100),
            'legacy_handschy_number' => self::nullable($data['legacy_handschy_number'] ?? null, 100),
            'legacy_internal_code' => self::nullable($data['legacy_internal_code'] ?? null, 100),
            'date_assigned' => $data['date_assigned'] ?? gmdate('Y-m-d'),
            'assigned_by' => $actorId,
            'original_customer_id' => isset($data['original_customer_id']) && (int) $data['original_customer_id'] > 0 ? (int) $data['original_customer_id'] : null,
            'voided' => 0,
            'source' => $source,
            'notes' => self::nullable($data['notes'] ?? null, 65000),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $prefix @param array<string,mixed>|null $suffix @param array<string,mixed> $data */
    private function insertSell(Db $db, array $prefix, int $number, ?array $suffix, string $description, array $data, ?int $actorId, string $source): int
    {
        $now = $db->now();
        $normalized = $prefix['code'] . $number . ($suffix['code'] ?? '');
        $existing = $db->one('SELECT id FROM chroma_sell_numbers WHERE sell_number_normalized = ?', [$normalized]);
        if ($existing) {
            throw ValidationException::single('prefix_id', $this->alreadyUsedMessage($db, $normalized, (int) $existing['id']));
        }
        return $db->insert('chroma_sell_numbers', [
            'prefix_id' => (int) $prefix['id'],
            'number' => $number,
            'suffix_id' => $suffix ? (int) $suffix['id'] : null,
            'sell_number_normalized' => $normalized,
            'legacy_text' => self::nullable($data['legacy_text'] ?? null, 60),
            'description' => mb_substr($description, 0, 500),
            'internal_code_id' => isset($data['internal_code_id']) && (int) $data['internal_code_id'] > 0 ? (int) $data['internal_code_id'] : null,
            'legacy_internal_code' => self::nullable($data['legacy_internal_code'] ?? null, 100),
            'original_customer_id' => isset($data['original_customer_id']) && (int) $data['original_customer_id'] > 0 ? (int) $data['original_customer_id'] : null,
            'date_assigned' => $data['date_assigned'] ?? gmdate('Y-m-d'),
            'assigned_by' => $actorId,
            'reason' => self::nullable($data['reason'] ?? null, 40),
            'reason_text' => self::nullable($data['reason_text'] ?? null, 500),
            'voided' => 0,
            'source' => $source,
            'notes' => self::nullable($data['notes'] ?? null, 65000),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function alreadyUsedMessage(Db $db, string $normalized, int $sellId): string
    {
        $row = $db->one('SELECT s.description, i.number, i.version FROM chroma_sell_numbers s LEFT JOIN chroma_internal_codes i ON i.id = s.internal_code_id WHERE s.id = ?', [$sellId]);
        $by = $row && $row['number'] ? ' by ' . CodeFormat::internal((int) $row['number'], $row['version']) : ($row && $row['description'] ? ' for "' . $row['description'] . '"' : '');
        return sprintf('%s is already used%s. Pick a different suffix, or search for it.', $normalized, $by);
    }

    private function linkItem(Db $db, mixed $itemId, string $column, int $codeId, string $display, ?int $actorId): void
    {
        if ($itemId === null || (int) $itemId <= 0) {
            return;
        }
        $item = $db->one('SELECT id, project_id FROM chroma_project_items WHERE id = ? FOR UPDATE', [(int) $itemId]);
        if ($item === null) {
            throw ValidationException::general('That color or item no longer exists, so the code was not assigned.');
        }
        $db->update('chroma_project_items', [$column => $codeId, 'updated_at' => $db->now()], ['id' => (int) $item['id']]);
        $db->insert('chroma_project_activity', [
            'project_id' => (int) $item['project_id'],
            'item_id' => (int) $item['id'],
            'user_id' => $actorId,
            'kind' => 'code_assigned',
            'body' => ($column === 'internal_code_id' ? 'E number ' : 'Sell number ') . $display . ' assigned',
            'meta_json' => json_encode([$column => $codeId]),
            'created_at' => $db->now(),
        ]);
    }

    private static function nullable(mixed $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
