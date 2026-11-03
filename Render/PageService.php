<?php

declare(strict_types=1);

namespace App\Uuims\Render;

use App\Uuims\Services\PermissionService;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\I18n;
use App\Uuims\Support\MenuRegistry;
use Loongs\Cache\CacheManager;
use Loongs\Render\ClientInfo;
use Loongs\Render\Exception\PageForbidden;
use Loongs\Render\Exception\PageNotFound;
use Loongs\Render\Exception\PlatformNotSupported;
use Loongs\Render\Exception\SchemaNotSupported;
use Loongs\Render\Http\CacheKey;
use Loongs\Render\PageCode;
use Loongs\Render\PageContext;
use Loongs\Render\PageRegistry;

/**
 * GET /uuims/api/pages/{page} (RENDER.md §2, §4.4, §4.5): page description for the current uuims and
 * client platform, shared by PC (Flutter) and uni-app.
 *
 * - The page must be declared in a menu file (menu with page, or a hidden "type": "page" node); its
 *   page_type is passed as the expected builder type.
 * - Cached in loongs/cache under: permission version (bumped on role / grant / dept / assignment
 *   changes) + menu files hash + loongs/render CacheKey (schema version, page code, platform + env,
 *   permission fingerprint = codes + field permissions, page class version, locale) + language pack
 *   version + route parameters.
 * - Rendered in the request locale (I18n / LocaleMiddleware): texts translated by the registry's
 *   loongs/language translator, meta.locale set, so the ETag differs per language.
 */
final class PageService
{
    public const MAX_PARAMS = 20;

    public function __construct(
        private readonly PageRegistry $pages,
        private readonly MenuRegistry $menus,
        private readonly PermissionService $permissions,
        private readonly CacheManager $cache,
        private readonly int $ttl = 600,
    ) {
    }

    public function registry(): PageRegistry
    {
        return $this->pages;
    }

    /**
     * @param array<string, mixed> $permCtx PermissionService::context()
     * @param array<string, mixed> $query   request query (scalar values become page params)
     * @return array<string, mixed> page description
     * @throws ApiException 403 / 404 / 426
     */
    public function describe(string $code, array $permCtx, ClientInfo $client, array $query = []): array
    {
        if (!PageCode::isValid($code)) {
            throw new ApiException(40401, '页面不存在', 404);
        }
        if ($client->locale === null) {
            $client = $client->withLocale(I18n::locale());
        }
        if ($client->schema < \Loongs\Render\Schema::VERSION) {
            throw new ApiException(42601, '客户端版本过旧，请升级后再试', 426, ['schema' => \Loongs\Render\Schema::VERSION]);
        }
        $menuPage = $this->menus->page($code);
        if ($menuPage === null || !$this->pages->has($code)) {
            throw new ApiException(40401, '页面不存在', 404);
        }
        $params = [];
        foreach ($query as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) === 1 && (is_scalar($v) || $v === null) && count($params) < self::MAX_PARAMS) {
                $params[$k] = is_string($v) ? mb_substr($v, 0, 256) : $v;
            }
        }
        ksort($params);
        $ctx = new PageContext($client, $params, $permCtx, [], $code);
        $resolver = new UuimsPermissionResolver();
        $key = 'user_page_' . $this->permissions->version() . '_' . $this->menus->hash() . '_' . I18n::packVersion((string) $client->locale) . '_'
            . substr(hash('sha256', CacheKey::for($this->pages->get($code), $ctx, $resolver) . '|' . json_encode($params)), 0, 32);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $desc = $this->pages->render($code, $ctx, $resolver, new UuimsFieldPolicy(), $menuPage['page_type']);
        } catch (PageNotFound) {
            throw new ApiException(40401, '页面不存在', 404);
        } catch (PageForbidden) {
            throw new ApiException(40301, '无权限访问该页面', 403, ['page' => $code]);
        } catch (PlatformNotSupported) {
            throw new ApiException(40402, '当前客户端不支持该页面', 404, ['page' => $code, 'platform' => $client->platform->value]);
        } catch (SchemaNotSupported) {
            throw new ApiException(42601, '客户端版本过旧，请升级后再试', 426, ['schema' => \Loongs\Render\Schema::VERSION]);
        }
        $this->cache->set($key, $desc, $this->ttl);

        return $desc;
    }
}
