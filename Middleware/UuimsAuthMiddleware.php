<?php

declare(strict_types=1);

namespace App\Uuims\Middleware;

use App\Uuims\Models\Uuims;
use App\Uuims\Services\PermissionService;
use App\Uuims\Support\Api;
use App\Uuims\Support\LocalBearer;
use App\Uuims\Support\OAuthError;
use App\Uuims\Support\UuimsContext;
use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Middleware\MiddlewareInterface;
use Loongs\OAuth\Exception\OAuthException;
use Loongs\OAuth\Framework\Bridge;

/**
 * Bearer token via loongs/oauth local ResourceServer (JWE only, no DB on validate) → enabled uuims
 * → permission context (UuimsContext + request attribute "user_ctx").
 *
 * Token validation never hits the database. User/permission rows load only after local auth succeeds,
 * because HTTP handlers need RBAC context beyond JWT claims.
 *
 * 40103 expired (client may refresh); 40104 revoked / 40101 invalid (re-login); 40300 insufficient scope.
 */
final class UuimsAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LocalBearer $bearer,
        private readonly PermissionService $permissions,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $info = $this->bearer->authenticate(Bridge::request($request), ['uuims']);
        } catch (OAuthException $e) {
            return OAuthError::toApi($e);
        }

        // After local auth only: identity status + RBAC (not used to prove the token).
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
