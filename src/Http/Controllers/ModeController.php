<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

/** Easy / Expert switch. GET so it works as a plain link in the top bar. */
final class ModeController extends Controller
{
    public function set(Request $request): Response
    {
        $mode = $request->str('set') === 'expert' ? 'expert' : 'easy';
        $this->app->setMode($mode);
        $return = $request->str('return', '/');
        if (!str_starts_with($return, '/') || str_starts_with($return, '//')) {
            $return = '/';
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'mode' => $mode]);
        }
        return $this->redirect($return);
    }
}
