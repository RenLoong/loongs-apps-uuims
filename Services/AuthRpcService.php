<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use App\Uuims\Models\Uuims;
use App\Uuims\Support\LocalBearer;
use App\Uuims\Support\OAuthError;
use Loongs\OAuth\Exception\OAuthException;
use Loongs\Rpc\Contract\RpcResponse;

/**
 * RPC-facing auth helpers for other apps (Erp/Mall/Wms/Admin) via $rpc->call('uuims', …).
 *
 * Auth.check validates the **access** half of the access+refresh pair with loongs/oauth local
 * ResourceServer only (no DB on the token hot path). Refresh rotates the whole pair at
 * POST /oauth/token (grant_type=refresh_token). User/permission projection loads after local auth.
 */
final class AuthRpcService
{
    public function __construct(
        private readonly LocalBearer $bearer,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Authenticate a bearer token (scope uuims) and return a safe user projection.
     *
     * @param array<string, mixed> $payload token|authorization
     */
    public function check(array $payload): RpcResponse
    {
        $token = $this->extractToken($payload);
        if ($token === null) {
            return RpcResponse::fail(OAuthError::CODE_INVALID, '未登录或登录已失效', [
                'error' => 'invalid_token',
                'reason' => 'invalid',
            ]);
        }

        try {
            $info = $this->bearer->authenticateToken($token, ['uuims']);
        } catch (OAuthException $e) {
            return OAuthError::toRpc($e);
        }

        // After local auth only — projection for RPC consumers (not token proof).
        $uuims = $info->userId === null
            ? null
            : Uuims::query()->withoutGlobalScope('data_scope')->find((int) $info->userId);
        if ($uuims === null || (int) $uuims->status !== 1) {
            return RpcResponse::fail(40102, '账号不存在或已停用');
        }

        $ctx = $this->permissions->context($uuims);

        return RpcResponse::ok($this->project($ctx));
    }

    /**
     * Any-of permission check (same semantics as PermissionMiddleware::need).
     *
     * Payload: codes[] required; plus either token/authorization (Auth.check path)
     * or user_id (load enabled uuims + PermissionService::context).
     *
     * @param array<string, mixed> $payload
     */
    public function can(array $payload): RpcResponse
    {
        $codes = $payload['codes'] ?? $payload['perms'] ?? [];
        if (!is_array($codes) || $codes === []) {
            return RpcResponse::fail(40001, '请求无效', ['required' => ['codes']]);
        }
        /** @var list<string> $codes */
        $codes = array_values(array_filter(array_map('strval', $codes), static fn (string $c): bool => $c !== ''));
        if ($codes === []) {
            return RpcResponse::fail(40001, '请求无效', ['required' => ['codes']]);
        }

        $hasToken = $this->extractToken($payload) !== null
            || (isset($payload['authorization']) && is_string($payload['authorization']) && $payload['authorization'] !== '');
        if ($hasToken) {
            $checked = $this->check($payload);
            if (!$checked->isOk()) {
                return $checked;
            }
            /** @var array<string, mixed> $proj */
            $proj = is_array($checked->data) ? $checked->data : [];
        } else {
            $userId = (int) ($payload['user_id'] ?? $payload['id'] ?? 0);
            if ($userId <= 0) {
                return RpcResponse::fail(40001, '请求无效', ['required' => ['token|user_id', 'codes']]);
            }
            $uuims = Uuims::query()->withoutGlobalScope('data_scope')->find($userId);
            if ($uuims === null || (int) $uuims->status !== 1) {
                return RpcResponse::fail(40102, '账号不存在或已停用');
            }
            $proj = $this->project($this->permissions->context($uuims));
        }

        $isSuper = (bool) ($proj['is_super'] ?? false);
        /** @var list<string> $perms */
        $perms = is_array($proj['perms'] ?? null) ? array_map('strval', $proj['perms']) : [];
        if ($isSuper) {
            return RpcResponse::ok(['ok' => true, 'allowed' => true, 'matched' => $codes[0]]);
        }
        foreach ($codes as $code) {
            if (in_array($code, $perms, true)) {
                return RpcResponse::ok(['ok' => true, 'allowed' => true, 'matched' => $code]);
            }
        }

        return RpcResponse::fail(40301, '无权限：' . implode(' / ', $codes), ['required' => $codes, 'ok' => false, 'allowed' => false]);
    }

    /**
     * @param array<string, mixed> $ctx PermissionService::context()
     * @return array{id: int, username: string, is_super: bool, perms: list<string>, roles: list<string>}
     */
    private function project(array $ctx): array
    {
        $roles = [];
        if (isset($ctx['roles']) && is_array($ctx['roles'])) {
            foreach ($ctx['roles'] as $r) {
                if (is_array($r) && isset($r['code'])) {
                    $roles[] = (string) $r['code'];
                } elseif (is_string($r)) {
                    $roles[] = $r;
                }
            }
        }

        /** @var list<string> $perms */
        $perms = is_array($ctx['perms'] ?? null) ? array_values(array_map('strval', $ctx['perms'])) : [];

        return [
            'id' => (int) ($ctx['id'] ?? 0),
            'username' => (string) ($ctx['username'] ?? ''),
            'is_super' => (bool) ($ctx['is_super'] ?? false),
            'perms' => $perms,
            'roles' => $roles,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function extractToken(array $payload): ?string
    {
        if (isset($payload['token']) && is_string($payload['token'])) {
            $t = trim($payload['token']);

            return $t !== '' ? $t : null;
        }
        if (isset($payload['authorization']) && is_string($payload['authorization'])) {
            $auth = trim($payload['authorization']);
            if (preg_match('/^Bearer[ ]+(.+)$/i', $auth, $m)) {
                $t = trim($m[1]);

                return $t !== '' ? $t : null;
            }
        }

        return null;
    }
}
