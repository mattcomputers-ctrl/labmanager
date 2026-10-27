<?php
declare(strict_types=1);

namespace Chroma\Core;

use Chroma\Domain\Audit\AuditLog;

final class App
{
    private static ?App $instance = null;

    public readonly string $root;
    public readonly Config $config;
    public readonly Db $db;
    public readonly Session $session;
    public readonly Auth $auth;
    public readonly Csrf $csrf;
    public readonly Flash $flash;
    public readonly Logger $log;
    public readonly View $view;
    public readonly Router $router;
    public readonly Settings $settings;
    public readonly AuditLog $audit;
    public readonly bool $debug;
    private ?string $modeCache = null;
    private ?Request $currentRequest = null;

    private function __construct(string $root, ?string $configPath)
    {
        $this->root = $root;
        $this->config = Config::load($configPath, $root);
        $this->db = Db::fromConfig($this->config);
        $this->debug = $this->config->bool('APP_DEBUG', false);
        $this->log = new Logger($this->config->get('LOG_PATH', $root . '/storage/logs'));
        $this->session = new Session($this->db, $this->config->bool('APP_SECURE_COOKIES', false), $this->config->int('SESSION_LIFETIME', 43200));
        $this->auth = new Auth($this->db, $this->session);
        $this->csrf = new Csrf($this->session);
        $this->flash = new Flash($this->session);
        $this->settings = new Settings($this->db);
        $this->audit = new AuditLog($this->db, $this->auth);
        $this->view = new View($root . '/resources/views', $root . '/resources/components');
        $this->router = new Router();
        date_default_timezone_set('UTC');
    }

