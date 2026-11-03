<?php

declare(strict_types=1);

namespace App\Uuims\Modules\System\Controller;

use App\Uuims\Models\Uuims;
use App\Uuims\Models\Dept;
use App\Uuims\Services\PermissionService;
use App\Uuims\Services\TokenService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\Validator;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Orm\Orm;

/**
 * Uuims CRUD. Row-level data scope comes from the Uuims model's global scope (out-of-scope rows are
 * invisible → 404); field permissions from HasFieldPermissions (toArray / fill).
 */
final class UuimsController
{
    private const LABELS = ['username' => '用户名', 'password' => '密码', 'nickname' => '昵称', 'mobile' => '手机号',
        'email' => '邮箱', 'dept_id' => '部门', 'status' => '状态', 'role_ids' => '角色'];

    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        [$page, $size] = Api::pagination($request);
        $q = Uuims::query();
        $kw = trim((string) $request->query('keyword', ''));
        if ($kw !== '') {
            $q->where(static fn ($w) => $w->where('username', 'like', "%{$kw}%")->orWhere('nickname', 'like', "%{$kw}%"));
        }
        if (($dept = (int) $request->query('dept_id', 0)) > 0) {
            $q->where('dept_id', $dept);
        }
        $status = $request->query('status');
        if ($status !== null && $status !== '') {
            $q->where('status', (int) $status);
        }
        $p = $q->orderBy('id')->paginate($size, $page);
        $ids = [];
        $deptIds = [];
        foreach ($p->items() as $a) {
            $ids[] = (int) $a->id;
            if ($a->dept_id) {
                $deptIds[] = (int) $a->dept_id;
            }
        }
        $roles = $this->rolesOf($ids);
        $depts = $deptIds === [] ? [] : Orm::table('uuims_depts')->whereIn('id', array_unique($deptIds))->pluck('name', 'id')->all();

