<?php

declare(strict_types=1);

namespace App\Uuims\Models;

use Loongs\Orm\Model\Model;

final class ResourceField extends Model
{
    public bool $timestamps = false;
    protected string $table = 'uuims_resource_fields';
    protected array $fillable = ['resource', 'field', 'label', 'default_perm'];
    protected array $casts = ['id' => 'int'];
}
