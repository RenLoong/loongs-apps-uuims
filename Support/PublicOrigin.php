<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\Http\Request;

/**
 * Public origin of the uuims API per request — for intranet deployments reachable under several
 * names (LAN IP, internal hostname, reverse proxy) where one fixed OAUTH_ISSUER does not fit.
 *
 * - The OAuth issuer (metadata `issuer`, the `iss` authorization response parameter) is the request's
 *   origin when that origin is allow-listed (OAUTH_ISSUER itself + OAUTH_ALLOWED_ORIGINS; "*" matches one
 *   label / octet, e.g. http://192.168.1.*:21000, http://*.corp.lan:21000); otherwise OAUTH_ISSUER.
 *   Arbitrary Host headers are never reflected.
 * - X-Forwarded-Proto / -Host / -Port are honoured only from TRUSTED_PROXIES (IPs / CIDRs).
 */
final class PublicOrigin
{
    /**
     * @param list<string> $allowed origins or patterns (scheme://host[:port])
     * @param list<string> $trustedProxies IPv4 / IPv6 addresses or CIDRs
     */
    public function __construct(
        private readonly string $issuer,
        private readonly array $allowed = [],
        private readonly array $trustedProxies = [],
    ) {
    }

    /** @param array<string, mixed> $cfg config('uuims.oauth') */
    public static function fromConfig(array $cfg): self
    {
        return new self((string) $cfg['issuer'], array_values((array) ($cfg['allowed_origins'] ?? [])), array_values((array) ($cfg['trusted_proxies'] ?? [])));
    }

    public function defaultIssuer(): string
    {
        return $this->issuer;
    }

    /** Issuer to use for this request (an allow-listed request origin, else the configured issuer). */
    public function issuerFor(Request $request): string
    {
        $origin = $this->requestOrigin($request);

        return $origin !== null && $this->isAllowed($origin) ? $origin : $this->issuer;
    }

    public function isAllowed(string $origin): bool
    {
        $origin = self::normalize($origin) ?? '';
        if ($origin === '') {
            return false;
        }
        if ($origin === self::normalize($this->issuer)) {
            return true;
        }
        foreach ($this->allowed as $pattern) {
            $p = self::normalize($pattern, true);
            if ($p !== null && ($p === $origin || (str_contains($p, '*') && preg_match(self::patternRegex($p), $origin) === 1))) {
                return true;
            }
        }

        return false;
    }

    /** "*" matches exactly one DNS label / IPv4 octet / port, never dots or colons. */
    private static function patternRegex(string $pattern): string
    {
        return '#^' . str_replace('\\*', '[^.:/\\[\\]]+', preg_quote($pattern, '#')) . '$#i';
    }

    /** scheme://host[:port] as seen by the client (forwarded headers from trusted proxies only). */
    public function requestOrigin(Request $request): ?string
    {
        $server = $request->swoole()->server ?? [];
        $remote = (string) ($server['remote_addr'] ?? '');
        $scheme = 'http';
        $host = (string) ($request->header('host') ?? '');
        if ($remote !== '' && $this->trusted($remote)) {
            $proto = strtolower(trim(explode(',', (string) ($request->header('x-forwarded-proto') ?? ''))[0]));
            if (in_array($proto, ['http', 'https'], true)) {
                $scheme = $proto;
            }
            $fh = trim(explode(',', (string) ($request->header('x-forwarded-host') ?? ''))[0]);
            if ($fh !== '') {
                $host = $fh;
            }
            $fp = trim(explode(',', (string) ($request->header('x-forwarded-port') ?? ''))[0]);
            if ($fp !== '' && ctype_digit($fp) && !preg_match('/:\d+$/', $host)) {
                $host .= ':' . $fp;
            }
        }
        if ($host === '' || preg_match('/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.\-]+)(:\d{1,5})?$/', $host) !== 1) {
            return null;
        }

        return self::normalize($scheme . '://' . $host);
    }

    public function trusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase scheme + host, default port dropped, no path. */
    public static function normalize(string $origin, bool $pattern = false): ?string
    {
        $origin = rtrim(trim($origin), '/');
        $re = $pattern ? '#^(https?)://(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.\-*]+)(?::(\d{1,5}|\*))?$#i' : '#^(https?)://(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.\-]+)(?::(\d{1,5}))?$#i';
        if (preg_match($re, $origin, $m) !== 1) {
            return null;
        }
        $scheme = strtolower($m[1]);
        $host = strtolower($m[2]);
        $port = $m[3] ?? '';
        if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
            $port = '';
        }

        return $scheme . '://' . $host . ($port !== '' ? ':' . $port : '');
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton(trim($net));
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
