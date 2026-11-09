<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\Http\Response;
use Loongs\OAuth\Exception\OAuthException;
use Loongs\OAuth\Http\OAuthResponse;
use Loongs\Rpc\Contract\RpcResponse;

/**
 * Map loongs/oauth OAuthException to Uuims API / RPC envelopes.
 * Expired (40103) → client may refresh; revoked/invalid (40104/40101) → re-login.
 */
final class OAuthError
{
    public const int CODE_EXPIRED = 40103;
    public const int CODE_REVOKED = 40104;
    public const int CODE_INVALID = 40101;
    public const int CODE_BAD_REQUEST = 40001;
    public const int CODE_INSUFFICIENT_SCOPE = 40300;

    /** @return array{0: int, 1: string, 2: int, 3: array<string, mixed>} code, message, http, data */
    public static function parts(OAuthException $e): array
    {
        $o = $e->toResponse();
        if ($o->status === 403 || $e->error === 'insufficient_scope') {
            return [self::CODE_INSUFFICIENT_SCOPE, '令牌权限范围不足', 403, self::payload($e, $o)];
        }
        if ($o->status === 400 || $e->error === 'invalid_request') {
            return [self::CODE_BAD_REQUEST, '请求无效', 400, self::payload($e, $o)];
        }
        if ($e->isExpired()) {
            return [self::CODE_EXPIRED, '访问令牌已过期', 401, self::payload($e, $o)];
        }
        if ($e->isRevoked()) {
            return [self::CODE_REVOKED, '访问令牌已撤销', 401, self::payload($e, $o)];
        }

        return [self::CODE_INVALID, '未登录或登录已失效', 401, self::payload($e, $o)];
    }

    public static function toApi(OAuthException $e): Response
    {
        [$code, $message, $http, $data] = self::parts($e);
        $res = Api::fail($code, $message, $http, $data);
        $o = $e->toResponse();
        if (($h = $o->header('WWW-Authenticate')) !== null) {
            $res->header('WWW-Authenticate', $h);
        }

        return $res;
    }

    public static function toRpc(OAuthException $e): RpcResponse
    {
        [$code, $message, , $data] = self::parts($e);

        return RpcResponse::fail($code, $message, $data);
    }

    /** @return array<string, mixed> */
    private static function payload(OAuthException $e, OAuthResponse $o): array
    {
        $data = $e->toArray();
        $data['http_status'] = $o->status;

        return $data;
    }
}
