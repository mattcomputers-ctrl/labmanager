<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * Thrown by domain operations when input is invalid or a business rule
 * refuses the action. Messages are written for lab users, not developers:
 * they say what happened and what to do next.
 */
class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (reset($errors) ?: 'Please check the form.'));
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }

    public static function general(string $message): self
    {
        return new self(['_' => $message], $message);
    }
}
