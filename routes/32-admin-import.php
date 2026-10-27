<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Admin\ImportController;

// Admin → Import legacy codes. Easy and Expert mode share the same three-step
// flow (upload → dry run → commit) because the task is sequential by nature.
$router->group('/admin/import', [Permissions::ADMIN_IMPORT], function ($r) {
    $r->get('', [ImportController::class, 'index']);
    $r->post('/upload', [ImportController::class, 'upload']);
    $r->get('/review/{id:\d+}', [ImportController::class, 'review']);
    $r->post('/review/{id:\d+}/rerun', [ImportController::class, 'rerun']);
    $r->post('/commit/{id:\d+}', [ImportController::class, 'commit']);
    $r->get('/done/{id:\d+}', [ImportController::class, 'finished']);

    $r->get('/history', [ImportController::class, 'history']);
    $r->get('/history/{id:\d+}', [ImportController::class, 'show']);

    $r->get('/issues', [ImportController::class, 'issues']);
    $r->get('/issues/{id:\d+}', [ImportController::class, 'issue']);
    // Entering a specific code by hand is its own permission everywhere in Chroma.
    $r->post('/issues/{id:\d+}/correct', [ImportController::class, 'correct'])->permission(Permissions::CODES_MANUAL_ENTRY);
    $r->post('/issues/{id:\d+}/merge', [ImportController::class, 'merge']);
    $r->post('/issues/{id:\d+}/discard', [ImportController::class, 'discard']);
    $r->post('/issues/{id:\d+}/reopen', [ImportController::class, 'reopen']);
});
