<?php

declare(strict_types=1);

/**
 * Uuims app bootstrap + routes. Module routes (Modules/<Module>/routes.php) are discovered by the
 * framework; app-wide extra routes live in apps/Uuims/routes/*.php (loaded here, sorted).
 */

use App\Uuims\Modules\Auth\Controller\LoginPage;
use App\Uuims\Render\PageService;
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
use App\Uuims\Services\MenusService;
use Loongs\Service\ProviderRegistry;
use Loongs\Rpc\Client\RpcClientInterface;
use App\Uuims\Support\OAuth;
use App\Uuims\Support\OAuthServers;
use App\Uuims\Support\PublicOrigin;
use Loongs\Cache\CacheManager;
use Loongs\Uploads\Uploads;
use Loongs\Config\Repository;
use Loongs\Language\Integration\Render\RenderTranslator;
use Loongs\Language\Translator;
use Loongs\OAuth\AuthorizationServer;
use Loongs\Render\PageRegistry;
use Loongs\Support\BasePath;

/** @var \Loongs\Routing\Router $router */
/** @var \Loongs\Http\Application $app */
/** @var \Loongs\Container\Container $container */

$config = $container->make(Repository::class);

// loongs/uploads: config/uploads.php; runtime switch via Uploads::configure/setDefault/extend
Uploads::configure((array) $config->get('uploads', []));

if (!method_exists(AuthorizationServer::class, 'registerGrant')) {
    throw new RuntimeException('loongs/oauth is too old for the WeChat mini program grant: run `composer update loongs/framework loongs/oauth` (needs oauth ≥ e302cd0, framework ≥ 5386309).');
}

// Languages (loongs/language, README §19): packs apps/*/lang/<locale>/*.php|json, fallback en-US; the
// request locale is set per coroutine by LocaleMiddleware (apps/Uuims/middleware.php).
$container->singleton(Translator::class, static fn (): Translator => I18n::boot(BasePath::get('apps')));
$container->make(Translator::class);

// Menus main service: ProviderRegistry (worker-local) + MenusService aggregator
// (own loongs.json + RPC Menus.export per registered/discovered sub). File-only
// MenuRegistry::load remains for `./loongs uuims:menus` offline validation.
$container->singleton(ProviderRegistry::class, static fn (): ProviderRegistry => new ProviderRegistry());
$container->singleton(MenusService::class, static function () use ($container): MenusService {
    return new MenusService(
        $container->make(ProviderRegistry::class),
        $container->make(RpcClientInterface::class),
        BasePath::get('apps'),
        BasePath::get('apps/Uuims'),
    );
});
$container->singleton(MenuRegistry::class, static function () use ($container): MenuRegistry {
    return $container->make(MenusService::class)->buildRegistry();
});

// WeChat mini program: real code2Session with WECHAT_MINI_APPID/SECRET, or the offline mock
// (WECHAT_MINI_MOCK=true, never in APP_ENV=production) for tests.
$container->singleton(Code2SessionClient::class, static function () use ($config): Code2SessionClient {
    $w = (array) $config->get('uuims.wechat_mini', []);
    if (($w['mock'] ?? false) === true && $config->get('app.env') !== 'production') {
        return new MockCode2SessionClient();
    }

    return new HttpCode2SessionClient((string) ($w['appid'] ?? ''), (string) ($w['secret'] ?? ''), (float) ($w['timeout'] ?? 5));
});
$container->singleton(WechatBindings::class, static fn (): WechatBindings => new WechatBindings($container->make(Code2SessionClient::class)));

// Per-worker services (no connections held: OrmStorage / models borrow pooled connections per call).
$oauthGrants = static fn (): array => [WechatMiniGrant::TYPE => new WechatMiniGrant($container->make(WechatBindings::class))];
$container->singleton(PublicOrigin::class, static fn (): PublicOrigin => PublicOrigin::fromConfig((array) $config->get('uuims.oauth', [])));
$container->singleton(AuthorizationServer::class, static fn (): AuthorizationServer => OAuth::server((array) $config->get('uuims.oauth', []), null, $oauthGrants()));
$container->singleton(OAuthServers::class, static fn (): OAuthServers => new OAuthServers(
    $container->make(PublicOrigin::class),
    static fn (string $issuer): AuthorizationServer => OAuth::server((array) $config->get('uuims.oauth', []), $issuer, $oauthGrants()),
    $container->make(AuthorizationServer::class),
));
$container->singleton(PermissionService::class, static fn (): PermissionService => new PermissionService(
    $container->make(CacheManager::class),
    $container->make(MenuRegistry::class),
    (int) $config->get('uuims.permission_cache_ttl', 600),
));
// Server-rendered pages (loongs/render, RENDER.md): #[AsPage] classes under apps/*/Pages, discovered
// once per worker; descriptions served by GET /uuims/api/pages/{page} (Modules/Render).
$container->singleton(PageRegistry::class, static function () use ($container): PageRegistry {
    $registry = new PageRegistry(
        static fn (string $class): object => $container->make($class),
        translator: new RenderTranslator($container->make(Translator::class)),
    );
    $registry->discoverApps(BasePath::get('apps'));

    return $registry;
});
$container->singleton(PageService::class, static fn (): PageService => new PageService(
    $container->make(PageRegistry::class),
    $container->make(MenuRegistry::class),
    $container->make(PermissionService::class),
    $container->make(CacheManager::class),
    (int) $config->get('uuims.page_cache_ttl', 600),
));
$container->singleton(LoginGuard::class, static function () use ($config): LoginGuard {
    $cfg = (array) $config->get('uuims.login', []);

    return new LoginGuard((int) ($cfg['max_failures'] ?? 5), (int) ($cfg['lock_seconds'] ?? 900), (int) ($cfg['captcha_ttl'] ?? 300));
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
