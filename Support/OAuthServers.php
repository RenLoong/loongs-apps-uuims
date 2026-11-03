<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Closure;
use Loongs\Http\Request;
use Loongs\OAuth\AuthorizationServer;

/**
 * One AuthorizationServer per public issuer (see PublicOrigin), created lazily and kept per worker.
 * All of them share the same stores and clients; only `issuer` (metadata, `iss`) differs.
 */
final class OAuthServers
{
    private const MAX = 16;

    /** @var array<string, AuthorizationServer> */
    private array $servers = [];

    /** @param Closure(string $issuer): AuthorizationServer $factory */
    public function __construct(
        private readonly PublicOrigin $origin,
        private readonly Closure $factory,
        AuthorizationServer $default,
    ) {
        $this->servers[$origin->defaultIssuer()] = $default;
    }

    public function forRequest(Request $request): AuthorizationServer
    {
        return $this->forIssuer($this->origin->issuerFor($request));
    }

    public function forIssuer(string $issuer): AuthorizationServer
    {
        if (!isset($this->servers[$issuer])) {
            if (count($this->servers) >= self::MAX) {
                $issuer = $this->origin->defaultIssuer();   // allow-list patterns are bounded; stay safe anyway
            } else {
                $this->servers[$issuer] = ($this->factory)($issuer);
            }
        }

        return $this->servers[$issuer];
    }

    public function origin(): PublicOrigin
    {
        return $this->origin;
    }
}
