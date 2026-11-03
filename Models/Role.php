<?php

declare(strict_types=1);

namespace App\Uuims\Models;

use Loongs\Orm\Model\Model;

final class Role extends Model
{
    protected string $table = 'uuims_roles';
    protected array $fillable = ['code', 'name', 'sort', 'status', 'remark', 'created_by'];
    protected array $casts = ['id' => 'int', 'sort' => 'int', 'status' => 'int'];
}
