<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * Loads /etc/chroma/config.env (or the file named by the CHROMA_CONFIG
 * environment variable) into a simple key/value store.
 *
 * The file is a list of KEY=VALUE lines. Blank lines and lines starting
 * with # are ignored. Values may be wrapped in single or double quotes.
 * Real environment variables override the file so tests and the CLI can
 * point at a different database without editing anything.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values = [];

    private function __construct()
    {
    }

    public static function load(?string $path = null, ?string $root = null): self
    {
        $cfg = new self();
        $path = $path ?? (getenv('CHROMA_CONFIG') ?: '/etc/chroma/config.env');
        if ($root !== null && !str_starts_with($path, '/')) {
            $path = rtrim($root, '/') . '/' . ltrim($path, './');
        }
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = substr($line, 7);
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $eq));
                $val = trim(substr($line, $eq + 1));
                if ($val !== '' && ($val[0] === '"' || $val[0] === "'") && str_ends_with($val, $val[0])) {
                    $val = substr($val, 1, -1);
                }
                $cfg->values[$key] = $val;
            }
        }
        $cfg->values['CONFIG_PATH'] = $path;
        return $cfg;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->values;
    }

    public function path(): string
    {
        return $this->values['CONFIG_PATH'];
    }

    /**
     * Rewrite a handful of keys in the config file, preserving everything
     * else (comments, order). Used by the admin CMS connection screen, which
     * is the only place the app writes its own config.
     *
     * @param array<string,string> $updates
     */
    public function write(array $updates): void
    {
        $path = $this->path();
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#') || !str_contains($t, '=')) {
                continue;
            }
            $key = trim(substr($t, 0, strpos($t, '=')));
            if (array_key_exists($key, $updates)) {
                $lines[$i] = $key . '=' . self::quote($updates[$key]);
                $seen[$key] = true;
            }
        }
        foreach ($updates as $key => $val) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . self::quote($val);
            }
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException("Cannot write config file $path");
        }
        @chmod($tmp, 0600);
        rename($tmp, $path);
        foreach ($updates as $k => $v) {
            $this->values[$k] = $v;
        }
    }

    private static function quote(string $v): string
    {
        if ($v === '' || preg_match('/^[A-Za-z0-9_.\/:@+-]+$/', $v)) {
            return $v;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
}
