<?php

declare(strict_types=1);

namespace App\Uuims\Services\Wechat;

/** Result of code2Session. The session_key is deliberately not kept (not needed for login). */
final readonly class WechatSession
{
    public function __construct(
        public string $openid,
        public ?string $unionid = null,
    ) {
    }
}
