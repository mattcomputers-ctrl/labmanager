<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Codes\InternalCodeController;
use Chroma\Http\Controllers\Codes\LogsController;
use Chroma\Http\Controllers\Codes\LookupController;
use Chroma\Http\Controllers\Codes\ManualEntryController;
use Chroma\Http\Controllers\Codes\QuickAssignController;
use Chroma\Http\Controllers\Codes\SellNumberController;
use Chroma\Http\Wizard\LinkCodesWizard;
use Chroma\Http\Wizard\ManualEntryWizard;
use Chroma\Http\Wizard\QuickAssignWizard;
use Chroma\Http\Wizard\VoidCodeWizard;
use Chroma\Http\Wizard\WizardRegistry;

// Code lookup (the cross-reference replacement) and the per-prefix logs.
$router->get('/codes', [LookupController::class, 'index'])->permission(Permissions::CODES_VIEW);
$router->get('/codes/export', [LookupController::class, 'export'])->permission(Permissions::CODES_VIEW, Permissions::CODES_EXPORT);
$router->get('/codes/logs', [LogsController::class, 'index'])->permission(Permissions::CODES_VIEW);
$router->get('/codes/logs/{prefix:[A-Za-z]+}', [LogsController::class, 'show'])->permission(Permissions::CODES_VIEW);
$router->get('/codes/logs/{prefix:[A-Za-z]+}/export', [LogsController::class, 'export'])->permission(Permissions::CODES_VIEW, Permissions::CODES_EXPORT);

// Quick assign (Expert page; Easy mode is /wizard/quick-assign). Either assign permission opens the page;
// the operation itself checks the one that matches what is being assigned.
$router->get('/assign', [QuickAssignController::class, 'form']);
$router->post('/assign', [QuickAssignController::class, 'assign']);

// Manual entry of a specific code (legacy items, odd cases).
$router->get('/codes/manual', [ManualEntryController::class, 'form'])->permission(Permissions::CODES_MANUAL_ENTRY);
$router->post('/codes/manual', [ManualEntryController::class, 'enter'])->permission(Permissions::CODES_MANUAL_ENTRY);

// E number pages
$router->get('/codes/e/{id:\d+}', [InternalCodeController::class, 'show'])->permission(Permissions::CODES_VIEW);
$router->post('/codes/e/{id:\d+}/notes', [InternalCodeController::class, 'notes'])->permission(Permissions::CODES_VIEW);
$router->post('/codes/e/{id:\d+}/description', [InternalCodeController::class, 'description'])->permission(Permissions::CODES_VIEW);
$router->post('/codes/e/{id:\d+}/void', [InternalCodeController::class, 'void'])->permission(Permissions::CODES_VOID);

// Sell number pages
$router->get('/codes/s/{id:\d+}', [SellNumberController::class, 'show'])->permission(Permissions::CODES_VIEW);
$router->post('/codes/s/{id:\d+}/notes', [SellNumberController::class, 'notes'])->permission(Permissions::CODES_VIEW);
$router->post('/codes/s/{id:\d+}/description', [SellNumberController::class, 'description'])->permission(Permissions::CODES_VIEW);
$router->post('/codes/s/{id:\d+}/void', [SellNumberController::class, 'void'])->permission(Permissions::CODES_VOID);
$router->post('/codes/s/{id:\d+}/link', [SellNumberController::class, 'link'])->permission(Permissions::CODES_LINK);

// Easy-mode wizards (permissions are declared on each wizard).
WizardRegistry::register('quick-assign', QuickAssignWizard::class);
WizardRegistry::register('void-code', VoidCodeWizard::class);
WizardRegistry::register('link-codes', LinkCodesWizard::class);
WizardRegistry::register('manual-entry', ManualEntryWizard::class);
