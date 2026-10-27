<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Core\Router;
use Chroma\Http\Controllers\Admin\CustomersController;
use Chroma\Http\Controllers\Admin\ListsController;
use Chroma\Http\Controllers\Admin\ProjectTypesController;

// Ink series, coatings, substrates: /admin/lists?tab=ink-series|coatings|substrates
$router->group('/admin/lists', [Permissions::ADMIN_LISTS], function (Router $r) {
    $r->get('', [ListsController::class, 'index']);
    $r->post('/{kind:[a-z-]+}', [ListsController::class, 'add']);
    $r->post('/{kind:[a-z-]+}/{id:\d+}', [ListsController::class, 'update']);
    $r->post('/{kind:[a-z-]+}/{id:\d+}/deactivate', [ListsController::class, 'deactivate']);
    $r->post('/{kind:[a-z-]+}/{id:\d+}/reactivate', [ListsController::class, 'reactivate']);
    $r->post('/{kind:[a-z-]+}/{id:\d+}/delete', [ListsController::class, 'delete']);
});

// Project types with their stages and item statuses.
$router->group('/admin/project-types', [Permissions::ADMIN_LISTS], function (Router $r) {
    $r->get('', [ProjectTypesController::class, 'index']);
    $r->get('/new', [ProjectTypesController::class, 'newForm']);
    $r->post('', [ProjectTypesController::class, 'create']);
    $r->get('/{id:\d+}', [ProjectTypesController::class, 'edit']);
    $r->post('/{id:\d+}', [ProjectTypesController::class, 'update']);
    $r->post('/{id:\d+}/stages', [ProjectTypesController::class, 'addStage']);
    $r->post('/{id:\d+}/stages/{sid:\d+}', [ProjectTypesController::class, 'updateStage']);
    $r->post('/{id:\d+}/stages/{sid:\d+}/move', [ProjectTypesController::class, 'moveStage']);
    $r->post('/{id:\d+}/stages/{sid:\d+}/delete', [ProjectTypesController::class, 'deleteStage']);
    $r->post('/{id:\d+}/statuses/customize', [ProjectTypesController::class, 'customizeStatuses']);
    $r->post('/{id:\d+}/statuses/revert', [ProjectTypesController::class, 'revertStatuses']);
    $r->post('/{id:\d+}/statuses', [ProjectTypesController::class, 'addStatus']);
});

// The shared item-status list, and edits to any single status (type-specific or shared).
$router->group('/admin/item-statuses', [Permissions::ADMIN_LISTS], function (Router $r) {
    $r->get('', [ProjectTypesController::class, 'globalStatuses']);
    $r->post('', [ProjectTypesController::class, 'addStatus']);
    $r->post('/{sid:\d+}', [ProjectTypesController::class, 'updateStatus']);
    $r->post('/{sid:\d+}/move', [ProjectTypesController::class, 'moveStatus']);
    $r->post('/{sid:\d+}/delete', [ProjectTypesController::class, 'deleteStatus']);
});

$router->group('/admin/customers', [Permissions::ADMIN_LISTS], function (Router $r) {
    $r->get('', [CustomersController::class, 'index']);
    $r->get('/new', [CustomersController::class, 'newForm']);
    $r->post('', [CustomersController::class, 'create']);
    $r->get('/{id:\d+}', [CustomersController::class, 'edit']);
    $r->post('/{id:\d+}', [CustomersController::class, 'update']);
    $r->post('/{id:\d+}/deactivate', [CustomersController::class, 'deactivate']);
    $r->post('/{id:\d+}/reactivate', [CustomersController::class, 'reactivate']);
    $r->get('/{id:\d+}/merge', [CustomersController::class, 'mergeForm']);
    $r->post('/{id:\d+}/merge', [CustomersController::class, 'merge']);
});
