<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

/** One screen of an Easy-mode wizard: one question, one or a few fields. */
final class Step
{
    /**
     * @param list<array<string,mixed>> $fields field specs as accepted by resources/components/form/field.php
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $intro = '',
        public readonly array $fields = [],
        public readonly ?string $help = null,
        public readonly ?string $template = null,
        public readonly ?string $nextLabel = null,
        /** Label that follows a radio on this step: ['field' => 'item_1_more', 'labels' => ['yes' => 'Next: color 2 ›', 'no' => 'Review ›']]. @var array{field:string,labels:array<string,string>}|null */
        public readonly ?array $nextLabelBy = null,
    ) {
    }
}
