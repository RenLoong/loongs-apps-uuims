<?php

declare(strict_types=1);

use App\Uuims\Modules\Render\Controller\PageController;

/** @var \Loongs\Routing\Router $router */

// Server-rendered page descriptions (loongs/render, RENDER.md §2): one endpoint for PC and uni-app.
$router->get('/uuims/api/pages/{page}', [PageController::class, 'show']);
