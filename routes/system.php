<?php

declare(strict_types=1);

use Loongs\Http\Request;
use Loongs\Http\Response;

/** @var \Loongs\Routing\Router $router */

// P0 smoke route for the Uuims app (real modules arrive in P1+).
$router->get('/uuims/api/ping', static function (Request $request): Response {
    return (new Response())->json(data: ['pong' => true, 'app' => 'Uuims', 'time' => date('c')]);
});
