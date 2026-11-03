<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use App\Uuims\Enums\DataScope;
use App\Uuims\Enums\EnableStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 角色列表 (R4, replaces the hand-written roles page). Basic fields are edited in the
 * uuims.system.role-form dialog; menu / button permissions, data scope and field permissions in the
 * registered custom page uuims.system.role-permissions (component role-permission-editor).
 * Data: GET /uuims/api/system/roles (rows carry data_scope + user_count).
 */
#[AsPage('uuims.system.roles', permission: 'system:role:list')]
final class RolesPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('角色')
            ->breadcrumb(['系统管理', '角色'])
            ->api('/uuims/api/system/roles')
            ->rowKey('id')
            ->search([
                Field::text('keyword', '编码/名称'),
            ])
            ->columns([
                Column::text('id', 'ID')->width(70),
                Column::text('code', '编码')->width(140)->mobile(),
                Column::text('name', '名称')->mobile(),
                Column::tag('data_scope', '数据范围')->enum(DataScope::class),
                Column::text('user_count', '管理员数')->width(90),
                Column::text('sort', '排序')->width(70),
                Column::tag('status', '状态')->enum(EnableStatus::class)->mobile(),
            ])
            ->toolbar([
                Action::openForm('新增', 'uuims.system.role-form', [], 'dialog')->can('system:role:create')->primary()->icon('plus'),
            ])
            ->rowActions([
                Action::openForm('编辑', 'uuims.system.role-form', ['id' => '{id}'], 'dialog')->can('system:role:update'),
                Action::openForm('权限', 'uuims.system.role-permissions', ['id' => '{id}'], 'dialog')->can('system:role:update')->pcOnly(),
                Action::request('删除', 'DELETE', '/uuims/api/system/roles/{id}')
                    ->confirm('确定删除角色「{name}」？已分配的管理员将失去该角色的权限。')->can('system:role:delete')->danger()->message('已删除'),
            ])
            ->pageSizes([20, 50, 100]);
    }
}
