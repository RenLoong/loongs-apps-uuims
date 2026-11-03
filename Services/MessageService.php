<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use App\Uuims\Enums\MessageLevel;
use App\Uuims\Support\I18n;
use Loongs\Orm\Orm;
use Loongs\Orm\Pagination\Paginator;

/**
 * 站内消息 (P5): one user_messages row per recipient. No push channel yet (App push / 订阅消息
 * come later); clients poll the unread count. Usable from any backend code:
 *
 *   (new MessageService())->send([12, 15], '租户即将到期', '杭州示例科技 3 天后到期', 'warning');
 *
 * Recipient validation (existing uuims, data scope) is the caller's job — the uuims send
 * endpoint (MessageController::send) resolves recipients through the Uuims model (data scope).
 */
final class MessageService
{
    public const TABLE = 'uuims_user_messages';

    /**
     * @param list<int> $userIds recipients
     * @return int number of messages written
     */
    public function send(array $userIds, string $title, ?string $content = null, string $level = 'info', ?int $senderId = null, ?string $link = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $level = MessageLevel::tryFrom($level)?->value ?? MessageLevel::Info->value;
        $now = date('Y-m-d H:i:s');
        foreach (array_chunk($ids, 500) as $chunk) {
            Orm::table(self::TABLE)->insert(array_map(static fn (int $id): array => [
                'user_id' => $id, 'sender_id' => $senderId, 'title' => mb_substr($title, 0, 128), 'content' => $content,
                'level' => $level, 'link' => $link, 'read_at' => null, 'created_at' => $now,
            ], $chunk));
        }

        return count($ids);
    }

    /** @param array{status?: string, keyword?: string, level?: string} $filters status: unread | read */
    public function list(int $userId, int $page, int $size, array $filters = []): Paginator
    {
        $q = Orm::table(self::TABLE)->where('user_id', $userId);
        $status = $filters['status'] ?? '';
        if ($status === 'unread') {
            $q->whereNull('read_at');
        } elseif ($status === 'read') {
            $q->whereNotNull('read_at');
        }
        if (($level = $filters['level'] ?? '') !== '' && MessageLevel::tryFrom($level) !== null) {
            $q->where('level', $level);
        }
        if (($kw = trim($filters['keyword'] ?? '')) !== '') {
            $q->where(static fn ($w) => $w->where('title', 'like', "%{$kw}%")->orWhere('content', 'like', "%{$kw}%"));
        }

        return $q->orderBy('id', 'desc')->paginate($size, $page);
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId, int $id): ?array
    {
        return Orm::table(self::TABLE)->where('user_id', $userId)->where('id', $id)->first();
    }

    public function unreadCount(int $userId): int
    {
        return Orm::table(self::TABLE)->where('user_id', $userId)->whereNull('read_at')->count();
    }

    /** @return bool false when the message does not exist / belongs to someone else */
    public function markRead(int $userId, int $id): bool
    {
        if ($this->find($userId, $id) === null) {
            return false;
        }
        Orm::table(self::TABLE)->where('user_id', $userId)->where('id', $id)->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** @return int messages newly marked as read */
    public function markAllRead(int $userId): int
    {
        return Orm::table(self::TABLE)->where('user_id', $userId)->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    /** Row → API shape (ids as int, sender name, read flag). @param array<string, mixed> $row @param array<int, string> $senders */
    public static function present(array $row, array $senders = []): array
    {
        $sender = $row['sender_id'] !== null ? (int) $row['sender_id'] : null;

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'content' => $row['content'],
            'level' => (string) $row['level'],
            'link' => $row['link'],
            'sender_id' => $sender,
            'sender_name' => $sender === null ? I18n::t('系统') : ($senders[$sender] ?? "#{$sender}"),
            'read' => $row['read_at'] !== null,
            'read_at' => $row['read_at'],
            'created_at' => $row['created_at'],
        ];
    }

    /** @param iterable<array<string, mixed>> $rows @return array<int, string> sender id → nickname / username */
    public static function senderNames(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['sender_id'] ?? 0), $rows))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Orm::table('uuims_users')->whereIn('id', $ids)->select('id', 'username', 'nickname')->get()->all() as $a) {
            $out[(int) $a['id']] = (string) ($a['nickname'] !== '' && $a['nickname'] !== null ? $a['nickname'] : $a['username']);
        }

        return $out;
    }
}
