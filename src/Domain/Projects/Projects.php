<?php
declare(strict_types=1);

namespace Chroma\Domain\Projects;

use Chroma\Core\Db;
use Chroma\Core\Settings;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\Customers\Customers;
use Chroma\Domain\ProjectTypes\ProjectTypes;

/**
 * Projects: create, edit, list, complete / cancel / reopen, stage and team.
 *
 * Both the Easy-mode wizard and the Expert-mode page call create() and
 * update(); validation and the plain-language messages live here. Project
 * numbers come from chroma_sequences 'project_number' locked with
 * SELECT … FOR UPDATE inside the create transaction (same pattern as
 * CodeAssigner): max(MAX(project_number)+1, next_value).
 */
final class Projects
{
    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Columns on chroma_projects that update() may change, besides the type fields. */
    private const EDITABLE = ['title', 'notes', 'default_prefix_id'];

    private ProjectTypes $types;
    private Customers $customers;
    private Activity $activity;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit, private readonly Settings $settings)
    {
        $this->types = new ProjectTypes($db);
        $this->customers = new Customers($db);
        $this->activity = new Activity($db, $audit);
    }

    public function types(): ProjectTypes
    {
        return $this->types;
    }

    public function activity(): Activity
    {
        return $this->activity;
    }

    public function items(): Items
    {
        return new Items($this->db, $this->audit, $this->settings);
    }

    /** The cap on items per project (setting max_items_per_project, default 50). */
    public function maxItems(): int
    {
        return max(1, $this->settings->int('max_items_per_project', 50));
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** A project with its type, customer, tech, stage and progress. @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT p.*, pt.code AS type_code, pt.label AS type_label, pt.color AS type_color, pt.item_label, pt.item_label_singular,
                    pt.has_customer, pt.customer_required, pt.has_stages, pt.has_team, pt.can_be_confidential,
                    c.name AS customer_name, u.display_name AS tech_name, u.username AS tech_username,
                    cb.display_name AS created_by_name, cp.display_name AS completed_by_name,
                    st.label AS stage_label, st.code AS stage_code, st.is_terminal AS stage_terminal,
                    isr.name AS default_ink_series_name, co.name AS default_coating_name, px.code AS default_prefix_code
             FROM chroma_projects p
             JOIN chroma_project_types pt ON pt.id = p.project_type_id
             LEFT JOIN chroma_customers c ON c.id = p.customer_id
             LEFT JOIN chroma_users u ON u.id = p.assigned_user_id
             LEFT JOIN chroma_users cb ON cb.id = p.created_by
             LEFT JOIN chroma_users cp ON cp.id = p.completed_by
             LEFT JOIN chroma_project_stages st ON st.id = p.stage_id
             LEFT JOIN chroma_ink_series isr ON isr.id = p.default_ink_series_id
             LEFT JOIN chroma_coatings co ON co.id = p.default_coating_id
             LEFT JOIN chroma_code_prefixes px ON px.id = p.default_prefix_id
             WHERE p.id = ?',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $row['progress'] = $this->progress($id, $row['type_code']);
        return $row;
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw ValidationException::general('That project no longer exists.');
        }
        return $row;
    }

    /**
     * Refuse changes to a completed or cancelled project with a plain-language
     * message. Both modes share this: the wizards, the Expert pages and any
     * stale tab get the same answer.
     * @param array<string,mixed> $project a row from find()/get()
     */
    public static function assertOpen(array $project, string $what = 'make changes'): void
    {
        if (($project['status'] ?? self::STATUS_OPEN) === self::STATUS_OPEN) {
            return;
        }
        throw ValidationException::general(sprintf('Project %d is %s, so you cannot %s. Reopen it from its page first.',
            (int) $project['project_number'], $project['status'] === self::STATUS_CANCELLED ? 'cancelled' : 'completed', $what));
    }

    /** Items done / total. @return array{total:int,done:int,pct:int,word:string,label:string} */
    public function progress(int $projectId, ?string $typeCode = null): array
    {
        $r = $this->db->one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(s.is_done = 1), 0) AS done, COALESCE(SUM(s.is_done = 1 AND s.is_success = 1), 0) AS matched
             FROM chroma_project_items i LEFT JOIN chroma_item_statuses s ON s.id = i.status_id WHERE i.project_id = ?',
            [$projectId]
        );
        $total = (int) ($r['total'] ?? 0);
        $done = (int) ($r['done'] ?? 0);
        $matched = (int) ($r['matched'] ?? 0);
        $word = self::progressWord($typeCode);
        return [
            'total' => $total,
            'done' => $done,
            'matched' => $matched,
            'pct' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'word' => $word,
            'label' => self::progressLabel($done, $total, $matched, $typeCode),
        ];
    }

    /** "18 of 25 matched" on a color match, "… done" elsewhere. */
    public static function progressWord(?string $typeCode): string
    {
        return $typeCode === 'color_match' ? 'matched' : 'done';
    }

    /**
     * The progress text. "Done" covers matched, approved, rejected and not needed, so a color match only says
     * "matched" when every finished color really was matched; otherwise "3 of 3 done (2 matched)".
     */
    public static function progressLabel(int $done, int $total, int $matched, ?string $typeCode): string
    {
        $word = self::progressWord($typeCode);
        if ($word === 'matched' && $done > $matched) {
            return $done . ' of ' . $total . ' done (' . plural($matched, 'matched', 'matched') . ')';
        }
        return $done . ' of ' . $total . ' ' . $word;
    }

    /** Team member rows (user id + name). @return list<array<string,mixed>> */
    public function team(int $projectId): array
    {
        return $this->db->all(
            'SELECT u.id, u.display_name, u.username FROM chroma_project_team t JOIN chroma_users u ON u.id = t.user_id WHERE t.project_id = ? ORDER BY u.display_name',
            [$projectId]
        );
    }

    /**
     * The projects list. Filters: status (open|completed|cancelled|all; default open), customer_id, tech
     * (assigned_user_id), type (code), from/to (date_submitted), mine (user id), q (project number, text or any code).
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(array $filters, Visibility $visibility, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->listWhere($filters, $visibility);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects p WHERE ' . $where, $params);
        $rows = $this->db->all(
            'SELECT p.id, p.project_number, p.title, p.description, p.problem_statement, p.goal, p.status, p.date_submitted, p.date_completed, p.target_date, p.confidential,
                    pt.code AS type_code, pt.label AS type_label, pt.color AS type_color, pt.item_label,
                    c.name AS customer_name, u.display_name AS tech_name, st.label AS stage_label,
                    (SELECT COUNT(*) FROM chroma_project_items i WHERE i.project_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM chroma_project_items i JOIN chroma_item_statuses s ON s.id = i.status_id WHERE i.project_id = p.id AND s.is_done = 1) AS items_done,
                    (SELECT COUNT(*) FROM chroma_project_items i JOIN chroma_item_statuses s ON s.id = i.status_id WHERE i.project_id = p.id AND s.is_done = 1 AND s.is_success = 1) AS items_matched
             FROM chroma_projects p
             JOIN chroma_project_types pt ON pt.id = p.project_type_id
             LEFT JOIN chroma_customers c ON c.id = p.customer_id
             LEFT JOIN chroma_users u ON u.id = p.assigned_user_id
             LEFT JOIN chroma_project_stages st ON st.id = p.stage_id
             WHERE ' . $where . '
             ORDER BY p.date_submitted DESC, p.id DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        foreach ($rows as &$r) {
            $r['summary'] = self::summary($r);
            $r['pct'] = (int) $r['item_count'] > 0 ? (int) round((int) $r['items_done'] / (int) $r['item_count'] * 100) : 0;
            $r['progress_label'] = self::progressLabel((int) $r['items_done'], (int) $r['item_count'], (int) $r['items_matched'], $r['type_code']);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /** The one-line text shown in lists: description, problem statement or goal, whichever the type uses. @param array<string,mixed> $p */
    public static function summary(array $p): string
    {
        foreach (['description', 'problem_statement', 'goal'] as $f) {
            $v = trim((string) ($p[$f] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function listWhere(array $filters, Visibility $visibility): array
    {
        [$sql, $params] = $visibility->sqlFilter('p');
        $where = [$sql];
        $status = (string) ($filters['status'] ?? self::STATUS_OPEN);
        if ($status !== 'all' && isset(self::STATUSES[$status])) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 'p.customer_id = ?';
            $params[] = (int) $filters['customer_id'];
        }
        if (!empty($filters['tech'])) {
            $where[] = 'p.assigned_user_id = ?';
            $params[] = (int) $filters['tech'];
        }
        if (!empty($filters['type'])) {
            $type = $this->types->byCode((string) $filters['type']);
            if ($type) {
                $where[] = 'p.project_type_id = ?';
                $params[] = (int) $type['id'];
            }
        }
        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])) {
            $where[] = 'p.date_submitted >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])) {
            $where[] = 'p.date_submitted <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['mine'])) {
            $uid = (int) $filters['mine'];
            $where[] = '(p.assigned_user_id = ? OR p.created_by = ? OR p.id IN (SELECT t.project_id FROM chroma_project_team t WHERE t.user_id = ?))';
            array_push($params, $uid, $uid, $uid);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            [$qs, $qp] = $this->searchClause($q);
            $where[] = $qs;
            $params = array_merge($params, $qp);
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Search: a project number, words from the title/description (FULLTEXT),
     * or any code held by one of the project's items (E# or sell number,
     * in any historical spelling, parsed with CodeFormat).
     * @return array{0:string,1:list<mixed>}
     */
    private function searchClause(string $q): array
    {
        $parts = [];
        $params = [];
        if (preg_match('/^#?\s*(\d{1,10})$/', $q, $m)) {
            $parts[] = 'p.project_number = ?';
            $params[] = (int) $m[1];
        }
        $internal = CodeFormat::looksInternal($q) ? CodeFormat::parseInternal($q) : null;
        if ($internal !== null) {
            $parts[] = 'p.id IN (SELECT i.project_id FROM chroma_project_items i JOIN chroma_internal_codes ic ON ic.id = i.internal_code_id WHERE ic.number >= ? AND ic.number < ?)';
            $params[] = $internal['number'];
            $params[] = self::upperBound($internal['number']);
        }
        $sell = CodeFormat::parseSell($q);
        if ($sell !== null && preg_match('/[A-Za-z]/', $q)) {
            $parts[] = 'p.id IN (SELECT i.project_id FROM chroma_project_items i JOIN chroma_sell_numbers sn ON sn.id = i.sell_number_id WHERE sn.sell_number_normalized LIKE ?)';
            $params[] = $sell['prefix'] . $sell['number'] . '%';
        }
        $bool = self::booleanQuery($q);
        if ($bool !== '') {
            $parts[] = 'MATCH(p.title, p.description) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $bool;
            $parts[] = 'p.id IN (SELECT i.project_id FROM chroma_project_items i WHERE MATCH(i.description) AGAINST (? IN BOOLEAN MODE))';
            $params[] = $bool;
        }
        if ($parts === []) {
            return ['1 = 0', []];
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    private static function upperBound(int $n): int
    {
        $len = strlen((string) $n);
        return $len >= 6 ? $n + 1 : (int) ($n . str_repeat('9', 6 - $len)) + 1;
    }

    /** Every word required, prefix-matched; safe for BOOLEAN MODE. */
    public static function booleanQuery(string $q): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 2) {
                continue;
            }
            $parts[] = '+' . $w . '*';
        }
        return implode(' ', $parts);
    }

    /** Typeahead: open projects matching a number or words. @return list<array{id:int,label:string,sub:string}> */
    public function search(string $q, Visibility $visibility, int $limit = 15, ?int $excludeId = null): array
    {
        [$vs, $vp] = $visibility->sqlFilter('p');
        $where = [$vs];
        $params = $vp;
        $q = trim($q);
        if ($q !== '') {
            [$qs, $qp] = $this->searchClause($q);
            $where[] = $qs;
            $params = array_merge($params, $qp);
        } else {
            $where[] = 'p.status = ?';
            $params[] = self::STATUS_OPEN;
        }
        if ($excludeId !== null) {
            $where[] = 'p.id <> ?';
            $params[] = $excludeId;
        }
        $rows = $this->db->all(
            'SELECT p.id, p.project_number, p.title, p.status, c.name AS customer_name, pt.label AS type_label FROM chroma_projects p
             JOIN chroma_project_types pt ON pt.id = p.project_type_id LEFT JOIN chroma_customers c ON c.id = p.customer_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY (p.status = ?) DESC, p.id DESC LIMIT ' . (int) $limit,
            array_merge($params, [self::STATUS_OPEN])
        );
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'label' => $r['project_number'] . ' · ' . $r['title'],
            'sub' => trim($r['type_label'] . ($r['customer_name'] ? ' · ' . $r['customer_name'] : '') . ($r['status'] !== 'open' ? ' · ' . ucfirst((string) $r['status']) : '')),
        ], $rows);
    }

    // ------------------------------------------------------------------
    // Creating and editing
    // ------------------------------------------------------------------

    /**
     * Create a project (and its first items) in one transaction.
     *
     * @param array<string,mixed> $data type (code) or project_type_id, customer_id / customer_text, title,
     *        the type's fields (description, problem_statement, goal, date_submitted, target_date,
     *        assigned_user_id, stage_id, confidential, default_ink_series_id, default_substrate,
     *        default_coating_id, notes), default_prefix_id, team (list of user ids), items (list of item arrays).
     * @return array<string,mixed> the new project (as find() returns it)
     */
    public function create(array $data, ?int $actorId): array
    {
        $type = $this->resolveType($data);
        $errors = [];
        $columns = $this->normalizeFields($type, $data, null, $errors);
        $columns['customer_id'] = $this->resolveCustomer($type, $data, $errors);
        if (!isset($data['assigned_user_id']) || $data['assigned_user_id'] === '' || $data['assigned_user_id'] === null) {
            $columns['assigned_user_id'] = $actorId;
        }
        if (empty($columns['date_submitted'])) {
            $columns['date_submitted'] = today();
        }
        $team = $this->normalizeTeam($type, $data['team'] ?? [], $errors);
        $items = $this->items()->normalizeMany($data['items'] ?? [], $type, $columns, $this->maxItems(), $errors, $actorId);
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }

        return $this->db->transaction(function (Db $db) use ($type, $columns, $team, $items, $actorId) {
            $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'project_number' FOR UPDATE");
            if ($seq === null) {
                $db->run("INSERT INTO chroma_sequences (name, next_value, updated_at) VALUES ('project_number', 101, UTC_TIMESTAMP())");
                $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'project_number' FOR UPDATE");
            }
            $max = (int) ($db->value('SELECT MAX(project_number) FROM chroma_projects') ?? 0);
            $number = max($max + 1, (int) $seq['next_value']);
            $now = $db->now();
            $id = $db->insert('chroma_projects', $columns + [
                'project_number' => $number,
                'project_type_id' => (int) $type['id'],
                'status' => self::STATUS_OPEN,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->run("UPDATE chroma_sequences SET next_value = ?, updated_at = UTC_TIMESTAMP() WHERE name = 'project_number'", [$number + 1]);
            foreach ($team as $uid) {
                $db->run('INSERT IGNORE INTO chroma_project_team (project_id, user_id) VALUES (?, ?)', [$id, $uid]);
            }
            $this->items()->insertNormalized($id, $items, $actorId);
            $row = $this->get($id);
            $this->audit->record('project.create', 'chroma_projects', $id, null, $row, 'Project ' . $number . ' created', $actorId);
            $this->activity->system($id, 'created', 'Project created' . (count($items) ? ' with ' . count($items) . ' ' . mb_strtolower((string) ($type[count($items) === 1 ? 'item_label_singular' : 'item_label'])) : ''), $actorId);
            return $row;
        });
    }

    /**
     * Change project details. Only the keys present in $data are changed
     * (so the Expert page can save one field at a time); keys the type does
     * not use are ignored. Status changes go through complete()/cancel()/reopen().
     * @param array<string,mixed> $data
     * @return array<string,mixed> the updated project
     */
    public function update(int $id, array $data, ?int $actorId): array
    {
        $before = $this->get($id);
        $type = $this->types->byId((int) $before['project_type_id']);
        if ($type === null) {
            throw ValidationException::general('This project has a type that no longer exists. Ask an administrator to check Admin → Lists.');
        }
        self::assertOpen($before, 'change its details');
        $errors = [];
        $columns = $this->normalizeFields($type, $data, $before, $errors);
        if (array_key_exists('customer_id', $data) || array_key_exists('customer_text', $data) || array_key_exists('customer_id_text', $data)) {
            $columns['customer_id'] = $this->resolveCustomer($type, $data, $errors);
        }
        $teamChanged = array_key_exists('team', $data) && (int) $type['has_team'] === 1;
        $team = $teamChanged ? $this->normalizeTeam($type, $data['team'] ?? [], $errors) : null;
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        $changed = [];
        foreach ($columns as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = $v;
            }
        }
        return $this->db->transaction(function (Db $db) use ($id, $before, $changed, $team, $actorId, $type) {
            if ($changed) {
                $db->update('chroma_projects', $changed + ['updated_at' => $db->now()], ['id' => $id]);
            }
            if ($team !== null) {
                $current = array_map('intval', $db->column('SELECT user_id FROM chroma_project_team WHERE project_id = ?', [$id]));
                if (array_values(array_diff($current, $team)) !== [] || array_values(array_diff($team, $current)) !== []) {
                    $db->exec('DELETE FROM chroma_project_team WHERE project_id = ?', [$id]);
                    foreach ($team as $uid) {
                        $db->run('INSERT IGNORE INTO chroma_project_team (project_id, user_id) VALUES (?, ?)', [$id, $uid]);
                    }
                    $changed['team'] = $team;
                    $this->activity->system($id, 'team_changed', 'Team changed', $actorId);
                }
            }
            if ($changed === []) {
                return $this->get($id);
            }
            $after = $this->get($id);
            $this->audit->record('project.update', 'chroma_projects', $id, array_intersect_key($before, $changed), array_intersect_key($after, $changed) + (isset($changed['team']) ? ['team' => $team] : []), 'Project ' . $before['project_number'] . ' details changed', $actorId);
            if (array_key_exists('stage_id', $changed)) {
                $this->activity->system($id, 'stage_changed', 'Stage changed from ' . ($before['stage_label'] ?: '(none)') . ' to ' . ($after['stage_label'] ?: '(none)'), $actorId, null, ['stage_id' => $after['stage_id']]);
            }
            $labels = [];
            foreach (array_keys($changed) as $k) {
                if (in_array($k, ['stage_id', 'team'], true)) {
                    continue;
                }
                $labels[] = ProjectTypes::FIELD_CATALOGUE[$k]['short'] ?? ($k === 'default_substrate_text' ? 'Substrate' : ($k === 'customer_id' ? 'Customer' : ucfirst(str_replace('_', ' ', $k))));
            }
            $labels = array_values(array_unique(array_diff($labels, ['Default substrate id'])));
            if ($labels) {
                $this->activity->system($id, 'edited', 'Details changed: ' . implode(', ', $labels), $actorId);
            }
            return $after;
        });
    }

    /** Set the stage (R&D). Null clears it. */
    public function setStage(int $id, ?int $stageId, ?int $actorId): array
    {
        return $this->update($id, ['stage_id' => $stageId], $actorId);
    }

    /** @param list<int> $userIds */
    public function setTeam(int $id, array $userIds, ?int $actorId): array
    {
        return $this->update($id, ['team' => $userIds], $actorId);
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    /** Mark the project completed (sets date_completed). $auto marks it as done by the system. */
    public function complete(int $id, ?int $actorId, bool $auto = false): array
    {
        $before = $this->get($id);
        if ($before['status'] === self::STATUS_COMPLETED) {
            throw ValidationException::general('Project ' . $before['project_number'] . ' is already completed.');
        }
        return $this->db->transaction(function (Db $db) use ($id, $before, $actorId, $auto) {
            $db->update('chroma_projects', [
                'status' => self::STATUS_COMPLETED,
                'date_completed' => today(),
                'completed_by' => $actorId,
                'cancelled_reason' => null,
                'updated_at' => $db->now(),
            ], ['id' => $id]);
            $after = $this->get($id);
            $this->audit->record('project.complete', 'chroma_projects', $id, $before, $after, 'Project ' . $before['project_number'] . ($auto ? ' completed automatically' : ' marked completed'), $actorId);
            $this->activity->system($id, 'completed', $auto ? 'Project completed automatically: every item is done or not needed' : 'Project marked completed', $actorId);
            return $after;
        });
    }

    /** Cancel with a reason (kept on the project and in the log). */
    public function cancel(int $id, string $reason, ?int $actorId): array
    {
        $before = $this->get($id);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'Say in a few words why the project is being cancelled. It is kept with the project.');
        }
        if ($before['status'] === self::STATUS_CANCELLED) {
            throw ValidationException::general('Project ' . $before['project_number'] . ' is already cancelled.');
        }
        return $this->db->transaction(function (Db $db) use ($id, $before, $reason, $actorId) {
            $db->update('chroma_projects', [
                'status' => self::STATUS_CANCELLED,
                'date_completed' => today(),
                'completed_by' => $actorId,
                'cancelled_reason' => mb_substr($reason, 0, 500),
                'updated_at' => $db->now(),
            ], ['id' => $id]);
            $after = $this->get($id);
            $this->audit->record('project.cancel', 'chroma_projects', $id, $before, $after, 'Project ' . $before['project_number'] . ' cancelled: ' . $reason, $actorId);
            $this->activity->system($id, 'cancelled', 'Project cancelled: ' . $reason, $actorId);
            return $after;
        });
    }

    public function reopen(int $id, ?int $actorId): array
    {
        $before = $this->get($id);
        if ($before['status'] === self::STATUS_OPEN) {
            throw ValidationException::general('Project ' . $before['project_number'] . ' is already open.');
        }
        return $this->db->transaction(function (Db $db) use ($id, $before, $actorId) {
            $db->update('chroma_projects', [
                'status' => self::STATUS_OPEN,
                'date_completed' => null,
                'completed_by' => null,
                'cancelled_reason' => null,
                'updated_at' => $db->now(),
            ], ['id' => $id]);
            $after = $this->get($id);
            $this->audit->record('project.reopen', 'chroma_projects', $id, $before, $after, 'Project ' . $before['project_number'] . ' reopened', $actorId);
            $this->activity->system($id, 'reopened', 'Project reopened', $actorId);
            return $after;
        });
    }

    /**
     * Complete the project automatically when every item is done or not
     * needed. Called after item status changes. Returns true if it did.
     */
    public function autoComplete(int $id, ?int $actorId): bool
    {
        $p = $this->db->one('SELECT id, status FROM chroma_projects WHERE id = ?', [$id]);
        if ($p === null || $p['status'] !== self::STATUS_OPEN) {
            return false;
        }
        $progress = $this->progress($id);
        if ($progress['total'] === 0 || $progress['done'] < $progress['total']) {
            return false;
        }
        $this->complete($id, $actorId, true);
        return true;
    }

    // ------------------------------------------------------------------
    // Normalizing input
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function resolveType(array $data): array
    {
        $type = null;
        if (!empty($data['project_type_id'])) {
            $type = $this->types->byId((int) $data['project_type_id']);
        } elseif (!empty($data['type'])) {
            $type = $this->types->byCode((string) $data['type']);
        }
        if ($type === null || (int) $type['active'] !== 1) {
            throw ValidationException::single('type', 'Pick what kind of project this is: a color match, a customer issue, an evaluation or R&D.');
        }
        return $type;
    }

    /**
     * Validate and map the type's fields to chroma_projects columns. For an update, only keys present in $data are touched.
     * @param array<string,mixed> $type @param array<string,mixed> $data @param array<string,mixed>|null $existing @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function normalizeFields(array $type, array $data, ?array $existing, array &$errors): array
    {
        $isUpdate = $existing !== null;
        $has = fn(string $k) => array_key_exists($k, $data);
        $out = [];
        if (!$isUpdate || $has('title')) {
            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '') {
                $errors['title'] = 'Give the project a short title, like "Pantone 300 for the spring carton" or "Blue not drying on PE film".';
            } elseif (mb_strlen($title) > 200) {
                $errors['title'] = 'Keep the title to 200 characters; put the detail in the description.';
            }
            $out['title'] = mb_substr($title, 0, 200);
        }
        $allowedTypes = ['textarea', 'date', 'user', 'stage', 'checkbox', 'ink_series', 'substrate', 'coating'];
        foreach ($this->types->fields($type) as $spec) {
            $name = $spec['name'];
            if (!in_array($spec['type'], $allowedTypes, true)) {
                continue;
            }
            if ($spec['type'] === 'substrate') {
                if ($isUpdate && !$has('default_substrate') && !$has('default_substrate_text') && !$has('default_substrate_id')) {
                    continue;
                }
                $text = trim((string) ($data['default_substrate'] ?? $data['default_substrate_text'] ?? ''));
                $sid = isset($data['default_substrate_id']) && (int) $data['default_substrate_id'] > 0 ? (int) $data['default_substrate_id'] : null;
                [$sid, $text] = $this->resolveSubstrate($sid, $text);
                $out['default_substrate_id'] = $sid;
                $out['default_substrate_text'] = $text === '' ? null : mb_substr($text, 0, 200);
                continue;
            }
            if ($isUpdate && !$has($name)) {
                continue;
            }
            $raw = $data[$name] ?? null;
            $val = is_string($raw) ? trim($raw) : $raw;
            switch ($spec['type']) {
                case 'textarea':
                    $out[$name] = ($val === null || $val === '') ? null : mb_substr((string) $val, 0, 65000);
                    break;
                case 'date':
                    if ($val === null || $val === '') {
                        $out[$name] = null;
                    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $val) || !checkdate((int) substr((string) $val, 5, 2), (int) substr((string) $val, 8, 2), (int) substr((string) $val, 0, 4))) {
                        $errors[$name] = 'Enter the date as YYYY-MM-DD, or pick it from the calendar.';
                    } else {
                        $out[$name] = (string) $val;
                    }
                    break;
                case 'user':
                    if ($val === null || $val === '' || (int) $val === 0) {
                        $out[$name] = null;
                    } elseif (!$this->db->value('SELECT COUNT(*) FROM chroma_users WHERE id = ? AND active = 1', [(int) $val])) {
                        $errors[$name] = 'That person is not an active user. Pick someone from the list.';
                    } else {
                        $out[$name] = (int) $val;
                    }
                    break;
                case 'stage':
                    if ($val === null || $val === '' || (int) $val === 0) {
                        $out[$name] = null;
                    } else {
                        $ok = false;
                        foreach ($type['stages'] as $s) {
                            if ((int) $s['id'] === (int) $val) {
                                $ok = true;
                            }
                        }
                        if (!$ok) {
                            $errors[$name] = 'That stage is not one of the stages for this kind of project. Pick one from the list.';
                        } else {
                            $out[$name] = (int) $val;
                        }
                    }
                    break;
                case 'checkbox':
                    $out[$name] = in_array(is_scalar($val) ? strtolower((string) $val) : '', ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
                    break;
                case 'ink_series':
                case 'coating':
                    $table = $spec['type'] === 'coating' ? 'chroma_coatings' : 'chroma_ink_series';
                    if ($val === null || $val === '' || (int) $val === 0) {
                        $out[$name] = null;
                    } elseif (!$this->db->value("SELECT COUNT(*) FROM $table WHERE id = ?", [(int) $val])) {
                        $errors[$name] = 'That is not in the list any more. Pick one from the list or leave it blank.';
                    } else {
                        $out[$name] = (int) $val;
                    }
                    break;
            }
        }
        if ($has('notes') && !array_key_exists('notes', $out)) {
            $out['notes'] = trim((string) $data['notes']) === '' ? null : mb_substr(trim((string) $data['notes']), 0, 65000);
        }
        if ($has('default_prefix_id')) {
            $pid = (int) ($data['default_prefix_id'] ?? 0);
            if ($pid > 0 && !$this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes WHERE id = ?', [$pid])) {
                $errors['default_prefix_id'] = 'That prefix does not exist. Pick one from the list.';
            } else {
                $out['default_prefix_id'] = $pid > 0 ? $pid : null;
            }
        }
        return $out;
    }

    /** Match typed substrate text to the admin list (case-insensitive); keep the text either way. @return array{0:?int,1:string} */
    public function resolveSubstrate(?int $id, string $text): array
    {
        if ($id !== null) {
            $row = $this->db->one('SELECT id, name FROM chroma_substrates WHERE id = ?', [$id]);
            if ($row) {
                return [(int) $row['id'], $text !== '' ? $text : (string) $row['name']];
            }
        }
        if ($text === '') {
            return [null, ''];
        }
        $row = $this->db->one('SELECT id, name FROM chroma_substrates WHERE LOWER(name) = LOWER(?) LIMIT 1', [$text]);
        return [$row ? (int) $row['id'] : null, $text];
    }

    /** @param array<string,mixed> $type @param array<string,mixed> $data @param array<string,string> $errors */
    private function resolveCustomer(array $type, array $data, array &$errors): ?int
    {
        if ((int) $type['has_customer'] !== 1) {
            // Internal projects (R&D) use the internal customer so codes assigned from them carry it.
            return $this->customers->internalRndId((string) $this->settings->get('rnd_customer_name', 'Precision Ink R&D'));
        }
        $id = isset($data['customer_id']) && (int) $data['customer_id'] > 0 ? (int) $data['customer_id'] : null;
        // The lookup component posts the typed name as customer_id_text; the wizard and the API use customer_text.
        $text = trim((string) ($data['customer_text'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($data['customer_id_text'] ?? ''));
        }
        if ($id !== null) {
            if ($this->customers->find($id) === null) {
                $errors['customer_id'] = 'That customer is not in the list any more. Search for it again or type the name to add it.';
                return null;
            }
            return $id;
        }
        if ($text !== '') {
            return $this->customers->findOrCreate($text, 'manual');
        }
        if ((int) $type['customer_required'] === 1) {
            $errors['customer_id'] = 'Choose the customer. Start typing the name and pick it from the list, or keep typing to add a new one.';
        }
        return null;
    }

    /** @param array<string,mixed> $type @param mixed $raw @param array<string,string> $errors @return list<int> */
    private function normalizeTeam(array $type, mixed $raw, array &$errors): array
    {
        if ((int) $type['has_team'] !== 1 || !is_array($raw)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $found = array_map('intval', $this->db->column("SELECT id FROM chroma_users WHERE active = 1 AND id IN ($in)", $ids));
        if (count($found) !== count($ids)) {
            $errors['team'] = 'One of the team members is not an active user. Pick people from the list.';
        }
        return $found;
    }
}
