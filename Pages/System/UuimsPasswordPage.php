<?php

declare(strict_types=1);

namespace App\Uuims\Pages\System;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Field;
use Loongs\Render\FormPage;
use Loongs\Render\PageContext;

/** 重置密码 dialog (hidden page node): PUT /uuims/api/system/users/{id}/password, revokes the uuims's tokens. */
#[AsPage('uuims.system.uuims-password', permission: 'system:uuims:reset-password')]
final class UuimsPasswordPage extends FormPage
{
    public function build(PageContext $ctx): Form
    {
        return Form::make('重置密码')
            ->submit('PUT', '/uuims/api/system/users/{id}/password')
            ->fields([
                Field::password('password', '新密码')->required()->length(8, 72)->help('重置后该管理员的登录令牌全部失效'),
            ])
            ->actions([Action::submit('重置')->message('密码已重置'), Action::close()]);
    }
}
