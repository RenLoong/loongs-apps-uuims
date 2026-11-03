<?php

declare(strict_types=1);

namespace App\Uuims\Enums;

use Loongs\Render\Contract\OptionEnum;

/** user_messages.level — 站内消息级别 (P5). */
enum MessageLevel: string implements OptionEnum
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => '通知',
            self::Success => '成功',
            self::Warning => '提醒',
            self::Error => '告警',
        };
    }

    public function color(): ?string
    {
        return match ($this) {
            self::Info => 'info',
            self::Success => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
        };
    }
}
