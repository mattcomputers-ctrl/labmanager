<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Export\Exporter;
use Chroma\Http\Controller;

/** Admin → System → Audit log: who changed what, with before/after diffs. */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'user' => $request->int('user'),
            'action' => $request->str('action'),
            'table' => $request->str('table'),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->str('from')) ? $request->str('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->str('to')) ? $request->str('to') : '',
            'q' => $request->str('q'),
        ];
        $where = [];
        $params = [];
        if ($filters['user']) {
            $where[] = 'a.user_id = ?';
            $params[] = $filters['user'];
        }
        if ($filters['action'] !== '') {
            $where[] = 'a.action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\\%', '\\_'], $filters['action']) . '%';
        }
        if ($filters['table'] !== '') {
            $where[] = 'a.table_name = ?';
            $params[] = $filters['table'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = $this->toUtc($filters['from'] . ' 00:00:00');
        }
        if ($filters['to'] !== '') {
            $where[] = 'a.created_at < ?';
            $params[] = $this->toUtc($filters['to'] . ' 00:00:00', '+1 day');
        }
        if ($filters['q'] !== '') {
            // Notes are searched through the FULLTEXT index (migration 0035), never LIKE '%…%':
            // the audit table grows with every assignment, void and admin change.
            $parts = [];
            if (ctype_digit($filters['q'])) {
                $parts[] = 'a.row_id = ?';
                $params[] = (int) $filters['q'];
            }
            $bool = self::booleanQuery($filters['q']);
            if ($bool !== '') {
                $parts[] = 'MATCH(a.note) AGAINST (? IN BOOLEAN MODE)';
                $params[] = $bool;
            }
            $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0';
        }
        $sql = ' FROM chroma_audit_log a' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $export = $request->str('export');
        if (in_array($export, ['csv', 'xlsx'], true)) {
            $rows = $this->db->all('SELECT a.*' . $sql . ' ORDER BY a.id DESC LIMIT 50000', $params);
            $this->app->audit->record('audit.export', '', null, null, ['format' => $export, 'rows' => count($rows)]);
            return Exporter::respond($export, 'audit-log', [
                'id' => 'ID', 'when' => 'When', 'user_name' => 'Who', 'action' => 'Action', 'table_name' => 'Table', 'row_id' => 'Row', 'note' => 'Note', 'before_json' => 'Before', 'after_json' => 'After', 'ip' => 'IP',
            ], (function () use ($rows) {
                foreach ($rows as $r) {
                    $r['when'] = fmt_datetime($r['created_at'], 'Y-m-d H:i:s');
                    yield $r;
                }
            })());
        }

        $paging = $this->paging($request, is_easy() ? 25 : 50);
        $total = (int) $this->db->value('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->all('SELECT a.*' . $sql . ' ORDER BY a.id DESC LIMIT ' . $paging['per_page'] . ' OFFSET ' . $paging['offset'], $params);
        foreach ($rows as &$r) {
            $r['diff'] = self::diff($r['before_json'], $r['after_json']);
        }
        unset($r);

        return $this->view('admin/audit/index', [
            'title' => 'Audit log',
            'rows' => $rows,
            'total' => $total,
            'paging' => $paging,
            'filters' => $filters,
            'users' => $this->db->all('SELECT DISTINCT a.user_id AS id, COALESCE(u.display_name, a.user_name) AS name FROM chroma_audit_log a LEFT JOIN chroma_users u ON u.id = a.user_id WHERE a.user_id IS NOT NULL ORDER BY name'),
            'actions' => $this->db->column('SELECT DISTINCT action FROM chroma_audit_log ORDER BY action'),
            'tables' => $this->db->column("SELECT DISTINCT table_name FROM chroma_audit_log WHERE table_name <> '' ORDER BY table_name"),
            'hasAny' => $total > 0 || (bool) $this->db->value('SELECT 1 FROM chroma_audit_log LIMIT 1'),
        ]);
    }

    /**
     * Every word of the note search required and prefix-matched, safe for
     * BOOLEAN MODE. Words shorter than three characters are dropped because
     * the InnoDB full-text index does not hold them.
     */
    public static function booleanQuery(string $q): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 3) {
                continue;
            }
            $parts[] = '+' . $w . '*';
        }
        return implode(' ', array_slice($parts, 0, 10));
    }

    /** Local calendar date → UTC datetime string. */
    private function toUtc(string $local, string $modify = ''): string
    {
        $d = new \DateTimeImmutable($local, $this->app->timezone());
        if ($modify !== '') {
            $d = $d->modify($modify) ?: $d;
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Readable diff of two JSON snapshots: one row per field, flagged when changed.
     * @return list<array{field:string,before:string,after:string,changed:bool}>
     */
    public static function diff(?string $beforeJson, ?string $afterJson): array
    {
        $before = $beforeJson !== null ? json_decode($beforeJson, true) : null;
        $after = $afterJson !== null ? json_decode($afterJson, true) : null;
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $out = [];
        foreach ($keys as $k) {
            $b = array_key_exists($k, $before) ? self::scalar($before[$k]) : null;
            $a = array_key_exists($k, $after) ? self::scalar($after[$k]) : null;
            $out[] = ['field' => (string) $k, 'before' => $b ?? '', 'after' => $a ?? '', 'changed' => $b !== $a];
        }
        usort($out, fn($x, $y) => ($y['changed'] <=> $x['changed']) ?: strcmp($x['field'], $y['field']));
        return $out;
    }

    private static function scalar(mixed $v): string
    {
        if ($v === null) {
            return '(empty)';
        }
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_array($v)) {
            return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return (string) $v;
    }
}
