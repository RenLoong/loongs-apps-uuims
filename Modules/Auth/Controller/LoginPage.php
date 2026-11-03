<?php

declare(strict_types=1);

namespace App\Uuims\Modules\Auth\Controller;

use App\Uuims\Models\Uuims;
use App\Uuims\Services\LoginGuard;
use App\Uuims\Services\LoginRemember;
use App\Uuims\Support\I18n;
use App\Uuims\Support\PublicOrigin;
use Loongs\Http\Request;
use Loongs\OAuth\Authorization\AuthorizationDecision;
use Loongs\OAuth\Authorization\AuthorizationRequest;
use Loongs\OAuth\AuthorizationServer;
use Loongs\OAuth\Http\OAuthRequest;
use Loongs\OAuth\Http\OAuthResponse;

/**
 * Login step of the authorization endpoint (README §4): server-rendered page with username,
 * password and captcha; posts back to /oauth/authorize with the validated OAuth parameters as hidden
 * fields. First-party public clients → no consent screen; on success the uuims id is approved and the
 * library redirects (303) to the client's redirect_uri with code + state + iss.
 *
 * Trust this browser (opt-in): after a successful HTML password login the page asks whether to trust
 * this browser (no checkbox on the password form). "信任" writes an HttpOnly cookie
 * (uuims_remember, TTL configurable, default 7 days) then continues OAuth; "不信任"
 * clears any remember cookie and continues; "取消" drops the pending login and returns to the
 * password form. Within TTL the authorize page shows the remembered account; "继续授权" continues
 * without password; "切换账号" clears the cookie. Pending trust choice is a short-lived Redis token.
 *
 * CSRF / replay: every form carries a one-time nonce (Redis, 5 min) bound to its captcha (or to the
 * remembered user id for continue). Captcha image can be refreshed asynchronously via GET /oauth/captcha.
 *
 * JSON mode (uni-app mini program / H5, which cannot follow browser redirects): when the request has
 * `Accept: application/json` and the redirect URI is one of uuims.oauth.json_redirect_uris, the same
 * step answers {login: {login_nonce, captcha (PNG data URI), error}} instead of HTML; on success the
 * route converts the library's redirect into {code, state, iss} (routes.php). Still authorization
 * code + PKCE: the code is only redeemable with the client's code_verifier. Remember cookie is HTML-only.
 *
 * Language / theme (README §19): texts and errors in the request locale (LocaleMiddleware:
 * ui_locales / lang / Accept-Language); ui_theme=light|dark forces the page theme (else
 * prefers-color-scheme). Both are carried through the POST as hidden fields.
 */
final class LoginPage
{
    /** Real argon2id hash of a random string: unknown usernames cost the same as wrong passwords. */
    private static ?string $dummyHash = null;

    /** @param list<string> $jsonRedirectUris */
    public function __construct(
        private readonly LoginGuard $guard,
        private readonly LoginRemember $remember,
        private readonly PublicOrigin $origin,
        private readonly array $jsonRedirectUris = [],
    ) {
    }

    /** JSON mode for this request (see class doc). */
    public function wantsJson(AuthorizationRequest $ar, Request $http): bool
    {
        return in_array($ar->redirectUri, $this->jsonRedirectUris, true)
            && str_contains(strtolower((string) $http->header('accept')), 'application/json');
    }

