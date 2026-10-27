<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Projects\AttachmentsController;
use Chroma\Http\Controllers\Projects\ItemsController;
use Chroma\Http\Controllers\Projects\ProjectsController;
use Chroma\Http\Controllers\Projects\TasksController;
use Chroma\Http\Wizard\AddItemWizard;
use Chroma\Http\Wizard\AssignItemCodeWizard;
use Chroma\Http\Wizard\BatchAssignWizard;
use Chroma\Http\Wizard\NewProjectWizard;
use Chroma\Http\Wizard\WizardRegistry;

// Easy-mode wizards (reachable at /wizard/<key>)
WizardRegistry::register('new-project', NewProjectWizard::class);
WizardRegistry::register('add-item', AddItemWizard::class);
WizardRegistry::register('assign-item-code', AssignItemCodeWizard::class);
WizardRegistry::register('batch-assign', BatchAssignWizard::class);

// Projects list, new project, typeahead
$router->get('/projects', [ProjectsController::class, 'index'])->permission(Permissions::PROJECTS_VIEW);
$router->get('/projects/export', [ProjectsController::class, 'export'])->permission(Permissions::PROJECTS_VIEW, Permissions::CODES_EXPORT);
$router->get('/projects/new', [ProjectsController::class, 'create'])->permission(Permissions::PROJECTS_CREATE);
$router->post('/projects', [ProjectsController::class, 'store'])->permission(Permissions::PROJECTS_CREATE);
$router->get('/api/projects/search', [ProjectsController::class, 'search'])->permission(Permissions::PROJECTS_VIEW);

// Project page and details (confidential projects 404 for people who may not see them)
$router->get('/projects/{id:\d+}', [ProjectsController::class, 'show'])->permission(Permissions::PROJECTS_VIEW);
$router->post('/projects/{id:\d+}', [ProjectsController::class, 'update'])->permission(Permissions::PROJECTS_EDIT);
$router->get('/projects/{id:\d+}/edit', [ProjectsController::class, 'edit'])->permission(Permissions::PROJECTS_EDIT);
$router->post('/projects/{id:\d+}/stage', [ProjectsController::class, 'stage'])->permission(Permissions::PROJECTS_EDIT);
$router->post('/projects/{id:\d+}/complete', [ProjectsController::class, 'complete'])->permission(Permissions::PROJECTS_COMPLETE);
$router->post('/projects/{id:\d+}/cancel', [ProjectsController::class, 'cancel'])->permission(Permissions::PROJECTS_COMPLETE);
$router->post('/projects/{id:\d+}/reopen', [ProjectsController::class, 'reopen'])->permission(Permissions::PROJECTS_COMPLETE);
$router->get('/projects/{id:\d+}/print', [ProjectsController::class, 'print'])->permission(Permissions::PROJECTS_VIEW);
$router->post('/projects/{id:\d+}/notes', [ProjectsController::class, 'note'])->permission(Permissions::PROJECTS_EDIT);

// Tasks (the home page posts to /projects/{id}/tasks/{tid}/done)
$router->post('/projects/{id:\d+}/tasks', [TasksController::class, 'store'])->permission(Permissions::PROJECTS_EDIT);
$router->post('/projects/{id:\d+}/tasks/{tid:\d+}/done', [TasksController::class, 'markDone'])->permission(Permissions::PROJECTS_VIEW);
$router->post('/projects/{id:\d+}/tasks/{tid:\d+}/undo', [TasksController::class, 'undo'])->permission(Permissions::PROJECTS_VIEW);
$router->post('/projects/{id:\d+}/tasks/{tid:\d+}/delete', [TasksController::class, 'remove'])->permission(Permissions::PROJECTS_EDIT);

// Attachments
$router->post('/projects/{id:\d+}/attachments', [AttachmentsController::class, 'store'])->permission(Permissions::PROJECTS_EDIT);
$router->get('/projects/{id:\d+}/attachments/{aid:\d+}', [AttachmentsController::class, 'download'])->permission(Permissions::PROJECTS_VIEW);
$router->post('/projects/{id:\d+}/attachments/{aid:\d+}/delete', [AttachmentsController::class, 'remove'])->permission(Permissions::PROJECTS_EDIT);

// Items / colors
$router->post('/projects/{id:\d+}/items', [ItemsController::class, 'store'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/reorder', [ItemsController::class, 'reorder'])->permission(Permissions::COLORS_EDIT);
$router->get('/projects/{id:\d+}/items/{iid:\d+}/edit', [ItemsController::class, 'edit'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}', [ItemsController::class, 'update'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/status', [ItemsController::class, 'status'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/reorder', [ItemsController::class, 'reorder'])->permission(Permissions::COLORS_EDIT);
$router->get('/projects/{id:\d+}/items/{iid:\d+}/move', [ItemsController::class, 'moveForm'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/move', [ItemsController::class, 'move'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/remove', [ItemsController::class, 'remove'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/link', [ItemsController::class, 'link'])->permission(Permissions::COLORS_EDIT);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/assign-internal', [ItemsController::class, 'assignInternal'])->permission(Permissions::CODES_ASSIGN_INTERNAL);
$router->post('/projects/{id:\d+}/items/{iid:\d+}/assign-sell', [ItemsController::class, 'assignSell'])->permission(Permissions::CODES_ASSIGN_SELL);

// Batch assignment (Expert review page; Easy mode redirects to /wizard/batch-assign). Needs either assign permission (checked in the controller).
$router->get('/projects/{id:\d+}/batch', [ItemsController::class, 'batchForm'])->permission(Permissions::PROJECTS_VIEW);
$router->post('/projects/{id:\d+}/batch', [ItemsController::class, 'batch'])->permission(Permissions::PROJECTS_VIEW);
$router->get('/projects/{id:\d+}/batch/done', [ItemsController::class, 'batchDone'])->permission(Permissions::PROJECTS_VIEW);
