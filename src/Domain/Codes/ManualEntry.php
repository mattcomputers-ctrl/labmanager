<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;

/**
 * Entering a specific code by hand (legacy items, odd cases).
 *
 * One operation shared by the Expert /codes/manual page and the Easy-mode
 * manual-entry wizard. It refuses, in plain language, anything the database
 * could not store (numbers above the column range, versions longer than the
 * column) and a code that is already used — showing the code the way it is
 * displayed everywhere else (BN0777, not BN777) — then hands over to
 * CodeAssigner::manualSell/manualInternal, which do the real work inside a
 * transaction and log the entry.
 */
final class ManualEntry
{
    /** Six digits: the longest sell numbers in use are the X104xxx block. */
    public const MAX_SELL_NUMBER = 999999;
    /** E numbers are four or five digits today (E9102); the column holds far more, this keeps typos out. */
    public const MAX_INTERNAL_NUMBER = 999999;
    /** chroma_internal_codes.version is VARCHAR(20). */
    public const MAX_VERSION_LENGTH = 20;

    public function __construct(private Db $db, private CodeAssigner $codes)
    {
    }

    /**
     * What is wrong with a typed code, in plain words, or null when it can be
     * entered. Checked before the details step of the wizard and again on
     * commit, so the user hears about it as early as possible.
     */
    public static function problem(string $type, string $code): ?string
    {
        if ($type === 'internal') {
            $p = CodeFormat::parseInternal($code);
            if ($p === null) {
                return 'Enter the E number as E followed by digits, with an optional version after a dash, like E6374-10.';
            }
            if (!self::digitsFit($code, self::MAX_INTERNAL_NUMBER) || $p['number'] > self::MAX_INTERNAL_NUMBER) {
                return sprintf('That E number is too long. E numbers have at most %d digits, like E9102 or E6374-10.', strlen((string) self::MAX_INTERNAL_NUMBER));
            }
            if (strlen($p['version']) > self::MAX_VERSION_LENGTH) {
                return sprintf('The version after the dash is too long: %d characters at most, like E6374-10.', self::MAX_VERSION_LENGTH);
            }
            return null;
        }
        $p = CodeFormat::parseSell($code);
        if ($p === null) {
            return 'Enter the sell number as prefix, number and optional suffix, like Y1714UV or X104043.';
        }
        if (!self::digitsFit($code, self::MAX_SELL_NUMBER) || $p['number'] > self::MAX_SELL_NUMBER) {
            return sprintf('That number is too long. Sell numbers have at most %d digits, like Y1714 or X104043.', strlen((string) self::MAX_SELL_NUMBER));
        }
        return null;
    }

    /** True when the digits in the code (leading zeros aside) could be a number up to $max. */
    private static function digitsFit(string $code, int $max): bool
    {
        if (!preg_match('/(\d+)/', $code, $m)) {
            return false;
        }
        $digits = ltrim($m[1], '0');
        return strlen($digits) <= strlen((string) $max);
    }

    /**
     * Enter a sell number. $data as CodeAssigner::manualSell expects.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function sell(string $code, array $data, ?int $actorId): array
    {
        $problem = self::problem('sell', $code);
        if ($problem !== null) {
            throw ValidationException::single('code', $problem);
        }
        $parsed = CodeFormat::parseSell($code);
        $existing = $this->db->one(
            'SELECT s.id, s.description, p.code AS prefix_code, p.min_digits, s.number, x.code AS suffix_code, i.number AS i_number, i.version AS i_version
             FROM chroma_sell_numbers s
             JOIN chroma_code_prefixes p ON p.id = s.prefix_id
             LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id
             LEFT JOIN chroma_internal_codes i ON i.id = s.internal_code_id
             WHERE s.sell_number_normalized = ?',
            [$parsed['normalized']]
        );
        if ($existing) {
            $display = CodeFormat::sell($existing['prefix_code'], (int) $existing['number'], (int) $existing['min_digits'], $existing['suffix_code']);
            $by = $existing['i_number'] ? ' by ' . CodeFormat::internal((int) $existing['i_number'], $existing['i_version']) : ($existing['description'] !== '' ? ' for "' . $existing['description'] . '"' : '');
            throw ValidationException::single('code', sprintf('%s is already used%s. Pick a different number or suffix, or search for it.', $display, $by));
        }
        return $this->codes->manualSell($code, $data, $actorId);
    }

    /**
     * Enter an E number. $data as CodeAssigner::manualInternal expects.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function internal(string $code, array $data, ?int $actorId): array
    {
        $problem = self::problem('internal', $code);
        if ($problem !== null) {
            throw ValidationException::single('code', $problem);
        }
        return $this->codes->manualInternal($code, $data, $actorId);
    }
}
