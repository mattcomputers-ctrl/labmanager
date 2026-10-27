<?php
declare(strict_types=1);

namespace Chroma\Domain\Lists;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\ProjectTypes\ProjectTypes;

/**
 * Admin edits to project types, their stages and their item statuses.
 * Reads for the lab UI go through Domain\ProjectTypes\ProjectTypes; this
 * class only writes (and reads what the admin screens need).
 */
final class ProjectTypeAdmin
{
    public const COLORS = ['blue' => 'Blue', 'green' => 'Green', 'red' => 'Red', 'amber' => 'Amber', 'purple' => 'Purple', 'gray' => 'Gray'];

    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
    }

    // ------------------------------------------------------------------ reads

    /** Every type (active and not) with counts. @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM chroma_projects p WHERE p.project_type_id = t.id) AS project_count,
                    (SELECT COUNT(*) FROM chroma_project_stages s WHERE s.project_type_id = t.id AND s.active = 1) AS stage_count,
                    (SELECT COUNT(*) FROM chroma_item_statuses i WHERE i.project_type_id = t.id) AS own_status_count
             FROM chroma_project_types t ORDER BY t.active DESC, t.sort_order, t.label'
        );
        foreach ($rows as &$r) {
            $r['fields'] = json_decode((string) ($r['fields_json'] ?? '[]'), true) ?: [];
        }
        return $rows;
    }

    /** @return array<string,mixed>|null type + fields (list) + stages + statuses (+ uses_global_statuses) */
    public function find(int $id): ?array
    {
        $t = $this->db->one('SELECT * FROM chroma_project_types WHERE id = ?', [$id]);
        if ($t === null) {
            return null;
        }
        $t['fields'] = json_decode((string) ($t['fields_json'] ?? '[]'), true) ?: [];
        $t['project_count'] = (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE project_type_id = ?', [$id]);
        $t['stages'] = $this->stages($id);
        $own = $this->ownStatuses($id);
        $t['uses_global_statuses'] = $own === [];
        $t['statuses'] = $own ?: $this->globalStatuses();
        return $t;
    }

    /** @return list<array<string,mixed>> */
    public function stages(int $typeId): array
    {
        $rows = $this->db->all('SELECT * FROM chroma_project_stages WHERE project_type_id = ? ORDER BY sort_order, id', [$typeId]);
        foreach ($rows as &$r) {
            $r['usage_count'] = (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE stage_id = ?', [(int) $r['id']]);
        }
        return $rows;
    }

    /** Statuses defined for exactly this type (empty when it uses the global list). @return list<array<string,mixed>> */
    public function ownStatuses(int $typeId): array
    {
        return $this->withStatusUsage($this->db->all('SELECT * FROM chroma_item_statuses WHERE project_type_id = ? ORDER BY sort_order, id', [$typeId]));
    }

    /** @return list<array<string,mixed>> */
    public function globalStatuses(): array
    {
        return $this->withStatusUsage($this->db->all('SELECT * FROM chroma_item_statuses WHERE project_type_id IS NULL ORDER BY sort_order, id'));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function withStatusUsage(array $rows): array
    {
        foreach ($rows as &$r) {
            $r['usage_count'] = (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_items WHERE status_id = ?', [(int) $r['id']]);
        }
        return $rows;
    }

    // ------------------------------------------------------------ types

    /**
     * Add a brand-new type. Input: code, label, description, item_label, item_label_singular, has_customer,
     * customer_required, has_stages, has_team, can_be_confidential, color, sort_order, fields (list of catalogue keys).
     * @param array<string,mixed> $input
     */
    public function create(array $input): int
    {
        $v = new Validator($input);
        $v->required('label', 'Give the type a name people will recognise, for example "Press trial".')->maxLength('label', 100);
        $v->required('code', 'Give the type a short code, for example "press_trial". Letters, numbers and underscores only.')
            ->maxLength('code', 40)->regex('code', '/^[a-z][a-z0-9_]*$/', 'The code can only use lowercase letters, numbers and underscores, and must start with a letter.');
        $this->validateCommon($v);
        $v->fail();
        $code = (string) $input['code'];
        if ($this->db->value('SELECT id FROM chroma_project_types WHERE code = ?', [$code]) !== null) {
            throw ValidationException::single('code', "There is already a project type with the code \"$code\". Pick another code.");
        }
        $data = $this->typeData($input) + ['code' => $code, 'created_at' => $this->db->now()];
        $id = $this->db->insert('chroma_project_types', $data);
        $this->audit->record('project_type.create', 'chroma_project_types', $id, null, $this->db->one('SELECT * FROM chroma_project_types WHERE id = ?', [$id]), 'Added project type ' . $input['label']);
        return $id;
    }

    /** Edit everything except the code. @param array<string,mixed> $input */
    public function update(int $id, array $input): void
    {
        $before = $this->db->one('SELECT * FROM chroma_project_types WHERE id = ?', [$id]);
        if ($before === null) {
            throw ValidationException::general('That project type no longer exists.');
        }
        $v = new Validator($input);
        $v->required('label', 'Give the type a name people will recognise.')->maxLength('label', 100);
        $this->validateCommon($v);
        $v->fail();
        $data = $this->typeData($input);
        if (array_key_exists('active', $input)) {
            $data['active'] = $this->flag($input['active']);
            if ($data['active'] === 0 && (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_types WHERE active = 1 AND id <> ?', [$id]) === 0) {
                throw ValidationException::single('active', 'This is the only active project type, so it has to stay active. Activate another type first.');
            }
        }
        $this->db->update('chroma_project_types', $data, ['id' => $id]);
        $this->audit->record('project_type.update', 'chroma_project_types', $id, $before, $this->db->one('SELECT * FROM chroma_project_types WHERE id = ?', [$id]), 'Edited project type ' . $before['label']);
    }

    private function validateCommon(Validator $v): void
    {
        $v->maxLength('description', 255);
        $v->maxLength('item_label', 40)->maxLength('item_label_singular', 40);
        $v->in('color', array_keys(self::COLORS), 'Pick one of the colours in the list.');
        $v->integer('sort_order', -100000, 100000, 'Sort order is a whole number; lower numbers come first.');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function typeData(array $input): array
    {
        $fields = [];
        foreach ((array) ($input['fields'] ?? []) as $k => $val) {
            $key = is_string($k) ? $k : (string) $val;
            if (is_string($k) && in_array($val, ['0', 0, '', false], true)) {
                continue;
            }
            if (isset(ProjectTypes::FIELD_CATALOGUE[$key])) {
                $fields[$key] = true;
            }
        }
        // Keep catalogue order so forms read consistently.
        $ordered = array_values(array_filter(array_keys(ProjectTypes::FIELD_CATALOGUE), fn($k) => isset($fields[$k])));
        $hasCustomer = $this->flag($input['has_customer'] ?? 1);
        return [
            'label' => trim((string) $input['label']),
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 255),
            'item_label' => trim((string) ($input['item_label'] ?? '')) ?: 'Items',
            'item_label_singular' => trim((string) ($input['item_label_singular'] ?? '')) ?: 'Item',
            'has_customer' => $hasCustomer,
            'customer_required' => $hasCustomer ? $this->flag($input['customer_required'] ?? 1) : 0,
            'has_stages' => $this->flag($input['has_stages'] ?? 0),
            'has_team' => $this->flag($input['has_team'] ?? 0),
            'can_be_confidential' => $this->flag($input['can_be_confidential'] ?? 0),
            'fields_json' => json_encode($ordered),
            'color' => isset(self::COLORS[(string) ($input['color'] ?? '')]) ? (string) $input['color'] : 'blue',
            'sort_order' => ($input['sort_order'] ?? '') === '' || !is_numeric($input['sort_order'] ?? null) ? 100 : (int) $input['sort_order'],
            'updated_at' => $this->db->now(),
        ];
    }

    // ----------------------------------------------------------- stages

    /** @param array<string,mixed> $input label, is_terminal */
    public function addStage(int $typeId, array $input): int
    {
        $this->requireType($typeId);
        $v = new Validator($input);
        $v->required('label', 'Type a name for the stage, for example "Bench".')->maxLength('label', 100);
        $v->fail();
        $label = trim((string) $input['label']);
        $code = $this->uniqueCode('chroma_project_stages', 'project_type_id = ' . $typeId, $label);
        $next = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM chroma_project_stages WHERE project_type_id = ?', [$typeId]);
        $id = $this->db->insert('chroma_project_stages', [
            'project_type_id' => $typeId, 'code' => $code, 'label' => $label, 'sort_order' => $next,
            'is_terminal' => $this->flag($input['is_terminal'] ?? 0), 'active' => 1,
        ]);
        $this->audit->record('stage.add', 'chroma_project_stages', $id, null, $this->db->one('SELECT * FROM chroma_project_stages WHERE id = ?', [$id]), 'Added stage ' . $label);
        return $id;
    }

    /** @param array<string,mixed> $input label, is_terminal, active */
    public function updateStage(int $id, array $input): void
    {
        $before = $this->db->one('SELECT * FROM chroma_project_stages WHERE id = ?', [$id]);
        if ($before === null) {
            throw ValidationException::general('That stage no longer exists.');
        }
        $v = new Validator($input);
        $v->required('label', 'A stage needs a name.')->maxLength('label', 100);
        $v->fail();
        $data = ['label' => trim((string) $input['label'])];
        if (array_key_exists('is_terminal', $input)) {
            $data['is_terminal'] = $this->flag($input['is_terminal']);
        }
        if (array_key_exists('active', $input)) {
            $data['active'] = $this->flag($input['active']);
        }
        $this->db->update('chroma_project_stages', $data, ['id' => $id]);
        $this->audit->record('stage.update', 'chroma_project_stages', $id, $before, $this->db->one('SELECT * FROM chroma_project_stages WHERE id = ?', [$id]), 'Edited stage ' . $before['label']);
    }

    /** Move a stage one place up (-1) or down (+1). */
    public function moveStage(int $id, int $direction): void
    {
        $this->move('chroma_project_stages', $id, $direction, fn(array $row) => 'project_type_id = ' . (int) $row['project_type_id'], 'stage.reorder');
    }

    /** Remove a stage that no project uses; otherwise deactivate it. */
    public function deleteStage(int $id): void
    {
        $before = $this->db->one('SELECT * FROM chroma_project_stages WHERE id = ?', [$id]);
        if ($before === null) {
            throw ValidationException::general('That stage no longer exists.');
        }
        $used = (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE stage_id = ?', [$id]);
        if ($used > 0) {
            throw ValidationException::general(sprintf('"%s" is the current stage of %s, so it cannot be removed. Untick "active" instead and it will stop being offered.', $before['label'], $used === 1 ? '1 project' : "$used projects"));
        }
        $this->db->exec('DELETE FROM chroma_project_stages WHERE id = ?', [$id]);
        $this->audit->record('stage.delete', 'chroma_project_stages', $id, $before, null, 'Removed stage ' . $before['label']);
    }

    // ------------------------------------------------------ item statuses

    /** Give a type its own statuses by copying the global list. */
    public function customizeStatuses(int $typeId): void
    {
        $type = $this->requireType($typeId);
        if ($this->ownStatuses($typeId) !== []) {
            throw ValidationException::general($type['label'] . ' already has its own statuses.');
        }
        $this->db->transaction(function () use ($typeId, $type) {
            foreach ($this->globalStatuses() as $s) {
                $this->db->insert('chroma_item_statuses', [
                    'project_type_id' => $typeId, 'code' => $s['code'], 'label' => $s['label'], 'sort_order' => (int) $s['sort_order'],
                    'is_done' => (int) $s['is_done'], 'is_success' => (int) $s['is_success'], 'color' => $s['color'], 'active' => (int) $s['active'],
                ]);
            }
            $this->audit->record('item_status.customize', 'chroma_project_types', $typeId, null, ['statuses' => array_column($this->ownStatuses($typeId), 'label')], 'Gave ' . $type['label'] . ' its own item statuses (copied from the shared list)');
        });
    }

    /** Go back to the shared list. Only possible while no item uses one of the type's own statuses. */
    public function revertStatuses(int $typeId): void
    {
        $type = $this->requireType($typeId);
        $own = $this->ownStatuses($typeId);
        if ($own === []) {
            return;
        }
        $used = array_sum(array_column($own, 'usage_count'));
        if ($used > 0) {
            throw ValidationException::general(sprintf('%s items already carry one of these statuses, so the list cannot be removed. You can rename or deactivate statuses instead.', number_format($used)));
        }
        $this->db->transaction(function () use ($typeId, $type, $own) {
            $this->db->exec('DELETE FROM chroma_item_statuses WHERE project_type_id = ?', [$typeId]);
            $this->audit->record('item_status.revert', 'chroma_project_types', $typeId, ['statuses' => array_column($own, 'label')], null, $type['label'] . ' uses the shared item statuses again');
        });
    }

    /**
     * Add a status to a type's own list, or to the shared list when $typeId is null.
     * @param array<string,mixed> $input label, is_done, is_success, color
     */
    public function addStatus(?int $typeId, array $input): int
    {
        if ($typeId !== null) {
            $this->requireType($typeId);
            if ($this->ownStatuses($typeId) === []) {
                throw ValidationException::general('This type uses the shared status list. Press "Customize for this type" first, then add statuses to it.');
            }
        }
        $v = new Validator($input);
        $v->required('label', 'Type a name for the status, for example "Sent to customer".')->maxLength('label', 100);
        $v->in('color', array_keys(self::COLORS), 'Pick one of the colours in the list.');
        $v->fail();
        $label = trim((string) $input['label']);
        $scope = $typeId === null ? 'project_type_id IS NULL' : 'project_type_id = ' . $typeId;
        $code = $this->uniqueCode('chroma_item_statuses', $scope, $label);
        $next = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM chroma_item_statuses WHERE ' . $scope);
        $id = $this->db->insert('chroma_item_statuses', [
            'project_type_id' => $typeId, 'code' => $code, 'label' => $label, 'sort_order' => $next,
            'is_done' => $this->flag($input['is_done'] ?? 0), 'is_success' => $this->flag($input['is_success'] ?? 0),
            'color' => isset(self::COLORS[(string) ($input['color'] ?? '')]) ? (string) $input['color'] : 'gray', 'active' => 1,
        ]);
        $this->audit->record('item_status.add', 'chroma_item_statuses', $id, null, $this->db->one('SELECT * FROM chroma_item_statuses WHERE id = ?', [$id]), 'Added item status ' . $label);
        return $id;
    }

    /** @param array<string,mixed> $input label, is_done, is_success, color, active */
    public function updateStatus(int $id, array $input): void
    {
        $before = $this->db->one('SELECT * FROM chroma_item_statuses WHERE id = ?', [$id]);
        if ($before === null) {
            throw ValidationException::general('That status no longer exists.');
        }
        $v = new Validator($input);
        $v->required('label', 'A status needs a name.')->maxLength('label', 100);
        $v->in('color', array_keys(self::COLORS), 'Pick one of the colours in the list.');
        $v->fail();
        $data = ['label' => trim((string) $input['label'])];
        foreach (['is_done', 'is_success', 'active'] as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $this->flag($input[$f]);
            }
        }
        if (!empty($input['color']) && isset(self::COLORS[(string) $input['color']])) {
            $data['color'] = (string) $input['color'];
        }
        if (($data['is_done'] ?? (int) $before['is_done']) === 0) {
            $data['is_success'] = 0; // "success" only makes sense for a finished item
        }
        if (($data['active'] ?? 1) === 0) {
            $scope = $before['project_type_id'] === null ? 'project_type_id IS NULL' : 'project_type_id = ' . (int) $before['project_type_id'];
            $others = (int) $this->db->value("SELECT COUNT(*) FROM chroma_item_statuses WHERE $scope AND active = 1 AND is_done = 0 AND id <> ?", [$id]);
            if ($others === 0 && (int) $before['is_done'] === 0) {
                throw ValidationException::single('active', 'At least one "not done" status has to stay active, or new items would have nowhere to start.');
            }
        }
        $this->db->update('chroma_item_statuses', $data, ['id' => $id]);
        $this->audit->record('item_status.update', 'chroma_item_statuses', $id, $before, $this->db->one('SELECT * FROM chroma_item_statuses WHERE id = ?', [$id]), 'Edited item status ' . $before['label']);
    }

    public function moveStatus(int $id, int $direction): void
    {
        $this->move('chroma_item_statuses', $id, $direction, fn(array $row) => $row['project_type_id'] === null ? 'project_type_id IS NULL' : 'project_type_id = ' . (int) $row['project_type_id'], 'item_status.reorder');
    }

    /** Remove a status no item uses; otherwise refuse and suggest deactivating. */
    public function deleteStatus(int $id): void
    {
        $before = $this->db->one('SELECT * FROM chroma_item_statuses WHERE id = ?', [$id]);
        if ($before === null) {
            throw ValidationException::general('That status no longer exists.');
        }
        $used = (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_items WHERE status_id = ?', [$id]);
        if ($used > 0) {
            throw ValidationException::general(sprintf('"%s" is the status of %s, so it cannot be removed. Untick "active" instead and it will stop being offered.', $before['label'], $used === 1 ? '1 item' : "$used items"));
        }
        $this->db->exec('DELETE FROM chroma_item_statuses WHERE id = ?', [$id]);
        $this->audit->record('item_status.delete', 'chroma_item_statuses', $id, $before, null, 'Removed item status ' . $before['label']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function requireType(int $id): array
    {
        $t = $this->db->one('SELECT * FROM chroma_project_types WHERE id = ?', [$id]);
        if ($t === null) {
            throw ValidationException::general('That project type no longer exists.');
        }
        return $t;
    }

    private function flag(mixed $v): int
    {
        return in_array(is_scalar($v) ? strtolower((string) $v) : '', ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    /** Slug for a label, unique within $scopeSql (a safe SQL fragment built from ints). */
    private function uniqueCode(string $table, string $scopeSql, string $label): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? '', '_') ?: 'item';
        $base = substr($base, 0, 34);
        $code = $base;
        $n = 2;
        while ($this->db->value("SELECT id FROM $table WHERE $scopeSql AND code = ?", [$code]) !== null) {
            $code = $base . '_' . $n++;
        }
        return $code;
    }

    /** Swap sort_order with the neighbour in the given direction, inside the row's scope. */
    private function move(string $table, int $id, int $direction, callable $scopeFor, string $action): void
    {
        $row = $this->db->one("SELECT * FROM $table WHERE id = ?", [$id]);
        if ($row === null) {
            throw ValidationException::general('That entry no longer exists.');
        }
        $scope = $scopeFor($row);
        $this->db->transaction(function () use ($table, $scope, $row, $direction, $action) {
            $rows = $this->db->all("SELECT id, sort_order FROM $table WHERE $scope ORDER BY sort_order, id");
            $idx = array_search((int) $row['id'], array_map(fn($r) => (int) $r['id'], $rows), true);
            if ($idx === false) {
                return;
            }
            $target = $idx + ($direction < 0 ? -1 : 1);
            if ($target < 0 || $target >= count($rows)) {
                return;
            }
            // Renumber the whole scope 10, 20, 30 … with the two neighbours swapped; keeps orders unambiguous.
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            [$ids[$idx], $ids[$target]] = [$ids[$target], $ids[$idx]];
            foreach ($ids as $i => $rid) {
                $this->db->update($table, ['sort_order' => ($i + 1) * 10], ['id' => $rid]);
            }
            $this->audit->record($action, $table, (int) $row['id'], ['position' => $idx + 1], ['position' => $target + 1], 'Moved ' . $row['label'] . ($direction < 0 ? ' up' : ' down'));
        });
    }
}
