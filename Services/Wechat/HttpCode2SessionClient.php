<?php

declare(strict_types=1);

namespace App\Uuims\Services\Wechat;

use App\Uuims\Support\I18n;

/**
 * auth.code2Session over HTTPS (https://api.weixin.qq.com/sns/jscode2session). Coroutine-friendly:
 * file_get_contents is hooked by Swoole (SWOOLE_HOOK_STREAM_FUNCTION | SSL) in the workers.
 * AppID / AppSecret: WECHAT_MINI_APPID / WECHAT_MINI_SECRET in .env (never logged or returned).
 */
final class HttpCode2SessionClient implements Code2SessionClient
{
    private const URL = 'https://api.weixin.qq.com/sns/jscode2session';

    /** @var \Closure(string): array{0: int, 1: string}|null test seam: url → [status, body] */
    private ?\Closure $transport;

    public function __construct(
        private readonly string $appId,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly float $timeout = 5.0,
        ?\Closure $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function appId(): string
    {
        return $this->appId;
    }

    public function exchange(string $code): WechatSession
    {
        if ($this->appId === '' || $this->secret === '') {
            throw new WechatException('not_configured', '服务器未配置微信小程序 AppID / AppSecret（WECHAT_MINI_APPID / WECHAT_MINI_SECRET）');
        }
        if (preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $code) !== 1) {
            throw new WechatException('invalid_code', '微信登录凭证无效，请重试');
        }
        $url = self::URL . '?' . http_build_query(['appid' => $this->appId, 'secret' => $this->secret, 'js_code' => $code, 'grant_type' => 'authorization_code']);
        [$status, $body] = $this->transport !== null ? ($this->transport)($url) : $this->get($url);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new WechatException('upstream', '微信服务暂时不可用，请稍后重试');
        }
        $err = (int) ($data['errcode'] ?? 0);
        if ($err !== 0) {
            // 40029 invalid code, 40163 code been used, 45011 frequency limit, 40226 risky user
            $msg = match ($err) {
                40029, 40163 => '微信登录凭证无效或已使用，请重试',
                45011 => '微信登录过于频繁，请稍后再试',
                40226 => '该微信账号存在风险，已被微信拦截',
                default => I18n::t('微信登录失败（errcode {errcode}）', ['errcode' => $err]),
            };
            throw new WechatException(in_array($err, [40029, 40163], true) ? 'invalid_code' : 'upstream', $msg, $err);
        }
        $openid = (string) ($data['openid'] ?? '');
        if ($openid === '') {
            throw new WechatException('upstream', '微信服务返回异常（缺少 openid）');
        }

        return new WechatSession($openid, isset($data['unionid']) && $data['unionid'] !== '' ? (string) $data['unionid'] : null);
    }

    /** @return array{0: int, 1: string} */
    private function get(string $url): array
    {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $this->timeout, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$body === false ? 0 : $status, $body === false ? '' : $body];
    }
}
