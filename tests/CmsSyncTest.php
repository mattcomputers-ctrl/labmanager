<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\ValidationException;
use Chroma\Domain\Cms\CmsConnection;
use Chroma\Domain\Cms\CustomerSource;
use Chroma\Domain\Cms\CustomerSync;
use Chroma\Domain\Customers\Customers;

/** In-memory stand-in for the MSSQL source. */
final class FakeCustomerSource implements CustomerSource
{
    /** @param list<array{id:string,name:string,active:bool}> $rows */
    public function __construct(public array $rows)
    {
    }

    public function fetchCustomers(): iterable
    {
        return $this->rows;
    }

    public function preview(int $limit = 10): array
    {
        return array_slice($this->rows, 0, $limit);
    }
}

final class CmsSyncTest extends TestCase
{
    private function sync(): CustomerSync
    {
        return new CustomerSync($this->db, $this->app->settings, $this->app->audit);
    }

    /** @return array<string,mixed>|null */
    private function byCmsId(string $id): ?array
    {
        return $this->db->one('SELECT * FROM chroma_customers WHERE cms_customer_id = ?', [$id]);
    }

    public function testCreatesMissingCustomersAsCmsSourced(): void
    {
        $r = $this->sync()->run(new FakeCustomerSource([
            ['id' => 'C100', 'name' => 'Acme Packaging', 'active' => true],
            ['id' => 'C101', 'name' => 'Big Box Labels', 'active' => false],
        ]), $this->adminId);
        $this->assertSame('ok', $r['status']);
        $this->assertSame(2, $r['created']);
        $acme = $this->byCmsId('C100');
        $this->assertSame('Acme Packaging', $acme['name']);
        $this->assertSame('cms', $acme['source']);
        $this->assertSame(1, (int) $acme['active']);
        $this->assertSame(0, (int) $this->byCmsId('C101')['active']);
        // run recorded and settings updated
        $run = $this->db->one('SELECT * FROM chroma_cms_sync_runs WHERE id = ?', [$r['run_id']]);
        $this->assertSame('ok', $run['status']);
        $this->assertSame(2, (int) $run['created_count']);
        $this->assertNotSame('', $this->app->settings->get('cms_last_sync_at'));
        $this->assertStringStartsWith('OK:', (string) $this->app->settings->get('cms_last_sync_result'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'cms.sync'"));
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'customer.create'"));
    }

    public function testMatchesOnNormalizedNameAndAdoptsCmsId(): void
    {
        $manualId = (new Customers($this->db))->findOrCreate('ACME Packaging, Inc.', 'manual');
        $r = $this->sync()->run(new FakeCustomerSource([['id' => 'C100', 'name' => 'Acme Packaging Inc', 'active' => true]]), $this->adminId);
        $this->assertSame(0, $r['created']);
        $this->assertSame(1, $r['matched']);
        $row = $this->db->one('SELECT * FROM chroma_customers WHERE id = ?', [$manualId]);
        $this->assertSame('C100', $row['cms_customer_id']);
        $this->assertSame('ACME Packaging, Inc.', $row['name'], 'a manually typed name is kept');
        $this->assertSame('manual', $row['source']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers WHERE name_normalized = ?', ['acme packaging inc']));
    }

    public function testMatchesOnCmsIdBeforeNameAndRenamesCmsRows(): void
    {
        $this->sync()->run(new FakeCustomerSource([['id' => 'C7', 'name' => 'Old Name Ltd', 'active' => true]]), $this->adminId);
        $r = $this->sync()->run(new FakeCustomerSource([['id' => 'C7', 'name' => 'New Name Ltd', 'active' => true]]), $this->adminId);
        $this->assertSame(1, $r['updated']);
        $this->assertSame(0, $r['created']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers WHERE cms_customer_id = ?', ['C7']));
        $this->assertSame('New Name Ltd', $this->byCmsId('C7')['name']);
        $this->assertSame('new name ltd', $this->byCmsId('C7')['name_normalized']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'customer.update' AND row_id = ?", [(int) $this->byCmsId('C7')['id']]));
    }

    public function testIsIdempotent(): void
    {
        $src = new FakeCustomerSource([
            ['id' => 'A1', 'name' => 'One', 'active' => true],
            ['id' => 'A2', 'name' => 'Two', 'active' => true],
        ]);
        $this->sync()->run($src, $this->adminId);
        $before = $this->db->all('SELECT id, name, cms_customer_id, active, source FROM chroma_customers ORDER BY id');
        $r = $this->sync()->run($src, $this->adminId);
        $this->assertSame(0, $r['created']);
        $this->assertSame(0, $r['updated']);
        $this->assertSame(2, $r['matched']);
        $this->assertSame($before, $this->db->all('SELECT id, name, cms_customer_id, active, source FROM chroma_customers ORDER BY id'));
    }

    public function testSkipsBlankAndDuplicateRows(): void
    {
        $r = $this->sync()->run(new FakeCustomerSource([
            ['id' => '', 'name' => 'No id', 'active' => true],
            ['id' => 'B1', 'name' => '   ', 'active' => true],
            ['id' => 'B2', 'name' => 'Real', 'active' => true],
            ['id' => 'B2', 'name' => 'Real again', 'active' => true],
        ]), $this->adminId);
        $this->assertSame(3, $r['skipped']);
        $this->assertSame(1, $r['created']);
        $this->assertSame('Real', $this->byCmsId('B2')['name']);
    }

    public function testMissingCmsCustomersAreOnlyDeactivatedWhenSettingIsOn(): void
    {
        $this->sync()->run(new FakeCustomerSource([
            ['id' => 'D1', 'name' => 'Stays', 'active' => true],
            ['id' => 'D2', 'name' => 'Goes', 'active' => true],
        ]), $this->adminId);
        $manualId = (new Customers($this->db))->findOrCreate('Hand typed', 'manual');

        // Default: nothing deactivated.
        $r = $this->sync()->run(new FakeCustomerSource([['id' => 'D1', 'name' => 'Stays', 'active' => true]]), $this->adminId);
        $this->assertSame(0, $r['deactivated']);
        $this->assertSame(1, (int) $this->byCmsId('D2')['active']);

        $this->app->settings->set('cms_deactivate_missing', '1');
        $r = $this->sync()->run(new FakeCustomerSource([['id' => 'D1', 'name' => 'Stays', 'active' => true]]), $this->adminId);
        $this->assertSame(1, $r['deactivated']);
        $this->assertSame(0, (int) $this->byCmsId('D2')['active']);
        $this->assertSame(1, (int) $this->db->value('SELECT active FROM chroma_customers WHERE id = ?', [$manualId]), 'manual customers are never touched');
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM chroma_customers WHERE source <> 'system'"), 'nothing is deleted');

        // An empty source must not deactivate everything.
        $r = $this->sync()->run(new FakeCustomerSource([]), $this->adminId);
        $this->assertSame(0, $r['deactivated']);
        $this->assertSame(1, (int) $this->byCmsId('D1')['active']);
    }

    public function testSourceFailureIsRecordedNotThrown(): void
    {
        $src = new class implements CustomerSource {
            public function fetchCustomers(): iterable
            {
                throw new \RuntimeException('Could not connect to the CMS SQL Server at 10.0.0.1:1433.');
            }
            public function preview(int $limit = 10): array
            {
                return [];
            }
        };
        $r = $this->sync()->run($src, $this->adminId);
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('Could not connect', $r['message']);
        $run = $this->db->one('SELECT * FROM chroma_cms_sync_runs ORDER BY id DESC LIMIT 1');
        $this->assertSame('failed', $run['status']);
        $this->assertStringStartsWith('Failed:', (string) $this->app->settings->get('cms_last_sync_result'));
    }

    // ---------------------------------------------------------- CmsConnection

    public function testConnectionValidationRefusesBadInput(): void
    {
        try {
            CmsConnection::validate(['host' => 'bad host/name', 'port' => '70000', 'database' => 'CMS;DROP', 'cms_customer_table' => 'Customers; DELETE', 'cms_customer_id_column' => 'Cust ID', 'cms_customer_filter' => "1=1; DROP TABLE x"]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('host', $e->errors);
            $this->assertArrayHasKey('port', $e->errors);
            $this->assertArrayHasKey('database', $e->errors);
            $this->assertArrayHasKey('cms_customer_table', $e->errors);
            $this->assertArrayHasKey('cms_customer_id_column', $e->errors);
            $this->assertArrayHasKey('cms_customer_filter', $e->errors);
            $this->assertStringContainsString('CMS administrator', $e->errors['cms_customer_id_column']);
        }
    }

    public function testEnablingRequiresHostUserAndTable(): void
    {
        try {
            CmsConnection::validate(['enabled' => '1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('host', $e->errors);
            $this->assertArrayHasKey('user', $e->errors);
            $this->assertArrayHasKey('cms_customer_table', $e->errors);
        }
        $clean = CmsConnection::validate(['host' => 'MARKOV-SQL\\CMS', 'user' => 'chroma_ro', 'password' => 'p@ss word', 'enabled' => '1', 'cms_customer_table' => 'dbo.Customers', 'cms_customer_id_column' => 'CustomerID', 'cms_customer_name_column' => 'CustomerName']);
        $this->assertSame('1433', $clean['port']);
        $this->assertSame('CMS', $clean['database']);
        $this->assertTrue($clean['enabled']);
    }

    public function testCustomerQueryIsBuiltOnlyFromTheMapping(): void
    {
        $s = $this->app->settings;
        $s->set('cms_customer_table', 'dbo.Customers');
        $s->set('cms_customer_id_column', 'CustomerID');
        $s->set('cms_customer_name_column', 'Name');
        $s->set('cms_customer_active_column', 'IsActive');
        $s->set('cms_customer_filter', "Type = 'CUST'");
        $cms = new CmsConnection($this->app->config, $s);
        $this->assertSame("SELECT TOP 10 [CustomerID] AS cms_id, [Name] AS cms_name, [IsActive] AS cms_active FROM [dbo].[Customers] WHERE Type = 'CUST' ORDER BY [CustomerID]", $cms->customerQuery(10));
        $this->assertTrue($cms->hasMapping());

        $s->set('cms_customer_table', '');
        $this->assertFalse($cms->hasMapping());
        $this->expectException(ValidationException::class);
        $cms->customerQuery();
    }

    public function testRowNormalizationReadsActiveFlags(): void
    {
        $this->assertTrue(CmsConnection::normalizeRow(['cms_id' => ' 1 ', 'cms_name' => ' X ', 'cms_active' => 'Y'], true)['active']);
        $this->assertFalse(CmsConnection::normalizeRow(['cms_id' => '1', 'cms_name' => 'X', 'cms_active' => '0'], true)['active']);
        $this->assertTrue(CmsConnection::normalizeRow(['cms_id' => '1', 'cms_name' => 'X'], false)['active']);
        $this->assertSame('1', CmsConnection::normalizeRow(['cms_id' => ' 1 ', 'cms_name' => ' X '], false)['id']);
    }

    public function testDriverStatusIsRememberedAndEverythingWorksDisabled(): void
    {
        $cms = new CmsConnection($this->app->config, $this->app->settings);
        $this->assertFalse($cms->isEnabled());
        $status = $cms->driverStatus();
        $this->assertContains($status, ['ok', 'driver not installed']);
        $this->assertSame($status, $this->app->settings->get('cms_driver_status'));
        $this->assertSame('not set', $cms->connectionSummary()['password_state']);
        $this->assertSame([], (new CustomerSync($this->db, $this->app->settings, $this->app->audit))->recentRuns());
    }

    public function testPasswordsThatTheConfigFileCannotStoreAreRefused(): void
    {
        foreach (['S3cret"quote', 'back\\slash', "line\nbreak"] as $bad) {
            try {
                CmsConnection::validate(['host' => 'sql', 'user' => 'ro', 'password' => $bad]);
                $this->fail('expected ValidationException for ' . $bad);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors);
            }
        }
        // Everything else is fine, including spaces, #, $, ; and quotes of the other kind.
        $clean = CmsConnection::validate(['host' => 'sql', 'user' => 'ro', 'password' => "p@ss word#1\$;it's"]);
        $this->assertSame("p@ss word#1\$;it's", $clean['password']);
    }

    public function testEnablingNeedsASavedOrTypedPassword(): void
    {
        $base = ['host' => 'sql', 'user' => 'ro', 'enabled' => '1', 'cms_customer_table' => 'dbo.Customers'];
        try {
            CmsConnection::validate($base, false);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors);
            $this->assertStringContainsString('before turning the connection on', $e->errors['password']);
        }
        $this->assertTrue(CmsConnection::validate($base, true)['enabled'], 'a password already in config.env is enough');
        $this->assertTrue(CmsConnection::validate($base + ['password' => 'typed-now'], false)['enabled']);
        try {
            CmsConnection::validate($base + ['clear_password' => '1'], true);
            $this->fail('removing the password while turning the connection on must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors);
        }
    }

    public function testSaveWritesThePasswordOnlyToConfigAndReadsItBackExactly(): void
    {
        $file = sys_get_temp_dir() . '/chroma-cms-' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($file, "# dev\nDB_NAME=chroma\n");
        $cms = new CmsConnection(\Chroma\Core\Config::load($file), $this->app->settings);
        $pw = "p@ss word#1\$;it's";
        [$before, $after] = $cms->save(['host' => 'MARKOV-SQL', 'user' => 'chroma_ro', 'password' => $pw, 'cms_customer_table' => 'dbo.Customers', 'cms_customer_id_column' => 'CustomerID', 'cms_customer_name_column' => 'CustomerName', 'enabled' => '1'], $this->adminId);
        $this->assertSame('not set', $before['password_state']);
        $this->assertSame('set', $after['password_state']);
        $this->assertArrayNotHasKey('password', $after, 'the audit snapshot never holds the secret');
        $this->assertSame($pw, \Chroma\Core\Config::load($file)->get('CMS_PASS'), 'the password reads back exactly as typed');
        $this->assertStringContainsString("DB_NAME=chroma\n", (string) file_get_contents($file), 'other config lines are kept');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM chroma_settings WHERE `value` LIKE ?", ['%' . $pw . '%']), 'the password is never stored in the database');
        $this->assertSame('1', $this->app->settings->get('cms_enabled'));
        // A blank password keeps the saved one; turning it on again stays allowed.
        [, $after2] = $cms->save(['host' => 'MARKOV-SQL', 'user' => 'chroma_ro', 'password' => '', 'cms_customer_table' => 'dbo.Customers', 'enabled' => '1'], $this->adminId);
        $this->assertSame('set', $after2['password_state']);
        $this->assertSame($pw, \Chroma\Core\Config::load($file)->get('CMS_PASS'));
        @unlink($file);
    }
}
