<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * Serves the packed PC web and H5 builds, and sends the browser to the
 * matching one. Static assets under public/web and public/h5 are served by
 * ServeAppPublicMiddleware; the SPA shell (spa.html) is injected here so the
 * API base URL comes from env and is never cached as a raw static file.
 */
final class ClientEntry
{
    public static function isMobile(?string $clientHint, ?string $userAgent): bool
    {
        $hint = strtolower(trim((string) $clientHint));
        if ($hint === '?1') {
            return true;
        }
        if ($hint === '?0') {
            return false;
        }

        return (bool) preg_match(
            '/Mobile|Android|iPhone|iPod|iPad|webOS|BlackBerry|IEMobile|Opera Mini|HarmonyOS|OpenHarmony/i',
            (string) $userAgent,
        );
    }

    public static function redirectTarget(bool $mobile): string
    {
        return $mobile ? '/uuims/h5/' : '/uuims/web/';
    }

    public static function redirect(Request $request): Response
    {
        $to = self::redirectTarget(self::isMobile(
            $request->header('sec-ch-ua-mobile'),
            $request->header('user-agent'),
        ));

        return (new Response())->status(302)->header('Location', $to);
    }

    /**
     * First CSV entry of OAUTH_ALLOWED_ORIGINS → API base for packed web/h5.
     * Fallback: OAUTH_ISSUER, then http://127.0.0.1:21000.
     */
    public static function apiBaseUrl(): string
    {
        $raw = (string) env('OAUTH_ALLOWED_ORIGINS', '');
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                return rtrim($part, '/');
            }
        }

        $issuer = trim((string) env('OAUTH_ISSUER', ''));
        if ($issuer !== '') {
            return rtrim($issuer, '/');
        }

        return 'http://127.0.0.1:21000';
    }

    public static function page(string $which): Response
    {
        $dir = $which === 'h5' ? 'h5' : 'web';
        $publicDir = dirname(__DIR__) . '/public/' . $dir;
        $spa = $publicDir . '/spa.html';
        // Legacy fallback while old packs still have index.html only.
        if (!is_file($spa)) {
            $spa = $publicDir . '/index.html';
        }
        if (!is_file($spa)) {
            $script = $dir === 'h5' ? 'mobile/pack.ps1' : 'client/pack.ps1';

            return (new Response())->html($dir . ' build is missing. Run uuims/' . $script . '.', 404);
        }

        $html = file_get_contents($spa);
        if ($html === false) {
            return (new Response())->html('Unable to read SPA shell.', 500);
        }

        $html = self::injectApiBase($html, self::apiBaseUrl());

        return (new Response())
            ->html($html)
            ->header('Cache-Control', 'no-cache');
    }

    private static function injectApiBase(string $html, string $apiBase): string
    {
        $literal = json_encode($apiBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($literal === false) {
            $literal = '""';
        }
        $script = '<script>window.__LOONGS_API_BASE_URL__=' . $literal . ';</script>';

        // Prefer explicit marker (pack/reorganize inserts it).
        if (str_contains($html, '<!--LOONGS_API_BASE_INJECT-->')) {
            return str_replace('<!--LOONGS_API_BASE_INJECT-->', $script, $html);
        }

        // Drop stale domain.js tags if an old shell is still present.
        $html = preg_replace(
            '/[ \t]*<script\s+src=["\']domain\.js["\']\s*>\s*<\/script>\s*\n?/i',
            '',
            $html,
        ) ?? $html;

        // Insert immediately before flutter bootstrap (PC web).
        $count = 0;
        $patched = preg_replace(
            '/([ \t]*)(<script\s+[^>]*src=["\'](?:\.?\/?js\/)?flutter_bootstrap\.js["\'][^>]*>\s*<\/script>)/i',
            '$1' . $script . "\n" . '$1$2',
            $html,
            1,
            $count,
        );
        if (is_string($patched) && $count > 0) {
            return $patched;
        }

        // H5 / generic: before </head>, else before </body>.
        $count = 0;
        $patched = preg_replace(
            '/<\/head>/i',
            '  ' . $script . "\n</head>",
            $html,
            1,
            $count,
        );
        if (is_string($patched) && $count > 0) {
            return $patched;
        }

        return $html . "\n" . $script . "\n";
    }
}
