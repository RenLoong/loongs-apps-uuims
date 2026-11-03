<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Swoole\Coroutine;

/**
 * Current administrator's permission context for this request. Stored in the Swoole coroutine
 * context (per request, never shared across requests); outside coroutines (CLI) it falls back to a
 * process-local slot so install/tests can run without a request.
 *
 * Shape (PermissionService::context()):
 *   id, username, dept_id, is_super, role_ids[], perms[] (permission codes),
 *   data: {all: bool, dept_ids: int[], self: bool},
 *   fields: {resource: {field: hidden|masked|readonly|editable}}
 */
final class UuimsContext
{
    private const KEY = 'uuims.context';

    /** @var array<string, mixed> only used when not inside a coroutine */
    private static array $cli = [];

    /** @param array<string, mixed>|null $ctx */
    public static function set(?array $ctx): void
    {
        if (Coroutine::getCid() > 0) {
            Coroutine::getContext()[self::KEY] = $ctx;
        } else {
            self::$cli[self::KEY] = $ctx;
        }
    }

    /** @return array<string, mixed>|null */
    public static function get(): ?array
    {
        if (Coroutine::getCid() > 0) {
            $c = Coroutine::getContext();

            return isset($c[self::KEY]) ? $c[self::KEY] : null;
        }

        return self::$cli[self::KEY] ?? null;
    }

    /** @return array<string, mixed> */
    public static function require(): array
    {
        return self::get() ?? throw ApiException::unauthorized();
    }

    public static function id(): ?int
    {
        $c = self::get();

        return $c === null ? null : (int) $c['id'];
    }

    public static function isSuper(): bool
    {
        return (bool) (self::get()['is_super'] ?? false);
    }

    public static function can(string $perm): bool
    {
        $c = self::get();

        return $c !== null && ($c['is_super'] || in_array($perm, $c['perms'], true));
    }

    /** Field permission for the current uuims (editable when no context / super / not controlled). */
    public static function fieldPerm(string $resource, string $field): string
    {
        $c = self::get();
        if ($c === null || $c['is_super']) {
            return 'editable';
        }

        return $c['fields'][$resource][$field] ?? 'editable';
    }
}
