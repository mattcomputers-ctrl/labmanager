<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * PHP templates. Views live in resources/views, reusable components in
 * resources/components. Templates receive their data as local variables
 * plus $app, $auth, $mode and $view.
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];
    /** @var array<string,string> */
    private array $sections = [];
    private ?string $currentSection = null;

    public function __construct(private readonly string $viewsDir, private readonly string $componentsDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $name, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->partial($name, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->partial($layout, $data + ['content' => $content, 'sections' => $this->sections]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $name, array $data = []): string
    {
        $file = $this->viewsDir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $name");
        }
        return $this->include($file, $data);
    }

    /** @param array<string,mixed> $props */
    public function component(string $name, array $props = []): string
    {
        $file = $this->componentsDir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Component not found: $name");
        }
        return $this->include($file, $props);
    }

    /** @param array<string,mixed> $__data */
    private function include(string $__file, array $__data): string
    {
        $__data = $this->shared + $__data;
        $__data['view'] = $this;
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Begin a named section (e.g. page actions, head extras) from inside a view. */
    public function start(string $section): void
    {
        $this->currentSection = $section;
        ob_start();
    }

    public function end(): void
    {
        if ($this->currentSection === null) {
            return;
        }
        $this->sections[$this->currentSection] = (string) ob_get_clean();
        $this->currentSection = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }
}
