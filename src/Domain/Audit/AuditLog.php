<?php
declare(strict_types=1);

namespace Chroma\Domain\Audit;

use Chroma\Core\Auth;
use Chroma\Core\Db;

/**
 * Every code assignment, void, counter change and admin change is recorded
 * here with before/after snapshots. Nothing that holds a code is ever
 * hard-deleted, so this log plus the voided flags is the full history.
 */
final class AuditLog
{
    public function __construct(private readonly Db $db, private readonly Auth $auth)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(string $action, string $table = '', ?int $rowId = null, ?array $before = null, ?array $after = null, string $note = '', ?int $actorId = null): int
    {
        $actorId = $actorId ?? $this->auth->id();
        $name = '';
        if ($actorId !== null) {
            $u = $this->auth->id() === $actorId ? $this->auth->user() : $this->db->one('SELECT username, display_name FROM chroma_users WHERE id = ?', [$actorId]);
            $name = $u ? (string) ($u['display_name'] ?: $u['username']) : '';
        }
        if ($name === '' && PHP_SAPI === 'cli') {
            $name = 'command line';
        }
        return $this->db->insert('chroma_audit_log', [
            'user_id' => $actorId,
            'user_name' => mb_substr($name, 0, 150),
            'action' => mb_substr($action, 0, 80),
            'table_name' => $table,
            'row_id' => $rowId,
            'before_json' => $before === null ? null : json_encode(self::clean($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => $after === null ? null : json_encode(self::clean($after), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'note' => mb_substr($note, 0, 500),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'created_at' => $this->db->now(),
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function clean(array $row): array
    {
        unset($row['password_hash'], $row['token_hash']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function forRow(string $table, int $rowId, int $limit = 100): array
    {
        return $this->db->all(
            'SELECT * FROM chroma_audit_log WHERE table_name = ? AND row_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
            [$table, $rowId]
        );
    }
}
