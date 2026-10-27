<?php
declare(strict_types=1);

namespace Chroma\Core;

final class Response
{
    /**
     * Hardening headers every response carries unless a caller overrides one
     * (Response::header() replaces the default). Pages are never framed by
     * another site, browsers never guess content types, the referrer stays
     * inside the app, and the CSP keeps scripts, styles, fonts, images and
     * fetches on this origin. Inline scripts/styles and eval stay allowed
     * because the templates use inline handlers and Alpine needs eval.
     * nginx repeats the first three for the static files it serves itself
     * (deploy/nginx/chroma.conf).
     */
    public const SECURITY_HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'",
    ];

    /** @var array<string,string> */
    private array $headers = self::SECURITY_HEADERS;

    public function __construct(
        private string $body = '',
        private int $status = 200,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/html; charset=utf-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return (new self((string) $json, $status))->header('Content-Type', 'application/json; charset=utf-8');
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return (new self('', $status))->header('Location', $to);
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function download(string $content, string $filename, string $mime = 'application/octet-stream'): self
    {
        return (new self($content))
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $filename) . '"')
            ->header('Content-Length', (string) strlen($content));
    }

    public static function file(string $path, ?string $filename = null, ?string $mime = null, bool $inline = false): self
    {
        $content = (string) file_get_contents($path);
        $mime = $mime ?? (mime_content_type($path) ?: 'application/octet-stream');
        $r = (new self($content))->header('Content-Type', $mime)->header('Content-Length', (string) strlen($content));
        $name = $filename ?? basename($path);
        $r->header('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header("$k: $v");
            }
        }
        echo $this->body;
    }
}
