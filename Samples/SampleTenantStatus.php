<?php

declare(strict_types=1);

namespace App\Uuims\Samples;

use Loongs\Render\Contract\OptionEnum;

/** SAMPLE (R3): tenant status of the sample tenant detail page. The real enum arrives with the tenant module (P2). */
enum SampleTenantStatus: int implements OptionEnum
{
    case Trial = 0;
    case Active = 1;
    case Expired = 2;
    case Suspended = 3;

    public function label(): string
    {
        return match ($this) {
            self::Trial => '试用中',
            self::Active => '正常',
            self::Expired => '已到期',
            self::Suspended => '已停用',
        };
    }

    public function color(): ?string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Expired => 'warning',
            self::Suspended => 'danger',
        };
    }
}
