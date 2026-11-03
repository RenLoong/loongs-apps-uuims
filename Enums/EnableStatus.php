<?php

declare(strict_types=1);

namespace App\Uuims\Enums;

use Loongs\Render\Contract\OptionEnum;

/** roles.status / depts.status (1 启用, 0 停用) — same labels as the former hand-written pages. */
enum EnableStatus: int implements OptionEnum
{
    case Enabled = 1;
    case Disabled = 0;

    public function label(): string
    {
        return match ($this) {
            self::Enabled => '启用',
            self::Disabled => '停用',
        };
    }

    public function color(): ?string
    {
        return match ($this) {
            self::Enabled => 'success',
            self::Disabled => 'danger',
        };
    }
}
