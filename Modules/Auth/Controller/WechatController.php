<?php

declare(strict_types=1);

namespace App\Uuims\Modules\Auth\Controller;

use App\Uuims\Models\Uuims;
use App\Uuims\Services\Wechat\WechatBindings;
use App\Uuims\Services\Wechat\WechatException;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\Validator;
use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * WeChat mini program binding of the signed-in uuims (password login first, then bind), and
 * unbinding by an administrator (system:uuims:unbind-wechat, data scope applies).
 */
final class WechatController
{
    public function __construct(
        private readonly WechatBindings $bindings,
    ) {
    }

    /** GET /uuims/api/auth/wechat → binding state of the current uuims (openid never returned). */
    public function status(Request $request): Response
    {
        $row = $this->bindings->forUuims((int) UuimsContext::require()['id']);

        return Api::ok([
            'bound' => $row !== null,
            'appid' => $this->bindings->client()->appId() !== '' ? $this->bindings->client()->appId() : null,
            'bound_at' => $row['created_at'] ?? null,
            'last_login_at' => $row['last_login_at'] ?? null,
        ]);
    }

    /** POST /uuims/api/auth/wechat/bind {code} — code from wx.login() in the mini program. */
    public function bind(Request $request): Response
    {
        $in = Validator::check(Api::input($request), ['code' => 'required|string|max:128'], ['code' => '微信登录凭证']);
        try {
            $r = $this->bindings->bind((int) UuimsContext::require()['id'], (string) $in['code']);
        } catch (WechatException $e) {
            throw $e->kind === 'invalid_code' ? ApiException::validation(['code' => $e->getMessage()]) : new ApiException(50302, $e->getMessage(), 503);
        }

        return Api::ok(['bound' => true, 'bound_at' => $r['bound_at']], $r['created'] ? '已绑定微信，下次可直接微信登录' : '此微信已绑定当前账号');
    }

    /** DELETE /uuims/api/auth/wechat/bind — unbind own WeChat. */
    public function unbind(Request $request): Response
    {
        $n = $this->bindings->unbind((int) UuimsContext::require()['id']);

        return Api::ok(['unbound' => $n > 0], $n > 0 ? '已解绑微信' : '当前账号未绑定微信');
    }

    /** DELETE /uuims/api/system/users/{id}/wechat */
    public function unbindUuims(Request $request): Response
    {
        $uuims = Uuims::query()->find(Api::id($request)) ?? throw ApiException::notFound();   // data scope
        if ($uuims->is_super && !UuimsContext::require()['is_super']) {
            throw ApiException::forbidden('不能操作超级管理员', 40305);
        }
        $n = $this->bindings->unbind((int) $uuims->id);

        return Api::ok(['unbound' => $n > 0], $n > 0 ? '已解绑微信' : '该管理员未绑定微信');
    }
}
