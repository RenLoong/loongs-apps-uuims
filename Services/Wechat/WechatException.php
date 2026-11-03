<?php

declare(strict_types=1);

namespace App\Uuims\Services\Wechat;

/** code2Session failure. $kind: invalid_code | not_configured | upstream. Message is user-facing (Chinese). */
final class WechatException extends \RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $wxErrcode = null,
    ) {
        parent::__construct($message);
    }
}
