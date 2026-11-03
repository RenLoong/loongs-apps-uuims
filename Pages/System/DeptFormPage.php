<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Component\Field;
use Loongs\Render\FormPage;
use Loongs\Render\PageContext;

/**
 * 新增 / 编辑 / 新增下级部门 (hidden page node). ?id= edits (GET /uuims/api/system/depts/{id});
 * ?parent_id= pre-selects the parent (新增下级). The parent picker excludes the edited department and its
 * descendants (props.excludeSubtreeOf); the API rejects such a move too (不能移动到自己或下级下面).
 */
#[AsPage('uuims.system.dept-form', permission: ['system:dept:create', 'system:dept:update'])]
final class DeptFormPage extends FormPage
{
    public function build(PageContext $ctx): Form
    {
        $edit = (int) $ctx->param('id', 0) > 0;

        return Form::make($edit ? '编辑部门' : '新增部门')
            ->load('/uuims/api/system/depts/{id}')
            ->submit('POST', '/uuims/api/system/depts', 'create')
            ->submit('PUT', '/uuims/api/system/depts/{id}', 'update')
            ->columns(2)
            ->fields([
                Field::treeSelect('parent_id', '上级')
                    ->options([['value' => 0, 'label' => '（顶级）']])
                    ->optionsApi('/uuims/api/system/depts')
                    ->props(['excludeSubtreeOf' => 'id'])
                    ->default(0)->help('只有超级管理员可以创建顶级部门')->span(24),
                Field::text('name', '名称')->required()->length(null, 64)->span(12),
                Field::text('leader', '负责人')->length(null, 64)->span(12),
                Field::text('phone', '电话')->length(null, 32)->span(12),
                Field::number('sort', '排序')->default(0)->span(12),
                Field::switch('status', '启用')->default(1)->span(12),
            ]);
    }
}