    public function handle(AuthorizationRequest $ar, OAuthRequest $req, AuthorizationServer $as, Request $http): AuthorizationDecision
    {
        $json = $this->wantsJson($ar, $http);
        $theme = (string) ($http->input('ui_theme') ?? $http->query('ui_theme') ?? '');
        $page = fn (string $error = '', string $username = '', int $status = 200, ?array $remembered = null, ?array $trustAsk = null): AuthorizationDecision
            => $this->page($json, $ar, $error, $username, $status, $theme, $remembered, $http, $trustAsk);

        $cookieToken = $this->cookieToken($http);
        $secure = $this->isSecure($http);

        // Switch account: clear remember and show the password form.
        if ($req->param('switch_account', 'post') !== null || $http->query('switch_account') !== null) {
            $this->remember->forget($cookieToken);
            $http->setAttribute('uuims_set_cookie', $this->remember->clearCookieHeader($secure));

            return $page();
        }

        // Trust prompt after password login (HTML only): trust | untrust | cancel.
        if (!$json && $req->method === 'POST' && $req->param('trust_nonce', 'post') !== null) {
            $pending = $this->remember->consumePending((string) $req->param('trust_nonce', 'post'));
            $choice = (string) $req->param('trust_choice', 'post');
            if ($pending === null) {
                return $page('登录状态已失效，请重新登录');
            }
            if ($choice === 'cancel') {
                return $page('', $pending['username']);
            }
            $uuims = Uuims::query()->withoutGlobalScope('data_scope')->find($pending['uid']);
            if ($uuims === null || (int) $uuims->status !== 1) {
                return $page('账号已停用或不存在', $pending['username']);
            }
            if ($choice === 'trust') {
                $this->remember->forget($cookieToken);
                $token = $this->remember->issue((int) $uuims->id, (string) $uuims->username);
                $http->setAttribute('uuims_set_cookie', $this->remember->setCookieHeader($token, $secure));
            } else {
                // untrust (or unknown choice treated as untrust+continue): clear any remember cookie.
                $this->remember->forget($cookieToken);
                $http->setAttribute('uuims_set_cookie', $this->remember->clearCookieHeader($secure));
            }

            return AuthorizationDecision::approve((string) $uuims->id);
        }

        // Continue as remembered account (HTML only).
        if (!$json && $req->method === 'POST' && $req->param('continue_nonce', 'post') !== null) {
            $uid = $this->remember->consumeContinue((string) $req->param('continue_nonce', 'post'));
            $session = $this->remember->read($cookieToken);
            if ($uid === null || $session === null || $session['uid'] !== $uid) {
                $this->remember->forget($cookieToken);
                $http->setAttribute('uuims_set_cookie', $this->remember->clearCookieHeader($secure));

                return $page('登录状态已失效，请重新登录');
            }
            $uuims = Uuims::query()->withoutGlobalScope('data_scope')->find($uid);
            if ($uuims === null || (int) $uuims->status !== 1) {
                $this->remember->forget($cookieToken);
                $http->setAttribute('uuims_set_cookie', $this->remember->clearCookieHeader($secure));

                return $page('账号已停用或不存在');
            }
            $this->remember->touch($cookieToken);

            return AuthorizationDecision::approve((string) $uuims->id);
        }

        // Password + captcha login.
        if ($req->method === 'POST' && $req->param('login_nonce', 'post') !== null) {
            $username = trim((string) $req->param('username', 'post'));
            $password = (string) $req->param('password', 'post');
            $check = $this->guard->consume((string) $req->param('login_nonce', 'post'), (string) $req->param('captcha', 'post'));
            if ($check === 'expired') {
                return $page('页面已过期，请重新输入', $username);
            }
            if ($username === '' || $password === '') {
                return $page('请输入用户名和密码', $username);
            }
            if (($locked = $this->guard->lockedFor($username)) > 0) {
                return $page(I18n::t('登录失败次数过多，请 {minutes} 分钟后再试', ['minutes' => (int) ceil($locked / 60)]), $username, 429);
            }
            if ($check === 'captcha') {
                return $page('验证码错误', $username);
            }

            $uuims = Uuims::query()->withoutGlobalScope('data_scope')->where('username', $username)->first();
            $ok = password_verify($password, $uuims !== null ? (string) $uuims->getAttribute('password') : (self::$dummyHash ??= Uuims::hashPassword(random_bytes(16))));
            if (!$ok || $uuims === null) {
                $left = $this->guard->fail($username);

                return $page($left > 0 ? I18n::t('用户名或密码错误（还可尝试 {left} 次）', ['left' => $left]) : '登录失败次数过多，账号已临时锁定', $username, 401);
            }
            if ((int) $uuims->status !== 1) {
                return $page('账号已停用', $username, 403);
            }
            $this->guard->clear($username);
            if (password_needs_rehash((string) $uuims->getAttribute('password'), PASSWORD_ARGON2ID)) {
                $uuims->forceFill(['password' => Uuims::hashPassword($password)]);
            }
            $uuims->forceFill([
                'last_login_at' => date('Y-m-d H:i:s'),
                'last_login_ip' => (string) ($http->swoole()->server['remote_addr'] ?? ''),
            ])->save();

            // Browser HTML: ask trust before completing authorize (JSON / mini-program skips the ask).
            if (!$json) {
                $trustNonce = $this->remember->issuePending((int) $uuims->id, (string) $uuims->username);

                return $page('', (string) $uuims->username, 200, null, [
                    'username' => (string) $uuims->username,
                    'trust_nonce' => $trustNonce,
                ]);
            }

            return AuthorizationDecision::approve((string) $uuims->id);
        }

        // GET (or POST without login fields): show remembered account or captcha form.
        if (!$json) {
            $session = $this->remember->read($cookieToken);
            if ($session !== null) {
                $uuims = Uuims::query()->withoutGlobalScope('data_scope')->find($session['uid']);
                if ($uuims !== null && (int) $uuims->status === 1) {
                    return $page('', '', 200, [
                        'username' => (string) $uuims->username,
                        'nickname' => (string) ($uuims->nickname ?? ''),
                        'continue_nonce' => $this->remember->issueContinue((int) $uuims->id),
                    ]);
                }
                $this->remember->forget($cookieToken);
                $http->setAttribute('uuims_set_cookie', $this->remember->clearCookieHeader($secure));
            }
        }

        return $page();
    }

