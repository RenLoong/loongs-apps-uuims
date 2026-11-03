<?php

declare(strict_types=1);

namespace App\Uuims\Models;

use App\Uuims\Models\Concerns\HasDataScope;
use App\Uuims\Models\Concerns\HasFieldPermissions;
use Loongs\Orm\Model\Model;

/** Platform administrator (user_platform.users). */
final class Uuims extends Model
{
    use HasDataScope;
    use HasFieldPermissions;

    protected string $table = 'uuims_users';
    protected string $fieldResource = 'uuims';
    protected array $fillable = ['username', 'password', 'nickname', 'mobile', 'email', 'dept_id', 'status', 'is_super',
        'last_login_at', 'last_login_ip', 'password_changed_at', 'created_by'];
    protected array $hidden = ['password'];
    protected array $casts = ['id' => 'int', 'dept_id' => 'int', 'status' => 'int', 'is_super' => 'bool', 'created_by' => 'int'];

    /** Users: "self" data scope = the uuims's own record. */
    public function dataScopeColumns(): array
    {
        return ['dept' => 'dept_id', 'owner' => 'id'];
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }
}
