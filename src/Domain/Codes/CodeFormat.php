<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

/**
 * Parsing, normalizing and displaying codes.
 *
 * Sell numbers: PREFIX + number + optional SUFFIX, e.g. Y1714UV, X104043.
 * Historical spellings (y-1000, Y 1000, b2978uv) all normalize to the
 * same key. Internal codes: E + integer, optional -version (E6374-10).
 */
final class CodeFormat
{
    /** Canonical key: uppercase, no spaces/hyphens, number without leading zeros. */
    public static function normalizeSell(string $text): ?string
    {
        $p = self::parseSell($text);
        return $p === null ? null : $p['normalized'];
    }

    /**
     * @return array{prefix:string,number:int,suffix:string,normalized:string}|null
     */
    public static function parseSell(string $text): ?array
    {
        $t = strtoupper(trim($text));
        $t = preg_replace('/[\s\-_.\/]+/', '', $t) ?? '';
        if ($t === '' || !preg_match('/^([A-Z]+)(\d+)([A-Z]+)?$/', $t, $m)) {
            return null;
        }
        $number = (int) ltrim($m[2], '0');
        if ($number <= 0 && ltrim($m[2], '0') === '') {
            return null; // all zeros
        }
        $suffix = $m[3] ?? '';
        return [
            'prefix' => $m[1],
            'number' => $number,
            'suffix' => $suffix,
            'normalized' => $m[1] . $number . $suffix,
        ];
    }

    public static function sell(string $prefix, int $number, int $minDigits = 1, ?string $suffix = null): string
    {
        return strtoupper($prefix) . str_pad((string) $number, max(1, $minDigits), '0', STR_PAD_LEFT) . strtoupper((string) $suffix);
    }

    /** @return array{number:int,version:string}|null */
    public static function parseInternal(string $text): ?array
    {
        $t = strtoupper(trim($text));
        $t = preg_replace('/\s+/', '', $t) ?? '';
        if (!preg_match('/^E?(\d+)(?:-([A-Z0-9]+))?$/', $t, $m)) {
            return null;
        }
        $n = (int) $m[1];
        if ($n <= 0) {
            return null;
        }
        return ['number' => $n, 'version' => $m[2] ?? ''];
    }

    public static function internal(int $number, ?string $version = null): string
    {
        $version = trim((string) $version);
        return 'E' . $number . ($version !== '' ? '-' . $version : '');
    }

    /** True when the text looks like an E number (as opposed to a legacy non-E code like 2UY0480). */
    public static function looksInternal(string $text): bool
    {
        return (bool) preg_match('/^\s*E\s*\d+(\s*-\s*[A-Za-z0-9]+)?\s*$/i', $text);
    }
}
