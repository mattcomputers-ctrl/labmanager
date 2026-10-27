<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Permissions;
use Chroma\Core\ValidationException;
use Chroma\Domain\Users\GroupAdmin;
use Chroma\Domain\Users\UserAdmin;

final class UserAdminTest extends TestCase
{
    private UserAdmin $users;
    private GroupAdmin $groups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec('DELETE FROM chroma_groups WHERE is_system = 0');
        $this->users = new UserAdmin($this->db, $this->app->auth, $this->app->audit);
        $this->groups = new GroupAdmin($this->db, $this->app->audit, $this->users);
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM chroma_groups WHERE is_system = 0');
        parent::tearDown();
    }

    /** A user who may manage users but is NOT an Administrator. */
    private function makeUserManager(string $username = 'manager'): int
    {
        $gid = $this->groups->create(['name' => 'User managers', 'description' => 'test', 'permissions' => [Permissions::ADMIN_USERS, Permissions::PROJECTS_VIEW]]);
        $id = $this->makeUser($username);
        $this->db->run('INSERT IGNORE INTO chroma_user_groups (user_id, group_id) VALUES (?, ?)', [$id, $gid]);
        return $id;
    }

    // ------------------------------------------------------------ creating

    public function testCreateHashesWithArgon2idAndForcesPasswordChange(): void
    {
        $r = $this->users->create(['username' => 'JSmith', 'display_name' => 'Jane Smith', 'password_mode' => 'typed', 'password' => 'a long enough password', 'password_confirm' => 'a long enough password', 'group_ids' => []]);
        $row = $this->db->one('SELECT * FROM chroma_users WHERE id = ?', [$r['id']]);
        $this->assertSame('jsmith', $row['username'], 'usernames are stored lowercase');
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertSame('a long enough password', $r['password']);
        $this->assertStringStartsWith('$argon2id$', (string) $row['password_hash']);
        $this->assertTrue(password_verify('a long enough password', (string) $row['password_hash']));
    }

    public function testCreateGeneratesAMemorablePasswordWhenAsked(): void
    {
        $r = $this->users->create(['username' => 'gen', 'display_name' => 'Gen', 'password_mode' => 'generate']);
        $this->assertNull(\Chroma\Core\Auth::passwordProblem($r['password']), 'generated password passes the policy');
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+-[A-Z][a-z]+-[A-Z][a-z]+-\d{2}$/', $r['password']);
        $row = $this->db->one('SELECT password_hash FROM chroma_users WHERE id = ?', [$r['id']]);
        $this->assertTrue(password_verify($r['password'], (string) $row['password_hash']));
    }

    public function testCreateRejectsBadInputWithPlainMessages(): void
    {
        try {
            $this->users->create(['username' => 'admin', 'display_name' => 'Dup', 'password_mode' => 'generate']);
            $this->fail('duplicate username accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already taken', $e->errors['username']);
        }
        try {
            $this->users->create(['username' => 'short', 'display_name' => 'S', 'password_mode' => 'typed', 'password' => 'short', 'password_confirm' => 'short']);
            $this->fail('short password accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors);
        }
        try {
            $this->users->create(['username' => 'mismatch', 'display_name' => 'M', 'password_mode' => 'typed', 'password' => 'long enough one', 'password_confirm' => 'long enough two']);
            $this->fail('mismatched passwords accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password_confirm', $e->errors);
        }
        try {
            $this->users->create(['username' => 'bad name!', 'display_name' => 'B', 'password_mode' => 'generate']);
            $this->fail('username with spaces accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->errors);
        }
    }

    public function testAuditNeverContainsPasswordHashes(): void
    {
        $r = $this->users->create(['username' => 'audited', 'display_name' => 'Audited', 'password_mode' => 'generate']);
        $this->users->resetPassword($r['id'], ['password_mode' => 'generate']);
        $rows = $this->db->all('SELECT action, before_json, after_json, note FROM chroma_audit_log WHERE table_name = "chroma_users" AND row_id = ?', [$r['id']]);
        $this->assertSame(['user.create', 'user.reset_password'], array_column($rows, 'action'));
        foreach ($rows as $row) {
            $blob = (string) $row['before_json'] . (string) $row['after_json'] . (string) $row['note'];
            $this->assertStringNotContainsString('password_hash', $blob);
            $this->assertStringNotContainsString('argon2', $blob);
            $this->assertStringNotContainsString($r['password'], $blob);
        }
    }

    public function testResetPasswordForcesChangeAndSignsOutOtherSessions(): void
    {
        $id = $this->makeUser('reset');
        $this->db->insert('chroma_sessions', ['id' => 'sess-other', 'user_id' => $id, 'data' => '', 'created_at' => $this->db->now(), 'last_seen_at' => $this->db->now(), 'expires_at' => $this->db->now()]);
        $pw = $this->users->resetPassword($id, ['password_mode' => 'typed', 'password' => 'temporary password 1', 'password_confirm' => 'temporary password 1']);
        $row = $this->db->one('SELECT * FROM chroma_users WHERE id = ?', [$id]);
        $this->assertSame('temporary password 1', $pw);
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertTrue(password_verify($pw, (string) $row['password_hash']));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sessions WHERE user_id = ?', [$id]));
    }

    // ------------------------------------------------- admin protections

    public function testYouCannotDeactivateYourself(): void
    {
        $this->makeUser('admin2', 'Administrators'); // so the last-admin rule is not what fires
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('your own account');
        $this->users->deactivate($this->adminId);
    }

    public function testYouCannotRemoveYourselfFromAdministrators(): void
    {
        $this->makeUser('admin2', 'Administrators'); // a second admin exists, so the last-admin rule is not what fires
        $adminGroup = $this->users->adminGroupId();
        try {
            $this->users->update($this->adminId, ['display_name' => 'Admin', 'group_ids' => []]);
            $this->fail('removed yourself from Administrators via the user form');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('remove yourself', $e->getMessage());
        }
        try {
            $this->groups->removeMember($adminGroup, $this->adminId);
            $this->fail('removed yourself from Administrators via the group page');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('remove yourself', $e->getMessage());
        }
        $this->assertTrue($this->users->isAdministrator($this->adminId));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'group.remove_member'"));

        // Another Administrator may still remove you.
        $other = (int) $this->db->value('SELECT id FROM chroma_users WHERE username = ?', ['admin2']);
        $this->app->auth->actAs($other);
        $this->groups->removeMember($adminGroup, $this->adminId);
        $this->assertFalse($this->users->isAdministrator($this->adminId));
    }

    public function testLastActiveAdministratorCannotBeDeactivatedOrRemovedFromTheGroup(): void
    {
        $other = $this->makeUser('other', 'Administrators');
        $this->app->auth->actAs($other); // another admin deactivates the first one: allowed, two admins exist
        $this->users->deactivate($this->adminId);
        $this->assertSame(0, (int) $this->db->value('SELECT active FROM chroma_users WHERE id = ?', [$this->adminId]));
        $this->assertSame(1, $this->users->activeAdministratorCount());

        // "other" is now the last active Administrator and may not leave the group, by either route.
        try {
            $this->users->update($other, ['display_name' => 'Other', 'group_ids' => []]);
            $this->fail('last admin removed from Administrators');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('last active Administrator', $e->getMessage());
        }
        try {
            $this->groups->removeMember($this->users->adminGroupId(), $other);
            $this->fail('last admin removed from Administrators via the group page');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('last active Administrator', $e->getMessage());
        }
        $this->assertTrue($this->users->isAdministrator($other));

        // Deactivated administrators do not count: reactivating the first one frees "other" to leave
        // (removed by the first admin, since nobody may remove themselves).
        $this->users->reactivate($this->adminId);
        $this->assertSame(2, $this->users->activeAdministratorCount());
        $this->app->auth->actAs($this->adminId);
        $this->users->update($other, ['display_name' => 'Other', 'group_ids' => []]);
        $this->assertFalse($this->users->isAdministrator($other));
        $this->assertSame(1, $this->users->activeAdministratorCount());
    }

    public function testOnlyAnAdministratorMayDeactivateOrDemoteAnAdministrator(): void
    {
        $this->makeUser('admin2', 'Administrators');
        $manager = $this->makeUserManager();
        $this->app->auth->actAs($manager);
        $this->assertTrue($this->app->auth->can(Permissions::ADMIN_USERS));
        $this->assertFalse($this->app->auth->isAdmin());

        try {
            $this->users->deactivate($this->adminId);
            $this->fail('non-admin deactivated an administrator');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only an Administrator', $e->getMessage());
        }
        try {
            $this->users->update($this->adminId, ['display_name' => 'Admin', 'group_ids' => []]);
            $this->fail('non-admin removed an administrator from the group');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only an Administrator', $e->getMessage());
        }
        $this->assertTrue($this->users->isAdministrator($this->adminId));
        $this->assertSame(1, (int) $this->db->value('SELECT active FROM chroma_users WHERE id = ?', [$this->adminId]));

        // A manager may still deactivate an ordinary user.
        $plain = $this->makeUser('plain', 'Lab');
        $this->users->deactivate($plain);
        $this->assertSame(0, (int) $this->db->value('SELECT active FROM chroma_users WHERE id = ?', [$plain]));
    }

    public function testOnlyAnAdministratorMayPromoteToAdministrators(): void
    {
        $manager = $this->makeUserManager();
        $this->app->auth->actAs($manager);
        $adminGroup = $this->users->adminGroupId();
        try {
            $this->users->create(['username' => 'sneaky', 'display_name' => 'Sneaky', 'password_mode' => 'generate', 'group_ids' => [$adminGroup]]);
            $this->fail('non-admin created an administrator');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group_ids', $e->errors);
        }
        try {
            $this->groups->addMember($adminGroup, $manager);
            $this->fail('non-admin added themselves to Administrators');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group_ids', $e->errors);
        }
        $this->assertFalse($this->users->isAdministrator($manager));

        // An administrator can.
        $this->app->auth->actAs($this->adminId);
        $this->groups->addMember($adminGroup, $manager);
        $this->assertTrue($this->users->isAdministrator($manager));
    }

    // ------------------------------------------------------------ groups

    public function testEffectivePermissionsAreTheUnionOfGroups(): void
    {
        $a = $this->groups->create(['name' => 'Alpha', 'permissions' => [Permissions::CODES_VIEW]]);
        $b = $this->groups->create(['name' => 'Beta', 'permissions' => [Permissions::REPORTS_VIEW]]);
        $uid = $this->makeUser('union');
        $this->groups->addMember($a, $uid);
        $this->groups->addMember($b, $uid);
        $this->app->auth->actAs($uid);
        $this->assertTrue($this->app->auth->can(Permissions::CODES_VIEW));
        $this->assertTrue($this->app->auth->can(Permissions::REPORTS_VIEW));
        $this->assertFalse($this->app->auth->can(Permissions::CODES_VOID));

        $this->app->auth->actAs($this->adminId);
        $this->groups->setPermissions($b, [Permissions::REPORTS_VIEW, Permissions::CODES_VOID]);
        $this->groups->removeMember($a, $uid);
        $this->app->auth->actAs($uid);
        $this->assertFalse($this->app->auth->can(Permissions::CODES_VIEW), 'lost with group Alpha');
        $this->assertTrue($this->app->auth->can(Permissions::CODES_VOID), 'gained through group Beta');
        $this->assertSame([Permissions::CODES_VOID, Permissions::REPORTS_VIEW], $this->groups->find($b)['permissions']);
    }

    public function testUnknownPermissionKeysAreIgnored(): void
    {
        $id = $this->groups->create(['name' => 'Odd', 'permissions' => ['not.a.permission', Permissions::CODES_VIEW]]);
        $this->assertSame([Permissions::CODES_VIEW], $this->groups->find($id)['permissions']);
    }

    public function testSystemGroupsCannotBeDeletedAndAdministratorsKeepEverything(): void
    {
        $adminGroup = $this->users->adminGroupId();
        try {
            $this->groups->delete($adminGroup);
            $this->fail('deleted a system group');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('built-in', $e->getMessage());
        }
        try {
            $this->groups->setPermissions($adminGroup, [Permissions::CODES_VIEW]);
            $this->fail('stripped permissions from Administrators');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('every permission', $e->getMessage());
        }
        $this->assertCount(count(Permissions::all()), $this->groups->find($adminGroup)['permissions']);
        // Description of a system group can still change.
        $this->groups->update($adminGroup, ['name' => 'Administrators', 'description' => 'Changed']);
        $this->assertSame('Changed', $this->groups->find($adminGroup)['description']);
    }

    public function testDeletingAGroupWithMembersRemovesMembershipsOnly(): void
    {
        $gid = $this->groups->create(['name' => 'Temp', 'permissions' => [Permissions::CODES_VIEW]]);
        $uid = $this->makeUser('member', 'Lab');
        $this->groups->addMember($gid, $uid);
        $this->assertSame(1, (int) $this->groups->list()[array_search('Temp', array_column($this->groups->list(), 'name'), true)]['member_count']);
        $this->groups->delete($gid);
        $this->assertNull($this->groups->find($gid));
        $this->assertNotNull($this->db->one('SELECT id FROM chroma_users WHERE id = ?', [$uid]), 'the user still exists');
        $this->assertSame(['Lab'], $this->users->find($uid)['group_names']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "group.delete" AND row_id = ?', [$gid]));
    }

    public function testDuplicateGroupNamesAreRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->groups->create(['name' => 'Lab']);
    }
}
