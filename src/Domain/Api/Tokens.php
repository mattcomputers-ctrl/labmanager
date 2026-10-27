<?php
declare(strict_types=1);

namespace Chroma\Domain\Api;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;

/**
 * Bearer tokens for the read-only JSON API. The plaintext token is shown
 * once at creation; only its SHA-256 hash is stored.
 */
final class Tokens
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Create a token and return [id, plaintext]. @return array{0:int,1:string} */
    public function create(string $name, ?int $userId): array
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::single('name', 'Give the token a name that says what will use it, e.g. "ERP read access".');
        }
        $plain = 'chroma_' . bin2hex(random_bytes(24));
        $id = $this->db->insert('chroma_api_tokens', [
            'name' => mb_substr($name, 0, 100),
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 7, 8),
            'created_by' => $userId,
            'created_at' => $this->db->now(),
        ]);
        return [$id, $plain];
    }

    public function revoke(int $id): void
    {
        $this->db->exec('UPDATE chroma_api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT t.*, u.display_name AS created_by_name FROM chroma_api_tokens t LEFT JOIN chroma_users u ON u.id = t.created_by ORDER BY t.id DESC');
    }

    /** Validate a bearer token; returns the token row or null. Touches last_used_at. @return array<string,mixed>|null */
    public function authenticate(?string $bearer): ?array
    {
        if ($bearer === null || !str_starts_with($bearer, 'chroma_')) {
            return null;
        }
        $row = $this->db->one('SELECT * FROM chroma_api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', trim($bearer))]);
        if ($row === null) {
            return null;
        }
        $this->db->exec('UPDATE chroma_api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $row['id']]);
        return $row;
    }
}
