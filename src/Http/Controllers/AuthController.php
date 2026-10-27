<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }
        return $this->view('auth/login', ['title' => 'Sign in', 'next' => $request->str('next')], 'layouts/plain');
    }

    public function login(Request $request): Response
    {
        $username = $request->str('username');
        $password = (string) $request->input('password', '');
        $next = $request->str('next');
        $error = $this->auth->attempt($username, $password, $request->ip);
        if ($error !== null) {
            $this->app->flash->withInput(['username' => $username], ['username' => $error]);
            return $this->redirect(url('/login', ['next' => $next]));
        }
        $this->app->audit->record('auth.login');
        $safeNext = ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) ? $next : '/';
        return $this->redirect($safeNext);
    }

    public function logout(Request $request): Response
    {
        if ($this->auth->check()) {
            $this->app->audit->record('auth.logout');
        }
        $this->auth->logout();
        return $this->redirect('/login');
    }
}
