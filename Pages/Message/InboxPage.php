<?php

declare(strict_types=1);

namespace App\Uuims\Pages\Message;

use App\Uuims\Enums\MessageLevel;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 消息（站内消息收件箱, P5) — PC menu /messages. The API always returns the CURRENT uuims's own
 * messages (GET /uuims/api/messages, login only); the permission code message:list only controls
 * the PC menu entry. The mobile app has native 消息 pages on the same API.
 */
#[AsPage('uuims.message.inbox', permission: 'message:list')]
final class InboxPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('消息')
            ->breadcrumb(['消息'])
            ->api('/uuims/api/messages')
            ->rowKey('id')
            ->search([
                Field::text('keyword', '标题/内容'),
                Field::select('status', '状态')->options(['unread' => '未读', 'read' => '已读']),
                Field::select('level', '级别')->options(MessageLevel::class),
            ])
            ->columns([
                Column::text('title', '标题')->mobile(),
                Column::tag('level', '级别')->enum(MessageLevel::class)->width(90)->mobile(),
                Column::text('sender_name', '发送人')->width(120),
                Column::bool('read', '已读')->width(80),
                Column::datetime('created_at', '时间')->format('yyyy-MM-dd HH:mm')->width(160)->mobile(),
            ])
            ->toolbar([
                Action::openForm('发送消息', 'uuims.message.send-form', [], 'drawer')->can('message:send')->primary()->icon('send'),
                Action::request('全部已读', 'PUT', '/uuims/api/messages/read-all')->confirm('将所有未读消息标为已读？')->then('refresh'),
            ])
            ->rowActions([
                Action::openDetail('查看', 'uuims.message.detail', ['id' => '{id}'], 'drawer'),
                Action::request('标为已读', 'PUT', '/uuims/api/messages/{id}/read')->then('refresh')->hiddenWhen('read', 'eq', true),
            ])
            ->pageSizes([20, 50, 100]);
    }
}
