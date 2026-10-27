<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/**
 * Everything the detail page of one code needs, plus the small edits that
 * happen there (notes, description). Voiding and linking stay in
 * CodeAssigner; this class only reads and records the light edits.
 */
final class CodeDetails
{
    public const ACTIONS = [
        'code.assign_internal' => 'Assigned',
        'code.assign_sell' => 'Assigned',
        'code.manual_internal' => 'Entered by hand',
        'code.manual_sell' => 'Entered by hand',
        'code.void_internal' => 'Voided',
        'code.void_sell' => 'Voided',
        'code.link' => 'Link changed',
        'code.notes' => 'Notes changed',
        'code.description' => 'Description changed',
        'code.import_internal' => 'Imported',
        'code.import_sell' => 'Imported',
        'import.fill_internal' => 'Filled in by the import',
        'import.fill_sell' => 'Filled in by the import',
    ];

    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
    }

    /**
     * An E number with its sell numbers, project origin and history.
     * @return array<string,mixed>|null
     */
    public function internal(int $id, ?string $sdsBaseUrl = null): ?array
    {
        $search = new CodeSearch($this->db);
        $rows = $search->internalRows([$id]);
        $code = $rows[$id] ?? null;
        if ($code === null) {
            return null;
        }
        $sells = $search->sellRows('s.internal_code_id = ?', [$id]);
        $sellIds = array_map(fn($s) => (int) $s['id'], $sells);
        $origins = $search->origins([$id], $sellIds);
        $history = $this->audit->forRow('chroma_internal_codes', $id);
        if ($sellIds !== []) {
            $linkRows = $this->db->all(
                'SELECT * FROM chroma_audit_log WHERE table_name = ? AND row_id IN (' . implode(',', $sellIds) . ") AND action IN ('code.link', 'code.assign_sell', 'code.manual_sell', 'code.import_sell', 'code.void_sell') ORDER BY id DESC LIMIT 100",
                ['chroma_sell_numbers']
            );
            $history = array_merge($history, $linkRows);
            usort($history, fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        }
        return [
            'code' => $code,
            'sells' => $sells,
            'origins' => $origins,
            'history' => array_map([self::class, 'historyRow'], $history),
            'sds_url' => self::sdsUrl($sdsBaseUrl, (int) $code['number']),
        ];
    }

    /**
     * A sell number with its E number, sibling sell numbers, origin and history.
     * @return array<string,mixed>|null
     */
    public function sell(int $id): ?array
    {
        $search = new CodeSearch($this->db);
        $rows = $search->sellRows('s.id = ?', [$id]);
        $code = $rows[0] ?? null;
        if ($code === null) {
            return null;
        }
        $internal = null;
        $siblings = [];
        if ($code['internal_code_id']) {
            $internal = $search->internalRows([(int) $code['internal_code_id']])[(int) $code['internal_code_id']] ?? null;
            $siblings = array_values(array_filter(
                $search->sellRows('s.internal_code_id = ?', [(int) $code['internal_code_id']]),
                fn($s) => (int) $s['id'] !== $id
            ));
        }
        return [
            'code' => $code,
            'internal' => $internal,
            'siblings' => $siblings,
            'origins' => $search->origins([], [$id]),
            'history' => array_map([self::class, 'historyRow'], $this->audit->forRow('chroma_sell_numbers', $id)),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function historyRow(array $row): array
    {
        $row['label'] = self::ACTIONS[$row['action']] ?? ucfirst(str_replace(['code.', '_'], ['', ' '], (string) $row['action']));
        return $row;
    }

    /**
     * The SDS system is keyed by base E number: base URL + "/E9102".
     * A base URL containing {code} gets the E number substituted instead.
     */
    public static function sdsUrl(?string $base, int $number): ?string
    {
        $base = trim((string) $base);
        if ($base === '' || $number <= 0) {
            return null;
        }
        $code = 'E' . $number;
        // Admin → System documents {E} as the placeholder; {code} is accepted too.
        if (str_contains($base, '{E}') || str_contains($base, '{code}')) {
            return str_replace(['{E}', '{code}'], rawurlencode($code), $base);
        }
        return rtrim($base, '/') . '/' . rawurlencode($code);
    }

    // ------------------------------------------------------------------
    // Light edits (audited)
    // ------------------------------------------------------------------

    /** @param 'internal'|'sell' $type */
    public function updateNotes(string $type, int $id, ?string $notes, ?int $actorId): void
    {
        $table = self::table($type);
        $notes = trim((string) $notes);
        $notes = $notes === '' ? null : mb_substr($notes, 0, 65000);
        $this->db->transaction(function (Db $db) use ($table, $id, $notes, $actorId, $type) {
            $before = $db->one("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$id]);
            if ($before === null) {
                throw ValidationException::general($type === 'internal' ? 'That E number no longer exists.' : 'That sell number no longer exists.');
            }
            if ((string) $before['notes'] === (string) $notes) {
                return;
            }
            $db->update($table, ['notes' => $notes, 'updated_at' => $db->now()], ['id' => $id]);
            $this->audit->record('code.notes', $table, $id, ['notes' => $before['notes']], ['notes' => $notes], 'Notes changed on ' . self::display($before, $type), $actorId);
        });
    }

    /** @param 'internal'|'sell' $type */
    public function updateDescription(string $type, int $id, string $description, ?int $actorId): void
    {
        $table = self::table($type);
        $description = trim($description);
        if ($description === '') {
            throw ValidationException::single('description', 'The description cannot be empty. Say what the product is so people can find it.');
        }
        if (mb_strlen($description) > 500) {
            throw ValidationException::single('description', 'Keep the description to 500 characters or fewer.');
        }
        $this->db->transaction(function (Db $db) use ($table, $id, $description, $actorId, $type) {
            $before = $db->one("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$id]);
            if ($before === null) {
                throw ValidationException::general($type === 'internal' ? 'That E number no longer exists.' : 'That sell number no longer exists.');
            }
            if ((string) $before['description'] === $description) {
                return;
            }
            $db->update($table, ['description' => $description, 'updated_at' => $db->now()], ['id' => $id]);
            $this->audit->record('code.description', $table, $id, ['description' => $before['description']], ['description' => $description],
                'Description of ' . self::display($before, $type) . ' changed from "' . mb_substr((string) $before['description'], 0, 120) . '" to "' . mb_substr($description, 0, 120) . '"', $actorId);
        });
    }

    private static function table(string $type): string
    {
        return match ($type) {
            'internal' => 'chroma_internal_codes',
            'sell' => 'chroma_sell_numbers',
            default => throw new \InvalidArgumentException('Unknown code type ' . $type),
        };
    }

    /** @param array<string,mixed> $row */
    private function display(array $row, string $type): string
    {
        if ($type === 'internal') {
            return CodeFormat::internal((int) $row['number'], $row['version']);
        }
        return (string) $row['sell_number_normalized'];
    }
}
