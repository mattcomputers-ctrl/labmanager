<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Api;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Api\ApiQueries;
use Chroma\Domain\Api\Tokens;
use Chroma\Http\Controller;

/**
 * The read-only JSON API for other Precision Ink systems (/api/v1).
 *
 * Authentication is a bearer token created under Admin → API tokens:
 *   Authorization: Bearer chroma_…
 * Routes are ->public()->noCsrf(); the token check happens here and every
 * failure is JSON, whatever Accept header the client sent.
 *
 * Envelope: lists are {data: [...], page, per_page, total}; single objects
 * are {data: {...}}; failures are {error: "..."} with a 401/404/422 status.
 */
final class PublicApiController extends Controller
{
    private function queries(): ApiQueries
    {
        return new ApiQueries($this->db);
    }

    /** Returns a 401 response when the bearer token is missing or invalid, otherwise null. */
    private function denied(Request $request): ?Response
    {
        $header = $request->header('authorization') ?? '';
        $bearer = null;
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            $bearer = $m[1];
        }
        // The token is only ever read from the header: a ?token= query string
        // would end up in web-server logs, browser history and Referer headers.
        if ($bearer === null) {
            return $this->error('Send an API token in the Authorization header: "Authorization: Bearer chroma_…". Tokens are created under Admin → API tokens.', 401);
        }
        $token = (new Tokens($this->db))->authenticate($bearer);
        if ($token === null) {
            return $this->error('That API token is not valid or has been revoked. Ask an administrator for a new one under Admin → API tokens.', 401);
        }
        return null;
    }

    private function error(string $message, int $status): Response
    {
        return $this->json(['error' => $message], $status)->header('Cache-Control', 'no-store');
    }

    /** @param list<array<string,mixed>> $data */
    private function list(array $data, int $page, int $perPage, int $total): Response
    {
        return $this->json(['data' => $data, 'page' => $page, 'per_page' => $perPage, 'total' => $total])->header('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $data */
    private function one(array $data): Response
    {
        return $this->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /** @return array{0:int,1:int} page, per_page (1..200) */
    private function pageArgs(Request $request): array
    {
        $page = max(1, $request->int('page', 1) ?? 1);
        $per = $request->int('per_page', 50) ?? 50;
        return [$page, max(1, min(ApiQueries::MAX_PER_PAGE, $per))];
    }

    /** Run an action, turning any validation problem into a JSON 422 and anything unexpected into a JSON 500. */
    private function guarded(Request $request, callable $fn): Response
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }
        try {
            return $fn();
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            $this->app->log->error('API error: ' . $e->getMessage(), ['type' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(), 'path' => $request->path]);
            return $this->error('Something went wrong on the Chroma side. Try again; if it keeps happening, tell an administrator.', 500);
        }
    }

    // ------------------------------------------------------------------
    // Endpoints
    // ------------------------------------------------------------------

    /** GET /api/v1/codes?q=…&include_voided=1&page=&per_page= */
    public function codes(Request $request): Response
    {
        return $this->guarded($request, function () use ($request) {
            [$page, $per] = $this->pageArgs($request);
            $q = $request->str('q');
            $includeVoided = !$request->bool('exclude_voided');
            $r = $this->queries()->searchCodes($q, $page, $per, $includeVoided);
            return $this->list($r['data'], $page, $per, $r['total']);
        });
    }

    /** GET /api/v1/codes/sell/{code} */
    public function sell(Request $request): Response
    {
        return $this->guarded($request, function () use ($request) {
            $code = (string) ($request->params['code'] ?? '');
            $row = $this->queries()->sellNumber($code);
            if ($row === null) {
                return $this->error(sprintf('No sell number matches "%s". Sell numbers look like Y1714UV or X104043; spacing, hyphens and case do not matter.', $code), 404);
            }
            return $this->one($row);
        });
    }

    /** GET /api/v1/codes/internal/{code} */
    public function internal(Request $request): Response
    {
        return $this->guarded($request, function () use ($request) {
            $code = (string) ($request->params['code'] ?? '');
            $row = $this->queries()->internalCode($code);
            if ($row === null) {
                return $this->error(sprintf('No E number matches "%s". Use E followed by the number, with an optional version after a dash, like E6374-10.', $code), 404);
            }
            return $this->one($row);
        });
    }

    /** GET /api/v1/prefixes */
    public function prefixes(Request $request): Response
    {
        return $this->guarded($request, function () {
            $rows = $this->queries()->prefixes();
            return $this->list($rows, 1, count($rows), count($rows));
        });
    }

    /** GET /api/v1/projects?status=open|completed|cancelled&customer=&type=&updated_since=ISO&page=&per_page= */
    public function projects(Request $request): Response
    {
        return $this->guarded($request, function () use ($request) {
            [$page, $per] = $this->pageArgs($request);
            $status = $request->str('status');
            if ($status !== '' && !in_array($status, ['open', 'completed', 'cancelled'], true)) {
                return $this->error('status must be one of open, completed or cancelled (or leave it out for all).', 422);
            }
            $since = $request->str('updated_since');
            $sinceUtc = null;
            if ($since !== '') {
                try {
                    $sinceUtc = (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                } catch (\Exception) {
                    return $this->error('updated_since must be an ISO 8601 date or date-time, like 2026-10-01 or 2026-10-01T14:30:00Z.', 422);
                }
            }
            $r = $this->queries()->projects([
                'status' => $status ?: null,
                'customer' => $request->str('customer') ?: null,
                'type' => $request->str('type') ?: null,
                'updated_since' => $sinceUtc,
            ], $page, $per);
            return $this->list($r['data'], $page, $per, $r['total']);
        });
    }

    /**
     * Any /api/v1 path that no endpoint claims: a JSON 404, or a JSON 405
     * when the path exists but only for GET (the API is read-only). Without
     * this the framework would answer with the HTML error page.
     */
    public function unmatched(Request $request): Response
    {
        return $this->guarded($request, function () use ($request) {
            if ($request->method !== 'GET' && $request->method !== 'HEAD') {
                $get = $this->app->router->match('GET', $request->path);
                $handler = $get[0]->handler ?? null;
                if ($get !== null && !(is_array($handler) && ($handler[1] ?? '') === 'unmatched')) {
                    return $this->error('The Chroma API is read-only: use GET on ' . $request->path . '.', 405)->header('Allow', 'GET, HEAD');
                }
            }
            return $this->error(sprintf('There is no API endpoint at %s. The endpoints are listed in docs/API.md: /api/v1/codes, /api/v1/codes/sell/{code}, /api/v1/codes/internal/{code}, /api/v1/prefixes, /api/v1/projects and /api/v1/projects/{number}.', $request->path), 404);
        });
    }

    /** GET /api/v1/projects/{number} */
    public function project(Request $request): Response
    {
        return $this->guarded($request, function () use ($request) {
            $number = (int) ($request->params['number'] ?? 0);
            $row = $this->queries()->project($number);
            if ($row === null) {
                return $this->error(sprintf('There is no project number %d that this API can show.', $number), 404);
            }
            return $this->one($row);
        });
    }
}
