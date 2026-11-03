<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use App\Uuims\Enums\UuimsStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Component\Field;
use Loongs\Render\Component\Section;
use Loongs\Render\FormPage;
use Loongs\Render\PageContext;

/**
 * 新增 / 编辑管理员 (hidden page node in loongs.json). With ?id= the client loads the record and submits
 * PUT; without it POST. Field permissions of resource "uuims" (mobile, email) apply.
 */
#[AsPage('uuims.system.uuims-form', permission: ['system:uuims:create', 'system:uuims:update'])]
final class UuimsFormPage extends FormPage
{
    public function build(PageContext $ctx): Form
    {
        $edit = (int) $ctx->param('id', 0) > 0;
        $account = [
            Field::text('username', '用户名')->required()->regex('^[A-Za-z0-9_]{3,32}$', '3–32 位字母、数字或下划线')->readonly($edit)->span(12),
        ];
        if (!$edit) {
            $account[] = Field::password('password', '初始密码')->required()->length(8, 72)->span(12);
        }

        return Form::make($edit ? '编辑管理员' : '新增管理员')
            ->resource('uuims')
            ->load('/uuims/api/system/users/{id}')
            ->submit('POST', '/uuims/api/system/users', 'create')
            ->submit('PUT', '/uuims/api/system/users/{id}', 'update')
            ->columns(2)
            ->sections([
                Section::make('账号', [
                    ...$account,
                    Field::text('nickname', '昵称')->length(null, 64)->span(12),
                    Field::radio('status', '状态')->options(UuimsStatus::class)->default(1)->span(12),
                ]),
                Section::make('联系方式', [
                    Field::text('mobile', '手机号')->length(null, 32)->span(12),
                    Field::text('email', '邮箱')->email()->span(12),
                ]),
                Section::make('组织与角色', [
                    Field::treeSelect('dept_id', '部门')->optionsApi('/uuims/api/system/depts')->span(12),
                    Field::select('role_ids', '角色')->multiple()->optionsApi('/uuims/api/system/roles/options')->help('只能分配自己拥有的角色（超级管理员除外）')->span(12),
                ]),
            ]);
    }
}
