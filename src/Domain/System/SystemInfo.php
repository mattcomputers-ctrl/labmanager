<?php
declare(strict_types=1);

namespace Chroma\Domain\System;

use Chroma\Core\App;

/** Facts about this installation for the Admin → System screen. No HTTP. */
final class SystemInfo
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,string> */
    public function summary(): array
    {
        $v = $this->app->version();
        $maria = '';
        try {
            $maria = (string) $this->app->db->value('SELECT VERSION()');
        } catch (\Throwable) {
            $maria = 'not reachable';
        }
        return [
            'version_tag' => $v['tag'],
            'version_commit' => $v['short'] ?: 'unknown',
            'version_date' => $v['date'] ?: 'unknown',
            'php' => PHP_VERSION,
            'mariadb' => $maria,
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'server_time' => gmdate('Y-m-d H:i') . ' UTC',
            'config_path' => $this->app->config->path(),
            'log_dir' => dirname($this->app->log->path()),
            'log_file' => $this->app->log->path(),
            'files_path' => (string) $this->app->config->get('FILES_PATH', $this->app->root . '/storage/files'),
            'backup_dir' => Backups::DEFAULT_DIR,
        ];
    }

    /** Free / total space on the disk holding the app, in bytes. @return array{free:int,total:int,percent_used:int} */
    public function disk(): array
    {
        $free = (int) @disk_free_space($this->app->root);
        $total = (int) @disk_total_space($this->app->root);
        return ['free' => $free, 'total' => $total, 'percent_used' => $total > 0 ? (int) round(($total - $free) / $total * 100) : 0];
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $units[$i];
    }

    /** The last $lines lines of the app log (newest last). @return list<string> */
    public function logTail(int $lines = 100): array
    {
        $file = $this->app->log->path();
        if (!is_readable($file)) {
            return [];
        }
        $size = (int) filesize($file);
        $fh = fopen($file, 'r');
        if ($fh === false) {
            return [];
        }
        $chunk = 64 * 1024;
        $buffer = '';
        $pos = $size;
        while ($pos > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($fh, $pos);
            $buffer = (string) fread($fh, $read) . $buffer;
        }
        fclose($fh);
        $all = explode("\n", rtrim($buffer, "\n"));
        return array_values(array_slice($all, -$lines));
    }
}
