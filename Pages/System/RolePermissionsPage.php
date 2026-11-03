<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use App\Uuims\Enums\DataScope;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Custom;
use Loongs\Render\CustomPage;
use Loongs\Render\PageContext;
use Loongs\Render\Support\Options;

/**
 * 角色权限 — registered custom page (RENDER.md §9): the client component role-permission-editor
 * (lib/features/system/role_permission_editor.dart) edits the menu / button permission tree, the data
 * scope (with the custom department tree) and the field permissions of one role (?id=), then saves them
 * with a partial PUT /uuims/api/system/roles/{id} {permissions, data_scope, field_perms}. The server
 * still checks every grant (cannot grant more than the current uuims has).
 */
#[AsPage('uuims.system.role-permissions', permission: 'system:role:update')]
final class RolePermissionsPage extends CustomPage
{
    public function build(PageContext $ctx): Custom
    {
        return Custom::make('角色权限')
            ->breadcrumb(['系统管理', '角色', '权限'])
            ->pcOnly()
            ->component('role-permission-editor')
            ->config([
                'load' => '/uuims/api/system/roles/{id}',
                'save' => '/uuims/api/system/roles/{id}',
                'menus' => '/uuims/api/system/menus',
                'depts' => '/uuims/api/system/depts',
                'fields' => '/uuims/api/system/fields',
                'scopes' => Options::fromEnum(DataScope::class, 'scopes'),
                'fieldPerms' => [
                    ['value' => '', 'label' => '默认'],
                    ['value' => 'hidden', 'label' => '隐藏'],
                    ['value' => 'masked', 'label' => '脱敏'],
                    ['value' => 'readonly', 'label' => '只读'],
                    ['value' => 'editable', 'label' => '可编辑'],
                ],
            ]);
    }
}
