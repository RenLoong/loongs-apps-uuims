<?php

declare(strict_types=1);

namespace App\Uuims\Modules\System\Controller;

use App\Uuims\Models\Dept;
use App\Uuims\Models\Role;
use App\Uuims\Services\PermissionService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\I18n;
use App\Uuims\Support\Validator;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Orm\Orm;

/**
 * Roles with their grants: permission codes (menus / buttons of the merged apps/*\/loongs.json, table
 * role_permission), data scope (all | custom | dept_and_child | dept | self)
 * and field permissions ({field_id: hidden|masked|readonly|editable}). Non-super users cannot grant
 * more than they have (permission codes, data scope, field permissions).
 */
final class RoleController
{
    private const SCOPES = ['all', 'custom', 'dept_and_child', 'dept', 'self'];

    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        [$page, $size] = Api::pagination($request);
        $q = Role::query();
        $kw = trim((string) $request->query('keyword', ''));
        if ($kw !== '') {
            $q->where(static fn ($w) => $w->where('name', 'like', "%{$kw}%")->orWhere('code', 'like', "%{$kw}%"));
        }
        $p = $q->orderBy('sort')->orderBy('id')->paginate($size, $page);
        $ids = [];
        foreach ($p->items() as $r) {
            $ids[] = (int) $r->id;
        }
        $scopes = $ids === [] ? [] : Orm::table('uuims_role_data_scope')->whereIn('role_id', $ids)->pluck('scope', 'role_id')->all();
        $counts = [];
        if ($ids !== []) {
            foreach (Orm::table('uuims_user_role')->whereIn('role_id', $ids)->get()->all() as $row) {
                $counts[(int) $row['role_id']] = ($counts[(int) $row['role_id']] ?? 0) + 1;
            }
        }

