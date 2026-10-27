<?php
declare(strict_types=1);

namespace Chroma\Domain\System;

use Chroma\Core\Helper;
use Chroma\Core\ValidationException;

/**
 * Backups live in /var/backups/chroma (written by bin/backup through the
 * privileged helper, and by the nightly timer). This class lists them,
 * asks the helper for a new one, and resolves a download request to a
 * file that is guaranteed to be inside the backup directory.
 */
final class Backups
{
    public const DEFAULT_DIR = '/var/backups/chroma';

    public function __construct(private readonly Helper $helper, private readonly string $dir = self::DEFAULT_DIR)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Newest first. Uses the helper when it is installed (it can read the
     * root-owned directory); otherwise scans the directory directly.
     * @return list<array{name:string,path:string,bytes:int,created:string}>
     */
    public function list(): array
    {
        $out = [];
        if ($this->helper->available()) {
            [$code, $text] = $this->helper->run('list-backups');
            if ($code === 0) {
                foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
                    $parts = explode("\t", $line);
                    if (count($parts) < 3 || !$this->isInsideDir($parts[0])) {
                        continue;
                    }
                    $out[] = ['name' => basename($parts[0]), 'path' => $parts[0], 'bytes' => (int) $parts[1], 'created' => trim($parts[2])];
                }
                return $out;
            }
        }
        if (is_dir($this->dir) && is_readable($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                if (!is_file($f) || !self::looksLikeBackup(basename($f))) {
                    continue;
                }
                $out[] = ['name' => basename($f), 'path' => $f, 'bytes' => (int) filesize($f), 'created' => gmdate('Y-m-d H:i', (int) filemtime($f))];
            }
            usort($out, fn($a, $b) => strcmp($b['created'], $a['created']) ?: strcmp($b['name'], $a['name']));
        }
        return $out;
    }

    /** Ask the helper for a new backup. @return array{ok:bool,message:string,path:?string} */
    public function create(): array
    {
        [$code, $text] = $this->helper->run('backup');
        $text = trim($text);
        if ($code !== 0) {
            return ['ok' => false, 'message' => $text !== '' ? $text : 'The backup did not finish (exit code ' . $code . ').', 'path' => null];
        }
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $path = trim((string) end($lines));
        return ['ok' => true, 'message' => 'Backup saved' . ($path !== '' ? ' as ' . basename($path) : '') . '.', 'path' => $path !== '' ? $path : null];
    }

    /**
     * Turn a requested file name into a safe absolute path inside the backup
     * directory, or refuse. Only a bare file name is accepted (no slashes,
     * no "..", no hidden files) and the resolved path must stay inside dir.
     */
    public function resolveDownload(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name !== basename($name) || str_contains($name, '..') || str_starts_with($name, '.') || str_contains($name, "\0")
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) || !self::looksLikeBackup($name)) {
            throw ValidationException::general('That is not the name of a backup file. Pick one from the list.');
        }
        $base = realpath($this->dir);
        $full = $base !== false ? realpath($base . '/' . $name) : false;
        if ($base === false || $full === false || !is_file($full) || !str_starts_with($full, rtrim($base, '/') . '/')) {
            throw ValidationException::general('That backup file is not there any more. Refresh the list and try again.');
        }
        return $full;
    }

    private function isInsideDir(string $path): bool
    {
        return str_starts_with($path, rtrim($this->dir, '/') . '/') && basename($path) === basename(rtrim($path, '/')) && !str_contains($path, '..');
    }

    public static function looksLikeBackup(string $name): bool
    {
        return (bool) preg_match('/\.(sql|sql\.gz|tar|tar\.gz|tgz|zip)$/i', $name);
    }
}
