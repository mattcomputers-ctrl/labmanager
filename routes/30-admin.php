<?php
/** @var \Chroma\Core\Router $router */
use Chroma\Core\Permissions;
use Chroma\Http\Controllers\Admin\AdminController;

$router->get('/admin', [AdminController::class, 'index'])->anyPermission(...Permissions::adminKeys());
