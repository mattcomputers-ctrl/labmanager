<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Api\CodeApiController;
use Chroma\Http\Controllers\Api\LookupApiController;

// JSON endpoints used by Expert-mode pages and bin/stress-assign (session + CSRF header).
$router->post('/api/codes/assign-sell', [CodeApiController::class, 'assignSell'])->permission(Permissions::CODES_ASSIGN_SELL);
$router->post('/api/codes/assign-internal', [CodeApiController::class, 'assignInternal'])->permission(Permissions::CODES_ASSIGN_INTERNAL);
$router->get('/api/codes/preview', [CodeApiController::class, 'preview'])->permission(Permissions::CODES_VIEW);
$router->get('/api/prefixes', [CodeApiController::class, 'prefixes'])->permission(Permissions::CODES_VIEW);

// Typeahead sources
// Type-ahead lists: open to anyone who can fill in a screen that uses them (projects, quick assign, manual entry, admin lists and users).
$router->get('/api/customers', [LookupApiController::class, 'customers'])->anyPermission(Permissions::PROJECTS_CREATE, Permissions::PROJECTS_EDIT, Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL, Permissions::CODES_MANUAL_ENTRY, Permissions::ADMIN_LISTS);
$router->get('/api/users', [LookupApiController::class, 'users'])->anyPermission(Permissions::PROJECTS_CREATE, Permissions::PROJECTS_EDIT, Permissions::ADMIN_USERS);
$router->get('/api/codes/search', [LookupApiController::class, 'codes'])->permission(Permissions::CODES_VIEW);
