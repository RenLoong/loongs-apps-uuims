<?php

declare(strict_types=1);

namespace App\Uuims\Support;

/**
 * Menus, pages and buttons (permission codes) come from files, not the database:
 * every app may ship apps/<App>/loongs.json (app manifest); the registry loads them all, validates
 * them and merges their menu lists into one tree. Role grants reference permission codes
 * (role_permission.code).
 *
 * File format (loongs.json):
 *   {
 *     "package": "loongs/app",          // 包名
 *     "name": "App",                    // 应用名
 *     "description": "…",               // 简介
 *     "icon": "public/icon.png",        // icon 路径（相对 app 目录，可空）
 *     "version": "1.0.0",               // 版本号（semver）
 *     "version_name": "正式版",         // 版本名称
 *     "services": [                     // 可选：主/子服务声明，如 menus main|sub
 *       {"id": "menus", "role": "main"}
 *       // or {"id": "menus", "role": "sub", "main": "uuims"}
 *     ],
 *     "menus": [                        // 菜单组列表
 *       {"id": "main", "list": [ node, ... ]}   // 菜单标识 + 节点列表（原 loongs.json 的 menus）
 *     ]
 *   }
 *   node   = {"type": "dir"|"menu"|"button"|"page", "name": "…", "path": "/x", "component": "x/y", "icon": "…",
 *             "perms": "app:thing:action", "sort": 0, "visible": true, "children": [node…],
 *             "page": "app.module.name", "page_type": "dashboard"|"form"|"table"|"detail"|"custom"}
 *   A top-level node in a group list may carry "parent": "<path of a dir of any app>" to hang under it.
 *
 * Rules (violations throw MenuConfigException naming the file and the node):
 *   - dir: path required, no perms; may contain dirs and menus
 *   - menu: path + perms required (perms = the code that grants the page); may contain buttons only
 *   - button: perms required, no path, no children; only inside a menu
 *   - page (RENDER.md §3): a hidden page that is not a sidebar entry (dialog form, embedded table,
 *     detail page): page + page_type required, no path / perms / children; inside a dir or a menu.
 *     Access is decided by the page class (#[AsPage] permission), never by the node.
 *   - page + page_type (server-rendered page, loongs/render) may also be set on a menu; page codes
 *     ([a-z0-9._-]) are unique across all apps. `./loongs uuims:menus` checks that a PHP page class
 *     exists for each code and builds the declared type.
 *   - paths (dir/menu) and perms codes are unique across all apps; unknown keys are rejected
 * Ordering: siblings by sort, then app name, then position in its file (stable).
 * Node key (used by clients): the path for dir/menu, the perms code for buttons, the page code for pages.
 */
final class MenuRegistry
{
    public const FILE = 'loongs.json';

    private const KEYS = ['type', 'name', 'path', 'component', 'icon', 'perms', 'sort', 'visible', 'children', 'parent', 'page', 'page_type'];
    public const PAGE_TYPES = ['dashboard', 'form', 'table', 'detail', 'custom'];
    private const PAGE_RE = '/^[a-z0-9][a-z0-9._-]{0,127}$/';
    private const PERM_RE = '/^[a-z0-9][a-z0-9:_-]{0,127}$/';
    private const PATH_RE = '#^/[A-Za-z0-9/_.:-]{0,254}$#';

    /** @var list<array<string, mixed>> merged tree */
    private array $tree = [];

    /** @var array<string, array<string, mixed>> perms code → node (without children) */
    private array $byCode = [];

    /** @var array<string, list<string>> perms code → codes of its ancestors (menus/dirs with perms) */
    private array $ancestors = [];

    /** @var array<string, array<string, mixed>> page code → {page, page_type, type, name, app, path, component, perms, menu_perms, at} */
    private array $pages = [];

    /** @var list<string> loaded files (relative to the apps dir) */
    private array $files = [];

    private string $hash = '';

    private function __construct()
    {
    }

    /** Load every apps/<App>/loongs.json under $appsDir (sorted by app name). */
    public static function load(string $appsDir): self
    {
        $files = glob(rtrim($appsDir, '/') . '/*/' . self::FILE) ?: [];
        sort($files, SORT_STRING);
        $sources = [];
        foreach ($files as $file) {
            $sources[basename(dirname($file))] = ['file' => $file, 'json' => (string) file_get_contents($file)];
        }

        return self::fromSources($sources, rtrim($appsDir, '/'));
    }

