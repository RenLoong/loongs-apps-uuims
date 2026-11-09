<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\OAuth\Jwt\EncryptionKey;
use Loongs\OAuth\Jwt\SigningKey;
use Loongs\OAuth\Support\Crypto;
use Loongs\Support\BasePath;

/**
 * Resolve JWE signing / encryption keys from uuims.oauth config (paths or inline material).
 * Secrets stay in .env / runtime files; never commit PEMs or dir secrets.
 */
final class OAuthKeys
{
    /**
     * @param array<string, mixed> $cfg config('uuims.oauth')
     * @return array{0: ?SigningKey, 1: ?EncryptionKey}
     */
    public static function fromConfig(array $cfg): array
    {
        $alg = (string) ($cfg['signing_alg'] ?? 'ES256');
        $signing = self::signing($cfg, $alg);
        $encryption = self::encryption($cfg);

        return [$signing, $encryption];
    }

    /** @param array<string, mixed> $cfg */
    private static function signing(array $cfg, string $alg): ?SigningKey
    {
        $pem = trim((string) ($cfg['signing_key_pem'] ?? ''));
        if ($pem !== '') {
            return SigningKey::fromPem($pem, $alg);
        }
        $path = trim((string) ($cfg['signing_key_path'] ?? ''));
        if ($path === '') {
            return null;
        }
        $file = self::resolvePath($path);
        if (!is_file($file)) {
            return null;
        }
        $body = (string) file_get_contents($file);

        return $body !== '' ? SigningKey::fromPem($body, $alg) : null;
    }

    /** @param array<string, mixed> $cfg */
    private static function encryption(array $cfg): ?EncryptionKey
    {
        $b64 = trim((string) ($cfg['encryption_key'] ?? ''));
        if ($b64 !== '') {
            $raw = Crypto::base64UrlDecode($b64);
            if ($raw !== null && strlen($raw) === 32) {
                return EncryptionKey::direct($raw);
            }
            throw new \InvalidArgumentException('uuims.oauth.encryption_key must be base64url of exactly 32 bytes (dir CEK).');
        }
        $path = trim((string) ($cfg['encryption_key_path'] ?? ''));
        if ($path === '') {
            return null;
        }
        $file = self::resolvePath($path);
        if (!is_file($file)) {
            return null;
        }
        $body = (string) file_get_contents($file);
        if ($body === '') {
            return null;
        }
        if (strlen($body) === 32) {
            return EncryptionKey::direct($body);
        }
        if (str_contains($body, 'BEGIN')) {
            return EncryptionKey::fromRsaPrivatePem($body);
        }
        throw new \InvalidArgumentException("uuims.oauth.encryption_key_path [{$path}] must be a 32-byte dir secret or an RSA private PEM.");
    }

    private static function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\)#', $path) === 1) {
            return $path;
        }

        return BasePath::get($path);
    }
}
