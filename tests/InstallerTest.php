<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Helper;

/**
 * Shell-level checks for the installer module (install.sh, bin/backup, bin/uninstall,
 * bin/chroma-helper, bin/chroma-update, deploy/*). Nothing here needs root: the helper
 * validates its arguments before it checks who is running it, and bin/backup is run
 * against the test database into a temporary directory.
 */
final class InstallerTest extends TestCase
{
    /** @var list<string> */
    private const SHELL_FILES = ['install.sh', 'bin/backup', 'bin/uninstall', 'bin/chroma-helper', 'bin/chroma-update'];

    private function root(): string
    {
        return $this->app->root;
    }

    /**
     * @param list<string> $argv
     * @param array<string,string> $env
     * @return array{0:int,1:string,2:string}
     */
    private function sh(array $argv, array $env = []): array
    {
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root(), $env + ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir()]);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    private function helper(string ...$args): array
    {
        return $this->sh(array_merge(['bash', $this->root() . '/bin/chroma-helper'], $args));
    }

    private function hasCommand(string $name): bool
    {
        [$code] = $this->sh(['sh', '-c', 'command -v ' . escapeshellarg($name) . ' >/dev/null 2>&1']);
        return $code === 0;
    }

    public function testEveryShellFileParses(): void
    {
        foreach (self::SHELL_FILES as $f) {
            $this->assertFileExists($this->root() . '/' . $f);
            $this->assertTrue(is_executable($this->root() . '/' . $f), "$f must be executable");
            [$code, , $err] = $this->sh(['bash', '-n', $this->root() . '/' . $f]);
            $this->assertSame(0, $code, "bash -n $f: $err");
        }
    }

    public function testShellcheckIsCleanWhenAvailable(): void
    {
        if (!$this->hasCommand('shellcheck')) {
            $this->markTestSkipped('shellcheck is not installed (apt-get install shellcheck)');
        }
        foreach (self::SHELL_FILES as $f) {
            [$code, $out, $err] = $this->sh(['shellcheck', '-S', 'warning', $this->root() . '/' . $f]);
            $this->assertSame(0, $code, "shellcheck $f:\n$out$err");
        }
    }

    public function testHelperRejectsUnknownOrMalformedCommands(): void
    {
        $cases = [
            [],                                   // no command
            ['bogus'],
            ['check-update', 'extra'],
            ['backup', 'extra'],
            ['list-backups', 'extra'],
            ['network-status', 'extra'],
            ['restart-services', 'extra'],
            ['update', 'main', 'extra'],
            ['set-network', 'eth0', '10.0.0.5/24'],   // too few arguments
            ['set-network', 'eth0', 'dhcp', '10.0.0.1'],
            ['set-network', 'eth0;id', 'dhcp'],
            ['set-network', 'eth0', 'DHCP'],
            ['set-network', 'eth0', '10.0.0.5/24', '10.0.0.1', '1.1.1.1', 'extra'],
            ['sh'], ['bash', '-c', 'id'], ['--', 'ls'],
        ];
        foreach ($cases as $args) {
            [$code, , $err] = $this->helper(...$args);
            $this->assertSame(2, $code, 'chroma-helper ' . implode(' ', $args) . " should exit 2, stderr: $err");
            $this->assertNotSame('', trim($err), 'a refusal must explain itself');
        }
    }

    public function testHelperHelpListsExactlyTheContractCommands(): void
    {
        [$code, $out] = $this->helper('--help');
        $this->assertSame(0, $code);
        foreach (['check-update', 'update [branch]', 'backup', 'list-backups', 'network-status', 'set-network', 'set-network <iface> dhcp', 'restart-services'] as $c) {
            $this->assertStringContainsString($c, $out);
        }
    }

    public function testHelperCommandsMatchThePhpWrapper(): void
    {
        $src = (string) file_get_contents($this->root() . '/bin/chroma-helper');
        foreach (Helper::COMMANDS as $c) {
            $this->assertMatchesRegularExpression('/^\s*' . preg_quote($c, '/') . '\)/m', $src, "chroma-helper has no case for '$c' used by src/Core/Helper.php");
        }
    }

    /** Admin -> Network sends "dns1,dns2"; the PHP wrapper must let the comma through to the helper. */
    public function testPhpWrapperAcceptsCommaSeparatedDns(): void
    {
        // /bin/true stands in for the helper; the wrapper calls it through sudo -n, which may be refused
        // for the user running the suite, so only the argument check matters here: no exception for the comma.
        $helper = new Helper($this->app->log, '/bin/true');
        [$code] = $helper->run('set-network', ['ens18', '10.10.5.20/24', '10.10.5.1', '10.10.5.2,1.1.1.1']);
        $this->assertIsInt($code);
        $this->expectException(\InvalidArgumentException::class);
        $helper->run('set-network', ['ens18', '10.10.5.20/24', '10.10.5.1', '1.1.1.1;id']);
    }

    public function testHelperValidatesNetworkArgumentsStrictly(): void
    {
        $bad = [
            'ip not an address' => ['eth0', 'ten.ten.5.20/24', '10.10.5.1', '1.1.1.1'],
            'octet > 255' => ['eth0', '10.10.300.20/24', '10.10.5.1', '1.1.1.1'],
            'missing prefix' => ['eth0', '10.10.5.20', '10.10.5.1', '1.1.1.1'],
            'prefix 0' => ['eth0', '10.10.5.20/0', '10.10.5.1', '1.1.1.1'],
            'prefix 33' => ['eth0', '10.10.5.20/33', '10.10.5.1', '1.1.1.1'],
            'prefix 32 has no gateway room' => ['eth0', '10.10.5.20/32', '10.10.5.1', '1.1.1.1'],
            'gateway not an address' => ['eth0', '10.10.5.20/24', 'router', '1.1.1.1'],
            'gateway outside subnet' => ['eth0', '10.10.5.20/24', '10.10.6.1', '1.1.1.1'],
            'gateway equals server' => ['eth0', '10.10.5.20/24', '10.10.5.20', '1.1.1.1'],
            'loopback address' => ['eth0', '127.0.0.5/8', '127.0.0.1', '1.1.1.1'],
            'dns empty' => ['eth0', '10.10.5.20/24', '10.10.5.1', ''],
            'dns not an address' => ['eth0', '10.10.5.20/24', '10.10.5.1', 'dns.local'],
            'dns with empty entry' => ['eth0', '10.10.5.20/24', '10.10.5.1', '1.1.1.1,,8.8.8.8'],
            'four dns servers' => ['eth0', '10.10.5.20/24', '10.10.5.1', '1.1.1.1,8.8.8.8,9.9.9.9,8.8.4.4'],
            'interface with shell characters' => ['eth0;reboot', '10.10.5.20/24', '10.10.5.1', '1.1.1.1'],
            'interface with path' => ['../eth0', '10.10.5.20/24', '10.10.5.1', '1.1.1.1'],
            'interface too long' => [str_repeat('e', 16), '10.10.5.20/24', '10.10.5.1', '1.1.1.1'],
            'yaml injection in ip' => ['eth0', "10.10.5.20/24]\n  x: y", '10.10.5.1', '1.1.1.1'],
        ];
        foreach ($bad as $label => $args) {
            [$code, , $err] = $this->helper('set-network', ...$args);
            $this->assertSame(2, $code, "set-network should refuse: $label (stderr: $err)");
            $this->assertStringContainsString('chroma-helper:', $err, "refusal for '$label' must say why");
        }
    }

    public function testHelperValidatesBranchNames(): void
    {
        foreach (['main;ls', '../evil', 'a..b', '-rf', 'branch with space', '$(id)'] as $branch) {
            [$code, , $err] = $this->helper('update', $branch);
            $this->assertSame(2, $code, "branch '$branch' must be refused: $err");
        }
    }

    public function testHelperNeedsRootForRealWork(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('running as root; the non-root refusal cannot be observed');
        }
        foreach ([['check-update'], ['backup'], ['list-backups'], ['restart-services'], ['set-network', 'eth0', '10.10.5.20/24', '10.10.5.1', '1.1.1.1']] as $args) {
            [$code, , $err] = $this->helper(...$args);
            $this->assertSame(1, $code, 'chroma-helper ' . implode(' ', $args));
            $this->assertStringContainsString('root', $err);
        }
    }

    public function testSudoersAllowsOnlyTheHelperWithoutPassword(): void
    {
        $file = $this->root() . '/deploy/sudoers/chroma';
        $rules = array_values(array_filter(array_map('trim', file($file) ?: []), fn ($l) => $l !== '' && !str_starts_with($l, '#') && !str_starts_with($l, 'Defaults')));
        $this->assertCount(1, $rules, 'exactly one sudoers rule');
        $this->assertSame('chroma ALL=(root) NOPASSWD: /usr/local/sbin/chroma-helper', $rules[0]);
        $this->assertStringNotContainsString('chroma-update', implode("\n", $rules), 'the updater is only reachable through the helper');
        if ($this->hasCommand('visudo')) {
            [$code, $out, $err] = $this->sh(['visudo', '-cf', $file]);
            $this->assertSame(0, $code, "visudo -c: $out$err");
        }
    }

    public function testInstallerFlagsAndHelp(): void
    {
        $script = $this->root() . '/install.sh';
        [$code, $out] = $this->sh(['bash', $script, '--help']);
        $this->assertSame(0, $code);
        foreach (['--check', '--force', '--branch', '--unattended', 'CHROMA_ADMIN_PASSWORD', 'CHROMA_NET_SKIP'] as $flag) {
            $this->assertStringContainsString($flag, $out);
        }
        [$code, , $err] = $this->sh(['bash', $script, '--bogus']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown option', $err);
        [$code, , $err] = $this->sh(['bash', $script, '--branch', 'main;id']);
        $this->assertSame(2, $code, 'branch names are validated before anything runs');
        $this->assertStringContainsString('not a valid branch name', $err);
        [$code, , $err] = $this->sh(['bash', $script, '--branch']);
        $this->assertSame(2, $code);
    }

    public function testInstallerCoversEverySpecStep(): void
    {
        $src = (string) file_get_contents($this->root() . '/install.sh');
        foreach ([
            '/etc/os-release', 'mariadb-server', 'php${PHP_VER}-fpm', 'pecl', 'msodbcsql18', 'clone -q --branch', 'adduser --system',
            'CREATE DATABASE IF NOT EXISTS', 'chmod 600 "$CONFIG_FILE"', 'openssl rand -hex 32', 'netplan', 'composer', 'bin/migrate',
            'bin/create-admin', 'sites-available/chroma', 'pool.d/chroma.conf', 'ufw allow 80/tcp', 'visudo -cf', 'chroma-backup.timer',
            'cms_driver_status', 'git_app fetch', '--ff-only', 'status --porcelain', 'vendor/bin/phpunit', 'reset -q --hard',
            'MODE_CHECK', 'MODE_FORCE', 'UNATTENDED',
        ] as $needle) {
            $this->assertStringContainsString($needle, $src, "install.sh should contain '$needle'");
        }
    }

    /**
     * The checkout root executes (install.sh, bin/*, deploy/*) must never be handed to the
     * web-app user: root later runs those files through chroma-helper update.
     */
    public function testInstallerKeepsTheCheckoutRootOwned(): void
    {
        $src = (string) file_get_contents($this->root() . '/install.sh');
        $this->assertStringNotContainsString('chown -R "$APP_USER:$APP_USER" "$APP_DIR"', $src, 'the app user must not own /opt/chroma');
        $this->assertStringContainsString('make_tree_root_owned', $src);
        $this->assertStringContainsString('assert_tree_root_owned', $src);
        $this->assertStringContainsString('chown -R root:root "$APP_DIR"', $src);
        $this->assertStringContainsString('TMPDIR=$APP_TMP', $src, 'the test-suite must not share /tmp with root');
        $this->assertStringContainsString('--do-not-cache-result', $src);
        $this->assertStringContainsString('phpunit-', $src, 'the complete test output is kept in its own log file');
        $this->assertMatchesRegularExpression('/^die\(\)\s*\{[^\n]*"\$1"/m', $src, 'die() prints only the message, not the exit code');
        $helper = (string) file_get_contents($this->root() . '/bin/chroma-helper');
        $this->assertStringContainsString('assert_not_app_writable', $helper);
        $this->assertMatchesRegularExpression('/^fail\(\)\s*\{[^\n]*"\$1"/m', $helper, 'fail() prints only the message, not the exit code');
        $this->assertStringContainsString("chroma-files-*.tar.gz", $helper, 'list-backups lists the attachment tarballs too');
    }

    public function testErrorMessagesDoNotEndWithTheExitCode(): void
    {
        [$code, , $err] = $this->sh(['bash', $this->root() . '/install.sh', '--branch']);
        $this->assertSame(2, $code);
        $this->assertStringEndsWith("needs a branch name\n", $err);
        [$code, , $err] = $this->helper('set-network', 'eth0', '10.10.5.20/24', '10.10.6.1', '1.1.1.1');
        $this->assertSame(2, $code);
        $this->assertDoesNotMatchRegularExpression('/\s[0-9]\s*$/', $err, "no stray exit code at the end of: $err");
    }

    /** Root only: the helper refuses to run an updater/installer the app user could have edited. */
    public function testHelperRefusesToUpdateFromAnAppWritableTree(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('needs root to pass the helper\'s root check');
        }
        $tmp = sys_get_temp_dir() . '/chroma-helper-test-' . getmypid();
        $this->sh(['rm', '-rf', $tmp]);
        mkdir("$tmp/app/bin", 0755, true);
        mkdir("$tmp/app/deploy", 0755, true);
        file_put_contents("$tmp/app/install.sh", "#!/bin/bash\necho ran\n");
        chmod("$tmp/app/install.sh", 0777);                       // world-writable: must be refused
        file_put_contents("$tmp/updater", "#!/bin/bash\necho updater-ran\n");
        chmod("$tmp/updater", 0755);
        $env = ['CHROMA_APP_DIR' => "$tmp/app", 'CHROMA_UPDATER' => "$tmp/updater"];
        [$code, $out, $err] = $this->sh(['bash', $this->root() . '/bin/chroma-helper', 'update'], $env);
        $this->assertSame(1, $code, "stdout: $out stderr: $err");
        $this->assertStringContainsString('writable by someone other than root', $err);
        $this->assertStringNotContainsString('updater-ran', $out);
        chmod("$tmp/app/install.sh", 0755);
        [$code, $out] = $this->sh(['bash', $this->root() . '/bin/chroma-helper', 'update'], $env);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('updater-ran', $out, 'a root-owned, non-writable tree is fine');
        $this->sh(['rm', '-rf', $tmp]);
    }

    /** Root only: list-backups prints dumps AND attachment tarballs, newest first. */
    public function testHelperListsBothBackupFiles(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('needs root to pass the helper\'s root check');
        }
        $tmp = sys_get_temp_dir() . '/chroma-list-test-' . getmypid();
        $this->sh(['rm', '-rf', $tmp]);
        mkdir($tmp, 0755, true);
        file_put_contents("$tmp/chroma-db-2026-01-01_000000.sql.gz", 'db');
        touch("$tmp/chroma-db-2026-01-01_000000.sql.gz", time() - 3600);
        file_put_contents("$tmp/chroma-files-2026-01-01_010000.tar.gz", 'files');
        file_put_contents("$tmp/notes.txt", 'ignored');
        [$code, $out] = $this->sh(['bash', $this->root() . '/bin/chroma-helper', 'list-backups'], ['CHROMA_BACKUP_DIR' => $tmp]);
        $this->assertSame(0, $code);
        $lines = array_values(array_filter(explode("\n", trim($out))));
        $this->assertCount(2, $lines, $out);
        $this->assertStringStartsWith("$tmp/chroma-files-2026-01-01_010000.tar.gz\t5\t", $lines[0], 'newest first');
        $this->assertStringStartsWith("$tmp/chroma-db-2026-01-01_000000.sql.gz\t2\t", $lines[1]);
        $this->sh(['rm', '-rf', $tmp]);
    }

    public function testBackupAndUninstallFlags(): void
    {
        [$code, $out] = $this->sh(['bash', $this->root() . '/bin/backup', '--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--print-path', $out);
        [$code] = $this->sh(['bash', $this->root() . '/bin/backup', '--bogus']);
        $this->assertSame(2, $code);
        [$code, , $err] = $this->sh(['bash', $this->root() . '/bin/backup', '--keep-days', 'soon']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('whole number', $err);
        [$code, $out] = $this->sh(['bash', $this->root() . '/bin/uninstall', '--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--purge', $out);
        $this->assertStringContainsString('chroma-uninstall', $out, 'the copy in /usr/local/sbin survives a keep-uninstall');
        [$code] = $this->sh(['bash', $this->root() . '/bin/uninstall', '--bogus']);
        $this->assertSame(2, $code);
    }

    public function testBackupDumpsTheDatabaseArchivesFilesAndPrunes(): void
    {
        if (!$this->hasCommand('mysqldump')) {
            $this->markTestSkipped('mysqldump is not installed');
        }
        $tmp = sys_get_temp_dir() . '/chroma-backup-test-' . getmypid();
        $this->sh(['rm', '-rf', $tmp]);
        mkdir("$tmp/files", 0777, true);
        mkdir("$tmp/out");
        mkdir("$tmp/logs");
        file_put_contents("$tmp/files/sample.txt", 'attachment');
        $cfg = $this->app->config;
        file_put_contents("$tmp/config.env", implode("\n", [
            'DB_HOST=' . $cfg->get('DB_HOST', '127.0.0.1'),
            'DB_PORT=' . $cfg->get('DB_PORT', '3306'),
            'DB_NAME=' . $cfg->get('DB_NAME'),   // the test database (overridden by tests/bootstrap.php)
            'DB_USER=' . $cfg->get('DB_USER'),
            'DB_PASS="' . $cfg->get('DB_PASS') . '"',
            "FILES_PATH=$tmp/files",
            "LOG_PATH=$tmp/logs",
        ]) . "\n");
        $old = "$tmp/out/chroma-db-2000-01-01_000000.sql.gz";
        file_put_contents($old, 'old');
        touch($old, time() - 40 * 86400);
        $recent = "$tmp/out/chroma-files-2000-01-02_000000.tar.gz";
        file_put_contents($recent, 'recent');
        touch($recent, time() - 5 * 86400);

        [$code, $out, $err] = $this->sh(['bash', $this->root() . '/bin/backup', '--dir', "$tmp/out", '--keep-days', '30'], ['CHROMA_CONFIG' => "$tmp/config.env"]);
        $this->assertSame(0, $code, "bin/backup failed: $out$err");
        $this->assertStringContainsString('Database backup:', $out);
        $this->assertStringContainsString('Attachments backup:', $out);
        $this->assertStringContainsString('removed 1 old file', $out);
        $this->assertFileDoesNotExist($old, 'backups older than --keep-days are pruned');
        $this->assertFileExists($recent, 'younger backups are kept');

        $dumps = glob("$tmp/out/chroma-db-*.sql.gz") ?: [];
        $this->assertCount(1, $dumps);
        $sql = (string) gzdecode((string) file_get_contents($dumps[0]));
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('chroma_users', $sql);
        $this->assertStringContainsString('chroma_sequences', $sql);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dumps[0])), -4), 'dumps are private');

        $tars = glob("$tmp/out/chroma-files-20[2-9]*.tar.gz") ?: [];
        $this->assertCount(1, $tars);
        [$code, $list] = $this->sh(['tar', '-tzf', $tars[0]]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('files/sample.txt', $list);

        $this->assertFileExists("$tmp/logs/backup.log");

        // --print-path prints exactly one line: the dump path (used by chroma-helper backup).
        [$code, $out] = $this->sh(['bash', $this->root() . '/bin/backup', '--dir', "$tmp/out", '--print-path'], ['CHROMA_CONFIG' => "$tmp/config.env"]);
        $this->assertSame(0, $code);
        $lines = array_values(array_filter(explode("\n", trim($out))));
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression('#^' . preg_quote("$tmp/out/chroma-db-", '#') . '.*\.sql\.gz$#', $lines[0]);
        $this->assertFileExists($lines[0]);

        // A wrong password fails loudly with a non-zero exit code (the updater refuses to continue).
        file_put_contents("$tmp/config.env", "DB_NAME=" . $cfg->get('DB_NAME') . "\nDB_USER=" . $cfg->get('DB_USER') . "\nDB_PASS=definitely-wrong\n");
        [$code, , $err] = $this->sh(['bash', $this->root() . '/bin/backup', '--dir', "$tmp/out"], ['CHROMA_CONFIG' => "$tmp/config.env"]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('mysqldump', $err);
        $this->sh(['rm', '-rf', $tmp]);
    }

    public function testDeployFilesAgreeWithEachOther(): void
    {
        $d = $this->root() . '/deploy';
        $nginx = (string) file_get_contents("$d/nginx/chroma.conf");
        $fpm = (string) file_get_contents("$d/php-fpm/chroma.conf");
        $this->assertMatchesRegularExpression('/^listen = (.+)$/m', $fpm);
        preg_match('/^listen = (.+)$/m', $fpm, $m);
        $this->assertStringContainsString('fastcgi_pass unix:' . trim($m[1]) . ';', $nginx, 'nginx must talk to the pool socket');
        $this->assertStringContainsString('root /opt/chroma/public;', $nginx);
        $this->assertStringContainsString('user = chroma', $fpm);
        $this->assertStringContainsString('/var/log/chroma/', $nginx);
        $this->assertStringContainsString('return 404;', $nginx, 'only index.php is executed');

        $backup = (string) file_get_contents("$d/systemd/chroma-backup.service");
        $this->assertStringContainsString('ExecStart=/opt/chroma/bin/backup', $backup);
        $this->assertStringContainsString('User=chroma', $backup);
        $this->assertStringContainsString('OnCalendar=', (string) file_get_contents("$d/systemd/chroma-backup.timer"));
        $sync = (string) file_get_contents("$d/systemd/chroma-cms-sync.service");
        $this->assertStringContainsString('/opt/chroma/bin/cms-sync', $sync);
        $this->assertStringContainsString('ConditionPathExists=/opt/chroma/bin/cms-sync', $sync, 'harmless until the sync tool exists');
        $this->assertStringContainsString('OnCalendar=', (string) file_get_contents("$d/systemd/chroma-cms-sync.timer"));
        $this->assertStringContainsString('/var/log/chroma/*.log', (string) file_get_contents("$d/logrotate/chroma"));
        $this->assertStringContainsString('rotate 30', (string) file_get_contents("$d/logrotate/chroma"));
    }

    public function testReadmeAndHelpExist(): void
    {
        $readme = (string) file_get_contents($this->root() . '/README.md');
        foreach (['install.sh | sudo bash', 'chroma-update', 'Import legacy codes', 'mysql', '/var/backups/chroma', '/var/log/chroma', 'chroma-uninstall', 'phpunit-'] as $needle) {
            $this->assertStringContainsString($needle, $readme, "README.md should mention '$needle'");
        }
        $this->assertFileExists($this->root() . '/resources/help/server.md');
    }
}
