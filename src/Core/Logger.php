<?php
declare(strict_types=1);

namespace Chroma\Core;

/** Minimal file logger writing to /var/log/chroma/app.log (or LOG_PATH). */
final class Logger
{
    private string $file;

    public function __construct(?string $dir, private readonly string $name = 'app')
    {
        $dir = $dir ?: sys_get_temp_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $this->file = rtrim($dir, '/') . '/' . $this->name . '.log';
    }

    /** @param array<string,mixed> $ctx */
    public function log(string $level, string $message, array $ctx = []): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $message,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $c */
    public function info(string $m, array $c = []): void { $this->log('info', $m, $c); }
    /** @param array<string,mixed> $c */
    public function warning(string $m, array $c = []): void { $this->log('warning', $m, $c); }
    /** @param array<string,mixed> $c */
    public function error(string $m, array $c = []): void { $this->log('error', $m, $c); }

    public function path(): string
    {
        return $this->file;
    }
}
