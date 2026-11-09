<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\OAuth\Exception\OAuthException;
use Loongs\OAuth\Http\OAuthRequest;
use Loongs\OAuth\TokenInfo;

/**
 * Hot-path bearer auth for the **access** half of an access+refresh token pair.
 *
 * Uses loongs/oauth ResourceServer::local only (JWE decrypt/verify/claims). No access-token
 * store / DB lookup. Multi-issuer: prefer the token's allow-listed `iss`.
 * Refresh / family rotation stays on AuthorizationServer::token (OrmStorage).
 */
final class LocalBearer
{
    public function __construct(
        private readonly OAuthServers $servers,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @throws OAuthException
     */
    public function authenticate(OAuthRequest $request, array $scopes = []): TokenInfo
    {
        $token = self::extract($request);
        if ($token === null) {
            throw new OAuthException('invalid_token', 'Missing access token.', 401, [
                'WWW-Authenticate' => 'Bearer',
            ], reason: OAuthException::REASON_INVALID);
        }

        return $this->authenticateToken($token, $scopes);
    }

    /**
     * @param list<string> $scopes
     * @throws OAuthException
     */
    public function authenticateToken(string $token, array $scopes = []): TokenInfo
    {
        $origin = $this->servers->origin();
        $default = $this->servers->forIssuer($origin->defaultIssuer());
        $peek = $default->localResourceServer();
        $claims = $peek->decodeJwe($token);
        $iss = is_array($claims) && isset($claims['iss']) && is_string($claims['iss']) ? $claims['iss'] : null;
        $issuer = $iss !== null && $origin->isAllowed($iss) ? $iss : $origin->defaultIssuer();
        $rs = $this->servers->forIssuer($issuer)->localResourceServer();

        return $rs->authenticate(
            OAuthRequest::fromArrays('GET', ['Authorization' => 'Bearer ' . $token]),
            $scopes,
        );
    }

    public static function extract(OAuthRequest $request): ?string
    {
        $auth = $request->header('authorization');
        if ($auth !== null && preg_match('/^Bearer[ ]+(.*)$/i', trim($auth), $m) === 1) {
            $t = trim($m[1]);

            return $t !== '' ? $t : null;
        }

        return null;
    }
}