    /**
     * @param array<string, array{file: string, json: string}> $sources app name → file + contents
     */
    public static function fromSources(array $sources, string $baseDir = ''): self
    {
        $r = new self();
        ksort($sources, SORT_STRING);
        $h = hash_init('sha256');
        $roots = [];           // top-level nodes: [app, index, node, parent|null, file]
        foreach ($sources as $app => $src) {
            $rel = $baseDir !== '' && str_starts_with($src['file'], $baseDir . '/') ? 'apps/' . substr($src['file'], strlen($baseDir) + 1) : $src['file'];
            $r->files[] = $rel;
            hash_update($h, $app . "\0" . $src['json'] . "\0");
            try {
                $doc = json_decode($src['json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new MenuConfigException("{$rel}: invalid JSON ({$e->getMessage()})");
            }
            if (!is_array($doc) || !isset($doc['menus']) || !is_array($doc['menus']) || !array_is_list($doc['menus'])) {
                throw new MenuConfigException("{$rel}: expected {\"menus\": [ {\"id\": \"…\", \"list\": [ … ]}, … ]}");
            }
            $extra = array_diff(array_keys($doc), ['package', 'name', 'description', 'icon', 'version', 'version_name', 'menus', 'services', '$schema', 'comment']);
            if ($extra !== []) {
                throw new MenuConfigException("{$rel}: unknown top-level key(s) " . implode(', ', $extra));
            }
            if (array_key_exists('services', $doc)) {
                if (!is_array($doc['services']) || !array_is_list($doc['services'])) {
                    throw new MenuConfigException("{$rel}: services must be a list of {id, role[, main]}");
                }
                foreach ($doc['services'] as $si => $svc) {
                    if (!is_array($svc) || !isset($svc['id'], $svc['role']) || !is_string($svc['id']) || $svc['id'] === '' || !is_string($svc['role'])) {
                        throw new MenuConfigException("{$rel}: services[{$si}] must be {\"id\": \"…\", \"role\": \"main\"|\"sub\", \"main\"?}");
                    }
                    if (!in_array($svc['role'], ['main', 'sub'], true)) {
                        throw new MenuConfigException("{$rel}: services[{$si}].role must be main or sub");
                    }
                    if ($svc['role'] === 'sub' && (!isset($svc['main']) || !is_string($svc['main']) || $svc['main'] === '')) {
                        throw new MenuConfigException("{$rel}: services[{$si}] role=sub requires main (RPC service key)");
                    }
                    $sExtra = array_diff(array_keys($svc), ['id', 'role', 'main', 'comment']);
                    if ($sExtra !== []) {
                        throw new MenuConfigException("{$rel}: services[{$si}] unknown key(s) " . implode(', ', $sExtra));
                    }
                }
            }
            $groupIds = [];
            $flat = 0;
            foreach ($doc['menus'] as $gi => $group) {
                if (!is_array($group) || !isset($group['id'], $group['list']) || !is_string($group['id']) || $group['id'] === '' || !is_array($group['list']) || !array_is_list($group['list'])) {
                    throw new MenuConfigException("{$rel}: menus[{$gi}] must be {\"id\": \"…\", \"list\": [ … ]}");
                }
                $gExtra = array_diff(array_keys($group), ['id', 'list', 'comment']);
                if ($gExtra !== []) {
                    throw new MenuConfigException("{$rel}: menus[{$gi}] unknown key(s) " . implode(', ', $gExtra));
                }
                if (isset($groupIds[$group['id']])) {
                    throw new MenuConfigException("{$rel}: duplicate menu group id \"{$group['id']}\"");
                }
                $groupIds[$group['id']] = $gi;
                foreach ($group['list'] as $i => $node) {
                    $at = "menus[{$gi}].list[{$i}]";
                    $parent = is_array($node) && isset($node['parent']) ? $node['parent'] : null;
                    if ($parent !== null && (!is_string($parent) || preg_match(self::PATH_RE, $parent) !== 1)) {
                        throw new MenuConfigException("{$rel}: {$at}.parent must be the path of a dir, e.g. \"/system\"");
                    }
                    $roots[] = [(string) $app, $flat, $r->normalize($node, (string) $app, $rel, $at, null, true), $parent, $rel];
                    $flat++;
                }
            }
        }
        $r->hash = substr(hash_final($h), 0, 16);

        // uniqueness across all apps
        $paths = [];
        $codes = [];
        $pageCodes = [];
        $walk = function (array $n) use (&$walk, &$paths, &$codes, &$pageCodes): void {
            if ($n['page'] !== null) {
                if (isset($pageCodes[$n['page']])) {
                    throw new MenuConfigException("duplicate page \"{$n['page']}\": {$pageCodes[$n['page']]} and {$n['_at']}");
                }
                $pageCodes[$n['page']] = $n['_at'];
            }
            if ($n['path'] !== null) {
                if (isset($paths[$n['path']])) {
                    throw new MenuConfigException("duplicate path \"{$n['path']}\": {$paths[$n['path']]} and {$n['_at']}");
                }
                $paths[$n['path']] = $n['_at'];
            }
            if ($n['perms'] !== null) {
                if (isset($codes[$n['perms']])) {
                    throw new MenuConfigException("duplicate permission code \"{$n['perms']}\": {$codes[$n['perms']]} and {$n['_at']}");
                }
                $codes[$n['perms']] = $n['_at'];
            }
            foreach ($n['children'] as $c) {
                $walk($c);
            }
        };
        foreach ($roots as [, , $node]) {
            $walk($node);
        }

        // attach "parent" roots under dirs (possibly of other apps); resolve against the tree built so far
        $top = [];
        $attach = [];
        foreach ($roots as [$app, $i, $node, $parent, $rel]) {
            if ($parent === null) {
                $top[] = $node;
            } else {
                $attach[] = [$node, $parent, $rel, $i];
            }
        }
        foreach ($attach as [$node, $parent, $rel, $i]) {
            if (!self::attachTo($top, $parent, $node)) {
                $hint = isset($paths[$parent]) ? ' (it is not a dir)' : ' (no dir has this path)';
                throw new MenuConfigException("{$rel}: node #{$i} parent \"{$parent}\" not found{$hint}");
            }
            if ($node['type'] === 'button' || $node['type'] === 'page') {
                throw new MenuConfigException("{$rel}: node #{$i} a {$node['type']} cannot be attached with parent (only dirs / menus)");
            }
        }
        $r->tree = self::sortTree($top);
        $r->index($r->tree, []);

        return $r;
    }

    /** @return list<array<string, mixed>> merged tree (all nodes incl. buttons) */
    public function tree(): array
    {
        return self::publicTree($this->tree);
    }

    /** @return list<string> every permission code, sorted */
    public function codes(): array
    {
        $c = array_keys($this->byCode);
        sort($c, SORT_STRING);

        return $c;
    }

    public function has(string $code): bool
    {
        return isset($this->byCode[$code]);
    }

    /** @return array<string, mixed>|null */
    public function node(string $code): ?array
    {
        return $this->byCode[$code] ?? null;
    }

    /**
     * Server-rendered pages declared in loongs.json menu lists (menus with page + hidden page nodes).
     *
     * @return array<string, array<string, mixed>> page code → {page, page_type, type, name, app, path, component, perms, menu_perms, at}
     */
    public function pages(): array
    {
        $p = $this->pages;
        ksort($p, SORT_STRING);

        return $p;
    }

    /** @return array<string, mixed>|null */
    public function page(string $code): ?array
    {
        return $this->pages[$code] ?? null;
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }

    /** Content hash of all menu files (part of the permission cache key). */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * Sidebar tree for a set of granted codes: dirs + menus only (no buttons), visible ones, a menu
     * only when its code is granted, a dir only when something below it is shown.
     *
     * @param list<string>|null $granted null = everything (super uuims)
     * @return list<array<string, mixed>>
     */
    public function menuTree(?array $granted): array
    {
        $set = $granted === null ? null : array_flip($granted);
        $filter = static function (array $nodes) use (&$filter, $set): array {
            $out = [];
            foreach ($nodes as $n) {
                if ($n['type'] === 'button' || $n['type'] === 'page' || !$n['visible']) {
                    continue;
                }
                if ($n['type'] === 'menu') {
                    if ($set !== null && !isset($set[$n['perms']])) {
                        continue;
                    }
                    $n['children'] = [];
                } else {
                    $n['children'] = $filter($n['children']);
                    if ($n['children'] === []) {
                        continue;
                    }
                }
                $out[] = $n;
            }

            return $out;
        };

        return self::publicTree($filter($this->tree));
    }

    /** Human-readable outline (./loongs uuims:menus). @return list<string> */
    public function outline(): array
    {
        $lines = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$lines): void {
            foreach ($nodes as $n) {
                $lines[] = str_repeat('  ', $depth) . sprintf('%-6s %s  %s%s%s  [%s]', $n['type'], $n['name'], $n['path'] ?? '',
                    $n['perms'] !== null ? ' ' . $n['perms'] : '', $n['page'] !== null ? " page={$n['page']} ({$n['page_type']})" : '', $n['app']);
                $walk($n['children'], $depth + 1);
            }
        };
        $walk($this->tree, 0);

        return $lines;
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $node, string $app, string $file, string $at, ?string $parentType, bool $top): array
    {
        $where = "{$file}: {$at}";
        if (!is_array($node) || array_is_list($node)) {
            throw new MenuConfigException("{$where} must be an object");
        }
        $unknown = array_diff(array_keys($node), self::KEYS);
        if ($unknown !== []) {
            throw new MenuConfigException("{$where} unknown key(s) " . implode(', ', $unknown));
        }
        if (!$top && array_key_exists('parent', $node)) {
            throw new MenuConfigException("{$where} \"parent\" is only allowed on top-level nodes");
        }
        $type = $node['type'] ?? null;
        if (!in_array($type, ['dir', 'menu', 'button', 'page'], true)) {
            throw new MenuConfigException("{$where} type must be dir, menu, button or page");
        }
        $name = $node['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 64) {
            throw new MenuConfigException("{$where} name is required (1–64 characters)");
        }
        $label = "{$where} ({$type} \"{$name}\")";
        foreach (['path', 'component', 'icon', 'perms', 'page', 'page_type'] as $k) {
            if (isset($node[$k]) && !is_string($node[$k])) {
                throw new MenuConfigException("{$label} {$k} must be a string");
            }
        }
        $path = isset($node['path']) && $node['path'] !== '' ? $node['path'] : null;
        $perms = isset($node['perms']) && $node['perms'] !== '' ? $node['perms'] : null;
        if ($path !== null && preg_match(self::PATH_RE, $path) !== 1) {
            throw new MenuConfigException("{$label} invalid path \"{$path}\" (must start with /, letters digits / _ . : -)");
        }
        if ($perms !== null && preg_match(self::PERM_RE, $perms) !== 1) {
            throw new MenuConfigException("{$label} invalid perms \"{$perms}\" (lowercase letters, digits, : _ -)");
        }
        $page = isset($node['page']) && $node['page'] !== '' ? $node['page'] : null;
        $pageType = isset($node['page_type']) && $node['page_type'] !== '' ? $node['page_type'] : null;
        if ($page !== null && preg_match(self::PAGE_RE, $page) !== 1) {
            throw new MenuConfigException("{$label} invalid page \"{$page}\" (lowercase letters, digits, . _ -)");
        }
        if ($pageType !== null && !in_array($pageType, self::PAGE_TYPES, true)) {
            throw new MenuConfigException("{$label} page_type must be one of " . implode(', ', self::PAGE_TYPES));
        }
        if (($page === null) !== ($pageType === null)) {
            throw new MenuConfigException("{$label} page and page_type go together");
        }
        if ($page !== null && !in_array($type, ['menu', 'page'], true)) {
            throw new MenuConfigException("{$label} only menus and page nodes declare a page");
        }
        if (isset($node['sort']) && !is_int($node['sort'])) {
            throw new MenuConfigException("{$label} sort must be an integer");
        }
        if (isset($node['visible']) && !is_bool($node['visible'])) {
            throw new MenuConfigException("{$label} visible must be true or false");
        }
        $children = $node['children'] ?? [];
        if (!is_array($children) || !array_is_list($children)) {
            throw new MenuConfigException("{$label} children must be an array");
        }
        switch ($type) {
            case 'dir':
                if ($path === null) {
                    throw new MenuConfigException("{$label} a dir needs a path");
                }
                if ($perms !== null) {
                    throw new MenuConfigException("{$label} a dir has no perms (grant its menus instead)");
                }
                if ($parentType === 'menu') {
                    throw new MenuConfigException("{$label} a dir cannot be inside a menu");
                }
                break;
            case 'menu':
                if ($path === null || $perms === null) {
                    throw new MenuConfigException("{$label} a menu needs a path and perms");
                }
                if ($parentType === 'menu') {
                    throw new MenuConfigException("{$label} a menu cannot be inside a menu (use a dir)");
                }
                break;
            case 'page':
                if ($page === null) {
                    throw new MenuConfigException("{$label} a page node needs page and page_type");
                }
                if ($path !== null || $perms !== null || $children !== []) {
                    throw new MenuConfigException("{$label} a page node has no path, perms or children (access comes from the page class)");
                }
                if ($parentType === 'button') {
                    throw new MenuConfigException("{$label} a page node cannot be inside a button");
                }
                break;
            default:
                if ($perms === null) {
                    throw new MenuConfigException("{$label} a button needs perms");
                }
                if ($path !== null || $children !== []) {
                    throw new MenuConfigException("{$label} a button has no path and no children");
                }
                if ($parentType !== 'menu') {
                    throw new MenuConfigException("{$label} a button must be inside a menu");
                }
        }
        $out = [
            'key' => match ($type) {
                'button' => $perms,
                'page' => $page,
                default => $path,
            },
            'app' => $app,
            'type' => $type,
            'name' => $name,
            'path' => $path,
            'component' => isset($node['component']) && $node['component'] !== '' ? $node['component'] : null,
            'icon' => isset($node['icon']) && $node['icon'] !== '' ? $node['icon'] : null,
            'perms' => $perms,
            'sort' => (int) ($node['sort'] ?? 0),
            'visible' => $type === 'page' ? false : (bool) ($node['visible'] ?? true),
            'page' => $page,
            'page_type' => $pageType,
            'children' => [],
            '_at' => $label,
            '_order' => [(int) ($node['sort'] ?? 0), $app, 0],
        ];
        foreach ($children as $i => $c) {
            $child = $this->normalize($c, $app, $file, "{$at}.children[{$i}]", $type, false);
            $child['_order'][2] = $i;
            $out['children'][] = $child;
        }
        if ($top) {
            $out['_order'][2] = (int) substr($at, 6, -1);
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function attachTo(array &$nodes, string $path, array $node): bool
    {
        foreach ($nodes as &$n) {
            if ($n['type'] === 'dir' && $n['path'] === $path) {
                $n['children'][] = $node;

                return true;
            }
            if (self::attachTo($n['children'], $path, $node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function sortTree(array $nodes): array
    {
        usort($nodes, static fn (array $a, array $b): int => $a['_order'] <=> $b['_order']);
        foreach ($nodes as &$n) {
            $n['children'] = self::sortTree($n['children']);
        }

        return $nodes;
    }

    /** @param list<array<string, mixed>> $nodes @param list<string> $chain */
    private function index(array $nodes, array $chain): void
    {
        foreach ($nodes as $n) {
            if ($n['perms'] !== null) {
                $this->byCode[$n['perms']] = array_diff_key($n, ['children' => 1, '_at' => 1, '_order' => 1]);
                $this->ancestors[$n['perms']] = $chain;
            }
            if ($n['page'] !== null) {
                $this->pages[$n['page']] = [
                    'page' => $n['page'], 'page_type' => $n['page_type'], 'type' => $n['type'], 'name' => $n['name'], 'app' => $n['app'],
                    'path' => $n['path'], 'component' => $n['component'], 'perms' => $n['perms'],
                    'menu_perms' => $n['perms'] ?? ($chain === [] ? null : $chain[array_key_last($chain)]), 'at' => $n['_at'],
                ];
            }
            $this->index($n['children'], $n['perms'] !== null ? [...$chain, $n['perms']] : $chain);
        }
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function publicTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            unset($n['_at'], $n['_order']);
            $n['children'] = self::publicTree($n['children']);
            $out[] = $n;
        }

        return $out;
    }
}
