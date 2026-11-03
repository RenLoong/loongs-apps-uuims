<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use App\Uuims\Enums\UuimsStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 管理员列表. Rendered generically by the Flutter client since R2 (loongs.json page uuims.system.users).
 * Data: GET /uuims/api/system/users (data scope + field permissions applied by the API as well).
 */
#[AsPage('uuims.system.users', permission: 'system:uuims:list')]
final class UsersPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('管理员')
            ->breadcrumb(['系统管理', '管理员'])
            ->resource('uuims')
            ->api('/uuims/api/system/users')
            ->rowKey('id')
            ->search([
                Field::text('keyword', '用户名/昵称'),
                Field::select('status', '状态')->options(UuimsStatus::class),
                Field::treeSelect('dept_id', '部门')->optionsApi('/uuims/api/system/depts'),
            ])
            ->columns([
                Column::text('id', 'ID')->fixed('left')->width(70),
                Column::text('username', '用户名')->width(140)->mobile(),
                Column::text('nickname', '昵称')->mobile(),
                Column::text('dept_name', '部门'),
                Column::copyable('mobile', '手机号'),
                Column::text('email', '邮箱'),
                Column::text('roles', '角色')->width(160),   // list of {id, name}: the client joins the names
                Column::tag('status', '状态')->enum(UuimsStatus::class)->mobile(),
                Column::datetime('last_login_at', '最后登录')->format('yyyy-MM-dd HH:mm'),
                Column::datetime('created_at', '创建时间')->format('yyyy-MM-dd HH:mm'),
            ])
            ->toolbar([
                Action::openForm('新增', 'uuims.system.uuims-form', [], 'drawer')->can('system:uuims:create')->primary()->icon('plus'),
            ])
            ->rowActions([
                Action::openForm('编辑', 'uuims.system.uuims-form', ['id' => '{id}'], 'drawer')->can('system:uuims:update'),
                Action::openForm('重置密码', 'uuims.system.uuims-password', ['id' => '{id}'])->can('system:uuims:reset-password'),
                Action::request('解绑微信', 'DELETE', '/uuims/api/system/users/{id}/wechat')
                    ->confirm('解绑后 {username} 需重新用账号密码登录小程序并绑定，才能再用微信登录。')->can('system:uuims:unbind-wechat')->pcOnly()->message('已解绑'),
                Action::request('删除', 'DELETE', '/uuims/api/system/users/{id}')
                    ->confirm('确定删除 {username}？')->can('system:uuims:delete')->danger()->message('已删除')
                    ->hiddenWhen('is_super', 'in', [true, 1]),   // the super uuims cannot be deleted (API enforces it too)
            ])
            ->pageSizes([20, 50, 100]);
    }
}
