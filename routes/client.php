<?php

declare(strict_types=1);

use App\Uuims\Support\ClientEntry;
use Loongs\Http\Request;
use Loongs\Http\Response;

/** @var \Loongs\Routing\Router $router */

$send = static fn (Request $request): Response => ClientEntry::redirect($request);

$router->get('/', $send);
$router->get('/uuims', $send);

$router->get('/uuims/web', static fn (): Response => ClientEntry::page('web'));
$router->get('/uuims/h5', static fn (): Response => ClientEntry::page('h5'));

// Old bookmarks / direct static hit: do not serve uninjected shell.
$router->get('/uuims/web/index.html', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/web/'));
$router->get('/uuims/h5/index.html', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/h5/'));

// Temporary compatibility redirects (remove after one release)
$router->get('/uuims', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims'));
$router->get('/uuims/web', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/web/'));
$router->get('/uuims/h5', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/h5/'));
$router->get('/uuims/web/', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/web/'));
$router->get('/uuims/h5/', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/h5/'));
$router->get('/uuims/web/index.html', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/web/'));
$router->get('/uuims/h5/index.html', static fn (): Response => (new Response())->status(302)->header('Location', '/uuims/h5/'));