    public static function boot(?string $root = null, ?string $configPath = null): self
    {
        if (self::$instance === null) {
            $root = $root ?? dirname(__DIR__, 2);
            self::$instance = new self($root, $configPath);
            self::$instance->view->share('app', self::$instance);
            self::$instance->view->share('auth', self::$instance->auth);
            self::$instance->view->share('settings', self::$instance->settings);
        }
        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('App not booted');
        }
        return self::$instance;
    }

    /** Reset the singleton (tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function loadRoutes(): void
    {
        foreach (glob($this->root . '/routes/*.php') ?: [] as $file) {
            $router = $this->router;
            $app = $this;
            require $file;
        }
    }

    public function request(): ?Request
    {
        return $this->currentRequest;
    }

    /** Current UI mode: 'easy' or 'expert'. */
    public function mode(): string
    {
        if ($this->modeCache !== null) {
            return $this->modeCache;
        }
        $mode = null;
        $user = $this->auth->user();
        if ($user && in_array($user['ui_mode'] ?? null, ['easy', 'expert'], true)) {
            $mode = $user['ui_mode'];
        } elseif (in_array($_COOKIE['chroma_mode'] ?? null, ['easy', 'expert'], true)) {
            $mode = $_COOKIE['chroma_mode'];
        }
        return $this->modeCache = $mode ?? 'easy';
    }

    public function setMode(string $mode): void
    {
        $mode = $mode === 'expert' ? 'expert' : 'easy';
        $this->modeCache = $mode;
        if ($id = $this->auth->id()) {
            $this->db->update('chroma_users', ['ui_mode' => $mode], ['id' => $id]);
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('chroma_mode', $mode, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        }
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->settings->get('timezone', 'America/Chicago'));
    }

    public function version(): array
    {
        $commit = '';
        $date = '';
        $head = $this->root . '/.git/HEAD';
        if (is_readable($head)) {
            $ref = trim((string) file_get_contents($head));
            if (str_starts_with($ref, 'ref: ')) {
                $refFile = $this->root . '/.git/' . substr($ref, 5);
                if (is_readable($refFile)) {
                    $commit = trim((string) file_get_contents($refFile));
                } else {
                    $packed = $this->root . '/.git/packed-refs';
                    if (is_readable($packed) && preg_match('#^([0-9a-f]{40}) ' . preg_quote(substr($ref, 5), '#') . '$#m', (string) file_get_contents($packed), $m)) {
                        $commit = $m[1];
                    }
                }
            } else {
                $commit = $ref;
            }
            $date = $commit !== '' && is_readable($head) ? gmdate('Y-m-d H:i', (int) filemtime($head)) . ' UTC' : '';
        }
        $versionFile = $this->root . '/VERSION';
        $tag = is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';
        return ['tag' => $tag, 'commit' => $commit, 'short' => substr($commit, 0, 8), 'date' => $date];
    }

    public function handle(Request $request): Response
    {
        $this->currentRequest = $request;
        $this->session->start();
        $this->view->share('mode', $this->mode());
        $this->view->share('request', $request);

        try {
            $match = $this->router->match($request->method, $request->path);
            if ($match === null) {
                if ($this->router->pathExists($request->path)) {
                    throw new HttpException(405, 'That action is not allowed on this page.');
                }
                throw new NotFoundException();
            }
            [$route, $params] = $match;
            $request->params = $params;

            if (!$route->public && !$this->auth->check()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Please sign in.'], 401);
                }
                $next = $request->method === 'GET' ? '?next=' . rawurlencode($request->fullUrl()) : '';
                return Response::redirect('/login' . $next);
            }

            $user = $this->auth->user();
            if ($user && (int) ($user['must_change_password'] ?? 0) === 1
                && !in_array($request->path, ['/account/password', '/logout', '/mode'], true)) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Please choose your own password before doing anything else.', 'redirect' => '/account/password?first=1'], 403);
                }
                return Response::redirect('/account/password?first=1');
            }

            if ($route->csrf && !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) && !$this->csrf->check($request)) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Your session expired. Reload the page and try again.'], 419);
                }
                $this->flash->error('Your session expired. Please try that again.');
                return Response::redirect($request->path);
            }

            foreach ($route->permissions as $perm) {
                if (!$this->auth->can($perm)) {
                    throw new ForbiddenException();
                }
            }
            if ($route->anyPermissions !== [] && !$this->auth->canAny(...$route->anyPermissions)) {
                throw new ForbiddenException();
            }

            $result = $this->invoke($route->handler, $request);
            return $this->toResponse($result);
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors], 422);
            }
            $this->flash->withInput($request->body, $e->errors);
            $this->flash->error($e->getMessage());
            $back = $request->header('referer');
            $target = $back && str_starts_with($back, '/') ? $back : ($back ? (parse_url($back, PHP_URL_PATH) ?: $request->path) . (parse_url($back, PHP_URL_QUERY) ? '?' . parse_url($back, PHP_URL_QUERY) : '') : $request->path);
            return Response::redirect($target);
        } catch (HttpException $e) {
            return $this->errorResponse($request, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            $this->log->error($e->getMessage(), ['type' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(), 'path' => $request->path]);
            $detail = $this->debug ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() : null;
            return $this->errorResponse($request, 500, 'Something went wrong on our side. Please try again; if it keeps happening, tell an administrator.', $detail);
        }
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function invoke(mixed $handler, Request $request): mixed
    {
        if (is_array($handler) && is_string($handler[0])) {
            $controller = new $handler[0]($this);
            return $controller->{$handler[1]}($request);
        }
        return $handler($request, $this);
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result) || $result instanceof \JsonSerializable) {
            return Response::json($result);
        }
        return Response::html('');
    }

    public function errorResponse(Request $request, int $status, string $message, ?string $detail = null): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message] + ($detail ? ['detail' => $detail] : []), $status);
        }
        $title = match ($status) {
            403 => "You can't open this",
            404 => 'Page not found',
            405 => 'Not allowed',
            default => 'Something went wrong',
        };
        $html = $this->view->render('errors/error', ['status' => $status, 'title' => $title, 'message' => $message, 'detail' => $detail],
            $this->auth->check() ? 'layouts/app' : 'layouts/plain');
        return Response::html($html, $status);
    }
}
