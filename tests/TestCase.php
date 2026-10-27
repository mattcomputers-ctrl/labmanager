<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\App;
use Chroma\Core\Auth;
use Chroma\Core\Db;
use Chroma\Db\Seeder;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected App $app;
    protected Db $db;
    protected int $adminId;

    /** Tables holding data (not reference data) that are wiped between tests. */
    protected const DATA_TABLES = [
        'chroma_project_activity', 'chroma_project_attachments', 'chroma_project_tasks', 'chroma_project_team',
        'chroma_project_items', 'chroma_projects', 'chroma_sell_numbers', 'chroma_internal_codes',
        'chroma_import_issues', 'chroma_imports', 'chroma_audit_log', 'chroma_login_attempts', 'chroma_sessions',
        'chroma_api_tokens', 'chroma_cms_sync_runs', 'chroma_user_groups', 'chroma_users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::get();
        $this->db = $this->app->db;
        $this->resetData();
        $this->adminId = $this->makeUser('admin', 'Administrators');
        $this->app->auth->actAs($this->adminId);
    }

    protected function resetData(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::DATA_TABLES as $t) {
            $this->db->exec("TRUNCATE TABLE $t");
        }
        $this->db->exec('DELETE FROM chroma_customers WHERE source <> "system"');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        // Restore seeded counters so tests are deterministic.
        $this->db->exec("UPDATE chroma_sequences SET next_value = 9102 WHERE name = 'internal_code'");
        $this->db->exec("UPDATE chroma_sequences SET next_value = 101 WHERE name = 'project_number'");
        $this->db->exec('DELETE FROM chroma_code_prefix_suffixes');
        $this->db->exec('DELETE FROM chroma_code_prefixes');
        $this->db->exec('DELETE FROM chroma_code_suffixes');
        $this->db->exec('DELETE FROM chroma_settings');
        (new Seeder($this->db))->run();
    }

    protected function makeUser(string $username, string ...$groups): int
    {
        $now = $this->db->now();
        $id = $this->db->insert('chroma_users', [
            'username' => $username, 'display_name' => ucfirst($username), 'password_hash' => Auth::hash('password-' . $username),
            'active' => 1, 'must_change_password' => 0, 'ui_mode' => 'easy', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($groups as $g) {
            $gid = (int) $this->db->value('SELECT id FROM chroma_groups WHERE name = ?', [$g]);
            $this->db->run('INSERT IGNORE INTO chroma_user_groups (user_id, group_id) VALUES (?, ?)', [$id, $gid]);
        }
        return $id;
    }

    protected function prefixId(string $code): int
    {
        return (int) $this->db->value('SELECT id FROM chroma_code_prefixes WHERE code = ?', [$code]);
    }

    protected function suffixId(string $code): int
    {
        return (int) $this->db->value('SELECT id FROM chroma_code_suffixes WHERE code = ?', [$code]);
    }
}
