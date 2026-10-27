<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * Small validator producing plain-language messages keyed by field.
 *
 *   $v = new Validator($input);
 *   $v->required('title', 'Give the project a short title.');
 *   $v->maxLength('title', 200);
 *   $v->fail();   // throws ValidationException if anything failed
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $input */
    public function __construct(private readonly array $input)
    {
    }

    public function value(string $field): mixed
    {
        $v = $this->input[$field] ?? null;
        return is_string($v) ? trim($v) : $v;
    }

    public function error(string $field, string $message): self
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function required(string $field, ?string $message = null): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '' || $v === []) {
            $this->error($field, $message ?? 'This is required.');
        }
        return $this;
    }

    public function maxLength(string $field, int $max, ?string $message = null): self
    {
        $v = $this->value($field);
        if (is_string($v) && mb_strlen($v) > $max) {
            $this->error($field, $message ?? "Keep this to $max characters or fewer.");
        }
        return $this;
    }

    public function integer(string $field, ?int $min = null, ?int $max = null, ?string $message = null): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_numeric($v) || (string) (int) $v !== (string) $v) {
            return $this->error($field, $message ?? 'Enter a whole number.');
        }
        $n = (int) $v;
        if ($min !== null && $n < $min) {
            return $this->error($field, $message ?? "Enter a number of at least $min.");
        }
        if ($max !== null && $n > $max) {
            return $this->error($field, $message ?? "Enter a number no bigger than $max.");
        }
        return $this;
    }

    public function date(string $field, ?string $message = null): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            $this->error($field, $message ?? 'Enter a date as YYYY-MM-DD, or pick one from the calendar.');
        }
        return $this;
    }

    /** @param list<string|int> $allowed */
    public function in(string $field, array $allowed, ?string $message = null): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !in_array($v, $allowed, false)) {
            $this->error($field, $message ?? 'Pick one of the options in the list.');
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && (!is_string($v) || !preg_match($pattern, $v))) {
            $this->error($field, $message);
        }
        return $this;
    }

    public function custom(string $field, callable $check, string $message): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !$check($v)) {
            $this->error($field, $message);
        }
        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @throws ValidationException */
    public function fail(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }
}
