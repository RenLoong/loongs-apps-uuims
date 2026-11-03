<?php

declare(strict_types=1);

use App\Uuims\Middleware\PermissionMiddleware as P;
use App\Uuims\Modules\Message\Controller\MessageController as M;

/** @var \Loongs\Routing\Router $router */

// P5 站内消息. Inbox endpoints only need login: every uuims reads / marks only their OWN messages
// (mobile tab 消息 for everyone). Sending is uuims-only: permission message:send (loongs.json).
$api = '/uuims/api/messages';
$router->get($api, [M::class, 'index']);
$router->get("$api/unread-count", [M::class, 'unreadCount']);
$router->put("$api/read-all", [M::class, 'readAll']);
$router->get("$api/recipients", [M::class, 'recipients'], [P::need('message:send')]);
$router->post("$api/send", [M::class, 'send'], [P::need('message:send')]);
$router->get("$api/{id}", [M::class, 'show']);
$router->put("$api/{id}/read", [M::class, 'read']);
