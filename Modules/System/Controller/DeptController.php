<?php

declare(strict_types=1);

namespace App\Uuims\Modules\System\Controller;

use App\Uuims\Models\Uuims;
use App\Uuims\Models\Dept;
use App\Uuims\Services\PermissionService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\Tree;
use App\Uuims\Support\Validator;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Orm\Orm;

/** Department tree; `ancestors` keeps the path for data-scope lookups. */
final class DeptController
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $rows = array_map(static fn (Dept $d): array => $d->toArray(), Dept::query()->orderBy('sort')->orderBy('id')->get()->all());

        return Api::ok(Tree::build($rows));
    }

    /** One department (form load of uuims.system.dept-form). */
    public function show(Request $request): Response
    {
        $dept = Dept::query()->find(Api::id($request)) ?? throw ApiException::notFound();

        return Api::ok($dept->toArray());
    }

    public function store(Request $request): Response
    {
        $in = $this->validate(Api::input($request));
        $in['ancestors'] = $this->ancestorsFor((int) $in['parent_id']);
        $dept = Dept::query()->create($in + ['created_by' => UuimsContext::id()]);
        $this->permissions->flush();

        return Api::ok(['id' => (int) $dept->id], '创建成功');
    }

    public function update(Request $request): Response
    {
        $dept = Dept::query()->find(Api::id($request)) ?? throw ApiException::notFound();
        $in = $this->validate(Api::input($request) + ['name' => $dept->name, 'parent_id' => $dept->parent_id]);
        $newParent = (int) $in['parent_id'];
        Orm::transaction(function () use ($dept, $in, $newParent): void {
            if ($newParent !== (int) $dept->parent_id) {
                $rows = Orm::table('uuims_depts')->select('id', 'parent_id')->get()->all();
                if (in_array($newParent, Tree::descendants($rows, (int) $dept->id), true)) {
                    throw ApiException::validation(['parent_id' => '不能移动到自己或下级下面']);
                }
                $old = (string) $dept->ancestors . ',' . $dept->id;
                $in['ancestors'] = $this->ancestorsFor($newParent);
                $new = $in['ancestors'] . ',' . $dept->id;
                foreach (Tree::descendants($rows, (int) $dept->id) as $childId) {
                    if ($childId === (int) $dept->id) {
                        continue;
                    }
                    $child = Dept::query()->find($childId);
                    if ($child !== null && str_starts_with((string) $child->ancestors, $old)) {
                        $child->forceFill(['ancestors' => $new . substr((string) $child->ancestors, strlen($old))])->save();
                    }
                }
            }
            $dept->fill($in)->save();
        });
        $this->permissions->flush();

        return Api::ok(['id' => (int) $dept->id], '保存成功');
    }

    public function destroy(Request $request): Response
    {
        $dept = Dept::query()->find(Api::id($request)) ?? throw ApiException::notFound();
        if (Dept::query()->where('parent_id', $dept->id)->exists()) {
            throw ApiException::conflict('请先删除下级部门');
        }
        if (Uuims::query()->withoutGlobalScope('data_scope')->where('dept_id', $dept->id)->exists()) {
            throw ApiException::conflict('部门下还有管理员');
        }
        $dept->delete();
        $this->permissions->flush();

        return Api::ok(null, '删除成功');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $in = Validator::check($input, [
            'parent_id' => 'int',
            'name' => 'required|string|max:64',
            'sort' => 'int',
            'leader' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:32',
            'status' => 'int|in:0,1',
        ], ['parent_id' => '上级部门', 'name' => '部门名称']);
        $in['parent_id'] = (int) ($in['parent_id'] ?? 0);
        if ($in['parent_id'] > 0 && Dept::query()->find($in['parent_id']) === null) {
            throw ApiException::validation(['parent_id' => '上级部门不存在']);
        }
        if ($in['parent_id'] === 0 && !UuimsContext::isSuper()) {
            throw ApiException::forbidden('只有超级管理员可以创建顶级部门', 40303);
        }
        if ($in['parent_id'] > 0 && !Uuims::deptInScope($in['parent_id'])) {
            throw ApiException::forbidden('上级部门不在你的数据权限范围内', 40303);
        }

        return $in;
    }

    private function ancestorsFor(int $parentId): string
    {
        if ($parentId === 0) {
            return '0';
        }
        $p = Dept::query()->find($parentId);

        return (string) $p->ancestors . ',' . $parentId;
    }
}
