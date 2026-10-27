<?php
declare(strict_types=1);

use Chroma\Core\App;

/** HTML-escape. Accepts anything; null becomes ''. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app(): App
{
    return App::get();
}

/** Render a component from resources/components. @param array<string,mixed> $props */
function component(string $name, array $props = []): string
{
    return App::get()->view->component($name, $props);
}

function csrf_token(): string
{
    return App::get()->csrf->token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function can(string ...$permissions): bool
{
    $auth = App::get()->auth;
    foreach ($permissions as $p) {
        if ($auth->can($p)) {
            return true;
        }
    }
    return false;
}

function ui_mode(): string
{
    return App::get()->mode();
}

function is_easy(): bool
{
    return App::get()->mode() === 'easy';
}

function is_expert(): bool
{
    return App::get()->mode() === 'expert';
}

function setting(string $key, ?string $default = null): ?string
{
    return App::get()->settings->get($key, $default);
}

/** Build a URL with a query string. @param array<string,mixed> $query */
function url(string $path, array $query = []): string
{
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return $path . ($query ? '?' . http_build_query($query) : '');
}

/** Old input after a failed form post (falls back to $default). */
function old(string $key, mixed $default = null): mixed
{
    static $old = null;
    if ($old === null) {
        $old = App::get()->flash->oldInput();
    }
    return $old[$key] ?? $default;
}

/** Field errors after a failed form post. @return array<string,string> */
function form_errors(): array
{
    static $errors = null;
    if ($errors === null) {
        $errors = App::get()->flash->errors();
    }
    return $errors;
}

/** Format a UTC datetime string in the display timezone. */
function fmt_datetime(?string $utc, string $format = 'M j, Y g:i a'): string
{
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
        return '';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(App::get()->timezone())->format($format);
    } catch (Exception) {
        return $utc;
    }
}

/** Format a date-only value (no timezone shift). */
function fmt_date(?string $date, string $format = 'M j, Y'): string
{
    if ($date === null || $date === '' || str_starts_with($date, '0000')) {
        return '';
    }
    try {
        return (new DateTimeImmutable(substr($date, 0, 10)))->format($format);
    } catch (Exception) {
        return $date;
    }
}

/** Today's date in the display timezone, as YYYY-MM-DD. */
function today(): string
{
    return (new DateTimeImmutable('now', App::get()->timezone()))->format('Y-m-d');
}

/** "3 days ago" style text. */
function fmt_ago(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    $t = strtotime($utc . ' UTC');
    if ($t === false) {
        return '';
    }
    $diff = time() - $t;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $n = intdiv($diff, 60);
        return $n . ' minute' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $n = intdiv($diff, 3600);
        return $n . ' hour' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400 * 30) {
        $n = intdiv($diff, 86400);
        return $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
    }
    return fmt_datetime($utc, 'M j, Y');
}

function plural(int $n, string $singular, ?string $plural = null): string
{
    return number_format($n) . ' ' . ($n === 1 ? $singular : ($plural ?? $singular . 's'));
}

/** Safe JSON for embedding in HTML attributes / x-data. */
function json_attr(mixed $value): string
{
    return e(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
}

/** Render simple, safe markdown (notes, help). */
function markdown(?string $text): string
{
    static $pd = null;
    if ($pd === null) {
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        $pd->setBreaksEnabled(true);
    }
    $html = $pd->text((string) $text);
    // @mentions -> highlighted
    return preg_replace('/(^|\s)@([A-Za-z0-9_.-]+)/', '$1<span class="mention">@$2</span>', $html) ?? $html;
}

/** Slug-safe file name part. */
function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '', '-'));
    return $s === '' ? 'item' : $s;
}
