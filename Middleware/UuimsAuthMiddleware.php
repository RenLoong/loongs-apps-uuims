<?php

declare(strict_types=1);

namespace App\Uuims\Middleware;

use App\Uuims\Models\Uuims;
use App\Uuims\Services\PermissionService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Middleware\MiddlewareInterface;
use Loongs\OAuth\AuthorizationServer;
use Loongs\OAuth\Exception\OAuthException;
use Loongs\OAuth\Framework\Bridge;

/**
 * Bearer token (loongs/oauth ResourceServer, scope "uuims") → enabled uuims → permission context
 * (UuimsContext + request attribute "user_ctx"). 401 → code 40101, insufficient scope → 40300.
 */
final class UuimsAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthorizationServer $oauth,
        private readonly PermissionService $permissions,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $info = $this->oauth->resourceServer()->authenticate(Bridge::request($request), ['uuims']);
        } catch (OAuthException $e) {
            $o = $e->toResponse();
            $res = $o->status === 403
                ? Api::fail(40300, '令牌权限范围不足', 403)
                : Api::fail($o->status === 400 ? 40001 : 40101, $o->status === 400 ? '请求无效' : '未登录或登录已失效', $o->status);
            if (($h = $o->header('WWW-Authenticate')) !== null) {
                $res->header('WWW-Authenticate', $h);
            }

            return $res;
        }
        $uuims = $info->userId === null ? null : Uuims::query()->withoutGlobalScope('data_scope')->find((int) $info->userId);
        if ($uuims === null || (int) $uuims->status !== 1) {
            return Api::fail(40102, '账号不存在或已停用', 401);
        }
        $ctx = $this->permissions->context($uuims);
        UuimsContext::set($ctx);
        $request->setAttribute('user_ctx', $ctx);
        $request->setAttribute('oauth', $info);

        return $next($request);
    }
}
