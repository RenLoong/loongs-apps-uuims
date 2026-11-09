<?php

declare(strict_types=1);

/**
 * Uuims RPC registration (AppDiscovery hook) — auth surface only.
 *
 * Auto service key: `uuims` (local) via apps/Uuims/rpc.php.
 * Cross-app auth bridge for Erp/Mall/Wms/Admin — do NOT import these handlers
 * from other apps; call via $rpc->call('uuims', 'Auth.check'|'Auth.can', …).
 *
 * Menus.* RPC (Menus.register / unregister / providers / export) moved to Admin.
 *
 * @var \Loongs\Rpc\Server\HandlerRegistry $handlers
 * @var \Loongs\Container\Container $container
 */

use App\Uuims\Services\AuthRpcService;
use Loongs\Rpc\Contract\RpcRequest;

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
