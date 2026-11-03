<?php

declare(strict_types=1);

/**
 * Uuims RPC registration (AppDiscovery hook).
 *
 * Auto service key: `uuims` (local) via apps/Uuims/rpc.php.
 * Cross-app auth bridge for Erp/Mall/Wms — do NOT import these handlers from other apps;
 * call via $rpc->call('uuims', 'Auth.check'|'Auth.can', …).
 *
 * Menus main service: Menus.register / Menus.unregister / Menus.providers / Menus.export.
 * Subs call Menus.register at boot (ServiceBoot); HTTP menus come from MenusService aggregator.
 *
 * @var \Loongs\Rpc\Server\HandlerRegistry $handlers
 * @var \Loongs\Container\Container $container
 */

use App\Uuims\Services\AuthRpcService;
use App\Uuims\Services\MenusService;
use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Service\Manifest;

$handlers->register('uuims', 'ping', static function (RpcRequest $request): array {
    return [
        'ok' => true,
        'app' => 'Uuims',
        'echo' => $request->payload,
        'service' => $request->service,
        'method' => $request->method,
    ];
});

$handlers->register('uuims', 'Auth.check', static function (RpcRequest $request) use ($container) {
    /** @var AuthRpcService $svc */
    $svc = $container->make(AuthRpcService::class);
    $payload = is_array($request->payload) ? $request->payload : [];

    return $svc->check($payload);
});

$handlers->register('uuims', 'Auth.can', static function (RpcRequest $request) use ($container) {
    /** @var AuthRpcService $svc */
    $svc = $container->make(AuthRpcService::class);
    $payload = is_array($request->payload) ? $request->payload : [];

    return $svc->can($payload);
});

// --- Menus main service (logical id = menus) ---
$handlers->register('uuims', 'Menus.register', static function (RpcRequest $request) use ($container): array {
    /** @var MenusService $svc */
    $svc = $container->make(MenusService::class);
    $payload = is_array($request->payload) ? $request->payload : [];

    return $svc->register($payload);
});

$handlers->register('uuims', 'Menus.unregister', static function (RpcRequest $request) use ($container): array {
    /** @var MenusService $svc */
    $svc = $container->make(MenusService::class);
    $payload = is_array($request->payload) ? $request->payload : [];

    return $svc->unregister($payload);
});

$handlers->register('uuims', 'Menus.providers', static function (RpcRequest $request) use ($container): array {
    /** @var MenusService $svc */
    $svc = $container->make(MenusService::class);

    return $svc->listProviders();
});

$handlers->register('uuims', 'Menus.export', static function (RpcRequest $request) use ($container): array {
    /** @var MenusService $svc */
    $svc = $container->make(MenusService::class);

    return $svc->exportOwn();
});
