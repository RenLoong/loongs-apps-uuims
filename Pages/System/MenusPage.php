<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Column;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 菜单（只读） tree (R4, replaces the hand-written menus page): the merged apps/*\/loongs.json tree from
 * GET /uuims/api/system/menus; menus are edited in those files, not here.
 */
#[AsPage('uuims.system.menus', permission: 'system:menu:list')]
final class MenusPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('菜单（只读）')
            ->breadcrumb(['系统管理', '菜单'])
            ->api('/uuims/api/system/menus')
            ->rowKey('perms')
            ->tree('children', 'name')
            ->notice("来源：{files}　·　权限标识 {codes} 个　·　版本 {hash}\n菜单与按钮在各应用的 loongs.json 中维护，修改后重启服务生效。", '/uuims/api/system/menus/sources')
            ->columns([
                Column::text('name', '名称')->mobile(),
                Column::tag('type', '类型')->options([
                    ['value' => 'dir', 'label' => '目录'],
                    ['value' => 'menu', 'label' => '菜单', 'color' => 'success'],
                    ['value' => 'button', 'label' => '按钮', 'color' => 'warning'],
                    ['value' => 'page', 'label' => '页面'],
                ])->mobile(),
                Column::text('path', '路由'),
                Column::text('perms', '权限标识')->mobile(),
                Column::text('page', '渲染页面'),
                Column::text('app', '应用'),
                Column::text('icon', '图标'),
                Column::text('sort', '排序')->width(70),
            ]);
    }
}