        return Api::page($p, static fn (Uuims $a): array => $a->toArray() + [
            'dept_name' => $depts[(int) $a->dept_id] ?? null,
            'roles' => $roles[(int) $a->id] ?? [],
        ], ['fields' => $this->fieldPerms()]);
    }

    public function show(Request $request): Response
    {
        $uuims = $this->find(Api::id($request));

        return Api::ok($uuims->toArray() + [
            'roles' => $this->rolesOf([(int) $uuims->id])[(int) $uuims->id] ?? [],
            'role_ids' => array_map(static fn (array $r): int => $r['id'], $this->rolesOf([(int) $uuims->id])[(int) $uuims->id] ?? []),
            'fields' => $this->fieldPerms(),
        ]);
    }

    public function store(Request $request): Response
    {
        $input = Api::input($request);
        $in = Validator::check($input, [
            'username' => 'required|string|regex:/^[A-Za-z0-9_]{3,32}$/',
            'password' => 'required|string|min:8|max:72',
            'nickname' => 'string|max:64',
            'mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:128',
            'dept_id' => 'nullable|int',
            'status' => 'int|in:0,1',
            'role_ids' => 'array',
        ], self::LABELS);
        $this->assertDept($in['dept_id'] ?? null);
        if (Uuims::query()->withoutGlobalScope('data_scope')->where('username', $in['username'])->exists()) {
            throw ApiException::conflict('用户名已存在');
        }
        $roleIds = Validator::ids($in['role_ids'] ?? []);
        $this->assertAssignable($roleIds);

        $uuims = Orm::transaction(function () use ($in, $input, $roleIds): Uuims {
            $uuims = new Uuims();
            // fill() applies field permissions to the raw client input (non-editable fields → 403)
            $uuims->fill(array_intersect_key($input, array_flip(['mobile', 'email'])) + [
                'username' => $in['username'],
                'nickname' => $in['nickname'] ?? $in['username'],
                'dept_id' => $in['dept_id'] ?? null,
                'status' => $in['status'] ?? 1,
            ]);
            $uuims->forceFill([
                'password' => Uuims::hashPassword($in['password']),
                'password_changed_at' => date('Y-m-d H:i:s'),
                'is_super' => 0,
                'created_by' => UuimsContext::id(),
            ])->save();
            $this->syncRoles((int) $uuims->id, $roleIds);

            return $uuims;
        });

        return Api::ok(['id' => (int) $uuims->id], '创建成功');
    }

    public function update(Request $request): Response
    {
        $uuims = $this->find(Api::id($request));
        $this->assertNotProtected($uuims);
        $input = Api::input($request);
        $in = Validator::check($input, [
            'nickname' => 'string|max:64',
            'mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:128',
            'dept_id' => 'nullable|int',
            'status' => 'int|in:0,1',
            'role_ids' => 'array',
        ], self::LABELS);
        if (array_key_exists('dept_id', $in) && (int) $in['dept_id'] !== (int) $uuims->dept_id) {
            $this->assertDept($in['dept_id']);
        }
        if ((int) $uuims->id === UuimsContext::id() && isset($in['status']) && (int) $in['status'] === 0) {
            throw ApiException::conflict('不能停用自己');
        }
        $roleIds = array_key_exists('role_ids', $in) ? Validator::ids($in['role_ids']) : null;
        if ($roleIds !== null) {
            $this->assertAssignable($roleIds, (int) $uuims->id);
        }
        $disabled = isset($in['status']) && (int) $in['status'] === 0 && (int) $uuims->status === 1;

        Orm::transaction(function () use ($uuims, $in, $input, $roleIds): void {
            $attrs = array_intersect_key($in, array_flip(['nickname', 'dept_id', 'status']));
            foreach (['mobile', 'email'] as $f) {
                if (array_key_exists($f, $input)) {
                    $attrs[$f] = $input[$f];
                }
            }
            $uuims->fill($attrs)->save();
            if ($roleIds !== null) {
                $this->syncRoles((int) $uuims->id, $roleIds);
            }
        });
        $this->permissions->flush();
        if ($disabled) {
            TokenService::revokeAll((int) $uuims->id);
        }

        return Api::ok(['id' => (int) $uuims->id], '保存成功');
    }

    public function destroy(Request $request): Response
    {
        $uuims = $this->find(Api::id($request));
        if ($uuims->is_super) {
            throw ApiException::forbidden('超级管理员不能删除', 40305);
        }
        if ((int) $uuims->id === UuimsContext::id()) {
            throw ApiException::conflict('不能删除自己');
        }
        Orm::transaction(static function () use ($uuims): void {
            Orm::table('uuims_user_role')->where('user_id', $uuims->id)->delete();
            Orm::table('uuims_user_wechat')->where('user_id', $uuims->id)->delete();
            Orm::table('uuims_user_messages')->where('user_id', $uuims->id)->delete();   // P5 inbox (table from uuims:install)
            $uuims->delete();
        });
        TokenService::revokeAll((int) $uuims->id);
        $this->permissions->flush();

        return Api::ok(null, '删除成功');
    }

    public function resetPassword(Request $request): Response
    {
        $uuims = $this->find(Api::id($request));
        $this->assertNotProtected($uuims);
        $in = Validator::check(Api::input($request), ['password' => 'required|string|min:8|max:72'], self::LABELS);
        $uuims->forceFill(['password' => Uuims::hashPassword($in['password']), 'password_changed_at' => date('Y-m-d H:i:s')])->save();
        $revoked = TokenService::revokeAll((int) $uuims->id);

        return Api::ok(['revoked_tokens' => $revoked], '密码已重置');
    }

    private function find(int $id): Uuims
    {
        return Uuims::query()->find($id) ?? throw ApiException::notFound();
    }

    private function assertNotProtected(Uuims $uuims): void
    {
        if ($uuims->is_super && !UuimsContext::isSuper()) {
            throw ApiException::forbidden('不能修改超级管理员', 40305);
        }
    }

    private function assertDept(?int $deptId): void
    {
        if ($deptId !== null && Dept::query()->find($deptId) === null) {
            throw ApiException::validation(['dept_id' => '部门不存在']);
        }
        if (!Uuims::deptInScope($deptId)) {
            throw ApiException::forbidden('该部门不在你的数据权限范围内', 40303);
        }
    }

    /** Non-super users may only assign roles they hold themselves (no privilege escalation). @param list<int> $roleIds */
    private function assertAssignable(array $roleIds, ?int $targetId = null): void
    {
        if ($roleIds === []) {
            return;
        }
        $found = array_map('intval', Orm::table('uuims_roles')->whereIn('id', $roleIds)->pluck('id')->all());
        if (count($found) !== count($roleIds)) {
            throw ApiException::validation(['role_ids' => '角色不存在']);
        }
        if (!UuimsContext::isSuper()) {
            $current = $targetId === null ? [] : array_map('intval', Orm::table('uuims_user_role')->where('user_id', $targetId)->pluck('role_id')->all());
            $new = array_diff($roleIds, $current);
            if (array_diff($new, UuimsContext::require()['role_ids']) !== []) {
                throw ApiException::forbidden('只能分配自己拥有的角色', 40304);
            }
        }
    }

    /** @param list<int> $roleIds */
    private function syncRoles(int $userId, array $roleIds): void
    {
        Orm::table('uuims_user_role')->where('user_id', $userId)->delete();
        if ($roleIds !== []) {
            Orm::table('uuims_user_role')->insert(array_map(static fn (int $r): array => ['user_id' => $userId, 'role_id' => $r], $roleIds));
        }
    }

    /**
     * @param list<int> $userIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function rolesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        $rows = Orm::table('uuims_user_role')->join('uuims_roles', 'uuims_roles.id', '=', 'uuims_user_role.role_id')
            ->whereIn('uuims_user_role.user_id', $userIds)->select('uuims_user_role.user_id', 'uuims_roles.id', 'uuims_roles.name')->get()->all();
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        return $out;
    }

    /** @return array<string, string> field => perm for the "uuims" resource */
    private function fieldPerms(): array
    {
        return UuimsContext::require()['fields']['uuims'] ?? [];
    }
}
