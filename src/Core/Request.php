<?php
declare(strict_types=1);

namespace Chroma\Core;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string,mixed> */
        public readonly array $query,
        /** @var array<string,mixed> */
        public readonly array $body,
        /** @var array<string,mixed> */
        public readonly array $files,
        /** @var array<string,string> */
        public readonly array $headers,
        /** @var array<string,string> */
        public readonly array $cookies,
        public readonly string $ip,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = $_POST;
        if ($method !== 'GET' && str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        if ($method === 'POST' && isset($body['_method'])) {
            $override = strtoupper((string) $body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        return new self(
            $method,
            $path,
            $_GET,
            $body,
            $_FILES,
            $headers,
            $_COOKIE,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->input($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key);
        return in_array(is_scalar($v) ? strtolower((string) $v) : '', ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<int|string,mixed> */
    public function arr(string $key): array
    {
        $v = $this->input($key);
        return is_array($v) ? $v : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept') ?? '';
        return str_contains($accept, 'application/json')
            || $this->header('x-requested-with') === 'fetch'
            || str_starts_with($this->header('content-type') ?? '', 'application/json');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function fullUrl(): string
    {
        $qs = http_build_query($this->query);
        return $this->path . ($qs !== '' ? '?' . $qs : '');
    }
}
