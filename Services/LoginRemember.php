<?php

declare(strict_types=1);

namespace App\Uuims\Services;

/**
 * Browser authorize "trust this browser" (opt-in): opaque HttpOnly cookie → Redis session.
 * Never stores passwords. Cookie name: uuims_remember. TTL defaults to 7 days (config).
 *
 * Also issues one-time continue_nonce values bound to a user id (CSRF for "continue as"),
 * and short-lived pending_auth tokens after password login until the user answers the
 * trust prompt (trust / untrust / cancel).
 */
final class LoginRemember
{
    public const COOKIE = 'uuims_remember';

    public function __construct(
        private readonly int $ttl = 604800,
        private readonly int $continueTtl = 300,
        private readonly int $pendingTtl = 300,
    ) {
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Create a remember session; returns the raw cookie token.
     *
     * @return string 64-char hex
     */
    public function issue(int $userId, string $username): string
    {
        $token = bin2hex(random_bytes(32));
        $payload = json_encode(['uid' => $userId, 'username' => $username], JSON_UNESCAPED_UNICODE);
        redis()->run(fn (\Redis $r) => $r->setex(self::key($token), $this->ttl, $payload));

        return $token;
    }

    /** @return array{uid: int, username: string}|null */
    public function read(?string $token): ?array
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $raw = redis()->run(static fn (\Redis $r): mixed => $r->get(self::key($token)));
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['uid'], $data['username'])) {
            return null;
        }

        return ['uid' => (int) $data['uid'], 'username' => (string) $data['username']];
    }

    /** Sliding TTL refresh when the user continues with a remembered account. */
    public function touch(string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return;
        }
        redis()->run(fn (\Redis $r) => $r->expire(self::key($token), $this->ttl));
    }

    public function forget(?string $token): void
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return;
        }
        redis()->run(static fn (\Redis $r) => $r->del(self::key($token)));
    }

    /** One-time nonce for the "continue as remembered user" POST. */
    public function issueContinue(int $userId): string
    {
        $nonce = bin2hex(random_bytes(16));
        redis()->run(fn (\Redis $r) => $r->setex('uuims:login:continue:' . $nonce, $this->continueTtl, (string) $userId));

        return $nonce;
    }

    /** Consume continue nonce; returns user id or null. */
    public function consumeContinue(string $nonce): ?int
    {
        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            return null;
        }
        $uid = redis()->run(static function (\Redis $r) use ($nonce): mixed {
            $k = 'uuims:login:continue:' . $nonce;
            $v = $r->get($k);
            $r->del($k);

            return $v;
        });

        return is_string($uid) && ctype_digit($uid) ? (int) $uid : null;
    }

    /**
     * After successful password login: one-time pending token until the user answers the trust prompt.
     *
     * @return string 32-char hex
     */
    public function issuePending(int $userId, string $username): string
    {
        $nonce = bin2hex(random_bytes(16));
        $payload = json_encode(['uid' => $userId, 'username' => $username], JSON_UNESCAPED_UNICODE);
        redis()->run(fn (\Redis $r) => $r->setex(self::pendingKey($nonce), $this->pendingTtl, $payload));

        return $nonce;
    }

    /**
     * Consume pending trust token; returns user payload or null.
     *
     * @return array{uid: int, username: string}|null
     */
    public function consumePending(string $nonce): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            return null;
        }
        $raw = redis()->run(static function (\Redis $r) use ($nonce): mixed {
            $k = self::pendingKey($nonce);
            $v = $r->get($k);
            $r->del($k);

            return $v;
        });
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['uid'], $data['username'])) {
            return null;
        }

        return ['uid' => (int) $data['uid'], 'username' => (string) $data['username']];
    }

    public function forgetPending(string $nonce): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            return;
        }
        redis()->run(static fn (\Redis $r) => $r->del(self::pendingKey($nonce)));
    }

    private static function pendingKey(string $nonce): string
    {
        return 'uuims:login:pending:' . $nonce;
    }

    /**
     * Build a Set-Cookie header value (RFC 6265).
     * Secure is set only when the request is HTTPS (else browsers on http://127.0.0.1 drop it).
     */
    public function setCookieHeader(string $token, bool $secure): string
    {
        $parts = [
            self::COOKIE . '=' . $token,
            'Path=/',
            'Max-Age=' . $this->ttl,
            'Expires=' . gmdate('D, d M Y H:i:s', time() + $this->ttl) . ' GMT',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    public function clearCookieHeader(bool $secure): string
    {
        $parts = [
            self::COOKIE . '=',
            'Path=/',
            'Max-Age=0',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private static function key(string $token): string
    {
        return 'uuims:login:remember:' . $token;
    }
}
