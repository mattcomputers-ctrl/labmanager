<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

/** Help pages are markdown files in resources/help/*.md, written for the lab. */
final class HelpController extends Controller
{
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
        $pages = [];
        foreach (glob($this->app->root . '/resources/help/*.md') ?: [] as $f) {
            $s = basename($f, '.md');
            $t = preg_match('/^#\s+(.+)$/m', (string) file_get_contents($f), $mm) ? trim($mm[1]) : $s;
            $pages[$s] = $t;
        }
        return $this->view('help/page', ['title' => $title, 'html' => markdown($md), 'slug' => $slug, 'pages' => $pages]);
    }
}
