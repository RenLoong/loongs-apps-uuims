<?php

declare(strict_types=1);

use App\Uuims\Middleware\PermissionMiddleware as P;
use App\Uuims\Modules\Sample\Controller\SampleTenantController as T;

/** @var \Loongs\Routing\Router $router */

// SAMPLE tenant API (fixtures, nothing persisted) for the 租户详情 / 租户列表（样例） pages. P2 replaces it.
$api = '/uuims/api/samples/tenants';
$need = [P::need('dashboard:overview')];
$router->get($api, [T::class, 'index'], $need);   // P5: 租户列表（样例）
$router->get("$api/plan-logs", [T::class, 'planLogs'], $need);
$router->get("$api/{id}", [T::class, 'show'], $need);
$router->get("$api/{id}/usage-summary", [T::class, 'usageSummary'], $need);
$router->get("$api/{id}/usage", [T::class, 'usage'], $need);
$router->get("$api/{id}/features", [T::class, 'features'], $need);
$router->get("$api/{id}/logs", [T::class, 'logs'], $need);
$router->post("$api/{id}/renew", [T::class, 'renew'], $need);
