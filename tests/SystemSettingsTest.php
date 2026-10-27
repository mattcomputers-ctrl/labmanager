<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Helper;
use Chroma\Core\ValidationException;
use Chroma\Domain\System\AppSettings;
use Chroma\Domain\System\Backups;
use Chroma\Domain\System\Network;
use Chroma\Domain\System\SystemInfo;
use Chroma\Domain\System\Updates;
use Chroma\Http\Controllers\Admin\AuditController;

final class SystemSettingsTest extends TestCase
{
    private function helperMissing(): Helper
    {
        return new Helper($this->app->log, '/nonexistent/chroma-helper');
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir() . '/chroma-test-' . bin2hex(random_bytes(4));
        mkdir($d, 0700, true);
        return $d;
    }

    // ------------------------------------------------------------ settings

    public function testSettingsValidationMessagesAreFieldKeyed(): void
    {
        try {
            AppSettings::validate(['company_name' => '', 'timezone' => 'Mars/Olympus', 'sds_base_url' => 'sds.local/sheet', 'max_upload_mb' => '0', 'max_items_per_project' => 'ten', 'rnd_customer_name' => '']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['company_name', 'timezone', 'sds_base_url', 'max_upload_mb', 'max_items_per_project', 'rnd_customer_name'], array_keys($e->errors));
            $this->assertStringContainsString('http://', $e->errors['sds_base_url']);
        }
    }

