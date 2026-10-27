<?php
declare(strict_types=1);

namespace Chroma\Http;

/**
 * One-paragraph "?" explanations for the fields people ask about most.
 * Rendered by the help-tip component (resources/components/help-tip.php) or
 * the 'tip' option of form/field. Kept here so the Easy-mode wizard step and
 * the Expert-mode form say exactly the same thing.
 */
final class Tips
{
    public const PREFIX = 'The letters at the front of a sell number say what kind of product it is, for example Y for tints or AQ for aqueous coatings. '
        . 'Each prefix keeps its own count, so the next free number depends on which prefix you pick. Retired prefixes are not offered here.';

    public const SUFFIX = 'A suffix is added to the end of a sell number, like the UV in Y1715UV, to mark a variant of the product. '
        . 'It does not start a new count: Y1715 and Y1716UV come from the same counter. Only the suffixes this prefix allows are offered; the plain number is always available.';

    public const REASON = 'Why this product is getting a sell number: a relabel of an existing product, a customer-specific code, a new version of the formula, '
        . 'something bought in for resale, or another reason you type in. It is kept with the code so anyone looking it up later can see why it was created.';

    public const NEXT_NUMBER = 'The counter is the next number that will be handed out. Move it up to skip ahead to a new block of numbers. '
        . 'It can only be moved back down as far as the number after the last one actually used, so no number is ever given out twice. Every change is logged.';

    public const DIGITAL_MATCH = 'Tick this when the color was matched on screen or from a digital standard rather than by eye against a physical sample. '
        . 'The formula card and the item list then say "Digital Match" so the press crew knows what to expect.';
}
