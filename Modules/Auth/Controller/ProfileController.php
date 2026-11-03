<?php

declare(strict_types=1);

namespace App\Uuims\Modules\Auth\Controller;

use App\Uuims\Models\Uuims;
use App\Uuims\Models\Dept;
use App\Uuims\Services\PermissionService;
use App\Uuims\Services\TokenService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\I18n;
use App\Uuims\Support\Validator;
use Loongs\Http\Request;
use Loongs\Http\Response;

/** Current uuims: profile + permission codes, menu tree, own password. */
final class ProfileController
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    public function me(Request $request): Response
    {
        $ctx = UuimsContext::require();
        $uuims = Uuims::query()->withoutGlobalScope('data_scope')->findOrFail($ctx['id']);
        $dept = $uuims->dept_id ? Dept::query()->find((int) $uuims->dept_id) : null;

        return Api::ok([
            'id' => (int) $uuims->id,
            'username' => $uuims->username,
            'nickname' => $uuims->nickname,
            'mobile' => $uuims->mobile,
            'email' => $uuims->email,
            'dept' => $dept === null ? null : ['id' => (int) $dept->id, 'name' => $dept->name],
            'is_super' => $ctx['is_super'],
            'roles' => $ctx['roles'],
            'permissions' => $ctx['is_super'] ? ['*'] : $ctx['perms'],
            'data_scope' => $ctx['data'],
            'fields' => $ctx['is_super'] ? new \stdClass() : $ctx['fields'],
            'last_login_at' => $uuims->last_login_at,
        ]);
    }

    public function menus(Request $request): Response
    {
        return Api::ok(I18n::tree($this->permissions->menuTree(UuimsContext::require())));   // names in the request locale
    }

    /** Change own password → every token of this uuims is revoked (log in again). */
    public function password(Request $request): Response
    {
        $in = Validator::check(Api::input($request), ['old_password' => 'required|string', 'new_password' => 'required|string|min:8|max:72'],
            ['old_password' => '原密码', 'new_password' => '新密码']);
        $uuims = Uuims::query()->withoutGlobalScope('data_scope')->findOrFail(UuimsContext::require()['id']);
        if (!password_verify($in['old_password'], (string) $uuims->getAttribute('password'))) {
            throw new ApiException(42203, '原密码错误', 422);
        }
        $uuims->forceFill(['password' => Uuims::hashPassword($in['new_password']), 'password_changed_at' => date('Y-m-d H:i:s')])->save();
        $revoked = TokenService::revokeAll((int) $uuims->id);

        return Api::ok(['revoked_tokens' => $revoked], '密码已修改，请重新登录');
    }
}
