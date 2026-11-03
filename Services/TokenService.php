<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use Loongs\Orm\Orm;

/** Force-logout: revoke every access / refresh token of an uuims (password change, disable, delete, kick). */
final class TokenService
{
    public static function revokeAll(int $userId): int
    {
        $now = time();
        $n = Orm::table('oauth_access_tokens')->where('user_id', (string) $userId)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        $n += Orm::table('oauth_refresh_tokens')->where('user_id', (string) $userId)->whereNull('revoked_at')->update(['revoked_at' => $now]);

        return $n;
    }
}
