<?php
declare(strict_types=1);

namespace Chroma\Core;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = "You don't have permission to do that.")
    {
        parent::__construct(403, $message);
    }
}
