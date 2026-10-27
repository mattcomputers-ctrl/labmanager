<?php
/** @var \Chroma\Core\Router $router */
/** Admin: System, Audit log, Settings, Network, CMS connection (module E2). */

use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Admin\AuditController;
use Chroma\Http\Controllers\Admin\CmsController;
use Chroma\Http\Controllers\Admin\NetworkController;
use Chroma\Http\Controllers\Admin\NetworkWizard;
use Chroma\Http\Controllers\Admin\SettingsController;
use Chroma\Http\Controllers\Admin\SystemController;
use Chroma\Http\Wizard\WizardRegistry;

$router->group('/admin/system', [Permissions::ADMIN_SYSTEM], function ($r) {
    $r->get('', [SystemController::class, 'index']);
    $r->post('/check-update', [SystemController::class, 'checkUpdate']);
    $r->post('/update', [SystemController::class, 'update']);
    $r->get('/update/log', [SystemController::class, 'updateLog']);
    $r->post('/backup', [SystemController::class, 'backup']);
    $r->get('/backups/{name}', [SystemController::class, 'downloadBackup']);
    $r->get('/log', [SystemController::class, 'log']);
    $r->post('/tokens', [SystemController::class, 'createToken']);
    $r->post('/tokens/{id:\d+}/revoke', [SystemController::class, 'revokeToken']);
});

$router->get('/admin/audit', [AuditController::class, 'index'])->permission(Permissions::ADMIN_SYSTEM);

$router->get('/admin/settings', [SettingsController::class, 'index'])->permission(Permissions::ADMIN_SYSTEM);
$router->post('/admin/settings', [SettingsController::class, 'save'])->permission(Permissions::ADMIN_SYSTEM);

$router->get('/admin/network', [NetworkController::class, 'index'])->permission(Permissions::ADMIN_NETWORK);
$router->post('/admin/network', [NetworkController::class, 'apply'])->permission(Permissions::ADMIN_NETWORK);
WizardRegistry::register('network-settings', NetworkWizard::class);

$router->get('/admin/cms', [CmsController::class, 'index'])->permission(Permissions::ADMIN_CMS);
$router->post('/admin/cms', [CmsController::class, 'save'])->permission(Permissions::ADMIN_CMS);
$router->post('/admin/cms/test', [CmsController::class, 'test'])->permission(Permissions::ADMIN_CMS);
$router->post('/admin/cms/sync', [CmsController::class, 'sync'])->permission(Permissions::ADMIN_CMS);
