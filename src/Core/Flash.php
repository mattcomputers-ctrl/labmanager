<?php
declare(strict_types=1);

namespace Chroma\Core;

/** One-shot messages shown as toasts on the next page. */
final class Flash
{
    public function __construct(private readonly Session $session)
    {
    }

    public function add(string $type, string $message, ?string $detail = null): void
    {
        $list = $this->session->get('_flash', []);
        $list[] = ['type' => $type, 'message' => $message, 'detail' => $detail];
        $this->session->set('_flash', $list);
    }

    public function success(string $m, ?string $d = null): void { $this->add('success', $m, $d); }
    public function error(string $m, ?string $d = null): void { $this->add('error', $m, $d); }
    public function info(string $m, ?string $d = null): void { $this->add('info', $m, $d); }
    public function warning(string $m, ?string $d = null): void { $this->add('warning', $m, $d); }

    /** @return list<array{type:string,message:string,detail:?string}> */
    public function pull(): array
    {
        $list = $this->session->pull('_flash', []);
        return is_array($list) ? $list : [];
    }

    /** Keep old form input + errors for re-display after a redirect. */
    public function withInput(array $input, array $errors): void
    {
        unset($input['_token'], $input['password'], $input['password_confirm'], $input['current_password']);
        $this->session->set('_old_input', $input);
        $this->session->set('_errors', $errors);
    }

    /** @return array<string,mixed> */
    public function oldInput(): array
    {
        $v = $this->session->pull('_old_input', []);
        return is_array($v) ? $v : [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        $v = $this->session->pull('_errors', []);
        return is_array($v) ? $v : [];
    }
}
