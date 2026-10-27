<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * Runs the privileged helper installed by install.sh:
 *   sudo -n /usr/local/sbin/chroma-helper <update|set-network|backup|restart-services|check-update|network-status> [args]
 * The sudoers drop-in lets only the app user run exactly that script.
 * In development (no helper installed) commands report "not available".
 */
final class Helper
{
    public const BIN = '/usr/local/sbin/chroma-helper';
    public const COMMANDS = ['update', 'check-update', 'set-network', 'network-status', 'backup', 'restart-services', 'list-backups'];

    public function __construct(private readonly Logger $log, private readonly ?string $bin = null)
    {
    }

    public function available(): bool
    {
        return is_executable($this->bin ?? self::BIN);
    }

    /**
     * Run a helper command and return [exit code, output]. $onLine, if given,
     * receives each output line as it arrives (for streaming update logs).
     * @param list<string> $args
     * @return array{0:int,1:string}
     */
    public function run(string $command, array $args = [], ?callable $onLine = null): array
    {
        if (!in_array($command, self::COMMANDS, true)) {
            throw new \InvalidArgumentException("Unknown helper command $command");
        }
        if (!$this->available()) {
            return [127, 'The system helper is not installed on this machine (expected ' . ($this->bin ?? self::BIN) . '). It is installed by install.sh.'];
        }
        foreach ($args as $a) {
            if (!preg_match('/^[A-Za-z0-9_.:\/-]+$/', $a)) {
                throw new \InvalidArgumentException('Invalid helper argument');
            }
        }
        $cmd = 'sudo -n ' . escapeshellarg($this->bin ?? self::BIN) . ' ' . escapeshellarg($command);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $this->log->info('helper: ' . $command, ['args' => $args]);
        $proc = proc_open($cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [1, 'Could not start the helper.'];
        }
        $out = '';
        while (($line = fgets($pipes[1])) !== false) {
            $out .= $line;
            if ($onLine) {
                $onLine($line);
            }
        }
        fclose($pipes[1]);
        $code = proc_close($proc);
        return [$code, $out];
    }
}
