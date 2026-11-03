<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use App\Uuims\Models\Uuims;
use Loongs\OAuth\AuthorizationServer;
use Loongs\OAuth\Exception\OAuthException;
use Loongs\OAuth\Http\OAuthRequest;
use Loongs\Rpc\Contract\RpcResponse;

/**
 * RPC-facing auth helpers for other apps (Erp/Mall/Wms) via $rpc->call('uuims', …).
 * Reuses the same ResourceServer + PermissionService path as UuimsAuthMiddleware.
 */
final class AuthRpcService
{
    public function __construct(
        private readonly AuthorizationServer $oauth,
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
            return RpcResponse::fail(40101, '未登录或登录已失效');
        }

        try {
            $info = $this->oauth->resourceServer()->authenticate(
                OAuthRequest::fromArrays('GET', ['Authorization' => 'Bearer ' . $token]),
                ['uuims'],
            );
        } catch (OAuthException $e) {
            $o = $e->toResponse();
            if ($o->status === 403) {
                return RpcResponse::fail(40300, '令牌权限范围不足');
            }

            return RpcResponse::fail(
                $o->status === 400 ? 40001 : 40101,
                $o->status === 400 ? '请求无效' : '未登录或登录已失效',
            );
        }

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
