<?php

declare(strict_types=1);

namespace App\Uuims\Console;

use App\Uuims\Support\I18n;
use App\Uuims\Support\MenuConfigException;
use App\Uuims\Support\MenuRegistry;
use Loongs\Console\Command;
use Loongs\Render\Exception\RenderException;
use Loongs\Render\PageRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ./loongs uuims:menus [--apps-dir=apps] [--codes] [--strict-i18n]
 *
 * Offline file validation: merges every apps/<App>/loongs.json (incl. optional services[]).
 * Runtime HTTP menus use MenusService aggregator (main file + RPC Menus.export per sub),
 * not this CLI path alone. Exit code 1 with the offending file + node on errors
 * (duplicate code/path/page, bad parent, invalid node) — run it in CI after editing a menu file.
 *
 * Server-rendered pages (loongs/render, RENDER.md §3): every page declared in a menu file (menu with
 * page + page_type, or a hidden "type": "page" node) must have an #[AsPage] class under apps/*\/Pages
 * whose builder type equals page_type (page_type custom may be a client-only component), and every
 * permission code a page uses (#[AsPage] permission, ->can()) must exist in the menu files.
 *
 * Languages (README §19): every user-facing source text — menu names, page texts (titles, labels,
 * options, messages …) and Chinese string literals in apps/*\/ PHP code (messages, enum labels,
 * views; not Console/ or lang/) — should have a translation in every language pack
 * (apps/Uuims/lang/en-US/*.php and the other locale directories, merged by filename). Missing ones are reported as warnings; --strict-i18n fails.
 */
#[AsCommand(name: 'uuims:menus', description: 'Validate + print the merged menu tree of apps/<App>/loongs.json and its server-rendered pages')]
final class MenusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('apps-dir', null, InputOption::VALUE_REQUIRED, 'Directory containing the apps', '')
            ->addOption('codes', null, InputOption::VALUE_NONE, 'Print permission codes only')
            ->addOption('strict-i18n', null, InputOption::VALUE_NONE, 'Fail when a language pack misses a translation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->io($input, $output);
        $dir = (string) $input->getOption('apps-dir');
        $dir = $dir === '' ? $this->basePath() . '/apps' : $dir;
        try {
            $reg = MenuRegistry::load($dir);
        } catch (MenuConfigException $e) {
            $io->error('loongs.json invalid: ' . $e->getMessage());

            return self::FAILURE;
        }
        foreach ($reg->files() as $file) {
            $io->writeln("source {$file}");
        }
        $io->writeln('');
        foreach ($input->getOption('codes') ? $reg->codes() : $reg->outline() as $line) {
            $io->writeln($line);
        }

        [$errors, $lines, $warnings] = self::checkPages($reg, $dir);
        if ($lines !== []) {
            $io->writeln('');
            foreach ($lines as $line) {
                $io->writeln($line);
            }
        }
        foreach ($warnings as $w) {
            $io->warning($w);
        }
        if ($errors !== []) {
            $io->error("pages invalid:\n" . implode("\n", $errors));

            return self::FAILURE;
        }
        [$i18nLines, $missing] = self::checkTranslations($reg, $dir);
        $io->writeln('');
        foreach ($i18nLines as $line) {
            $io->writeln($line);
        }
        if ($missing !== []) {
            $report = [];
            foreach ($missing as $locale => $keys) {
                $report[] = "{$locale}: " . count($keys) . ' missing translation(s):';
                foreach ($keys as $k) {
                    $report[] = '  ' . str_replace("\n", '\\n', $k);
                }
            }
            if ($input->getOption('strict-i18n')) {
                $io->error("languages incomplete:\n" . implode("\n", $report));

                return self::FAILURE;
            }
            $io->warning("languages incomplete (texts fall back to " . I18n::FALLBACK . "):\n" . implode("\n", $report));
        }
        $io->success(sprintf('menus OK: %d file(s), %d permission code(s), %d page(s), hash %s', count($reg->files()), count($reg->codes()), count($reg->pages()), $reg->hash()));

        return self::SUCCESS;
    }

    /**
     * Source texts (menu names, page texts, Chinese literals in PHP code) and which of them each
     * language pack lacks.
     *
     * @return array{0: list<string>, 1: array<string, list<string>>} report lines, locale => missing keys
     */
    public static function checkTranslations(MenuRegistry $menus, string $appsDir): array
    {
        $translator = I18n::boot($appsDir);
        $texts = self::sourceTexts($menus, $appsDir);
        $lines = [sprintf('i18n   %d source text(s) (menus %d, pages %d, code %d); fallback %s; packs: %s', count($texts['all']), count($texts['menus']),
            count($texts['pages']), count($texts['code']), I18n::FALLBACK, implode(', ', $translator->supported()) ?: '(none)')];
        $missing = [];
        // Keys are Chinese, so the en-US fallback pack is not optional: every supported locale,
        // including the fallback, must have its own entry (catalog), not just missingKeys()
        // (which treats the fallback locale as complete when sourceKeys is on).
        foreach ($translator->supported() as $locale) {
            $m = [];
            foreach ($texts['all'] as $k) {
                if (!$translator->catalog->has($locale, $k)) {
                    $m[] = $k;
                }
            }
            $lines[] = sprintf('i18n   %-8s %d/%d translated%s', $locale, count($texts['all']) - count($m), count($texts['all']), $m === [] ? '  OK' : '');
            if ($m !== []) {
                $missing[$locale] = $m;
            }
        }
        foreach ($texts['interpolated'] as $w) {
            $lines[] = "i18n   note: interpolated Chinese string (use I18n::t('… {name} …', [...])): {$w}";
        }

        return [$lines, $missing];
    }

    /** @return array{all: list<string>, menus: list<string>, pages: list<string>, code: list<string>, interpolated: list<string>} */
    public static function sourceTexts(MenuRegistry $menus, string $appsDir): array
    {
        $han = static fn (string $s): bool => preg_match('/\p{Han}/u', $s) === 1;
        $menuTexts = [];
        $walk = static function (array $nodes) use (&$walk, &$menuTexts): void {
            foreach ($nodes as $n) {
                if (isset($n['name']) && is_string($n['name'])) {
                    $menuTexts[$n['name']] = true;
                }
                $walk((array) ($n['children'] ?? []));
            }
        };
        $walk($menus->tree());
        $pageTexts = [];
        $pages = new PageRegistry();
        try {
            $pages->discoverApps($appsDir);
            foreach (array_keys($pages->definitions()) as $code) {
                foreach ($pages->inspect($code)['texts'] ?? [] as $s) {
                    $pageTexts[$s] = true;
                }
            }
        } catch (RenderException) {
            // reported by checkPages()
        }
        $codeTexts = $interpolated = [];
        foreach (self::phpFiles($appsDir) as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $tok) {
                if (!is_array($tok)) {
                    continue;
                }
                if ($tok[0] === T_CONSTANT_ENCAPSED_STRING && $han($tok[1])) {
                    $s = $tok[1][0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tok[1], 1, -1)) : stripcslashes(substr($tok[1], 1, -1));
                    $codeTexts[$s] = true;
                } elseif ($tok[0] === T_ENCAPSED_AND_WHITESPACE && $han($tok[1])) {
                    $interpolated[] = substr($file, strlen(rtrim($appsDir, '/')) + 1) . ':' . $tok[2] . ' "' . trim($tok[1]) . '"';
                } elseif ($tok[0] === T_INLINE_HTML && $han($tok[1])) {
                    $interpolated[] = substr($file, strlen(rtrim($appsDir, '/')) + 1) . ':' . $tok[2] . ' inline HTML "' . mb_substr(trim($tok[1]), 0, 40) . '"';
                }
            }
        }
        $filter = static fn (array $set): array => array_values(array_filter(array_map('strval', array_keys($set)), $han));
        $m = $filter($menuTexts);
        $p = $filter($pageTexts);
        $c = $filter($codeTexts);

        return ['all' => array_values(array_unique([...$m, ...$p, ...$c])), 'menus' => $m, 'pages' => $p, 'code' => $c, 'interpolated' => $interpolated];
    }

    /** @return list<string> PHP files of every app except Console/ and lang/ */
    private static function phpFiles(string $appsDir): array
    {
        $out = [];
        foreach (glob(rtrim($appsDir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $app) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr((string) $f, strlen($app) + 1);
                if ($f->isFile() && str_ends_with($rel, '.php') && !preg_match('#^(Console|lang|config)/#', $rel)) {
                    $out[] = (string) $f;
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} errors, report lines, warnings
     */
    public static function checkPages(MenuRegistry $menus, string $appsDir): array
    {
        $errors = $lines = $warnings = [];
        $pages = new PageRegistry();
        try {
            $pages->discoverApps($appsDir);
        } catch (RenderException $e) {
            return [['page classes: ' . $e->getMessage()], [], []];
        }
        foreach ($menus->pages() as $code => $p) {
            if (!$pages->has($code)) {
                if ($p['page_type'] === 'custom') {
                    $lines[] = sprintf('page   %-32s %-9s client component %s', $code, 'custom', $p['component'] ?? '(none)');
                } else {
                    $errors[] = "{$p['at']}: page \"{$code}\" has no #[AsPage('{$code}')] class under apps/*/Pages";
                }
                continue;
            }
            $def = $pages->get($code);
            try {
                $pages->assertType($code, $p['page_type']);
                $used = $pages->inspect($code);
            } catch (RenderException $e) {
                $errors[] = "{$p['at']}: {$e->getMessage()}";
                continue;
            }
            $unknown = array_values(array_filter(array_unique([...$def->permissions, ...$used['codes']]), static fn (string $c): bool => !$menus->has($c)));
            if ($unknown !== []) {
                $errors[] = "{$p['at']}: page \"{$code}\" ({$def->class}) uses permission code(s) missing from the loongs.json menus: " . implode(', ', $unknown);
                continue;
            }
            if ($def->permissions === []) {
                $warnings[] = "page \"{$code}\" ({$def->class}) has no #[AsPage] permission: every logged-in uuims can load it";
            }
            $lines[] = sprintf('page   %-32s %-9s %s  OK (permission %s; %d button code(s), %d field(s))', $code, $p['page_type'], $def->class,
                $def->permissions === [] ? '-' : implode('|', $def->permissions), count($used['codes']), count($used['fields']));
        }
        foreach ($pages->definitions() as $code => $def) {
            if ($menus->page($code) === null) {
                $warnings[] = "page class {$def->class} (\"{$code}\") is not declared in any loongs.json: GET /uuims/api/pages/{$code} answers 404";
            }
        }

        return [$errors, $lines, $warnings];
    }
}
