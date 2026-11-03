<?php

declare(strict_types=1);

namespace App\Uuims\Render;

use Loongs\Render\Contract\PermissionResolver;
use Loongs\Render\PageContext;

/**
 * loongs/render permission check backed by the P1 permission context (PermissionService::context():
 * role_permission codes of all enabled roles, super uuims = everything). PageContext::$user is that
 * context array.
 */
final class UuimsPermissionResolver implements PermissionResolver
{
    public function allows(string $code, PageContext $ctx): bool
    {
        $u = $ctx->user;

        return is_array($u) && (($u['is_super'] ?? false) === true || in_array($code, $u['perms'] ?? [], true));
    }

    /**
     * Everything that changes a rendered description for this uuims: super flag, permission codes and
     * field permissions (two users with the same roles share cached descriptions).
     */
    public function fingerprint(PageContext $ctx): string
    {
        return self::of(is_array($ctx->user) ? $ctx->user : []);
    }

    /** @param array<string, mixed> $permCtx */
    public static function of(array $permCtx): string
    {
        if (($permCtx['is_super'] ?? false) === true) {
            return 'super';
        }
        $fields = $permCtx['fields'] ?? [];
        ksort($fields);
        foreach ($fields as &$f) {
            ksort($f);
        }

        return substr(hash('sha256', json_encode([$permCtx['perms'] ?? [], $fields], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
