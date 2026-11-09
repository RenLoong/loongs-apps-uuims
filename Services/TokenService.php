<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use Loongs\OAuth\Storage\OrmStorage;
use Loongs\Orm\Orm;

/**
 * Force-logout for an uuims: revoke every access/refresh **token pair** (oauth family).
 *
 * loongs/oauth binds each access+refresh issue under one family id; refresh rotates the pair
 * (new access + new refresh, old refresh marked rotated; reuse revokes the family). Forced
 * logout must revoke families — not only row-level revoked_at — so a concurrent refresh cannot
 * mint into a still-live family, and introspect / store-backed checks agree.
 *
 * Local JWE access validation never hits this store; revoked access JWEs remain acceptable
 * until exp (short TTL). Refresh / revoke paths use the DB and fail immediately after this.
 */
final class TokenService
{
    /** @param string|null $connection OrmStorage / oauth connection name (null = default, same as OAuth::server) */
    public static function revokeAll(int $userId, string|null $connection = null): int
    {
        $uid = (string) $userId;
        $now = time();
        $families = [];
        foreach (['oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            foreach (Orm::table($table)->where('user_id', $uid)->pluck('family_id') as $fid) {
                $fid = (string) $fid;
                if ($fid !== '') {
                    $families[$fid] = true;
                }
            }
        }
        if ($families === []) {
            return 0;
        }

        $orm = new OrmStorage($connection);
        $n = 0;
        foreach (array_keys($families) as $familyId) {
            $beforeA = (int) Orm::table('oauth_access_tokens')->where('family_id', $familyId)->whereNull('revoked_at')->count();
            $beforeR = (int) Orm::table('oauth_refresh_tokens')->where('family_id', $familyId)->whereNull('revoked_at')->count();
            $orm->revokeFamily($familyId, $now, 'forced_logout');
            $n += $beforeA + $beforeR;
        }

        return $n;
    }
}
