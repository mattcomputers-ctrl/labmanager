<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

/** What the success screen shows after a wizard commits. */
final class WizardResult
{
    /**
     * @param list<array{0:string,1:string}> $details label/value pairs
     * @param list<array{label:string,href:string,primary?:bool}> $nextActions
     * @param list<array{code:string,label?:string,description?:string}> $codes codes just assigned, shown large
     */
    public function __construct(
        public readonly string $title,
        public readonly string $message = '',
        public readonly array $codes = [],
        public readonly array $details = [],
        public readonly array $nextActions = [],
        public readonly ?string $redirect = null,
    ) {
    }
}
