<?php

declare(strict_types=1);

namespace App\Uuims\Modules\Message\Controller;

use App\Uuims\Enums\MessageLevel;
use App\Uuims\Models\Uuims;
use App\Uuims\Services\MessageService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\I18n;
use App\Uuims\Support\Validator;
use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * 站内消息 API (P5). Inbox: the current uuims's own messages only (list / unread count / detail /
 * mark read / mark all read). Send: message:send; recipients must be enabled users inside the
 * sender's data scope (resolved through the Uuims model, which applies the data scope).
 */
final class MessageController
{
    private const LABELS = ['title' => '标题', 'content' => '内容', 'level' => '级别', 'user_ids' => '接收人', 'link' => '跳转链接', 'all' => '全部管理员'];

    private MessageService $messages;

    public function __construct()
    {
        $this->messages = new MessageService();
    }

    /** GET ?page=&page_size=&status=unread|read&level=&keyword= → {list,total,page,page_size,unread} */
    public function index(Request $request): Response
    {
        [$page, $size] = Api::pagination($request);
        $me = $this->me();
        $p = $this->messages->list($me, $page, $size, [
            'status' => (string) $request->query('status', ''),
            'level' => (string) $request->query('level', ''),
            'keyword' => (string) $request->query('keyword', ''),
        ]);
        $senders = MessageService::senderNames($p->items());

        return Api::page($p, static fn (array $r): array => MessageService::present($r, $senders), ['unread' => $this->messages->unreadCount($me)]);
    }

    public function unreadCount(Request $request): Response
    {
        return Api::ok(['unread' => $this->messages->unreadCount($this->me())]);
    }

    /** GET {id} — does NOT mark as read (clients call PUT {id}/read explicitly). */
    public function show(Request $request): Response
    {
        $row = $this->messages->find($this->me(), Api::id($request)) ?? throw ApiException::notFound('消息不存在');

        return Api::ok(MessageService::present($row, MessageService::senderNames([$row])));
    }

    public function read(Request $request): Response
    {
        $me = $this->me();
        $this->messages->markRead($me, Api::id($request)) || throw ApiException::notFound('消息不存在');

        return Api::ok(['unread' => $this->messages->unreadCount($me)], '已读');
    }

    public function readAll(Request $request): Response
    {
        $me = $this->me();
        $n = $this->messages->markAllRead($me);

        return Api::ok(['updated' => $n, 'unread' => 0], $n > 0 ? I18n::t('已将 {n} 条消息标为已读', ['n' => $n]) : '没有未读消息');
    }

    /** Recipient options for the send form: enabled users in the sender's data scope → [{value, label}]. */
    public function recipients(Request $request): Response
    {
        $q = Uuims::query()->where('status', 1);
        $kw = trim((string) $request->query('keyword', ''));
        if ($kw !== '') {
            $q->where(static fn ($w) => $w->where('username', 'like', "%{$kw}%")->orWhere('nickname', 'like', "%{$kw}%"));
        }
        $out = [];
        foreach ($q->orderBy('id')->limit(200)->get() as $a) {
            $out[] = ['value' => (int) $a->id, 'label' => $a->nickname !== '' && $a->nickname !== null ? "{$a->nickname}（{$a->username}）" : (string) $a->username];
        }

        return Api::ok($out);
    }

    /** POST {user_ids: int[] | all: true, title, content?, level?, link?} → {sent} */
    public function send(Request $request): Response
    {
        $input = Api::input($request);
        $in = Validator::check($input, [
            'title' => 'required|string|max:128',
            'content' => 'nullable|string|max:5000',
            'level' => 'nullable|string|in:' . implode(',', array_map(static fn (MessageLevel $l): string => $l->value, MessageLevel::cases())),
            'link' => 'nullable|string|max:255|regex:#^/[A-Za-z0-9_./?=&%-]*$#',
            'all' => 'nullable|bool',
            'user_ids' => 'nullable|array',
        ], self::LABELS);
        $all = (bool) ($in['all'] ?? false);
        $scoped = Uuims::query()->where('status', 1);
        if ($all) {
            $ids = array_map('intval', $scoped->pluck('id')->all());
        } else {
            $wanted = Validator::ids($in['user_ids'] ?? []);
            if ($wanted === []) {
                throw ApiException::validation(['user_ids' => '请选择接收人']);
            }
            $ids = array_map('intval', $scoped->whereIn('id', $wanted)->pluck('id')->all());
            $missing = array_values(array_diff($wanted, $ids));
            if ($missing !== []) {
                throw ApiException::validation(['user_ids' => I18n::t('接收人不存在、已停用或不在数据权限范围内：{ids}', ['ids' => implode(', ', $missing)])]);
            }
        }
        if ($ids === []) {
            throw ApiException::validation(['user_ids' => '没有可发送的接收人']);
        }
        $n = $this->messages->send($ids, (string) $in['title'], isset($in['content']) ? (string) $in['content'] : null,
            (string) ($in['level'] ?? 'info'), UuimsContext::id(), isset($in['link']) ? (string) $in['link'] : null);

        return Api::ok(['sent' => $n], I18n::t('已发送给 {n} 位管理员', ['n' => $n]));
    }

    private function me(): int
    {
        return UuimsContext::id() ?? throw ApiException::unauthorized();
    }
}