    public function testSettingsSaveWritesOnlyChangesAndAudits(): void
    {
        $svc = new AppSettings($this->app->settings, $this->app->audit);
        $input = $svc->current();
        $input['timezone'] = 'America/New_York';
        $input['max_items_per_project'] = '25';
        $input['sds_base_url'] = 'https://sds.precisionink.local/sheet/{E}';
        $input['sell_number_is_iqc_standard'] = '0';
        $changed = $svc->save($input, $this->adminId);
        $this->assertSame(['timezone', 'sds_base_url', 'max_items_per_project', 'sell_number_is_iqc_standard'], $changed);
        $this->assertSame('America/New_York', $this->app->settings->get('timezone'));
        $this->assertSame('25', $this->db->value("SELECT `value` FROM chroma_settings WHERE `key` = 'max_items_per_project'"));
        $audit = $this->db->one("SELECT * FROM chroma_audit_log WHERE action = 'settings.update' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);
        $this->assertSame('America/Chicago', json_decode($audit['before_json'], true)['timezone']);
        $this->assertSame('America/New_York', json_decode($audit['after_json'], true)['timezone']);
        // Saving the same thing again changes nothing and adds no audit row.
        $this->assertSame([], $svc->save($input, $this->adminId));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'settings.update'"));
    }

    public function testTimezoneListIsUsZonesPlusUtc(): void
    {
        $this->assertArrayHasKey('UTC', AppSettings::TIMEZONES);
        $this->assertArrayHasKey('America/Chicago', AppSettings::TIMEZONES);
        foreach (array_keys(AppSettings::TIMEZONES) as $tz) {
            $this->assertInstanceOf(\DateTimeZone::class, new \DateTimeZone($tz));
        }
    }

    // ------------------------------------------------------------- network

    public function testNetworkValidationAcceptsGoodStaticSettings(): void
    {
        $c = Network::validate(['interface' => 'ens18', 'mode' => 'static', 'ip' => '10.10.5.20/24', 'gateway' => '10.10.5.1', 'dns' => '10.10.5.2, 1.1.1.1']);
        $this->assertSame(['interface' => 'ens18', 'dhcp' => false, 'ip' => '10.10.5.20/24', 'gateway' => '10.10.5.1', 'dns' => ['10.10.5.2', '1.1.1.1']], $c);
        $this->assertSame('http://10.10.5.20/', Network::newUrl($c));
        $d = Network::validate(['interface' => 'eth0', 'mode' => 'dhcp', 'ip' => 'garbage']);
        $this->assertTrue($d['dhcp']);
        $this->assertSame('', $d['ip']);
    }

    /** @return list<array{0:array<string,string>,1:string}> */
    public static function badNetwork(): array
    {
        $ok = ['interface' => 'ens18', 'mode' => 'static', 'ip' => '10.10.5.20/24', 'gateway' => '10.10.5.1', 'dns' => '10.10.5.2'];
        return [
            [['interface' => ''] + $ok, 'interface'],
            [['interface' => 'eth 0; rm'] + $ok, 'interface'],
            [['ip' => '10.10.5.20'] + $ok, 'ip'],
            [['ip' => '10.10.5.256/24'] + $ok, 'ip'],
            [['ip' => '10.10.5.20/33'] + $ok, 'ip'],
            [['ip' => '127.0.0.1/8'] + $ok, 'ip'],
            [['ip' => '10.10.5.0/24'] + $ok, 'ip'],
            [['ip' => '10.10.5.255/24'] + $ok, 'ip'],
            [['gateway' => ''] + $ok, 'gateway'],
            [['gateway' => '10.10.5.1/24'] + $ok, 'gateway'],
            [['gateway' => '192.168.1.1'] + $ok, 'gateway'],
            [['dns' => ''] + $ok, 'dns'],
            [['dns' => '10.10.5.2, dns.google'] + $ok, 'dns'],
            [['dns' => '1.1.1.1,2.2.2.2,3.3.3.3,4.4.4.4'] + $ok, 'dns'],
        ];
    }

    /** @param array<string,string> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('badNetwork')]
    public function testNetworkValidationRefusesBadInput(array $input, string $field): void
    {
        try {
            Network::validate($input);
            $this->fail("expected $field to be refused");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors);
        }
    }

    public function testNetworkApplyWithoutHelperFailsGracefullyAndKeepsMirrorEmpty(): void
    {
        $net = new Network($this->helperMissing(), $this->app->settings, $this->app->audit);
        $this->assertFalse($net->helperAvailable());
        $this->assertNull($net->status());
        $this->assertNull($net->mirror());
        $r = $net->apply(Network::validate(['interface' => 'ens18', 'ip' => '10.10.5.20/24', 'gateway' => '10.10.5.1', 'dns' => '1.1.1.1']), $this->adminId);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not installed', $r['message']);
        $this->assertNull($net->mirror(), 'the mirror only records settings that were really applied');
    }

    public function testNetworkMirrorIsReadBack(): void
    {
        $this->app->settings->set('network_mirror', json_encode(['interface' => 'ens18', 'dhcp' => false, 'ip' => '10.10.5.20/24', 'gateway' => '10.10.5.1', 'dns' => ['1.1.1.1']]));
        $net = new Network($this->helperMissing(), $this->app->settings, $this->app->audit);
        $this->assertSame('10.10.5.20/24', $net->mirror()['ip']);
    }

    // ------------------------------------------------------------- backups

    public function testBackupDownloadRefusesPathTraversal(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/chroma-2026-10-27.sql.gz', 'x');
        file_put_contents($dir . '/../chroma-outside.sql.gz', 'x');
        file_put_contents($dir . '/.hidden.sql.gz', 'x');
        file_put_contents($dir . '/notes.txt', 'x');
        $b = new Backups($this->helperMissing(), $dir);

        $this->assertSame(realpath($dir . '/chroma-2026-10-27.sql.gz'), $b->resolveDownload('chroma-2026-10-27.sql.gz'));
        foreach (['../chroma-outside.sql.gz', '..%2Fchroma-outside.sql.gz', '/etc/passwd', 'sub/../../chroma-outside.sql.gz', '.hidden.sql.gz', 'notes.txt', '', 'missing.sql.gz', "a\0.sql.gz"] as $bad) {
            try {
                $b->resolveDownload($bad);
                $this->fail("expected '$bad' to be refused");
            } catch (ValidationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $names = array_column($b->list(), 'name');
        $this->assertSame(['chroma-2026-10-27.sql.gz'], $names, 'the listing never shows files outside the folder or non-backups');
        unlink($dir . '/../chroma-outside.sql.gz');
    }

    public function testBackupCreateWithoutHelperIsRefusedGracefully(): void
    {
        $r = (new Backups($this->helperMissing(), $this->tempDir()))->create();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not installed', $r['message']);
    }

    // ------------------------------------------------------------- updates

    public function testUpdateCheckAndStartWithoutHelper(): void
    {
        $u = new Updates($this->helperMissing(), $this->tempDir());
        $r = $u->check();
        $this->assertFalse($r['ok']);
        $this->assertSame('unavailable', $r['state']);
        $this->expectException(\RuntimeException::class);
        $u->start();
    }

    public function testUpdateLogIsReadIncrementallyUntilTheExitMarker(): void
    {
        $dir = $this->tempDir();
        $u = new Updates($this->helperMissing(), $dir);
        $job = '20261027-120000-0123abcd';
        file_put_contents($u->logPath($job), "Backing up…\nPulling…\npartial");
        $r = $u->read($job);
        $this->assertSame(['Backing up…', 'Pulling…'], $r['lines']);
        $this->assertFalse($r['done']);
        file_put_contents($u->logPath($job), " line\nTests passed\n" . Updates::EXIT_MARKER . " 0\n", FILE_APPEND);
        $r2 = $u->read($job, $r['offset']);
        $this->assertSame(['partial line', 'Tests passed'], $r2['lines']);
        $this->assertTrue($r2['done']);
        $this->assertSame(0, $r2['exit']);
        $this->expectException(ValidationException::class);
        $u->read('../../etc/passwd');
    }

    public function testBranchNamesAreValidated(): void
    {
        $this->assertSame('main', Updates::validateBranch(' main '));
        $this->assertSame('', Updates::validateBranch(''));
        $this->expectException(ValidationException::class);
        Updates::validateBranch('main; rm -rf /');
    }

    // ----------------------------------------------------------- misc

    public function testSystemInfoHelpers(): void
    {
        $this->assertSame('1.5 KB', SystemInfo::humanBytes(1536));
        $this->assertSame('0 B', SystemInfo::humanBytes(0));
        $info = new SystemInfo($this->app);
        $summary = $info->summary();
        $this->assertSame(PHP_VERSION, $summary['php']);
        $this->assertStringContainsString('MariaDB', $summary['mariadb'] . 'MariaDB');
        $this->app->log->info('system-settings-test marker');
        $tail = $info->logTail(5);
        $this->assertLessThanOrEqual(5, count($tail));
        $this->assertStringContainsString('system-settings-test marker', (string) end($tail));
    }

    public function testAuditDiffFlagsChangedFieldsFirst(): void
    {
        $d = AuditController::diff('{"a":1,"b":"x","c":null}', '{"a":2,"b":"x","d":true}');
        $this->assertSame(['a', 'c', 'd', 'b'], array_column($d, 'field'));
        $this->assertTrue($d[0]['changed']);
        $this->assertSame('1', $d[0]['before']);
        $this->assertSame('2', $d[0]['after']);
        $this->assertFalse($d[3]['changed']);
        $this->assertSame('yes', $d[2]['after']);
        $this->assertSame([], AuditController::diff(null, null));
    }

    public function testDhcpRefusalFromAnOlderHelperIsExplainedInPlainWords(): void
    {
        $old = Network::refusalMessage(true, 2, "chroma-helper: set-network needs exactly 4 arguments: <iface> <ip/cidr> <gateway> <dns1,dns2>\n");
        $this->assertStringContainsString('older version', $old);
        $this->assertStringContainsString('Nothing was changed', $old);
        $this->assertStringNotContainsString('exactly 4 arguments', $old, 'no helper jargon reaches the admin');
        $this->assertStringContainsString('choose a fixed address', $old);
        $static = Network::refusalMessage(false, 2, "chroma-helper: set-network needs exactly 4 arguments");
        $this->assertStringContainsString('nothing was changed', $static);
        $generic = Network::refusalMessage(true, 1, "chroma-helper: netplan generate failed");
        $this->assertStringContainsString('netplan generate failed', $generic);
        $this->assertStringContainsString('nothing was changed', $generic);
        $this->assertStringContainsString('Exit code 3', Network::refusalMessage(false, 3, ''));
    }

    public function testAuditNoteSearchUsesFulltextTermsNotLike(): void
    {
        $this->assertSame('+Network* +settings* +applied*', AuditController::booleanQuery('Network settings applied'));
        $this->assertSame('+E9102*', AuditController::booleanQuery('E9102 by'), 'words under three characters are dropped');
        $this->assertSame('', AuditController::booleanQuery('a + -'));
        $this->assertSame('+void* +wrong*', AuditController::booleanQuery('void: "wrong" (-)'), 'operators typed by the user are stripped');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chroma_audit_log' AND INDEX_NAME = 'ft_audit_note' AND INDEX_TYPE = 'FULLTEXT'"), 'migration 0035 adds the FULLTEXT index');
        $this->app->audit->record('test.note', '', null, null, null, 'Network settings applied through the helper', $this->adminId);
        $this->app->audit->record('test.note', '', 42, null, null, 'Something else', $this->adminId);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE MATCH(note) AGAINST (? IN BOOLEAN MODE)', [AuditController::booleanQuery('network applied')]));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE MATCH(note) AGAINST (? IN BOOLEAN MODE)', [AuditController::booleanQuery('network removed')]));
    }
}
