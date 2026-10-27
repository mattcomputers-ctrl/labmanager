<?php
/** @var \Chroma\Core\Router $router */
/** @var \Chroma\Core\App $app */

use Chroma\Http\Controllers\AccountController;
use Chroma\Http\Controllers\AuthController;
use Chroma\Http\Controllers\HelpController;
use Chroma\Http\Controllers\HomeController;
use Chroma\Http\Controllers\ModeController;
use Chroma\Http\Controllers\StyleguideController;
use Chroma\Http\Controllers\WizardController;

$router->get('/login', [AuthController::class, 'showLogin'])->public();
$router->post('/login', [AuthController::class, 'login'])->public();
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/mode', [ModeController::class, 'set'])->public();

$router->get('/', [HomeController::class, 'index']);
$router->get('/account', [AccountController::class, 'show']);
$router->post('/account', [AccountController::class, 'update']);
$router->get('/account/password', [AccountController::class, 'passwordForm']);
$router->post('/account/password', [AccountController::class, 'changePassword']);
$router->get('/styleguide', [StyleguideController::class, 'index']);
$router->get('/help', [HelpController::class, 'index']);
$router->get('/help/{slug}', [HelpController::class, 'page']);

$router->get('/wizard/{key}', [WizardController::class, 'start']);
$router->get('/wizard/{key}/step/{n:\d+}', [WizardController::class, 'step']);
$router->post('/wizard/{key}/step/{n:\d+}', [WizardController::class, 'submitStep']);
$router->get('/wizard/{key}/review', [WizardController::class, 'review']);
$router->post('/wizard/{key}/commit', [WizardController::class, 'commit']);
$router->get('/wizard/{key}/done', [WizardController::class, 'done']);
$router->post('/wizard/{key}/cancel', [WizardController::class, 'cancel']);
