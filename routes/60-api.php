<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Http\Controllers\Api\PublicApiController;

// Read-only JSON API for other Precision Ink systems. Token-authenticated
// (Authorization: Bearer chroma_…) inside the controller, so no session,
// no CSRF, and every failure is JSON. See docs/API.md.
$router->get('/api/v1/codes', [PublicApiController::class, 'codes'])->public()->noCsrf();
$router->get('/api/v1/codes/sell/{code}', [PublicApiController::class, 'sell'])->public()->noCsrf();
$router->get('/api/v1/codes/internal/{code}', [PublicApiController::class, 'internal'])->public()->noCsrf();
$router->get('/api/v1/prefixes', [PublicApiController::class, 'prefixes'])->public()->noCsrf();
$router->get('/api/v1/projects', [PublicApiController::class, 'projects'])->public()->noCsrf();
$router->get('/api/v1/projects/{number:\d+}', [PublicApiController::class, 'project'])->public()->noCsrf();

// Anything else under /api/v1 answers in JSON too (404, or 405 for a write
// method on a real endpoint) instead of the HTML error page. Registered
// last so it never shadows a real endpoint.
foreach (['get', 'post', 'put', 'delete'] as $method) {
    $router->$method('/api/v1{any:.*}', [PublicApiController::class, 'unmatched'])->public()->noCsrf();
}
