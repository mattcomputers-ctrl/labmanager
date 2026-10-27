<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Auth;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Http\Controller;

final class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('account/show', ['title' => 'My account', 'user' => $this->auth->user(), 'groups' => $this->auth->groups(), 'permissions' => $this->auth->permissions()]);
    }

    public function update(Request $request): Response
    {
        $v = new Validator($request->body);
        $v->required('display_name', 'Enter the name people should see.')->maxLength('display_name', 150);
        $v->maxLength('email', 190)->custom('email', fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false, 'That does not look like an email address.');
        $v->maxLength('initials', 5);
        $v->fail();
        $before = $this->auth->user();
        $this->db->update('chroma_users', [
            'display_name' => $request->str('display_name'),
            'email' => $request->str('email') ?: null,
            'initials' => $request->str('initials') ?: null,
            'updated_at' => $this->db->now(),
        ], ['id' => $this->userId()]);
        $this->app->audit->record('user.update_profile', 'chroma_users', $this->userId(), ['display_name' => $before['display_name'] ?? '', 'email' => $before['email'] ?? ''], ['display_name' => $request->str('display_name'), 'email' => $request->str('email')]);
        $this->success('Your details were saved.');
        return $this->redirect('/account');
    }

    public function passwordForm(Request $request): Response
    {
        $user = $this->auth->user();
        $first = $request->bool('first') || (int) ($user['must_change_password'] ?? 0) === 1;
        return $this->view('account/password', ['title' => 'Change password', 'first' => $first], $first ? 'layouts/plain' : 'layouts/app');
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->auth->user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirm', '');
        $errors = [];
        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        }
        if ($p = Auth::passwordProblem($new)) {
            $errors['password'] = $p;
        } elseif ($new === $current) {
            $errors['password'] = 'Pick a password different from the current one.';
        }
        if ($new !== $confirm) {
            $errors['password_confirm'] = 'The two passwords do not match. Type the new one again.';
        }
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        $this->db->update('chroma_users', ['password_hash' => Auth::hash($new), 'must_change_password' => 0, 'updated_at' => $this->db->now()], ['id' => $this->userId()]);
        $this->app->audit->record('user.change_password', 'chroma_users', $this->userId());
        $this->success('Your password was changed.');
        return $this->redirect('/');
    }
}
