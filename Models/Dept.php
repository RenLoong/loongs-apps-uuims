<?php

declare(strict_types=1);

namespace App\Uuims\Models;

use Loongs\Orm\Model\Model;

final class Dept extends Model
{
    protected string $table = 'uuims_depts';
    protected array $fillable = ['parent_id', 'ancestors', 'name', 'sort', 'leader', 'phone', 'status', 'created_by'];
    protected array $casts = ['id' => 'int', 'parent_id' => 'int', 'sort' => 'int', 'status' => 'int'];
}
