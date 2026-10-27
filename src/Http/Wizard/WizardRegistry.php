<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\App;

final class WizardRegistry
{
    /** @var array<string,class-string<Wizard>> */
    private static array $map = [];

    /** @param class-string<Wizard> $class */
    public static function register(string $key, string $class): void
    {
        self::$map[$key] = $class;
    }

    public static function make(App $app, string $key): ?Wizard
    {
        $class = self::$map[$key] ?? null;
        return $class ? new $class($app) : null;
    }

    /** @return array<string,class-string<Wizard>> */
    public static function all(): array
    {
        return self::$map;
    }
}
