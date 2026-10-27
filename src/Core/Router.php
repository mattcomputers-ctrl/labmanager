<?php
declare(strict_types=1);

namespace Chroma\Core;

final class Route
{
    /** @var list<string> */
    public array $permissions = [];
    /** @var list<string> at least one of these is required (checked after $permissions) */
    public array $anyPermissions = [];
    public bool $public = false;
    public bool $csrf = true;
    public ?string $name = null;

    /** @param callable|array{0:class-string,1:string} $handler */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly mixed $handler,
        public readonly string $regex,
        /** @var list<string> */
        public readonly array $paramNames,
    ) {
    }

    public function permission(string ...$keys): self
    {
        $this->permissions = array_merge($this->permissions, $keys);
        return $this;
    }

    /** Require at least one of the given permissions. */
    public function anyPermission(string ...$keys): self
    {
        $this->anyPermissions = array_merge($this->anyPermissions, $keys);
        return $this;
    }

    /** Route reachable without login (login page, API with token). */
    public function public(): self
    {
        $this->public = true;
        return $this;
    }

    public function noCsrf(): self
    {
        $this->csrf = false;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}

final class Router
{
    /** @var array<string,list<Route>> */
    private array $routes = [];
    /** @var array<string,Route> */
    private array $named = [];
    private string $groupPrefix = '';
    /** @var list<string> */
    private array $groupPermissions = [];

    /** @param callable|array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, mixed $handler): Route
    {
        $pattern = $this->groupPrefix . $pattern;
        $names = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#', function ($m) use (&$names) {
            $names[] = $m[1];
            $sub = $m[2] ?? '[^/]+';
            return '(' . $sub . ')';
        }, $pattern);
        $route = new Route(strtoupper($method), $pattern, $handler, '#^' . $regex . '$#', $names);
        $route->permissions = $this->groupPermissions;
        $this->routes[$route->method][] = $route;
        return $route;
    }

    /** @param callable|array{0:class-string,1:string} $h */
    public function get(string $p, mixed $h): Route { return $this->add('GET', $p, $h); }
    /** @param callable|array{0:class-string,1:string} $h */
    public function post(string $p, mixed $h): Route { return $this->add('POST', $p, $h); }
    /** @param callable|array{0:class-string,1:string} $h */
    public function put(string $p, mixed $h): Route { return $this->add('PUT', $p, $h); }
    /** @param callable|array{0:class-string,1:string} $h */
    public function delete(string $p, mixed $h): Route { return $this->add('DELETE', $p, $h); }

    /**
     * Register routes under a prefix with permissions applied to each.
     * @param list<string> $permissions
     * @param callable(Router):void $fn
     */
    public function group(string $prefix, array $permissions, callable $fn): void
    {
        $prevPrefix = $this->groupPrefix;
        $prevPerms = $this->groupPermissions;
        $this->groupPrefix = $prevPrefix . $prefix;
        $this->groupPermissions = array_merge($prevPerms, $permissions);
        $fn($this);
        $this->groupPrefix = $prevPrefix;
        $this->groupPermissions = $prevPerms;
    }

    /** @return array{0:Route,1:array<string,string>}|null */
    public function match(string $method, string $path): ?array
    {
        $candidates = $this->routes[$method] ?? [];
        if ($method === 'HEAD') {
            $candidates = array_merge($candidates, $this->routes['GET'] ?? []);
        }
        foreach ($candidates as $route) {
            if (preg_match($route->regex, $path, $m)) {
                $params = [];
                foreach ($route->paramNames as $i => $name) {
                    $params[$name] = $m[$i + 1];
                }
                return [$route, $params];
            }
        }
        return null;
    }

    /** True if some other method matches the path (for 405 responses). */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                if (preg_match($route->regex, $path)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,list<Route>> */
    public function all(): array
    {
        return $this->routes;
    }
}
