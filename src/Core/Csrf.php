<?php
declare(strict_types=1);

namespace Chroma\Core;

final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $t = $this->session->get('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $t);
        }
        return $t;
    }

    public function check(Request $request): bool
    {
        $sent = $request->body['_token'] ?? $request->header('x-csrf-token') ?? '';
        $known = $this->session->get('_csrf');
        return is_string($sent) && is_string($known) && $known !== '' && hash_equals($known, $sent);
    }
}
