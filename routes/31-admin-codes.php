<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Admin\PrefixesController;
use Chroma\Http\Controllers\Admin\SequencesController;
use Chroma\Http\Controllers\Admin\SuffixesController;
use Chroma\Http\Wizard\AddPrefixWizard;
use Chroma\Http\Wizard\WizardRegistry;

// Admin → Prefixes, Suffixes, Sequences (module A).

WizardRegistry::register('add-prefix', AddPrefixWizard::class);

$router->get('/admin/prefixes', [PrefixesController::class, 'index'])->permission(Permissions::ADMIN_PREFIXES);
$router->get('/admin/prefixes/export', [PrefixesController::class, 'export'])->permission(Permissions::ADMIN_PREFIXES);
$router->get('/admin/prefixes/new', [PrefixesController::class, 'create'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/prefixes', [PrefixesController::class, 'store'])->permission(Permissions::ADMIN_PREFIXES);
$router->get('/admin/prefixes/{id:\d+}', [PrefixesController::class, 'edit'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/prefixes/{id:\d+}', [PrefixesController::class, 'update'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/prefixes/{id:\d+}/next-number', [PrefixesController::class, 'setNextNumber'])->permission(Permissions::ADMIN_PREFIXES, Permissions::ADMIN_SEQUENCES);
$router->post('/admin/prefixes/{id:\d+}/retire', [PrefixesController::class, 'retire'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/prefixes/{id:\d+}/unretire', [PrefixesController::class, 'unretire'])->permission(Permissions::ADMIN_PREFIXES);

$router->get('/admin/suffixes', [SuffixesController::class, 'index'])->permission(Permissions::ADMIN_PREFIXES);
$router->get('/admin/suffixes/export', [SuffixesController::class, 'export'])->permission(Permissions::ADMIN_PREFIXES);
$router->get('/admin/suffixes/new', [SuffixesController::class, 'create'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/suffixes', [SuffixesController::class, 'store'])->permission(Permissions::ADMIN_PREFIXES);
$router->get('/admin/suffixes/{id:\d+}', [SuffixesController::class, 'edit'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/suffixes/{id:\d+}', [SuffixesController::class, 'update'])->permission(Permissions::ADMIN_PREFIXES);
$router->post('/admin/suffixes/{id:\d+}/active', [SuffixesController::class, 'setActive'])->permission(Permissions::ADMIN_PREFIXES);

$router->get('/admin/sequences', [SequencesController::class, 'index'])->permission(Permissions::ADMIN_SEQUENCES);
$router->post('/admin/sequences/internal', [SequencesController::class, 'setInternal'])->permission(Permissions::ADMIN_SEQUENCES);
$router->post('/admin/sequences/project', [SequencesController::class, 'setProject'])->permission(Permissions::ADMIN_SEQUENCES);
