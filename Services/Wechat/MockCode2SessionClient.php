<?php

declare(strict_types=1);

namespace App\Uuims\Services\Wechat;

/**
 * Offline code2Session for tests and development without a real AppID (WECHAT_MINI_MOCK=true; refused
 * when APP_ENV=production). A code "mock:<openid>" logs in as that openid; "mock-invalid" behaves like
 * WeChat's errcode 40029; anything else is invalid too.
 */
final class MockCode2SessionClient implements Code2SessionClient
{
    public function __construct(
        private readonly string $appId = 'mock-appid',
    ) {
    }

    public function appId(): string
    {
        return $this->appId;
    }

    public function exchange(string $code): WechatSession
    {
        if (preg_match('/^mock:([A-Za-z0-9_\-]{6,64})$/', $code, $m) !== 1) {
            throw new WechatException('invalid_code', '微信登录凭证无效或已使用，请重试', 40029);
        }

        return new WechatSession('mock_' . $m[1]);
    }
}
