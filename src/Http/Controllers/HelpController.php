<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

/** Help pages are markdown files in resources/help/*.md, written for the lab. */
final class HelpController extends Controller
{
    /** The order topics are listed in: everyday pages first, then the admin pages (shown only to people who can open those areas). */
    private const ORDER = ['index', 'projects', 'codes', 'quick-assign', 'reports'];
    private const ADMIN_ORDER = ['admin-users', 'admin-prefixes', 'admin-lists', 'admin-import', 'admin-cms', 'admin-system', 'server'];

    public function index(Request $request): Response
    {
        return $this->page($request, 'index');
    }

    public function page(Request $request, ?string $slug = null): Response
    {
        $slug = $slug ?? (string) ($request->params['slug'] ?? 'index');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $this->notFound();
        }
        $file = $this->app->root . '/resources/help/' . $slug . '.md';
        if (!is_file($file)) {
            $this->notFound('There is no help page with that name yet.');
        }
        $md = (string) file_get_contents($file);
        $title = preg_match('/^#\s+(.+)$/m', $md, $m) ? trim($m[1]) : 'Help';
        return $this->view('help/page', ['title' => $title, 'html' => markdown($md), 'slug' => $slug, 'pages' => $this->topics()]);
    }

    /**
     * The topic list for the index: slug => short title, in reading order. Admin topics are listed only
     * for people holding an admin permission; any page not in the explicit order goes last, by title.
     * @return array<string,string>
     */
    public function topics(): array
    {
        $all = [];
        foreach (glob($this->app->root . '/resources/help/*.md') ?: [] as $f) {
            $s = basename($f, '.md');
            $t = preg_match('/^#\s+(.+)$/m', (string) file_get_contents($f), $mm) ? trim($mm[1]) : $s;
            $all[$s] = self::shortTitle($t);
        }
        $order = self::ORDER;
        if ($this->auth->canAny(...Permissions::adminKeys())) {
            $order = array_merge($order, self::ADMIN_ORDER);
        }
        $pages = [];
        foreach ($order as $s) {
            if (isset($all[$s])) {
                $pages[$s] = $all[$s];
                unset($all[$s]);
            }
        }
        $rest = array_filter($all, fn(string $s) => !str_starts_with($s, 'admin-') && $s !== 'server', ARRAY_FILTER_USE_KEY);
        asort($rest);
        return $pages + $rest;
    }

    /** "Help: E numbers and sell numbers" -> "E numbers and sell numbers", capitalised for the list. */
    public static function shortTitle(string $title): string
    {
        $t = trim((string) preg_replace('/^Help:\s*/i', '', $title));
        return $t === '' ? $title : mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }
}
