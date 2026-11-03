<?php

declare(strict_types=1);

use App\Uuims\Middleware\PermissionMiddleware as P;
use App\Uuims\Modules\System\Controller\UuimsController;
use App\Uuims\Modules\System\Controller\DeptController;
use App\Uuims\Modules\System\Controller\FieldController;
use App\Uuims\Modules\System\Controller\MenuController;
use App\Uuims\Modules\System\Controller\RoleController;
use App\Uuims\Modules\Auth\Controller\WechatController;

/** @var \Loongs\Routing\Router $router */

$api = '/uuims/api/system';

// Users (data scope + field permissions apply)
$router->get("$api/users", [UuimsController::class, 'index'], [P::need('system:uuims:list')]);
$router->post("$api/users", [UuimsController::class, 'store'], [P::need('system:uuims:create')]);
$router->get("$api/users/{id}", [UuimsController::class, 'show'], [P::need('system:uuims:list')]);
$router->put("$api/users/{id}", [UuimsController::class, 'update'], [P::need('system:uuims:update')]);
$router->delete("$api/users/{id}", [UuimsController::class, 'destroy'], [P::need('system:uuims:delete')]);
$router->put("$api/users/{id}/password", [UuimsController::class, 'resetPassword'], [P::need('system:uuims:reset-password')]);
$router->delete("$api/users/{id}/wechat", [WechatController::class, 'unbindUuims'], [P::need('system:uuims:unbind-wechat')]);

// Roles (permission codes, data scope, field permissions)
$router->get("$api/roles", [RoleController::class, 'index'], [P::need('system:role:list')]);
$router->get("$api/roles/options", [RoleController::class, 'options'], [P::need('system:role:list', 'system:uuims:create', 'system:uuims:update')]);
$router->post("$api/roles", [RoleController::class, 'store'], [P::need('system:role:create')]);
$router->get("$api/roles/{id}", [RoleController::class, 'show'], [P::need('system:role:list')]);
$router->put("$api/roles/{id}", [RoleController::class, 'update'], [P::need('system:role:update')]);
$router->delete("$api/roles/{id}", [RoleController::class, 'destroy'], [P::need('system:role:delete')]);

// Menus / buttons: read-only merged tree of apps/*/loongs.json (edited in the files, not here)
$router->get("$api/menus", [MenuController::class, 'index'], [P::need('system:menu:list', 'system:role:list')]);
$router->get("$api/menus/sources", [MenuController::class, 'sources'], [P::need('system:menu:list')]);

// Departments
$router->get("$api/depts", [DeptController::class, 'index'], [P::need('system:dept:list', 'system:uuims:list', 'system:role:list')]);
$router->get("$api/depts/{id}", [DeptController::class, 'show'], [P::need('system:dept:list')]);
$router->post("$api/depts", [DeptController::class, 'store'], [P::need('system:dept:create')]);
$router->put("$api/depts/{id}", [DeptController::class, 'update'], [P::need('system:dept:update')]);
$router->delete("$api/depts/{id}", [DeptController::class, 'destroy'], [P::need('system:dept:delete')]);

// Controllable fields (for the role editor)
$router->get("$api/fields", [FieldController::class, 'index'], [P::need('system:role:list')]);
