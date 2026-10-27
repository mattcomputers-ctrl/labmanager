<?php
declare(strict_types=1);

namespace Chroma\Domain\Projects;

use Chroma\Core\Db;
use Chroma\Core\Settings;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\ProjectTypes\ProjectTypes;

/**
 * Project items: a color, a trial, a sample or an existing product under
 * investigation (chroma_project_items). Items get their defaults from the
 * project (ink series, substrate, coating, tech) and can override them.
 * Codes are never written here directly: assignment goes through
 * CodeAssigner with project_item_id, which links the item and writes the
 * activity entry. Items that hold a code are never hard-deleted.
 */
final class Items
{
    private ProjectTypes $types;
    private Activity $activity;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit, private readonly Settings $settings)
    {
        $this->types = new ProjectTypes($db);
        $this->activity = new Activity($db, $audit);
    }

    private function projects(): Projects
    {
        return new Projects($this->db, $this->audit, $this->settings);
    }

    public function maxItems(): int
    {
        return max(1, $this->settings->int('max_items_per_project', 50));
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    private const SELECT = 'SELECT i.*, s.label AS status_label, s.code AS status_code, s.color AS status_color, s.is_done AS status_done, s.is_success AS status_success,
            isr.name AS ink_series_name, co.name AS coating_name, u.display_name AS tech_name,
            ic.number AS internal_number, ic.version AS internal_version, ic.voided AS internal_voided, ic.description AS internal_description,
            sn.number AS sell_number_value, sn.voided AS sell_voided, sn.description AS sell_description, sn.internal_code_id AS sell_internal_code_id,
            px.code AS sell_prefix_code, px.min_digits AS sell_min_digits, sx.code AS sell_suffix_code,
            p.project_number, p.project_type_id, p.status AS project_status, p.customer_id AS project_customer_id, p.title AS project_title
         FROM chroma_project_items i
         JOIN chroma_projects p ON p.id = i.project_id
         LEFT JOIN chroma_item_statuses s ON s.id = i.status_id
         LEFT JOIN chroma_ink_series isr ON isr.id = i.ink_series_id
         LEFT JOIN chroma_coatings co ON co.id = i.coating_id
         LEFT JOIN chroma_users u ON u.id = i.assigned_user_id
         LEFT JOIN chroma_internal_codes ic ON ic.id = i.internal_code_id
         LEFT JOIN chroma_sell_numbers sn ON sn.id = i.sell_number_id
         LEFT JOIN chroma_code_prefixes px ON px.id = sn.prefix_id
         LEFT JOIN chroma_code_suffixes sx ON sx.id = sn.suffix_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one(self::SELECT . ' WHERE i.id = ?', [$id]);
        return $row === null ? null : self::decorate($row);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw ValidationException::general('That color or item no longer exists. It may have been removed or moved to another project.');
        }
        return $row;
    }

    /** All items of a project in order. @return list<array<string,mixed>> */
    public function forProject(int $projectId): array
    {
        return array_map([self::class, 'decorate'], $this->db->all(self::SELECT . ' WHERE i.project_id = ? ORDER BY i.sequence, i.id', [$projectId]));
    }

    /** Add display strings for the codes. @param array<string,mixed> $row @return array<string,mixed> */
    public static function decorate(array $row): array
    {
        $row['internal_display'] = $row['internal_number'] ? CodeFormat::internal((int) $row['internal_number'], $row['internal_version']) : null;
        $row['sell_display'] = $row['sell_number_value'] ? CodeFormat::sell((string) $row['sell_prefix_code'], (int) $row['sell_number_value'], (int) $row['sell_min_digits'], $row['sell_suffix_code']) : null;
        $row['kind_label'] = ProjectTypes::ITEM_KINDS[$row['kind']] ?? ucfirst((string) $row['kind']);
        $row['substrate_display'] = $row['substrate_text'] ?: null;
        return $row;
    }

    /** Refuse changes when the item's project is completed or cancelled. @param array<string,mixed> $item */
    private static function assertItemOpen(array $item, string $what): void
    {
        Projects::assertOpen(['status' => $item['project_status'], 'project_number' => $item['project_number']], $what);
    }

    public function count(int $projectId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_items WHERE project_id = ?', [$projectId]);
    }

    /** Statuses available for the items of a project. @return list<array<string,mixed>> */
    public function statusesFor(int $projectTypeId): array
    {
        return $this->types->itemStatuses($projectTypeId);
    }

    // ------------------------------------------------------------------
    // Adding
    // ------------------------------------------------------------------

    /**
     * Add one item to a project (fills down the project's defaults).
     * @param array<string,mixed> $data description, kind, pantone, hex_color, ink_series_id, substrate (text) / substrate_id, proofs_generated,
     *        proof_count, coating_id, is_digital_match, assigned_user_id, date_started, date_completed, notes, status_id,
     *        existing_ref ("E12" / "S34" from the code picker) or internal_code_id / sell_number_id (existing codes to link).
     * @return array<string,mixed> the new item
     */
    public function add(int $projectId, array $data, ?int $actorId): array
    {
        $project = $this->projects()->get($projectId);
        $type = $this->types->byId((int) $project['project_type_id']);
        if ($type === null) {
            throw ValidationException::general('This project has a type that no longer exists.');
        }
        Projects::assertOpen($project, 'add to it');
        $errors = [];
        $rows = $this->normalizeMany([$data], $type, $project, $this->maxItems(), $errors, $actorId, $this->count($projectId));
        if ($errors) {
            throw new ValidationException(self::stripIndex($errors), 'Please fix the highlighted fields.');
        }
        if ($rows === []) {
            throw ValidationException::single('description', 'Describe the ' . mb_strtolower((string) $type['item_label_singular']) . ' first, for example "Pantone 300 C" or "Trial 3: 2% more wax".');
        }
        return $this->db->transaction(function () use ($projectId, $rows, $actorId, $type) {
            $ids = $this->insertNormalized($projectId, $rows, $actorId);
            $item = $this->get($ids[0]);
            $this->activity->system($projectId, 'item_added', mb_convert_case((string) $type['item_label_singular'], MB_CASE_TITLE) . ' added: ' . $item['description'], $actorId, (int) $item['id']);
            return $item;
        });
    }

    /**
     * Validate a list of raw item arrays (from the Expert grid or the wizard loop). Blank rows are skipped.
     * Errors are keyed items.N.field (N = position in the submitted list).
     * @param list<array<string,mixed>> $items @param array<string,mixed> $type @param array<string,mixed> $projectDefaults @param array<string,string> $errors
     * @return list<array<string,mixed>> rows ready for insertNormalized()
     */
    public function normalizeMany(array $items, array $type, array $projectDefaults, int $cap, array &$errors, ?int $actorId, int $existingCount = 0): array
    {
        $out = [];
        foreach (array_values($items) as $i => $raw) {
            if (!is_array($raw) || self::isBlank($raw)) {
                continue;
            }
            $row = $this->normalizeOne($raw, $type, $projectDefaults, $errors, 'items.' . $i . '.', $actorId);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        $label = mb_strtolower((string) ($type['item_label'] ?? 'items'));
        if ($existingCount + count($out) > $cap) {
            $room = max(0, $cap - $existingCount);
            $errors['items'] = sprintf('A project can hold at most %d %s. This one has room for %d more; start another project for the rest.', $cap, $label, $room);
        }
        return $out;
    }

    /** @param array<string,mixed> $raw */
    private static function isBlank(array $raw): bool
    {
        foreach (['description', 'pantone', 'existing_ref', 'internal_code_id', 'sell_number_id', 'notes'] as $k) {
            if (isset($raw[$k]) && trim((string) $raw[$k]) !== '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,string> $errors @return array<string,string> */
    private static function stripIndex(array $errors): array
    {
        $out = [];
        foreach ($errors as $k => $v) {
            $out[preg_replace('/^items\.\d+\./', '', $k) ?? $k] = $v;
        }
        return $out;
    }

    /**
     * Validate one item. Returns the column array (without project_id/sequence) or null when invalid.
     * @param array<string,mixed> $raw @param array<string,mixed> $type @param array<string,mixed> $defaults @param array<string,string> $errors
     * @return array<string,mixed>|null
     */
    public function normalizeOne(array $raw, array $type, array $defaults, array &$errors, string $prefix = '', ?int $actorId = null, ?array $existing = null): ?array
    {
        $isUpdate = $existing !== null;
        $has = fn(string $k) => array_key_exists($k, $raw);
        $t = fn(string $k) => trim((string) ($raw[$k] ?? ''));
        $intOrNull = fn(string $k) => isset($raw[$k]) && $raw[$k] !== '' && (int) $raw[$k] > 0 ? (int) $raw[$k] : null;
        $before = count($errors);
        $out = [];

        if (!$isUpdate || $has('kind')) {
            $kind = $t('kind') !== '' ? $t('kind') : ($existing['kind'] ?? $type['default_item_kind'] ?? 'color');
            if (!isset(ProjectTypes::ITEM_KINDS[$kind])) {
                $errors[$prefix . 'kind'] = 'Pick what kind of item this is from the list.';
            }
            $out['kind'] = $kind;
        }
        $kindNow = $out['kind'] ?? $existing['kind'] ?? 'color';

        // Existing product: link codes that already exist (never assign here).
        $internalId = null;
        $sellId = null;
        if ($has('existing_ref') && $t('existing_ref') !== '') {
            $ref = strtoupper($t('existing_ref'));
            if (preg_match('/^E(\d+)$/', $ref, $m)) {
                $internalId = (int) $m[1];
            } elseif (preg_match('/^S(\d+)$/', $ref, $m)) {
                $sellId = (int) $m[1];
            } else {
                $errors[$prefix . 'existing_ref'] = 'Pick the existing E number or sell number from the list that appears as you type.';
            }
        }
        if ($has('internal_code_id') && $intOrNull('internal_code_id')) {
            $internalId = $intOrNull('internal_code_id');
        }
        if ($has('sell_number_id') && $intOrNull('sell_number_id')) {
            $sellId = $intOrNull('sell_number_id');
        }
        // An item that already holds a code keeps it: codes are voided or re-linked from the code page, never swapped here.
        if ($isUpdate && $internalId !== null && $existing['internal_code_id'] !== null && (int) $existing['internal_code_id'] !== $internalId) {
            $errors[$prefix . 'existing_ref'] = sprintf('"%s" already has %s. To change it, void or unlink that E number from its code page first.', $existing['description'], $existing['internal_display'] ?? 'an E number');
            $internalId = null;
        }
        if ($isUpdate && $sellId !== null && $existing['sell_number_id'] !== null && (int) $existing['sell_number_id'] !== $sellId) {
            $errors[$prefix . 'existing_ref'] = sprintf('"%s" already has sell number %s. To change it, void or unlink that sell number from its code page first.', $existing['description'], $existing['sell_display'] ?? '');
            $sellId = null;
        }
        $linkedDescription = null;
        if ($sellId !== null) {
            $sn = $this->db->one('SELECT id, internal_code_id, description FROM chroma_sell_numbers WHERE id = ?', [$sellId]);
            if ($sn === null) {
                $errors[$prefix . 'existing_ref'] = 'That sell number does not exist. Search for it again.';
            } else {
                $out['sell_number_id'] = (int) $sn['id'];
                $linkedDescription = (string) $sn['description'];
                if ($internalId === null && $sn['internal_code_id'] && !($isUpdate && $existing['internal_code_id'] !== null && (int) $existing['internal_code_id'] !== (int) $sn['internal_code_id'])) {
                    $internalId = (int) $sn['internal_code_id'];
                }
            }
        }
        if ($internalId !== null) {
            $ic = $this->db->one('SELECT id, description FROM chroma_internal_codes WHERE id = ?', [$internalId]);
            if ($ic === null) {
                $errors[$prefix . 'existing_ref'] = 'That E number does not exist. Search for it again.';
            } else {
                $out['internal_code_id'] = (int) $ic['id'];
                $linkedDescription = $linkedDescription ?? (string) $ic['description'];
            }
        }
        if (($internalId !== null || $sellId !== null) && !$isUpdate && $t('kind') === '') {
            $out['kind'] = 'existing_product';
            $kindNow = 'existing_product';
        }

        if (!$isUpdate || $has('description')) {
            $desc = $t('description');
            if ($desc === '' && $linkedDescription !== null) {
                $desc = $linkedDescription;
            }
            if ($desc === '') {
                $errors[$prefix . 'description'] = $kindNow === 'existing_product'
                    ? 'Pick the existing product (E number or sell number) or describe it.'
                    : 'Describe the ' . mb_strtolower((string) ($type['item_label_singular'] ?? 'item')) . ', for example "Pantone 300 C" or "Trial 3: 2% more wax".';
            } elseif (mb_strlen($desc) > 500) {
                $errors[$prefix . 'description'] = 'Keep the description to 500 characters; put the rest in the notes.';
            }
            $out['description'] = mb_substr($desc, 0, 500);
        }
        if (!$isUpdate || $has('pantone')) {
            $out['pantone'] = $t('pantone') === '' ? null : mb_substr($t('pantone'), 0, 60);
        }
        if (!$isUpdate || $has('hex_color')) {
            $hex = $t('hex_color');
            if ($hex !== '' && !str_starts_with($hex, '#')) {
                $hex = '#' . $hex;
            }
            if ($hex !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                $errors[$prefix . 'hex_color'] = 'Enter the color as six hex digits, like #1F6FEB, or leave it blank.';
            }
            $out['hex_color'] = $hex === '' ? null : strtoupper($hex);
        }
        foreach (['ink_series_id' => 'chroma_ink_series', 'coating_id' => 'chroma_coatings'] as $col => $table) {
            if ($isUpdate && !$has($col)) {
                continue;
            }
            if (!$isUpdate && !$has($col)) {
                $out[$col] = $defaults['default_' . $col] ?? null; // fill down from the project
                continue;
            }
            $id = $intOrNull($col);
            if ($id !== null && !$this->db->value("SELECT COUNT(*) FROM $table WHERE id = ?", [$id])) {
                $errors[$prefix . $col] = 'That is not in the list any more. Pick one from the list or leave it blank.';
            }
            $out[$col] = $id;
        }
        if (!$isUpdate && !$has('substrate') && !$has('substrate_text') && !$has('substrate_id')) {
            $out['substrate_id'] = $defaults['default_substrate_id'] ?? null;
            $out['substrate_text'] = $defaults['default_substrate_text'] ?? null;
        } elseif ($has('substrate') || $has('substrate_text') || $has('substrate_id')) {
            $text = $t('substrate') !== '' ? $t('substrate') : $t('substrate_text');
            [$sid, $text] = $this->projects()->resolveSubstrate($intOrNull('substrate_id'), $text);
            $out['substrate_id'] = $sid;
            $out['substrate_text'] = $text === '' ? null : mb_substr($text, 0, 200);
        }
        if (!$isUpdate || $has('proofs_generated') || $has('proof_count')) {
            $count = $has('proof_count') && $t('proof_count') !== '' ? $t('proof_count') : null;
            if ($count !== null && (!is_numeric($count) || (int) $count < 0 || (int) $count > 999)) {
                $errors[$prefix . 'proof_count'] = 'Enter how many proofs were made, as a whole number (0 to 999).';
                $count = null;
            }
            $generated = $has('proofs_generated') ? in_array(strtolower($t('proofs_generated')), ['1', 'true', 'on', 'yes'], true) : ($isUpdate ? (int) $existing['proofs_generated'] === 1 : false);
            if ($count !== null && (int) $count > 0) {
                $generated = true;
            }
            $out['proofs_generated'] = $generated ? 1 : 0;
            $out['proof_count'] = $count !== null ? (int) $count : ($isUpdate ? (int) $existing['proof_count'] : ($generated ? 1 : 0));
            if (!$generated && !$has('proof_count')) {
                $out['proof_count'] = 0;
            }
        }
        if (!$isUpdate || $has('is_digital_match')) {
            $out['is_digital_match'] = in_array(strtolower($t('is_digital_match')), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
        }
        if (!$isUpdate && !$has('assigned_user_id')) {
            $out['assigned_user_id'] = isset($defaults['assigned_user_id']) && (int) $defaults['assigned_user_id'] > 0 ? (int) $defaults['assigned_user_id'] : $actorId;
        } elseif ($has('assigned_user_id')) {
            $uid = $intOrNull('assigned_user_id');
            if ($uid !== null && !$this->db->value('SELECT COUNT(*) FROM chroma_users WHERE id = ? AND active = 1', [$uid])) {
                $errors[$prefix . 'assigned_user_id'] = 'That person is not an active user. Pick someone from the list.';
            }
            $out['assigned_user_id'] = $uid;
        }
        foreach (['date_started', 'date_completed'] as $d) {
            if ($isUpdate && !$has($d)) {
                continue;
            }
            $v = $t($d);
            if ($v === '') {
                $out[$d] = null;
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                $errors[$prefix . $d] = 'Enter the date as YYYY-MM-DD, or pick it from the calendar.';
            } else {
                $out[$d] = $v;
            }
        }
        if (!$isUpdate || $has('notes')) {
            $out['notes'] = $t('notes') === '' ? null : mb_substr($t('notes'), 0, 65000);
        }
        if ($has('status_id') && $intOrNull('status_id') !== null) {
            $sid = $intOrNull('status_id');
            $ok = false;
            foreach ($this->types->itemStatuses((int) $type['id']) as $s) {
                if ((int) $s['id'] === $sid) {
                    $ok = true;
                }
            }
            if (!$ok) {
                $errors[$prefix . 'status_id'] = 'That status is not one of the statuses for this kind of project. Pick one from the list.';
            } else {
                $out['status_id'] = $sid;
            }
        } elseif (!$isUpdate) {
            $out['status_id'] = $this->types->defaultItemStatusId((int) $type['id']);
        }
        return count($errors) > $before ? null : $out;
    }

    /**
     * Insert normalized rows (from normalizeMany) at the end of the project. Caller holds the transaction.
     * @param list<array<string,mixed>> $rows @return list<int> new ids
     */
    public function insertNormalized(int $projectId, array $rows, ?int $actorId): array
    {
        $seq = (int) ($this->db->value('SELECT MAX(sequence) FROM chroma_project_items WHERE project_id = ?', [$projectId]) ?? 0);
        $ids = [];
        $now = $this->db->now();
        foreach ($rows as $row) {
            $seq++;
            $id = $this->db->insert('chroma_project_items', $row + [
                'project_id' => $projectId,
                'sequence' => $seq,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[] = $id;
            $this->audit->record('item.add', 'chroma_project_items', $id, null, $row + ['project_id' => $projectId, 'sequence' => $seq], 'Item added to project', $actorId);
        }
        return $ids;
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    /** Change an item's fields (only the keys given). @param array<string,mixed> $data @return array<string,mixed> */
    public function update(int $itemId, array $data, ?int $actorId): array
    {
        $before = $this->get($itemId);
        $type = $this->types->byId((int) $before['project_type_id']);
        if ($type === null) {
            throw ValidationException::general('This project has a type that no longer exists.');
        }
        self::assertItemOpen($before, 'change its ' . mb_strtolower((string) ($type['item_label'] ?? 'items')));
        $errors = [];
        $cols = $this->normalizeOne($data, $type, [], $errors, '', $actorId, $before) ?? [];
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        $changed = [];
        foreach ($cols as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = $v;
            }
        }
        if ($changed === []) {
            return $before;
        }
        return $this->db->transaction(function (Db $db) use ($itemId, $before, $changed, $actorId) {
            $db->update('chroma_project_items', $changed + ['updated_at' => $db->now()], ['id' => $itemId]);
            $after = $this->get($itemId);
            $this->audit->record('item.update', 'chroma_project_items', $itemId, array_intersect_key($before, $changed), array_intersect_key($after, $changed), 'Item changed: ' . implode(', ', array_keys($changed)), $actorId);
            $pid = (int) $before['project_id'];
            if (array_key_exists('proof_count', $changed) || array_key_exists('proofs_generated', $changed)) {
                $this->activity->system($pid, 'proofs_changed', 'Proofs for "' . $after['description'] . '": ' . ((int) $after['proofs_generated'] ? $after['proof_count'] . ' made' : 'none'), $actorId, $itemId);
            }
            if (array_key_exists('is_digital_match', $changed)) {
                $this->activity->system($pid, 'edited', '"' . $after['description'] . '" ' . ((int) $after['is_digital_match'] ? 'marked as a digital match' : 'no longer a digital match'), $actorId, $itemId);
            }
            if (array_key_exists('status_id', $changed)) {
                $this->activity->system($pid, 'status_changed', '"' . $after['description'] . '" is now ' . mb_strtolower((string) $after['status_label']), $actorId, $itemId, ['status_id' => $after['status_id']]);
                $this->projects()->autoComplete($pid, $actorId);
            }
            $others = array_diff(array_keys($changed), ['proof_count', 'proofs_generated', 'is_digital_match', 'status_id']);
            if ($others) {
                $this->activity->system($pid, 'edited', '"' . $after['description'] . '" changed: ' . implode(', ', array_map(fn($k) => str_replace('_', ' ', preg_replace('/_id$/', '', $k) ?? $k), $others)), $actorId, $itemId);
            }
            return $this->get($itemId);
        });
    }

    /** Set the status; completes the project automatically when every item is done. @return array<string,mixed> */
    public function setStatus(int $itemId, int $statusId, ?int $actorId): array
    {
        return $this->update($itemId, ['status_id' => $statusId], $actorId);
    }

    /** Link an existing E number and/or sell number to an item (kind becomes "existing product"). */
    public function linkExisting(int $itemId, string $existingRef, ?int $actorId): array
    {
        $before = $this->get($itemId);
        $after = $this->update($itemId, ['existing_ref' => $existingRef, 'kind' => 'existing_product'], $actorId);
        $codes = array_filter([$after['internal_display'], $after['sell_display']]);
        if ($codes && ($before['internal_code_id'] !== $after['internal_code_id'] || $before['sell_number_id'] !== $after['sell_number_id'])) {
            $this->activity->system((int) $after['project_id'], 'code_linked', 'Existing product ' . implode(' / ', $codes) . ' linked to "' . $after['description'] . '"', $actorId, $itemId);
            $this->audit->record('item.link_code', 'chroma_project_items', $itemId,
                ['internal_code_id' => $before['internal_code_id'], 'sell_number_id' => $before['sell_number_id']],
                ['internal_code_id' => $after['internal_code_id'], 'sell_number_id' => $after['sell_number_id']],
                'Existing product ' . implode(' / ', $codes) . ' linked to item "' . $after['description'] . '" on project ' . $after['project_number'], $actorId);
        }
        return $after;
    }

    /** Move one item up or down, or set the full order with $orderedIds. @param list<int>|null $orderedIds */
    public function reorder(int $projectId, ?array $orderedIds = null, ?int $itemId = null, ?string $direction = null, ?int $actorId = null): void
    {
        Projects::assertOpen($this->projects()->get($projectId), 'reorder its items');
        $ids = array_map('intval', $this->db->column('SELECT id FROM chroma_project_items WHERE project_id = ? ORDER BY sequence, id', [$projectId]));
        if ($orderedIds !== null) {
            $given = array_values(array_unique(array_map('intval', $orderedIds)));
            $new = array_values(array_filter($given, fn($i) => in_array($i, $ids, true)));
            foreach ($ids as $i) {
                if (!in_array($i, $new, true)) {
                    $new[] = $i;
                }
            }
        } elseif ($itemId !== null && $direction !== null) {
            $pos = array_search($itemId, $ids, true);
            if ($pos === false) {
                throw ValidationException::general('That item is not in this project any more.');
            }
            $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
            if ($swap < 0 || $swap >= count($ids)) {
                return;
            }
            $new = $ids;
            [$new[$pos], $new[$swap]] = [$new[$swap], $new[$pos]];
        } else {
            return;
        }
        if ($new === $ids) {
            return;
        }
        $this->db->transaction(function (Db $db) use ($new, $projectId, $ids, $actorId) {
            foreach ($new as $i => $id) {
                $db->update('chroma_project_items', ['sequence' => $i + 1, 'updated_at' => $db->now()], ['id' => $id, 'project_id' => $projectId]);
            }
            $this->audit->record('item.reorder', 'chroma_projects', $projectId, ['order' => $ids], ['order' => $new], 'Items reordered', $actorId);
        });
    }

    /** Move an item to another project (permission checked by the caller; audited; the item keeps its codes). @return array<string,mixed> */
    public function move(int $itemId, int $toProjectId, ?int $actorId): array
    {
        $item = $this->get($itemId);
        $from = $this->projects()->get((int) $item['project_id']);
        $to = $this->projects()->find($toProjectId);
        if ($to === null) {
            throw ValidationException::single('to_project_id', 'Pick the project to move it to from the list.');
        }
        if ((int) $to['id'] === (int) $from['id']) {
            throw ValidationException::single('to_project_id', 'That is the project it is already in. Pick a different one.');
        }
        Projects::assertOpen($from, 'move items out of it');
        if ($to['status'] !== Projects::STATUS_OPEN) {
            throw ValidationException::single('to_project_id', sprintf('Project %d is %s. Pick an open project, or reopen that one first.', (int) $to['project_number'], $to['status']));
        }
        if ($this->count($toProjectId) >= $this->maxItems()) {
            throw ValidationException::single('to_project_id', sprintf('Project %d is full (%d items). Pick another project.', $to['project_number'], $this->maxItems()));
        }
        return $this->db->transaction(function (Db $db) use ($item, $from, $to, $actorId) {
            $seq = (int) ($db->value('SELECT MAX(sequence) FROM chroma_project_items WHERE project_id = ?', [(int) $to['id']]) ?? 0) + 1;
            $toType = $this->types->byId((int) $to['project_type_id']);
            $update = ['project_id' => (int) $to['id'], 'sequence' => $seq, 'updated_at' => $db->now()];
            // Keep the status only if the new project's type uses it.
            $valid = array_map(fn($s) => (int) $s['id'], $toType ? $this->types->itemStatuses((int) $toType['id']) : []);
            if ($item['status_id'] !== null && !in_array((int) $item['status_id'], $valid, true)) {
                $update['status_id'] = $toType ? $this->types->defaultItemStatusId((int) $toType['id']) : null;
            }
            $db->update('chroma_project_items', $update, ['id' => (int) $item['id']]);
            $db->exec('UPDATE chroma_project_attachments SET project_id = ? WHERE item_id = ?', [(int) $to['id'], (int) $item['id']]);
            $after = $this->get((int) $item['id']);
            $this->audit->record('item.move', 'chroma_project_items', (int) $item['id'], ['project_id' => (int) $from['id'], 'sequence' => $item['sequence']], ['project_id' => (int) $to['id'], 'sequence' => $seq],
                sprintf('Item "%s" moved from project %d to project %d', $item['description'], $from['project_number'], $to['project_number']), $actorId);
            $this->activity->system((int) $from['id'], 'item_moved', sprintf('"%s" moved to project %d', $item['description'], $to['project_number']), $actorId);
            $this->activity->system((int) $to['id'], 'item_moved', sprintf('"%s" moved here from project %d', $item['description'], $from['project_number']), $actorId, (int) $item['id']);
            // Re-pack the old project's sequence and check whether it is now complete.
            $ids = array_map('intval', $db->column('SELECT id FROM chroma_project_items WHERE project_id = ? ORDER BY sequence, id', [(int) $from['id']]));
            foreach ($ids as $i => $id) {
                $db->update('chroma_project_items', ['sequence' => $i + 1], ['id' => $id]);
            }
            $this->projects()->autoComplete((int) $from['id'], $actorId);
            return $after;
        });
    }

    /** Remove an item that holds no code. Items with codes are kept (mark them "not needed" instead). */
    public function remove(int $itemId, ?int $actorId): void
    {
        $item = $this->get($itemId);
        self::assertItemOpen($item, 'remove items from it');
        if ($item['internal_code_id'] !== null || $item['sell_number_id'] !== null) {
            throw ValidationException::general(sprintf('"%s" holds %s, so it cannot be removed. Set its status to "Not needed" instead, or void the code from the code page if it was a mistake.',
                $item['description'], implode(' and ', array_filter([$item['internal_display'], $item['sell_display']]))));
        }
        $this->db->transaction(function (Db $db) use ($item, $actorId) {
            $db->exec('UPDATE chroma_project_attachments SET item_id = NULL WHERE item_id = ?', [(int) $item['id']]);
            $db->exec('DELETE FROM chroma_project_items WHERE id = ?', [(int) $item['id']]);
            $this->audit->record('item.remove', 'chroma_project_items', (int) $item['id'], $item, null, 'Item "' . $item['description'] . '" removed', $actorId);
            $this->activity->system((int) $item['project_id'], 'item_removed', '"' . $item['description'] . '" removed', $actorId);
            $ids = array_map('intval', $db->column('SELECT id FROM chroma_project_items WHERE project_id = ? ORDER BY sequence, id', [(int) $item['project_id']]));
            foreach ($ids as $i => $id) {
                $db->update('chroma_project_items', ['sequence' => $i + 1], ['id' => $id]);
            }
        });
    }

    // ------------------------------------------------------------------
    // Codes (always through CodeAssigner)
    // ------------------------------------------------------------------

    /** Reserve the next E number for an item. @param array<string,mixed> $data description, notes, resale … @return array<string,mixed> the code row */
    public function assignInternal(int $itemId, array $data, ?int $actorId, CodeAssigner $codes): array
    {
        $item = $this->get($itemId);
        self::assertItemOpen($item, 'assign codes from it');
        if ($item['internal_code_id'] !== null) {
            throw ValidationException::general(sprintf('"%s" already has %s. If that was a mistake, void it from the code page and then assign a new one.', $item['description'], $item['internal_display']));
        }
        $row = $codes->assignInternal([
            'description' => trim((string) ($data['description'] ?? '')) !== '' ? (string) $data['description'] : (string) $item['description'],
            'resale' => !empty($data['resale']),
            'vendor_name' => $data['vendor_name'] ?? null,
            'vendor_code' => $data['vendor_code'] ?? null,
            'original_customer_id' => $item['project_customer_id'] ? (int) $item['project_customer_id'] : null,
            'notes' => $data['notes'] ?? null,
            'project_item_id' => $itemId,
        ], $actorId);
        // A sell number already on the item gets linked to the new E#.
        if ($item['sell_number_id'] !== null && $item['sell_internal_code_id'] === null) {
            $codes->linkSellToInternal((int) $item['sell_number_id'], (int) $row['id'], $actorId);
        }
        return $row;
    }

    /** Reserve the next sell number for an item. @param array<string,mixed> $data prefix_id, suffix_id, description, reason, reason_text, notes */
    public function assignSell(int $itemId, array $data, ?int $actorId, CodeAssigner $codes): array
    {
        $item = $this->get($itemId);
        self::assertItemOpen($item, 'assign codes from it');
        if ($item['sell_number_id'] !== null) {
            throw ValidationException::general(sprintf('"%s" already has sell number %s. If that was a mistake, void it from the code page and then assign a new one.', $item['description'], $item['sell_display']));
        }
        $row = $codes->assignSell([
            'prefix_id' => (int) ($data['prefix_id'] ?? 0),
            'suffix_id' => isset($data['suffix_id']) && $data['suffix_id'] !== '' ? (int) $data['suffix_id'] : null,
            'description' => trim((string) ($data['description'] ?? '')) !== '' ? (string) $data['description'] : (string) $item['description'],
            'internal_code_id' => $item['internal_code_id'] ? (int) $item['internal_code_id'] : null,
            'original_customer_id' => $item['project_customer_id'] ? (int) $item['project_customer_id'] : null,
            'reason' => $data['reason'] ?? null,
            'reason_text' => $data['reason_text'] ?? null,
            'notes' => $data['notes'] ?? null,
            'project_item_id' => $itemId,
        ], $actorId);
        // Remember the prefix on the project so the next item defaults to it.
        $this->db->update('chroma_projects', ['default_prefix_id' => (int) $data['prefix_id'], 'updated_at' => $this->db->now()], ['id' => (int) $item['project_id']]);
        return $row;
    }

    /**
     * Batch assignment: one review, then one transaction per item (CodeAssigner's). Nothing stops at the first
     * failure; every result and every failure is reported.
     * @param list<array<string,mixed>> $plan each: item_id, internal (bool), sell (bool), prefix_id, suffix_id, description
     * @return array{assigned:list<array{item_id:int,description:string,code:string,type:string,id:int}>,errors:list<array{item_id:int,description:string,message:string}>}
     */
    public function batchAssign(int $projectId, array $plan, ?int $actorId, CodeAssigner $codes): array
    {
        Projects::assertOpen($this->projects()->get($projectId), 'assign codes from it');
        $assigned = [];
        $errors = [];
        $any = false;
        foreach ($plan as $p) {
            $itemId = (int) ($p['item_id'] ?? 0);
            $item = $this->find($itemId);
            if ($item === null || (int) $item['project_id'] !== $projectId) {
                $errors[] = ['item_id' => $itemId, 'description' => '', 'message' => 'That item is not in this project any more.'];
                continue;
            }
            $wantInternal = !empty($p['internal']);
            $wantSell = !empty($p['sell']);
            if (!$wantInternal && !$wantSell) {
                continue;
            }
            $any = true;
            if ($wantInternal) {
                try {
                    $row = $this->assignInternal($itemId, ['description' => $p['description'] ?? ''], $actorId, $codes);
                    $assigned[] = ['item_id' => $itemId, 'description' => (string) $item['description'], 'code' => $row['display'], 'type' => 'internal', 'id' => (int) $row['id']];
                } catch (ValidationException $e) {
                    $errors[] = ['item_id' => $itemId, 'description' => (string) $item['description'], 'message' => 'E number: ' . $e->getMessage()];
                }
            }
            if ($wantSell) {
                try {
                    $row = $this->assignSell($itemId, ['prefix_id' => $p['prefix_id'] ?? 0, 'suffix_id' => $p['suffix_id'] ?? null, 'description' => $p['description'] ?? ''], $actorId, $codes);
                    $assigned[] = ['item_id' => $itemId, 'description' => (string) $item['description'], 'code' => $row['display'], 'type' => 'sell', 'id' => (int) $row['id']];
                } catch (ValidationException $e) {
                    $errors[] = ['item_id' => $itemId, 'description' => (string) $item['description'], 'message' => 'Sell number: ' . $e->getMessage()];
                }
            }
        }
        if (!$any) {
            throw ValidationException::general('Nothing was selected. Tick E number or sell number on at least one item.');
        }
        if ($assigned) {
            $this->audit->record('item.batch_assign', 'chroma_projects', $projectId, null, ['assigned' => $assigned, 'errors' => $errors], count($assigned) . ' code(s) assigned in a batch', $actorId);
        }
        return ['assigned' => $assigned, 'errors' => $errors];
    }
}
