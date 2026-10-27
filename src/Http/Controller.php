<?php
declare(strict_types=1);

namespace Chroma\Http;

use Chroma\Core\App;
use Chroma\Core\Auth;
use Chroma\Core\Db;
use Chroma\Core\NotFoundException;
use Chroma\Core\Request;
use Chroma\Core\Response;

abstract class Controller
{
    protected Db $db;
    protected Auth $auth;

    public function __construct(protected App $app)
    {
        $this->db = $app->db;
        $this->auth = $app->auth;
    }

    /** @param array<string,mixed> $data */
    protected function view(string $name, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        return Response::html($this->app->view->render($name, $data, $layout));
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function success(string $message, ?string $detail = null): void
    {
        $this->app->flash->success($message, $detail);
    }

    protected function failure(string $message, ?string $detail = null): void
    {
        $this->app->flash->error($message, $detail);
    }

    protected function notFound(string $message = 'We could not find that.'): never
    {
        throw new NotFoundException($message);
    }

    /** Either redirect (HTML) or return JSON (fetch) depending on the caller. @param array<string,mixed> $payload */
    protected function done(Request $request, string $redirectTo, array $payload = []): Response
    {
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'redirect' => $redirectTo] + $payload);
        }
        return $this->redirect($redirectTo);
    }

    protected function userId(): int
    {
        return (int) $this->auth->id();
    }

    /** Pagination helper. @return array{page:int,per_page:int,offset:int} */
    protected function paging(Request $request, int $default = 50): array
    {
        $page = max(1, $request->int('page', 1) ?? 1);
        $per = $request->int('per_page', $default) ?? $default;
        $per = max(10, min(500, $per));
        return ['page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per];
    }
}
