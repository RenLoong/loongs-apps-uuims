<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\Config\Repository;
use Loongs\OAuth\AuthorizationServer;
use Loongs\OAuth\Entity\Client;
use Loongs\OAuth\Grant\ExtensionGrantHandler;
use Loongs\OAuth\ServerConfig;
use Loongs\OAuth\Storage\MemoryStorage;
use Loongs\OAuth\Storage\OrmStorage;
use Loongs\OAuth\Storage\Stores;

/**
 * Builds the platform authorization server (access+refresh **token pairs**).
 *
 * Pair contract (loongs/oauth families):
 * - **Issue**: authorization_code / extension grants mint access + refresh under one family id.
 * - **Refresh**: rotates the whole pair — new access + new refresh; old refresh is marked rotated;
 *   replaying a rotated refresh revokes the family (`invalid_grant`).
 * - **Validate access**: ResourceServer::local / LocalBearer — JWE decrypt/verify only, **no DB**.
 * - **Persist**: OrmStorage keeps codes, refresh tokens, families (and access rows for revoke /
 *   introspect). Access-token lookup is never on the Auth.check / middleware hot path.
 *
 * Public clients always allow `refresh_token`; rotation is always on for them. Confidential
 * rotation stays enabled via ServerConfig::$rotateConfidentialRefreshTokens.
 */
final class OAuth
{
    /**
     * @param array<string, mixed> $cfg config('uuims.oauth')
     * @param array<string, ExtensionGrantHandler> $grants extension grant URI → handler
     */
    public static function server(array $cfg, ?string $issuer = null, array $grants = []): AuthorizationServer
    {
        $orm = new OrmStorage((string) $cfg['connection']);
        $clients = new MemoryStorage();
        foreach (self::clients($cfg) as $client) {
            $clients->saveClient($client);
        }

        [$signing, $encryption] = OAuthKeys::fromConfig($cfg);
        $format = strtolower((string) ($cfg['access_token_format'] ?? ServerConfig::FORMAT_JWE));
        if ($format !== ServerConfig::FORMAT_OPAQUE) {
            $format = ServerConfig::FORMAT_JWE;
        }
        if ($format === ServerConfig::FORMAT_JWE && ($signing === null || $encryption === null || !$encryption->canEncrypt())) {
            throw new \RuntimeException(
                'JWE access tokens need signing + encryption keys. Run `./loongs uuims:install` '
                . '(or `./loongs oauth:keygen jwt --encryption-dir --out=runtime/oauth`) and set '
                . 'OAUTH_SIGNING_KEY_PATH / OAUTH_ENCRYPTION_KEY in .env.',
            );
        }

        $as = new AuthorizationServer(new ServerConfig(
            issuer: $issuer ?? (string) $cfg['issuer'],
            scopes: (array) $cfg['scopes'],
            defaultScopes: (array) $cfg['default_scopes'],
            authCodeTtl: (int) $cfg['auth_code_ttl'],
            accessTokenTtl: (int) $cfg['access_token_ttl'],
            refreshTokenTtl: (int) $cfg['refresh_token_ttl'],
            tokenPepper: (string) $cfg['token_pepper'],
            accessTokenFormat: $format,
            signingKey: $signing,
            encryptionKey: $encryption,
            // Token-pair policy: always rotate refresh on use (public clients already must;
            // keep confidential on the same pair-rotation contract).
            rotateConfidentialRefreshTokens: true,
        ), new Stores(clients: $clients, codes: $orm, accessTokens: $orm, refreshTokens: $orm, families: $orm, consents: $orm));
        foreach ($grants as $type => $handler) {
            $as->registerGrant($type, $handler);
        }

        return $as;
    }

    public static function fromConfig(Repository $config): AuthorizationServer
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) $config->get('uuims.oauth', []);

        return self::server($cfg);
    }

    /**
     * Public clients with refresh_token (pair issue + full-pair refresh rotation).
     *
     * @param array<string, mixed> $cfg
     * @return list<Client>
     */
    public static function clients(array $cfg): array
    {
        $out = [];
        foreach ((array) $cfg['clients'] as $id => $c) {
            // refreshTokens: true → Client::public includes authorization_code + refresh_token
            $out[] = Client::public(
                (string) $id,
                (string) $c['name'],
                array_values((array) $c['redirect_uris']),
                array_values((array) $c['scopes']),
                true,
                array_values((array) ($c['extension_grants'] ?? [])),
            );
        }

        return $out;
    }
}
