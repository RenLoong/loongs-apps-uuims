<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use App\Uuims\Enums\EnableStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Component\Field;
use Loongs\Render\FormPage;
use Loongs\Render\PageContext;

/**
 * 新增 / 编辑角色 (hidden page node): basic fields only. The grants (permissions, data scope, field
 * permissions) are left untouched by this form (the API only updates the keys that are sent) and are
 * edited in uuims.system.role-permissions. A new role starts without permissions and with data scope 仅本人.
 */
#[AsPage('uuims.system.role-form', permission: ['system:role:create', 'system:role:update'])]
final class RoleFormPage extends FormPage
{
    public function build(PageContext $ctx): Form
    {
        $edit = (int) $ctx->param('id', 0) > 0;

        return Form::make($edit ? '编辑角色' : '新增角色')
            ->load('/uuims/api/system/roles/{id}')
            ->submit('POST', '/uuims/api/system/roles', 'create')
            ->submit('PUT', '/uuims/api/system/roles/{id}', 'update')
            ->columns(2)
            ->fields([
                Field::text('code', '编码')->required()->placeholder('如 ops_uuims')
                    ->regex('^[a-z][a-z0-9_:-]{1,63}$', '小写字母开头，2–64 位小写字母、数字或 _ : -')->span(12),
                Field::text('name', '名称')->required()->length(null, 64)->span(12),
                Field::number('sort', '排序')->default(0)->span(12),
                Field::switch('status', '启用')->default(1)->span(12),
                Field::textarea('remark', '备注')->length(null, 255)
                    ->help($edit ? '菜单/按钮、数据范围和字段权限请在列表的「权限」中设置' : '保存后在列表的「权限」中设置菜单/按钮、数据范围和字段权限')->span(24),
            ]);
    }
}
