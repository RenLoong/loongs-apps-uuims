<?php

declare(strict_types=1);

namespace App\Uuims\Pages\Message;

use App\Uuims\Enums\MessageLevel;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Component\Field;
use Loongs\Render\FormPage;
use Loongs\Render\PageContext;

/**
 * 发送站内消息 (hidden page node, P5; uuims-only: message:send). Recipients are limited to enabled
 * users inside the sender's data scope (GET /uuims/api/messages/recipients; POST /send checks again).
 */
#[AsPage('uuims.message.send-form', permission: 'message:send')]
final class SendMessagePage extends FormPage
{
    public function build(PageContext $ctx): Form
    {
        return Form::make('发送消息')
            ->submit('POST', '/uuims/api/messages/send')
            ->columns(1)
            ->fields([
                Field::switch('all', '发送给全部管理员')->default(false)->help('仅限数据权限范围内的启用管理员'),
                Field::select('user_ids', '接收人')->multiple()->optionsApi('/uuims/api/messages/recipients')->visibleWhen('all', 'in', [false, 0, null]),
                Field::text('title', '标题')->required()->length(null, 128),
                Field::radio('level', '级别')->options(MessageLevel::class)->default('info'),
                Field::textarea('content', '内容')->length(null, 5000),
                Field::text('link', '跳转链接')->help('可选，应用内路径，如 /p/uuims.sample.tenant-detail?id=1001'),
            ]);
    }
}
