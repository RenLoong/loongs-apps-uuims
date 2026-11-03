<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use App\Uuims\Enums\EnableStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 部门 tree table (R4, replaces the hand-written TreeCrudPage). GET /uuims/api/system/depts returns the
 * whole tree. Delete restrictions (下级部门 / 管理员) and scope checks are enforced by the API; the client
 * shows its messages.
 */
#[AsPage('uuims.system.depts', permission: 'system:dept:list')]
final class DeptsPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('部门')
            ->breadcrumb(['系统管理', '部门'])
            ->api('/uuims/api/system/depts')
            ->rowKey('id')
            ->tree('children', 'name')
            ->columns([
                Column::text('name', '名称')->mobile(),
                Column::text('leader', '负责人')->mobile(),
                Column::text('phone', '电话'),
                Column::text('sort', '排序')->width(70),
                Column::tag('status', '状态')->enum(EnableStatus::class)->mobile(),
            ])
            ->toolbar([
                Action::refresh(),
                Action::openForm('新增', 'uuims.system.dept-form', [], 'dialog')->can('system:dept:create')->primary()->icon('plus'),
            ])
            ->rowActions([
                Action::openForm('新增下级', 'uuims.system.dept-form', ['parent_id' => '{id}'], 'dialog')->can('system:dept:create'),
                Action::openForm('编辑', 'uuims.system.dept-form', ['id' => '{id}'], 'dialog')->can('system:dept:update'),
                Action::request('删除', 'DELETE', '/uuims/api/system/depts/{id}')
                    ->confirm('确定删除「{name}」？')->can('system:dept:delete')->danger()->message('已删除'),
            ]);
    }
}
