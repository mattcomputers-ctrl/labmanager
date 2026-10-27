<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\ReportsController;

// Reports are read-only pages; exporting a report counts as exporting a list (codes.export).
$router->get('/reports', [ReportsController::class, 'index'])->permission(Permissions::REPORTS_VIEW);
$router->get('/reports/{name:[a-z]+}/export', [ReportsController::class, 'export'])->permission(Permissions::REPORTS_VIEW, Permissions::CODES_EXPORT);
$router->get('/reports/{name:[a-z]+}', [ReportsController::class, 'show'])->permission(Permissions::REPORTS_VIEW);
