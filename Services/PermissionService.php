<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use App\Uuims\Models\Uuims;
use App\Uuims\Support\MenuRegistry;
use App\Uuims\Support\Tree;
use Loongs\Cache\CacheManager;
use Loongs\Orm\Orm;

/**
 * Builds and caches an uuims's merged permissions (RBAC, README §3):
 * permission codes (menus + buttons from the merged apps/*\/loongs.json, granted per role in
 * role_permission), data scope and field permissions. Multiple roles: the broadest permission wins.
 * Cached per uuims (loongs/cache) under a global version that is bumped whenever roles, grants,
 * departments or uuims-role assignments change; the key also carries the menu files' hash, so a
 * changed loongs.json never serves stale permission sets.
 */
final class PermissionService
{
    private const VERSION_KEY = 'user_perm_version';

    /** field permission rank: broader = higher */
    public const FIELD_RANK = ['hidden' => 0, 'masked' => 1, 'readonly' => 2, 'editable' => 3];

    public function __construct(
        private readonly CacheManager $cache,
        private readonly MenuRegistry $menus,
        private readonly int $ttl = 600,
    ) {
    }

    public function registry(): MenuRegistry
    {
        return $this->menus;
    }

    /** @return array<string, mixed> */
    public function context(Uuims $uuims): array
    {
        $key = 'user_perm_' . $this->version() . '_' . $this->menus->hash() . '_' . $uuims->id;
        $ctx = $this->cache->get($key);
        if (!is_array($ctx)) {
            $ctx = $this->build($uuims);
            $this->cache->set($key, $ctx, $this->ttl);
        }

        return $ctx;
    }

    /** Invalidate every cached permission set (roles / grants / menus / depts / assignments changed). */
    public function flush(): void
    {
        if ($this->cache->increment(self::VERSION_KEY) === false) {
            $this->cache->forever(self::VERSION_KEY, time());
        }
    }

    /** Global permission version (bumped by flush()); part of every permission-dependent cache key. */
    public function version(): int
    {
        $v = $this->cache->get(self::VERSION_KEY);
        if ($v === null) {
            $v = 1;
            $this->cache->forever(self::VERSION_KEY, $v);
        }

        return (int) $v;
    }

    /** @return array<string, mixed> */
    public function build(Uuims $uuims): array
    {
        $roles = Orm::table('uuims_user_role')->join('uuims_roles', 'uuims_roles.id', '=', 'uuims_user_role.role_id')
            ->where('uuims_user_role.user_id', $uuims->id)->where('uuims_roles.status', 1)
            ->select('uuims_roles.id', 'uuims_roles.code', 'uuims_roles.name')->get()->all();
        $roleIds = array_map(static fn (array $r): int => (int) $r['id'], $roles);
        $isSuper = (bool) $uuims->is_super;

        // permission codes (menus + buttons): granted ∩ codes that exist in the merged menu files
        if ($isSuper) {
            $perms = $this->menus->codes();
        } else {
            $granted = $roleIds === [] ? [] : Orm::table('uuims_role_permission')->whereIn('role_id', $roleIds)->pluck('code')->all();
            $perms = array_values(array_filter(array_unique(array_map('strval', $granted)), fn (string $c): bool => $this->menus->has($c)));
            sort($perms, SORT_STRING);
        }

        // data scope: union across roles; a role without a rule = self
        $data = ['all' => $isSuper, 'dept_ids' => [], 'self' => false];
        if (!$isSuper) {
            $rules = $roleIds === [] ? [] : Orm::table('uuims_role_data_scope')->whereIn('role_id', $roleIds)->get()->keyBy('role_id')->all();
            $deptRows = null;
            foreach ($roleIds as $rid) {
                $rule = $rules[$rid] ?? $rules[(string) $rid] ?? null;
                $scope = (string) ($rule['scope'] ?? 'self');
                switch ($scope) {
                    case 'all':
                        $data['all'] = true;
                        break;
                    case 'custom':
                        $ids = json_decode((string) ($rule['dept_ids'] ?? '[]'), true);
                        $data['dept_ids'] = array_merge($data['dept_ids'], array_map('intval', is_array($ids) ? $ids : []));
                        break;
                    case 'dept':
                        if ($uuims->dept_id) {
                            $data['dept_ids'][] = (int) $uuims->dept_id;
                        }
                        break;
                    case 'dept_and_child':
                        if ($uuims->dept_id) {
                            $deptRows ??= Orm::table('uuims_depts')->select('id', 'parent_id')->get()->all();
                            $data['dept_ids'] = array_merge($data['dept_ids'], Tree::descendants($deptRows, (int) $uuims->dept_id));
                        }
                        break;
                    default:
                        $data['self'] = true;
                }
            }
            $data['dept_ids'] = array_values(array_unique($data['dept_ids']));
            sort($data['dept_ids']);
        }
        if ($data['all']) {
            $data = ['all' => true, 'dept_ids' => [], 'self' => false];
        }

        // field permissions: per field the broadest of (role rule ?? field default) across roles
        $fields = [];
        $defs = Orm::table('uuims_resource_fields')->get()->all();
        $grants = $isSuper || $roleIds === [] ? [] : Orm::table('uuims_role_field')->whereIn('role_id', $roleIds)->get()->all();
        $byField = [];
        foreach ($grants as $g) {
            $byField[(int) $g['field_id']][(int) $g['role_id']] = (string) $g['perm'];
        }
        foreach ($defs as $d) {
            $perm = 'editable';
            if (!$isSuper) {
                $candidates = [];
                foreach ($roleIds === [] ? [0] : $roleIds as $rid) {
                    $candidates[] = $byField[(int) $d['id']][$rid] ?? (string) $d['default_perm'];
                }
                usort($candidates, static fn (string $a, string $b): int => self::FIELD_RANK[$b] <=> self::FIELD_RANK[$a]);
                $perm = $candidates[0];
            }
            $fields[(string) $d['resource']][(string) $d['field']] = $perm;
        }

        return [
            'id' => (int) $uuims->id,
            'username' => (string) $uuims->username,
            'dept_id' => $uuims->dept_id === null ? null : (int) $uuims->dept_id,
            'is_super' => $isSuper,
            'roles' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']], $roles),
            'role_ids' => $roleIds,
            'perms' => $perms,
            'data' => $data,
            'fields' => $fields,
        ];
    }

    /**
     * Sidebar menu tree (dirs + menus, visible) of the merged menu files, filtered by the uuims's
     * codes; dirs are kept when something below them is visible.
     *
     * @param array<string, mixed> $ctx
     * @return list<array<string, mixed>>
     */
    public function menuTree(array $ctx): array
    {
        return $this->menus->menuTree($ctx['is_super'] ? null : $ctx['perms']);
    }
}
