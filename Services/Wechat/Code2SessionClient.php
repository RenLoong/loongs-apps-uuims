<?php

declare(strict_types=1);

namespace App\Uuims\Services\Wechat;

/**
 * WeChat mini program login: wx.login() code → openid (auth.code2Session). Implementations:
 * HttpCode2SessionClient (api.weixin.qq.com) and MockCode2SessionClient (tests / dev without an AppID).
 */
interface Code2SessionClient
{
    /** @throws WechatException invalid / used code, not configured, upstream failure */
    public function exchange(string $code): WechatSession;

    /** AppID the openids belong to (bindings are per AppID). */
    public function appId(): string;
}
