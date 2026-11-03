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
 * Builds the platform authorization server: opaque tokens, codes, families in user_platform
 * (OrmStorage); the public clients come straight from config/uuims.php (redirect URIs follow .env
 * without re-running uuims:install). Extension grants (WeChat mini program) are registered here.
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
        $as = new AuthorizationServer(new ServerConfig(
            issuer: $issuer ?? (string) $cfg['issuer'],
            scopes: (array) $cfg['scopes'],
            defaultScopes: (array) $cfg['default_scopes'],
            authCodeTtl: (int) $cfg['auth_code_ttl'],
            accessTokenTtl: (int) $cfg['access_token_ttl'],
            refreshTokenTtl: (int) $cfg['refresh_token_ttl'],
            tokenPepper: (string) $cfg['token_pepper'],
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
     * @param array<string, mixed> $cfg
     * @return list<Client>
     */
    public static function clients(array $cfg): array
    {
        $out = [];
        foreach ((array) $cfg['clients'] as $id => $c) {
            $out[] = Client::public((string) $id, (string) $c['name'], array_values((array) $c['redirect_uris']), array_values((array) $c['scopes']),
                true, array_values((array) ($c['extension_grants'] ?? [])));
        }

        return $out;
    }
}
