<?php
declare(strict_types=1);

namespace Chroma\Core;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'We could not find that page.')
    {
        parent::__construct(404, $message);
    }
}
