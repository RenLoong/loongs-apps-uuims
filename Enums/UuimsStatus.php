<?php

declare(strict_types=1);

namespace App\Uuims\Enums;

use Loongs\Render\Contract\OptionEnum;

/** users.status — one PHP enum → {value, label, color} for every client (RENDER.md §7). */
enum UuimsStatus: int implements OptionEnum
{
    case Enabled = 1;
    case Disabled = 0;

    public function label(): string
    {
        return match ($this) {
            self::Enabled => '正常',
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
