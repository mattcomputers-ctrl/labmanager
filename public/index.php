<?php
declare(strict_types=1);

use Chroma\Core\App;
use Chroma\Core\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::boot(dirname(__DIR__));
$app->loadRoutes();
$app->handle(Request::fromGlobals())->send();
