<?php

declare(strict_types=1);

/**
 * Uuims app bootstrap + routes (auth-only).
 *
 * Unified user authentication: OAuth login/token, profile, WeChat mini bind,
 * permission context for Auth.check / Auth.can. Module routes under
 * Modules/Auth/routes.php are discovered by the framework; app-wide extras
 * live in apps/Uuims/routes/*.php (loaded here, sorted).
 *
 * Admin console (System / Message / Dashboard / Sample / Render Pages / SPA)
 * has moved to the Admin app — see PR MOVE inventory.
 */

use App\Uuims\Modules\Auth\Controller\LoginPage;
use App\Uuims\Services\LoginGuard;
use App\Uuims\Services\LoginRemember;
use App\Uuims\Services\PermissionService;
use App\Uuims\Services\Wechat\Code2SessionClient;
use App\Uuims\Services\Wechat\HttpCode2SessionClient;
use App\Uuims\Services\Wechat\MockCode2SessionClient;
use App\Uuims\Services\Wechat\WechatBindings;
use App\Uuims\Services\Wechat\WechatMiniGrant;
use App\Uuims\Support\I18n;
use App\Uuims\Support\MenuRegistry;
use App\Uuims\Support\LocalBearer;
use App\Uuims\Support\OAuth;
use App\Uuims\Support\OAuthServers;
use App\Uuims\Support\PublicOrigin;
use Loongs\Cache\CacheManager;
use Loongs\Config\Repository;
use Loongs\Language\Translator;
use Loongs\OAuth\AuthorizationServer;
use Loongs\Support\BasePath;

/** @var \Loongs\Routing\Router $router */
/** @var \Loongs\Http\Application $app */
/** @var \Loongs\Container\Container $container */

$config = $container->make(Repository::class);

if (!method_exists(AuthorizationServer::class, 'registerGrant')) {
    throw new RuntimeException(
        'loongs/oauth is too old for the WeChat mini program grant: run `composer update loongs/framework loongs/oauth`.'
    );
}
if (!method_exists(AuthorizationServer::class, 'localResourceServer')) {
    throw new RuntimeException(
        'loongs/oauth is too old for local (no-DB) JWE auth: update to SHA 18918a533d99ff330bad801580ca5f813aa42331 or later (`composer update loongs/oauth`).'
    );
}

// Languages (loongs/language): packs apps/*/lang/<locale>/*.php|json, fallback en-US;
// request locale is set per coroutine by LocaleMiddleware (apps/Uuims/middleware.php).
$container->singleton(Translator::class, static fn (): Translator => I18n::boot(BasePath::get('apps')));
$container->make(Translator::class);

// Permission codes / sidebar tree: file-only merge of every apps/*/loongs.json.
// Menus main-service aggregation (Menus.register / export) moved to Admin.
$container->singleton(MenuRegistry::class, static fn (): MenuRegistry => MenuRegistry::load(BasePath::get('apps')));

// WeChat mini program: real code2Session with WECHAT_MINI_APPID/SECRET, or the offline mock
// (WECHAT_MINI_MOCK=true, never in APP_ENV=production) for tests.
$container->singleton(Code2SessionClient::class, static function () use ($config): Code2SessionClient {
    $w = (array) $config->get('uuims.wechat_mini', []);
    if (($w['mock'] ?? false) === true && $config->get('app.env') !== 'production') {
        return new MockCode2SessionClient();
    }

    return new HttpCode2SessionClient(
        (string) ($w['appid'] ?? ''),
        (string) ($w['secret'] ?? ''),
        (float) ($w['timeout'] ?? 5),
    );
});
$container->singleton(WechatBindings::class, static fn (): WechatBindings => new WechatBindings(
    $container->make(Code2SessionClient::class),
));

// Per-worker services (no connections held: OrmStorage / models borrow pooled connections per call).
$oauthGrants = static fn (): array => [
    WechatMiniGrant::TYPE => new WechatMiniGrant($container->make(WechatBindings::class)),
];
$container->singleton(PublicOrigin::class, static fn (): PublicOrigin => PublicOrigin::fromConfig(
    (array) $config->get('uuims.oauth', []),
));
$container->singleton(AuthorizationServer::class, static fn (): AuthorizationServer => OAuth::server(
    (array) $config->get('uuims.oauth', []),
    null,
    $oauthGrants(),
));
$container->singleton(OAuthServers::class, static fn (): OAuthServers => new OAuthServers(
    $container->make(PublicOrigin::class),
    static fn (string $issuer): AuthorizationServer => OAuth::server(
        (array) $config->get('uuims.oauth', []),
        $issuer,
        $oauthGrants(),
    ),
    $container->make(AuthorizationServer::class),
));
$container->singleton(LocalBearer::class, static fn (): LocalBearer => new LocalBearer(
    $container->make(OAuthServers::class),
));
$container->singleton(PermissionService::class, static fn (): PermissionService => new PermissionService(
    $container->make(CacheManager::class),
    $container->make(MenuRegistry::class),
    (int) $config->get('uuims.permission_cache_ttl', 600),
));
$container->singleton(LoginGuard::class, static function () use ($config): LoginGuard {
    $cfg = (array) $config->get('uuims.login', []);

    return new LoginGuard(
        (int) ($cfg['max_failures'] ?? 5),
        (int) ($cfg['lock_seconds'] ?? 900),
        (int) ($cfg['captcha_ttl'] ?? 300),
    );
});
$container->singleton(LoginRemember::class, static function () use ($config): LoginRemember {
    $cfg = (array) $config->get('uuims.login', []);

    return new LoginRemember((int) ($cfg['remember_ttl'] ?? 604800));
});
$container->singleton(LoginPage::class, static fn (): LoginPage => new LoginPage(
    $container->make(LoginGuard::class),
    $container->make(LoginRemember::class),
    $container->make(PublicOrigin::class),
    array_values((array) $config->get('uuims.oauth.json_redirect_uris', [])),
));

$files = glob(__DIR__ . '/routes/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    require $file;
}
