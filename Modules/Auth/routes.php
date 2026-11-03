<?php

declare(strict_types=1);

use App\Uuims\Middleware\UuimsAuthMiddleware;
use App\Uuims\Modules\Auth\Controller\LoginPage;
use App\Uuims\Modules\Auth\Controller\ProfileController;
use App\Uuims\Modules\Auth\Controller\WechatController;
use App\Uuims\Services\LoginGuard;
use App\Uuims\Support\OAuthServers;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\OAuth\AuthorizationServer;
use Loongs\OAuth\Framework\Bridge;
use Loongs\OAuth\Framework\OAuthRoutes;
use Loongs\OAuth\Http\OAuthResponse;

/** @var \Loongs\Routing\Router $router */
/** @var \Loongs\Container\Container $container */

/** @var OAuthServers $servers per public origin (intranet: LAN IP / internal hostname), see PublicOrigin */
$servers = $container->make(OAuthServers::class);
$resolve = static fn (Request $r): AuthorizationServer => $servers->forRequest($r);
/** @var list<string> $jsonUris */
$jsonUris = array_values((array) $container->make(\Loongs\Config\Repository::class)->get('uuims.oauth.json_redirect_uris', []));

// Async captcha refresh for the HTML login page (no full authorize round-trip).
$router->get('/oauth/captcha', static function () use ($container): Response {
    /** @var LoginGuard $guard */
    $guard = $container->make(LoginGuard::class);
    $c = $guard->issue(false);
    $body = (string) json_encode([
        'login_nonce' => $c['nonce'],
        'captcha' => 'data:image/svg+xml;base64,' . base64_encode($c['svg']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return (new Response())
        ->raw($body, 'application/json; charset=utf-8')
        ->header('Cache-Control', 'no-store')
        ->header('Pragma', 'no-cache');
});

// Authorization endpoint with the login step (registered before OAuthRoutes so it wins the route
// match; the handler also gets the framework Request for the client IP / Accept header).
$authorize = static function (Request $r) use ($resolve, $container, $jsonUris) {
    /** @var LoginPage $page */
    $page = $container->make(LoginPage::class);
    $oreq = Bridge::request($r);
    $res = $resolve($r)->authorize($oreq, static fn ($ar, $req, $as) => $page->handle($ar, $req, $as, $r));

    // JSON mode (mini program): turn the final redirect to a JSON redirect URI into a JSON body.
    $redirectUri = (string) ($oreq->param('redirect_uri', $oreq->method === 'POST' ? 'post' : 'query') ?? '');
    $location = (string) $res->header('Location');
    if (in_array($res->status, [302, 303], true) && in_array($redirectUri, $jsonUris, true)
        && str_contains(strtolower((string) $r->header('accept')), 'application/json')
        && str_starts_with($location, $redirectUri . '?')) {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $keep = array_intersect_key($q, array_flip(['code', 'state', 'iss', 'error', 'error_description']));
        $res = OAuthResponse::json($keep, isset($keep['error']) ? 400 : 200);
    }

    $out = Bridge::response($res);
    // Remember-account Set-Cookie (set / clear) stashed by LoginPage on the request attribute.
    $cookie = $r->getAttribute('uuims_set_cookie');
    if (is_string($cookie) && $cookie !== '') {
        $out->header('Set-Cookie', $cookie);
    }

    return $out;
};
$router->get('/oauth/authorize', $authorize);
$router->post('/oauth/authorize', $authorize);

// token (authorization_code + PKCE, refresh_token rotation, WeChat mini program grant), revoke,
// introspect, RFC 8414 metadata — each answered by the server of the request's public origin
OAuthRoutes::register($router, $resolve, static fn () => throw new LogicException('authorize is handled above'), $container->make(AuthorizationServer::class));

$router->get('/uuims/api/auth/me', [ProfileController::class, 'me'], [UuimsAuthMiddleware::class]);
$router->get('/uuims/api/auth/menus', [ProfileController::class, 'menus'], [UuimsAuthMiddleware::class]);
$router->put('/uuims/api/auth/password', [ProfileController::class, 'password'], [UuimsAuthMiddleware::class]);

// WeChat mini program binding of the signed-in uuims (bind after a password login; see WechatMiniGrant)
$router->get('/uuims/api/auth/wechat', [WechatController::class, 'status'], [UuimsAuthMiddleware::class]);
$router->post('/uuims/api/auth/wechat/bind', [WechatController::class, 'bind'], [UuimsAuthMiddleware::class]);
$router->delete('/uuims/api/auth/wechat/bind', [WechatController::class, 'unbind'], [UuimsAuthMiddleware::class]);
