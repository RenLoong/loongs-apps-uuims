<?php

declare(strict_types=1);

namespace App\Uuims\Services\Wechat;

use App\Uuims\Support\ApiException;
use Loongs\Orm\Orm;

/**
 * user_wechat: one WeChat openid (per mini program AppID) ↔ one uuims. Created only by an uuims who
 * is already signed in with username + password (POST /uuims/api/auth/wechat/bind); the WeChat grant
 * then only logs in openids found here.
 */
final class WechatBindings
{
    public function __construct(
        private readonly Code2SessionClient $client,
    ) {
    }

    public function client(): Code2SessionClient
    {
        return $this->client;
    }

    /** @return array<string, mixed>|null */
    public function forUuims(int $userId): ?array
    {
        return Orm::table('uuims_user_wechat')->where('user_id', $userId)->where('appid', $this->client->appId())->first();
    }

    /** @return array<string, mixed>|null */
    public function forOpenid(string $openid): ?array
    {
        return Orm::table('uuims_user_wechat')->where('appid', $this->client->appId())->where('openid', $openid)->first();
    }

    /**
     * Bind the WeChat user behind $code to $userId (replacing the uuims's previous binding for this
     * AppID). An openid already bound to another uuims → 42202.
     */
    public function bind(int $userId, string $code): array
    {
        $s = $this->client->exchange($code);
        $appId = $this->client->appId();
        $now = date('Y-m-d H:i:s');

        return Orm::transaction(static function () use ($userId, $s, $appId, $now): array {
            $other = Orm::table('uuims_user_wechat')->where('appid', $appId)->where('openid', $s->openid)->first();
            if ($other !== null && (int) $other['user_id'] !== $userId) {
                throw ApiException::conflict('该微信已绑定其他管理员账号，请先在该账号中解绑');
            }
            if ($other !== null) {
                return ['created' => false, 'bound_at' => $other['created_at']];
            }
            Orm::table('uuims_user_wechat')->where('user_id', $userId)->where('appid', $appId)->delete();
            Orm::table('uuims_user_wechat')->insert(['user_id' => $userId, 'appid' => $appId, 'openid' => $s->openid, 'unionid' => $s->unionid,
                'created_at' => $now, 'last_login_at' => null]);

            return ['created' => true, 'bound_at' => $now];
        });
    }

    public function unbind(int $userId): int
    {
        return (int) Orm::table('uuims_user_wechat')->where('user_id', $userId)->where('appid', $this->client->appId())->delete();
    }

    public function touch(int $id): void
    {
        Orm::table('uuims_user_wechat')->where('id', $id)->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }
}
