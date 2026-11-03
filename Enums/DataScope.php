<?php

declare(strict_types=1);

namespace App\Uuims\Enums;

use Loongs\Render\Contract\OptionEnum;

/** role_data_scope.scope. The role permission editor gets the same list through its page config. */
enum DataScope: string implements OptionEnum
{
    case All = 'all';
    case Custom = 'custom';
    case DeptAndChild = 'dept_and_child';
    case Dept = 'dept';
    case Self = 'self';

    public function label(): string
    {
        return match ($this) {
            self::All => '全部数据',
            self::Custom => '自定义部门',
            self::DeptAndChild => '本部门及以下',
            self::Dept => '本部门',
            self::Self => '仅本人',
        };
    }

    public function color(): ?string
    {
        return match ($this) {
            self::All => 'success',
            self::Custom => 'warning',
            default => null,
        };
    }
}
