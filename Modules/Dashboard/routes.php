<?php

declare(strict_types=1);

use App\Uuims\Middleware\PermissionMiddleware as P;
use App\Uuims\Modules\Dashboard\Controller\OverviewController as O;

/** @var \Loongs\Routing\Router $router */

// 平台概览 (page uuims.dashboard.overview): every block loads its own data (RENDER.md §6.1).
$api = '/uuims/api/dashboard/overview';
$need = [P::need('dashboard:overview')];
$router->get("$api/stats", [O::class, 'stats'], $need);
$router->get("$api/uuims-trend", [O::class, 'uuimsTrend'], $need);
$router->get("$api/uuims-status", [O::class, 'uuimsStatus'], $need);
$router->get("$api/dept-users", [O::class, 'deptUsers'], $need);

// SAMPLE series (tenant module arrives in P2): fixed data from App\Uuims\Samples\SampleTenants.
$s = '/uuims/api/samples/overview';
$router->get("$s/tenant-opens", [O::class, 'sampleTenantOpens'], $need);
$router->get("$s/health", [O::class, 'sampleHealth'], $need);
$router->get("$s/tenant-profile", [O::class, 'sampleTenantProfile'], $need);
$router->get("$s/tenant-scale", [O::class, 'sampleTenantScale'], $need);
