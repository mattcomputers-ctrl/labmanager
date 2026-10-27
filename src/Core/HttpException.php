<?php
declare(strict_types=1);

namespace Chroma\Core;

/** Base for errors with an HTTP status. */
class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message);
    }
}
