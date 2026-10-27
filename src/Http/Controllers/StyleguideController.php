<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

final class StyleguideController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('styleguide/index', ['title' => 'Style guide']);
    }
}
