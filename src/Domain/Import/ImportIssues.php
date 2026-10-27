<?php
declare(strict_types=1);

namespace Chroma\Domain\Import;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\Customers\Customers;

/**
 * The rows the legacy importer could not place cleanly, and the three ways
 * to resolve one:
 *   correct()  enter the right code — created by hand through CodeAssigner
 *   merge()    fold the row's description and comments into the existing code
 *   discard()  leave it out, with a reason
 * Each marks the issue resolved and is audited.
 */
final class ImportIssues
{
    private CodeAssigner $codes;
    private Customers $customers;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit, ?CodeAssigner $codes = null, ?Customers $customers = null)
    {
        $this->codes = $codes ?? new CodeAssigner($db, $audit);
        $this->customers = $customers ?? new Customers($db);
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /**
     * @param array{status?:string,type?:string,q?:string,import_id?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($filters);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM chroma_import_issues i $where", $params);
        $rows = $this->db->all(
            "SELECT i.*, u.display_name AS resolved_by_name FROM chroma_import_issues i LEFT JOIN chroma_users u ON u.id = i.resolved_by
             $where ORDER BY i.status = 'open' DESC, i.id LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['rows' => array_map([self::class, 'decorate'], $rows), 'total' => $total];
    }

    /** Every matching row (for exports). @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function all(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        return array_map([self::class, 'decorate'], $this->db->all("SELECT i.*, u.display_name AS resolved_by_name FROM chroma_import_issues i LEFT JOIN chroma_users u ON u.id = i.resolved_by $where ORDER BY i.id DESC", $params));
    }

    /** @return array<string,int> type => open count */
    public function openCounts(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT issue_type, COUNT(*) AS n FROM chroma_import_issues WHERE status = 'open' GROUP BY issue_type") as $r) {
            $out[$r['issue_type']] = (int) $r['n'];
        }
        return $out;
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM chroma_import_issues WHERE status = 'open'");
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT i.*, u.display_name AS resolved_by_name FROM chroma_import_issues i LEFT JOIN chroma_users u ON u.id = i.resolved_by WHERE i.id = ?', [$id]);
        return $row ? self::decorate($row) : null;
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private function where(array $filters): array
    {
        $cond = [];
        $params = [];
        $status = $filters['status'] ?? 'open';
        if ($status === 'open' || $status === 'resolved') {
            $cond[] = 'i.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['type'])) {
            $cond[] = 'i.issue_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['import_id'])) {
            $cond[] = 'i.import_id = ?';
            $params[] = (int) $filters['import_id'];
        }
        if (!empty($filters['q'])) {
            $cond[] = '(i.message LIKE ? OR i.raw_json LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }

    /** Decode raw_json and add display helpers. @param array<string,mixed> $row @return array<string,mixed> */
    public static function decorate(array $row): array
    {
        $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
        $row['raw'] = is_array($raw) ? $raw : [];
        $row['type_label'] = ImportPlan::issueTypeLabel((string) $row['issue_type']);
        $row['code'] = (string) ($row['raw']['code'] ?? $row['raw']['sell'] ?? '');
        $row['description'] = (string) ($row['raw']['description'] ?? '');
        $row['internal'] = (string) ($row['raw']['internal'] ?? '');
        return $row;
    }

    // ------------------------------------------------------------------
    // Resolving
    // ------------------------------------------------------------------

    /**
     * The existing sell number a "merge" would fold this row into, if there
     * is one: the kept code of a duplicate, or the row's own sell number when
     * Chroma already holds it (a refused row). Rows whose sell number could
     * not be read have nothing to merge into — their own E number is not a
     * duplicate of anything, so it is never offered.
     * @param array<string,mixed> $issue
     * @return array{kind:string,id:int,display:string,description:string}|null
     */
    public function mergeTarget(array $issue): ?array
    {
        $raw = $issue['raw'] ?? [];
        $candidates = array_filter([(string) ($raw['kept_code'] ?? ''), (string) ($raw['code'] ?? ''), (string) ($raw['sell'] ?? '')]);
        foreach ($candidates as $text) {
            $norm = CodeFormat::normalizeSell($text);
            if ($norm === null) {
                continue;
            }
            $row = $this->db->one('SELECT id, description FROM chroma_sell_numbers WHERE sell_number_normalized = ?', [$norm]);
            if ($row) {
                $r = $this->codes->sellRow((int) $row['id']);
                return ['kind' => 'sell', 'id' => (int) $row['id'], 'display' => $r['display'], 'description' => (string) $r['description']];
            }
        }
        return null;
    }

    /**
     * Resolve by entering the right code. An E number creates an internal
     * code; anything else creates a sell number. Both go through
     * CodeAssigner's manual entry (uniqueness, counters, audit).
     * @return array<string,mixed> the new code row (with 'display')
     */
    public function correct(int $issueId, string $code, ?int $actorId, string $note = ''): array
    {
        $issue = $this->open($issueId);
        $code = trim($code);
        if ($code === '') {
            throw ValidationException::single('code', 'Type the corrected code, for example Y1714UV or E6374-10.');
        }
        $raw = $issue['raw'];
        $description = trim((string) ($raw['description'] ?? ''));
        if ($description === '') {
            $description = LegacyImporter::NO_DESCRIPTION;
        }
        $date = $raw['date'] ?? null;
        $comments = trim((string) ($raw['comments'] ?? ''));
        $notes = trim(($comments !== '' ? $comments . "\n" : '') . 'Entered from legacy import issue #' . $issueId . ($raw['sell'] ?? '' ? ' (sheet said "' . $raw['sell'] . '")' : ''));

        return $this->db->transaction(function () use ($issue, $issueId, $code, $description, $date, $notes, $raw, $actorId, $note) {
            // Inside the transaction, so a refused code does not leave a new customer behind.
            $customerId = $this->customers->findOrCreate((string) ($raw['customer'] ?? ''), LegacyImporter::SOURCE);
            if (CodeFormat::looksInternal($code)) {
                $payload = ['description' => $description, 'resale' => LegacyImporter::yes($raw['resale'] ?? ''), 'vendor_name' => $raw['vendor_name'] ?? null, 'vendor_code' => $raw['vendor_code'] ?? null,
                    'legacy_handschy_number' => $raw['handschy'] ?? null, 'original_customer_id' => $customerId, 'notes' => $notes];
                if ($date) {
                    $payload['date_assigned'] = $date;
                }
                $row = $this->codes->manualInternal($code, $payload, $actorId, LegacyImporter::SOURCE);
            } else {
                $internalId = null;
                $legacyInternal = null;
                $internalText = (string) ($raw['internal'] ?? '');
                if ($internalText !== '') {
                    if (CodeFormat::looksInternal($internalText)) {
                        $p = CodeFormat::parseInternal($internalText);
                        $internalId = $p ? $this->db->value('SELECT id FROM chroma_internal_codes WHERE number = ? AND version = ?', [$p['number'], $p['version']]) : null;
                        $internalId = $internalId === null ? null : (int) $internalId;
                    } else {
                        $legacyInternal = $internalText;
                    }
                }
                // legacy_text is left to CodeAssigner (the spelling typed here, if it differs from the
                // normalized code). The sheet's wrong text is only quoted in the notes, never stored
                // as an alias of the new code where lookups could match it.
                $payload = ['description' => $description, 'internal_code_id' => $internalId, 'legacy_internal_code' => $legacyInternal,
                    'original_customer_id' => $customerId, 'notes' => $notes];
                if ($date) {
                    $payload['date_assigned'] = $date;
                }
                $row = $this->codes->manualSell($code, $payload, $actorId, LegacyImporter::SOURCE, true);
            }
            $this->resolve($issue, 'corrected', trim('Entered as ' . $row['display'] . ($note !== '' ? '. ' . $note : '')), $actorId);
            return $row;
        });
    }

    /**
     * Resolve by folding the row into the existing code: an empty
     * description is filled, otherwise the description and comments are
     * appended to the notes, and a missing E# link is added when the row
     * names an E number that exists.
     */
    public function merge(int $issueId, ?int $actorId, string $note = ''): array
    {
        $issue = $this->open($issueId);
        $target = $this->mergeTarget($issue);
        if ($target === null) {
            throw ValidationException::general('There is no existing code to merge this row into. Enter the corrected code instead, or discard the row.');
        }
        $raw = $issue['raw'];
        return $this->db->transaction(function () use ($issue, $issueId, $target, $raw, $actorId, $note) {
            $table = $target['kind'] === 'sell' ? 'chroma_sell_numbers' : 'chroma_internal_codes';
            $before = $this->db->one("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$target['id']]);
            if ($before === null) {
                throw ValidationException::general('That code no longer exists.');
            }
            $set = [];
            $desc = trim((string) ($raw['description'] ?? ''));
            $comments = trim((string) ($raw['comments'] ?? ''));
            $line = sprintf('From legacy sheet row %s (%s): %s', $raw['row'] ?? '?', trim((string) ($raw['internal'] ?? '') . ' ' . (string) ($raw['sell'] ?? '')) ?: 'no code', $desc !== '' ? $desc : '(no description)');
            if ($comments !== '') {
                $line .= ' — ' . $comments;
            }
            if ($desc !== '' && (trim((string) $before['description']) === '' || str_ends_with((string) $before['description'], LegacyImporter::NO_DESCRIPTION))) {
                $set['description'] = mb_substr($desc, 0, 500);
            }
            $set['notes'] = trim((string) $before['notes'] . "\n" . $line);
            if ($target['kind'] === 'sell' && empty($before['internal_code_id'])) {
                $internalText = (string) ($raw['internal'] ?? '');
                if ($internalText !== '' && CodeFormat::looksInternal($internalText)) {
                    $p = CodeFormat::parseInternal($internalText);
                    $iid = $p ? $this->db->value('SELECT id FROM chroma_internal_codes WHERE number = ? AND version = ?', [$p['number'], $p['version']]) : null;
                    if ($iid !== null) {
                        $this->codes->linkSellToInternal($target['id'], (int) $iid, $actorId);
                    }
                }
            }
            $this->db->update($table, $set + ['updated_at' => $this->db->now()], ['id' => $target['id']]);
            $after = $this->db->one("SELECT * FROM $table WHERE id = ?", [$target['id']]);
            $this->audit->record('import.issue_merge', $table, $target['id'], $before, $after, 'Legacy import issue #' . $issueId . ' merged into ' . $target['display'], $actorId);
            $this->resolve($issue, 'merged', trim('Merged into ' . $target['display'] . ($note !== '' ? '. ' . $note : '')), $actorId);
            return $target;
        });
    }

    /** Resolve by leaving the row out. A reason is required and kept. */
    public function discard(int $issueId, string $reason, ?int $actorId): void
    {
        $issue = $this->open($issueId);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'Say in a few words why this row is being left out. The reason is kept with the issue.');
        }
        $this->resolve($issue, 'discarded', $reason, $actorId);
    }

    /** Put a resolved issue back on the open list (no data change). */
    public function reopen(int $issueId, ?int $actorId): void
    {
        $issue = $this->find($issueId);
        if ($issue === null) {
            throw ValidationException::general('That import issue no longer exists.');
        }
        if ($issue['status'] === 'open') {
            return;
        }
        $this->db->update('chroma_import_issues', ['status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'resolution' => null, 'resolution_note' => null], ['id' => $issueId]);
        $this->audit->record('import.issue_reopen', 'chroma_import_issues', $issueId, ['status' => $issue['status'], 'resolution' => $issue['resolution']], ['status' => 'open'], 'Import issue reopened', $actorId);
    }

    /** @return array<string,mixed> */
    private function open(int $issueId): array
    {
        $issue = $this->find($issueId);
        if ($issue === null) {
            throw ValidationException::general('That import issue no longer exists.');
        }
        if ($issue['status'] !== 'open') {
            throw ValidationException::general(sprintf('This issue was already resolved (%s). Reopen it first if it needs a different answer.', $issue['resolution'] ?? 'resolved'));
        }
        return $issue;
    }

    /** @param array<string,mixed> $issue */
    private function resolve(array $issue, string $resolution, string $note, ?int $actorId): void
    {
        $after = ['status' => 'resolved', 'resolved_by' => $actorId, 'resolved_at' => $this->db->now(), 'resolution' => $resolution, 'resolution_note' => mb_substr($note, 0, 500)];
        $this->db->update('chroma_import_issues', $after, ['id' => (int) $issue['id']]);
        $this->audit->record('import.issue_resolve', 'chroma_import_issues', (int) $issue['id'], ['status' => 'open', 'issue_type' => $issue['issue_type'], 'message' => $issue['message']], $after, ucfirst($resolution) . ': ' . $note, $actorId);
    }
}
