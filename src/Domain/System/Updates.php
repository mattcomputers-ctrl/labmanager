<?php
declare(strict_types=1);

namespace Chroma\Domain\System;

use Chroma\Core\Helper;
use Chroma\Core\ValidationException;

/**
 * "Check for updates" and "Update now" through the privileged helper.
 *
 * An update restarts PHP-FPM half way through, which would kill the web
 * request that started it, so the update runs as a detached background
 * process that appends to a job log file. The browser polls
 * /admin/system/update/log?job=… for new lines until the job finishes.
 * The last line of a finished job is "__CHROMA_JOB_EXIT__ <code>".
 */
final class Updates
{
    public const EXIT_MARKER = '__CHROMA_JOB_EXIT__';

    public function __construct(private readonly Helper $helper, private readonly string $jobDir)
    {
    }

    /** @return array{ok:bool,state:string,current:string,available:string,message:string} */
    public function check(): array
    {
        [$code, $text] = $this->helper->run('check-update');
        $text = trim($text);
        if ($code === 127) {
            return ['ok' => false, 'state' => 'unavailable', 'current' => '', 'available' => '', 'message' => $text];
        }
        if ($code !== 0) {
            return ['ok' => false, 'state' => 'error', 'current' => '', 'available' => '', 'message' => $text !== '' ? $text : 'The update check failed (exit code ' . $code . ').'];
        }
        if (preg_match('/^up-to-date\s+(\S+)/m', $text, $m)) {
            return ['ok' => true, 'state' => 'up-to-date', 'current' => $m[1], 'available' => '', 'message' => 'Chroma is up to date (version ' . $m[1] . ').'];
        }
        if (preg_match('/^update-available\s+(\S+)\s+(\S+)/m', $text, $m)) {
            return ['ok' => true, 'state' => 'update-available', 'current' => $m[1], 'available' => $m[2], 'message' => 'An update is available: ' . $m[1] . ' → ' . $m[2] . '.'];
        }
        return ['ok' => false, 'state' => 'error', 'current' => '', 'available' => '', 'message' => 'The helper gave an answer Chroma did not understand: ' . mb_substr($text, 0, 200)];
    }

    public static function validateBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/', $branch) || str_contains($branch, '..')) {
            throw ValidationException::single('branch', 'A branch name is letters, numbers, dots, dashes and slashes, for example main. Leave it blank to use the normal branch.');
        }
        return $branch;
    }

    /**
     * Start an update as a background job. Returns the job id, or throws a
     * RuntimeException with a plain-language reason (helper missing, etc.).
     */
    public function start(string $branch = ''): string
    {
        $branch = self::validateBranch($branch);
        if (!$this->helper->available()) {
            throw new \RuntimeException('Updates cannot be run from this machine: the system helper is not installed here (expected ' . Helper::BIN . '). On the server, run: sudo chroma-update');
        }
        if (!is_dir($this->jobDir) && !@mkdir($this->jobDir, 0750, true)) {
            throw new \RuntimeException('Chroma cannot write the update log in ' . $this->jobDir . '. Check the folder exists and the chroma user may write to it.');
        }
        $job = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $log = $this->logPath($job);
        file_put_contents($log, '[' . gmdate('Y-m-d H:i:s') . ' UTC] Starting update' . ($branch !== '' ? ' (branch ' . $branch . ')' : '') . "…\n");
        $cmd = 'sudo -n ' . escapeshellarg(Helper::BIN) . ' update' . ($branch !== '' ? ' ' . escapeshellarg($branch) : '');
        $shell = '(' . $cmd . '; echo "' . self::EXIT_MARKER . ' $?") >> ' . escapeshellarg($log) . ' 2>&1';
        $proc = proc_open('nohup setsid sh -c ' . escapeshellarg($shell) . ' > /dev/null 2>&1 &', [], $pipes);
        if (is_resource($proc)) {
            proc_close($proc);
        } else {
            throw new \RuntimeException('Could not start the update process.');
        }
        return $job;
    }

    public static function validateJobId(string $job): string
    {
        if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}$/', $job)) {
            throw ValidationException::general('That update job does not exist.');
        }
        return $job;
    }

    public function logPath(string $job): string
    {
        return rtrim($this->jobDir, '/') . '/update-' . self::validateJobId($job) . '.log';
    }

    /**
     * Lines of the job log from byte offset $from. Returns the new offset,
     * whether the job has finished and its exit code.
     * @return array{lines:list<string>,offset:int,done:bool,exit:?int}
     */
    public function read(string $job, int $from = 0): array
    {
        $path = $this->logPath($job);
        if (!is_file($path)) {
            throw ValidationException::general('That update job does not exist (or its log was cleaned up).');
        }
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $from = max(0, min($from, $size));
        $chunk = $size > $from ? (string) file_get_contents($path, false, null, $from, $size - $from) : '';
        // Only hand back complete lines; keep a partial last line for the next poll.
        $lastNl = strrpos($chunk, "\n");
        if ($lastNl === false) {
            $chunk = '';
        } else {
            $chunk = substr($chunk, 0, $lastNl + 1);
        }
        $offset = $from + strlen($chunk);
        $lines = $chunk === '' ? [] : explode("\n", rtrim($chunk, "\n"));
        $done = false;
        $exit = null;
        $out = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, self::EXIT_MARKER)) {
                $done = true;
                $exit = (int) trim(substr($line, strlen(self::EXIT_MARKER)));
                continue;
            }
            $out[] = $line;
        }
        return ['lines' => $out, 'offset' => $offset, 'done' => $done, 'exit' => $exit];
    }
}
