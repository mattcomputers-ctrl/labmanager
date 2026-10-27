<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Permissions;
use Chroma\Core\Response;

/**
 * The Admin landing page is a real door, not an empty room: a user with no
 * admin.* permission gets the standard "You can't open this" page instead of
 * a blank Admin page. Also checks the hardening headers every response carries.
 */
final class AdminAccessTest extends TestCase
{
    private static bool $routesLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$routesLoaded) {
            $this->app->loadRoutes();
            self::$routesLoaded = true;
        }
    }

    public function testAdminHomeRequiresAnAdminPermission(): void
    {
        $match = $this->app->router->match('GET', '/admin');
        $this->assertNotNull($match, 'the /admin route exists');
        [$route] = $match;
        $this->assertSame(Permissions::adminKeys(), $route->anyPermissions, 'any admin.* key opens the Admin home');
        $this->assertNotSame([], Permissions::adminKeys());

        $reader = $this->makeUser('reader', 'Read only');
        $this->app->auth->actAs($reader);
        $this->assertFalse($this->app->auth->canAny(...$route->anyPermissions), 'a read-only user is turned away with a 403, not shown an empty page');

        $this->app->auth->actAs($this->adminId);
        $this->assertTrue($this->app->auth->canAny(...$route->anyPermissions));
    }

    public function testEveryResponseCarriesTheHardeningHeaders(): void
    {
        foreach ([Response::html('<p>x</p>'), Response::json(['ok' => true]), Response::redirect('/'), Response::text('x')] as $r) {
            $h = $r->headers();
            $this->assertSame('DENY', $h['X-Frame-Options'] ?? null);
            $this->assertSame('nosniff', $h['X-Content-Type-Options'] ?? null);
            $this->assertSame('same-origin', $h['Referrer-Policy'] ?? null);
            $this->assertStringContainsString("frame-ancestors 'none'", $h['Content-Security-Policy'] ?? '');
            $this->assertStringContainsString("default-src 'self'", $h['Content-Security-Policy'] ?? '');
        }
        // A caller can still override one (downloads set their own nosniff; same value, but the mechanism must work).
        $this->assertSame('SAMEORIGIN', Response::html('x')->header('X-Frame-Options', 'SAMEORIGIN')->headers()['X-Frame-Options']);
    }
}
