<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\Language\Catalog;
use Loongs\Language\Lang;
use Loongs\Language\Translator;

/**
 * Uuims i18n on top of loongs/language (README §19).
 *
 *  - Default / fallback locale: en-US. A request with no Accept-Language is English.
 *    Keys stay the Chinese source texts ("gettext style"). zh-CN has its own pack
 *    (apps/Uuims/lang/zh-CN/*.php, each key mapped to itself) so Chinese still displays; a key
 *    missing there falls back to the English pack, then to the key.
 *  - Packs: apps/<App>/lang/<locale>/*.php|json — the directory name is the locale id, every file
 *    in it is merged (filename order, later overrides). Add a language by adding e.g.
 *    apps/Uuims/lang/ja-JP/; it is offered to clients automatically.
 *  - Placeholders: I18n::t('已删除 {n} 条', ['n' => $n]) — loongs/language replaces {n}. Same syntax
 *    as __($key, $params). Do not concatenate values into the message.
 *  - Current locale: LocaleMiddleware (loongs/language) per request coroutine from ui_locales / lang /
 *    Accept-Language. Check missing texts with `./loongs uuims:menus` (--strict-i18n fails on gaps).
 */
final class I18n
{
    public const FALLBACK = 'en-US';

    private static ?Translator $translator = null;

    /** Builds the translator from apps/*\/lang (once per worker / CLI) and makes it the Lang default. */
    public static function boot(string $appsDir): Translator
    {
        $dirs = glob(rtrim($appsDir, '/') . '/*/lang', GLOB_ONLYDIR) ?: [];
        sort($dirs, SORT_STRING);
        $t = new Translator(Catalog::fromDirectories(...$dirs), self::FALLBACK);
        Lang::setTranslator($t);

        self::$packVersions = [];

        return self::$translator = $t;
    }

    public static function translator(): Translator
    {
        return self::$translator ?? Lang::translator();
    }

    /** @var array<string, string> */
    private static array $packVersions = [];

    /** Short hash of the merged pack of $locale (part of cache keys: edited packs invalidate cached pages). */
    public static function packVersion(string $locale): string
    {
        return self::$packVersions[$locale] ??= substr(hash('sha256', $locale . json_encode(self::translator()->catalog->all($locale === '' ? self::FALLBACK : $locale), JSON_UNESCAPED_UNICODE)), 0, 8);
    }

    public static function locale(): string
    {
        return self::translator()->locale();
    }

    /** @param array<string, mixed> $params */
    public static function t(string $text, array $params = []): string
    {
        return self::translator()->get($text, $params);
    }

    /**
     * Translates a message that may already be translated / user data: only texts in the source
     * language (containing CJK characters) are looked up, so English output is not reported missing.
     */
    public static function msg(string $text): string
    {
        return $text !== '' && preg_match('/\p{Han}/u', $text) === 1 ? self::translator()->get($text) : $text;
    }

    /**
     * Translates "name" (or $key) in a menu-like tree.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public static function tree(array $nodes, string $key = 'name', string $children = 'children'): array
    {
        foreach ($nodes as $i => $n) {
            if (isset($n[$key]) && is_string($n[$key])) {
                $nodes[$i][$key] = self::msg($n[$key]);
            }
            if (isset($n[$children]) && is_array($n[$children])) {
                $nodes[$i][$children] = self::tree($n[$children], $key, $children);
            }
        }

        return $nodes;
    }

    /** Recursively translates source-language strings in (sample) API data. */
    public static function deep(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::msg($data);
        }
        if (is_array($data)) {
            return array_map(self::deep(...), $data);
        }

        return $data;
    }

    /**
     * Translates string values of $keys in API rows (e.g. chart rows with enum labels).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    public static function rows(array $rows, array $keys): array
    {
        foreach ($rows as $i => $r) {
            foreach ($keys as $k) {
                if (isset($r[$k]) && is_string($r[$k])) {
                    $rows[$i][$k] = self::msg($r[$k]);
                }
            }
        }

        return $rows;
    }
}