        return Api::page($p, static fn (Role $r): array => $r->toArray() + [
            'data_scope' => $scopes[(int) $r->id] ?? 'self',
            'user_count' => $counts[(int) $r->id] ?? 0,
        ]);
    }

    /** Lightweight list for role pickers. */
    public function options(Request $request): Response
    {
        return Api::ok(Orm::table('uuims_roles')->where('status', 1)->orderBy('sort')->orderBy('id')->select('id', 'code', 'name')->get()->all());
    }

    public function show(Request $request): Response
    {
        $role = Role::query()->find(Api::id($request)) ?? throw ApiException::notFound();

        return Api::ok($role->toArray() + $this->grants((int) $role->id));
    }

    public function store(Request $request): Response
    {
        [$in, $grants] = $this->validate(Api::input($request), true);
        if (Role::query()->where('code', $in['code'])->exists()) {
            throw ApiException::conflict('角色编码已存在');
        }
        $role = Orm::transaction(function () use ($in, $grants): Role {
            $role = Role::query()->create($in + ['created_by' => UuimsContext::id()]);
            $this->saveGrants((int) $role->id, $grants);

            return $role;
        });
        $this->permissions->flush();

        return Api::ok(['id' => (int) $role->id], '创建成功');
    }

    public function update(Request $request): Response
    {
        $role = Role::query()->find(Api::id($request)) ?? throw ApiException::notFound();
        [$in, $grants] = $this->validate(Api::input($request), false);
        if (isset($in['code']) && $in['code'] !== $role->code && Role::query()->where('code', $in['code'])->exists()) {
            throw ApiException::conflict('角色编码已存在');
        }
        Orm::transaction(function () use ($role, $in, $grants): void {
            $role->fill($in)->save();
            $this->saveGrants((int) $role->id, $grants);
        });
        $this->permissions->flush();

        return Api::ok(['id' => (int) $role->id], '保存成功');
    }

    public function destroy(Request $request): Response
    {
        $role = Role::query()->find(Api::id($request)) ?? throw ApiException::notFound();
        Orm::transaction(static function () use ($role): void {
            foreach (['uuims_user_role', 'uuims_role_permission', 'uuims_role_data_scope', 'uuims_role_field'] as $t) {
                Orm::table($t)->where('role_id', $role->id)->delete();
            }
            $role->delete();
        });
        $this->permissions->flush();

        return Api::ok(null, '删除成功');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validate(array $input, bool $creating): array
    {
        $in = Validator::check($input, [
            'code' => ($creating ? 'required|' : '') . 'string|regex:/^[a-z][a-z0-9_:-]{1,63}$/',
            'name' => ($creating ? 'required|' : '') . 'string|max:64',
            'sort' => 'int',
            'status' => 'int|in:0,1',
            'remark' => 'nullable|string|max:255',
        ], ['code' => '角色编码', 'name' => '角色名称', 'sort' => '排序', 'status' => '状态', 'remark' => '备注']);
        $ctx = UuimsContext::require();
        $grants = [];

        if (array_key_exists('permissions', $input)) {
            $raw = $input['permissions'];
            if (!is_array($raw) || !array_is_list($raw)) {
                throw ApiException::validation(['permissions' => '权限标识必须是数组']);
            }
            $codes = [];
            foreach ($raw as $c) {
                if (!is_string($c) || $c === '') {
                    throw ApiException::validation(['permissions' => '权限标识必须是字符串']);
                }
                $codes[$c] = true;
            }
            $codes = array_keys($codes);
            sort($codes, SORT_STRING);
            $registry = $this->permissions->registry();
            $unknown = array_values(array_filter($codes, static fn (string $c): bool => !$registry->has($c)));
            if ($unknown !== []) {
                throw ApiException::validation(['permissions' => I18n::t('权限标识不存在：{codes}', ['codes' => implode(', ', array_slice($unknown, 0, 5))])]);
            }
            if (!$ctx['is_super'] && array_diff($codes, $ctx['perms']) !== []) {
                throw ApiException::forbidden('不能授予自己没有的菜单或按钮权限', 40304);
            }
            $grants['permissions'] = $codes;
        }

        if (array_key_exists('data_scope', $input)) {
            $ds = is_array($input['data_scope']) ? $input['data_scope'] : ['scope' => $input['data_scope']];
            $scope = (string) ($ds['scope'] ?? 'self');
            if (!in_array($scope, self::SCOPES, true)) {
                throw ApiException::validation(['data_scope' => '数据范围取值无效']);
            }
            $deptIds = $scope === 'custom' ? Validator::ids($ds['dept_ids'] ?? []) : [];
            if ($deptIds !== [] && Dept::query()->whereIn('id', $deptIds)->count() !== count($deptIds)) {
                throw ApiException::validation(['data_scope' => '部门不存在']);
            }
            if (!$ctx['is_super'] && !$ctx['data']['all']) {
                if ($scope === 'all' || array_diff($deptIds, $ctx['data']['dept_ids']) !== []) {
                    throw ApiException::forbidden('不能授予超出自己范围的数据权限', 40304);
                }
            }
            $grants['data_scope'] = ['scope' => $scope, 'dept_ids' => $deptIds];
        }

        if (array_key_exists('field_perms', $input)) {
            $fp = is_array($input['field_perms']) ? $input['field_perms'] : [];
            $defs = [];
            foreach (Orm::table('uuims_resource_fields')->get()->all() as $d) {
                $defs[(int) $d['id']] = $d;
            }
            $out = [];
            foreach ($fp as $fieldId => $perm) {
                $fieldId = (int) $fieldId;
                $perm = (string) $perm;
                if (!isset($defs[$fieldId]) || !isset(PermissionService::FIELD_RANK[$perm])) {
                    throw ApiException::validation(['field_perms' => '字段或权限取值无效']);
                }
                if (!$ctx['is_super']) {
                    $own = $ctx['fields'][$defs[$fieldId]['resource']][$defs[$fieldId]['field']] ?? 'editable';
                    if (PermissionService::FIELD_RANK[$perm] > PermissionService::FIELD_RANK[$own]) {
                        throw ApiException::forbidden('不能授予超出自己的字段权限', 40304);
                    }
                }
                $out[$fieldId] = $perm;
            }
            $grants['field_perms'] = $out;
        }

        return [$in, $grants];
    }

    /** @param array<string, mixed> $grants */
    private function saveGrants(int $roleId, array $grants): void
    {
        if (isset($grants['permissions'])) {
            Orm::table('uuims_role_permission')->where('role_id', $roleId)->delete();
            if ($grants['permissions'] !== []) {
                Orm::table('uuims_role_permission')->insert(array_map(static fn (string $c): array => ['role_id' => $roleId, 'code' => $c], $grants['permissions']));
            }
        }
        if (isset($grants['data_scope'])) {
            Orm::table('uuims_role_data_scope')->where('role_id', $roleId)->delete();
            Orm::table('uuims_role_data_scope')->insert([
                'role_id' => $roleId,
                'scope' => $grants['data_scope']['scope'],
                'dept_ids' => json_encode($grants['data_scope']['dept_ids']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if (isset($grants['field_perms'])) {
            Orm::table('uuims_role_field')->where('role_id', $roleId)->delete();
            $rows = [];
            foreach ($grants['field_perms'] as $fieldId => $perm) {
                $rows[] = ['role_id' => $roleId, 'field_id' => $fieldId, 'perm' => $perm];
            }
            if ($rows !== []) {
                Orm::table('uuims_role_field')->insert($rows);
            }
        }
    }

    /** @return array<string, mixed> */
    private function grants(int $roleId): array
    {
        $ds = Orm::table('uuims_role_data_scope')->where('role_id', $roleId)->first();
        $fp = [];
        foreach (Orm::table('uuims_role_field')->where('role_id', $roleId)->get()->all() as $row) {
            $fp[(string) $row['field_id']] = $row['perm'];
        }

        $codes = array_map('strval', Orm::table('uuims_role_permission')->where('role_id', $roleId)->pluck('code')->all());
        sort($codes, SORT_STRING);
        $registry = $this->permissions->registry();

        return [
            // codes that exist in the merged menu files; stale ones (app removed / code renamed) are listed apart and ignored
            'permissions' => array_values(array_filter($codes, static fn (string $c): bool => $registry->has($c))),
            'stale_permissions' => array_values(array_filter($codes, static fn (string $c): bool => !$registry->has($c))),
            'data_scope' => ['scope' => $ds['scope'] ?? 'self', 'dept_ids' => array_map('intval', json_decode((string) ($ds['dept_ids'] ?? '[]'), true) ?: [])],
            'field_perms' => (object) $fp,
        ];
    }
}