    private function cookieToken(Request $http): ?string
    {
        $cookies = $http->swoole()->cookie ?? [];
        $v = $cookies[LoginRemember::COOKIE] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function isSecure(Request $http): bool
    {
        $origin = $this->origin->requestOrigin($http) ?? $this->origin->issuerFor($http);

        return str_starts_with(strtolower($origin), 'https://');
    }

    /**
     * @param array{username: string, nickname: string, continue_nonce: string}|null $remembered
     * @param array{username: string, trust_nonce: string}|null $trustAsk
     */
    private function page(bool $json, AuthorizationRequest $ar, string $error = '', string $username = '', int $status = 200, string $theme = '', ?array $remembered = null, ?Request $http = null, ?array $trustAsk = null): AuthorizationDecision
    {
        $error = I18n::msg($error);
        $locale = I18n::locale();
        if ($json) {
            $captcha = $this->guard->issue(true);

            return AuthorizationDecision::respond(OAuthResponse::json(['login' => [
                'client' => $ar->client->name,
                'login_nonce' => $captcha['nonce'],
                'captcha' => 'data:image/png;base64,' . base64_encode((string) ($captcha['png'] ?? '')),
                'error' => $error !== '' ? $error : null,
                'username' => $username,
                'locale' => $locale,
            ]], $status));
        }

        $needCaptcha = $remembered === null && $trustAsk === null;
        $captcha = $needCaptcha ? $this->guard->issue(false) : null;
        $html = self::render([
            'client' => $ar->client->name,
            'params' => $ar->parameters(),
            'nonce' => $captcha['nonce'] ?? '',
            'captcha' => $captcha !== null ? 'data:image/svg+xml;base64,' . base64_encode($captcha['svg']) : '',
            'error' => $error,
            'username' => $username,
            'locale' => $locale,
            'theme' => in_array($theme, ['light', 'dark'], true) ? $theme : '',
            'languages' => self::languages($ar->parameters(), $theme),
            'remembered' => $remembered,
            'trust_ask' => $trustAsk,
            'captcha_url' => '/oauth/captcha',
        ]);

        return AuthorizationDecision::respond(new OAuthResponse($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            'Referrer-Policy' => 'no-referrer',
            'Content-Language' => $locale,
        ], $html));
    }

    /** Endonyms of the languages with a pack (+ zh-CN); unknown tags show the tag itself. */
    public const LANGUAGE_NAMES = ['zh-CN' => '简体中文', 'zh-TW' => '繁體中文', 'en-US' => 'English', 'en' => 'English', 'ja-JP' => '日本語', 'ko-KR' => '한국어'];

    /**
     * Switcher links: the same authorize request with another ui_locales.
     *
     * @param array<string, mixed> $params
     * @return list<array{locale: string, name: string, href: string}>
     */
    private static function languages(array $params, string $theme): array
    {
        $out = [];
        foreach (I18n::translator()->supported() as $locale) {
            $q = [...$params, 'ui_locales' => $locale];
            if (in_array($theme, ['light', 'dark'], true)) {
                $q['ui_theme'] = $theme;
            }
            $out[] = ['locale' => $locale, 'name' => self::LANGUAGE_NAMES[$locale] ?? $locale, 'href' => '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986)];
        }

        return $out;
    }

    /** @param array<string, mixed> $v */
    private static function render(array $v): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = static fn (string $text, array $params = []): string => I18n::t($text, $params);
        ob_start();
        require __DIR__ . '/../views/login.php';

        return (string) ob_get_clean();
    }
}
