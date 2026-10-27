<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Core\Router;
use Chroma\Http\Controllers\Admin\GroupsController;
use Chroma\Http\Controllers\Admin\UsersController;
use Chroma\Http\Wizard\AddGroupWizard;
use Chroma\Http\Wizard\AddUserWizard;
use Chroma\Http\Wizard\WizardRegistry;

// Easy mode: /wizard/add-user and /wizard/add-group (permission checked by the wizard itself).
WizardRegistry::register('add-user', AddUserWizard::class);
WizardRegistry::register('add-group', AddGroupWizard::class);

$router->group('/admin/users', [Permissions::ADMIN_USERS], function (Router $r) {
    $r->get('', [UsersController::class, 'index']);
    $r->get('/new', [UsersController::class, 'newForm']);
    $r->post('', [UsersController::class, 'create']);
    $r->get('/{id:\d+}', [UsersController::class, 'edit']);
    $r->post('/{id:\d+}', [UsersController::class, 'update']);
    $r->get('/{id:\d+}/password', [UsersController::class, 'passwordForm']);
    $r->post('/{id:\d+}/password', [UsersController::class, 'resetPassword']);
    $r->post('/{id:\d+}/deactivate', [UsersController::class, 'deactivate']);
    $r->post('/{id:\d+}/reactivate', [UsersController::class, 'reactivate']);
});

$router->group('/admin/groups', [Permissions::ADMIN_USERS], function (Router $r) {
    $r->get('', [GroupsController::class, 'index']);
    $r->get('/new', [GroupsController::class, 'newForm']);
    $r->post('', [GroupsController::class, 'create']);
    $r->get('/{id:\d+}', [GroupsController::class, 'edit']);
    $r->post('/{id:\d+}', [GroupsController::class, 'update']);
    $r->post('/{id:\d+}/permissions', [GroupsController::class, 'permissions']);
    $r->post('/{id:\d+}/members', [GroupsController::class, 'addMember']);
    $r->post('/{id:\d+}/members/{uid:\d+}/remove', [GroupsController::class, 'removeMember']);
    $r->post('/{id:\d+}/delete', [GroupsController::class, 'delete']);
});
