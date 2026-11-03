<?php

declare(strict_types=1);

namespace App\Uuims\Services;

use App\Uuims\Support\MenuConfigException;
use App\Uuims\Support\MenuRegistry;
use Loongs\Process\ProcessLog;
use Loongs\Rpc\Client\RpcClientInterface;
use Loongs\Service\Manifest;
use Loongs\Service\ProviderRegistry;
use Throwable;

/**
 * Menus main-service aggregator (logical id = menus).
 *
 * - Accepts sub registration via RPC Menus.register (worker-local ProviderRegistry).
 * - Also discovers colocated apps/<App>/loongs.json with services[{id:menus,role:sub}].
 * - Builds MenuRegistry from this app's own file + each provider's Menus.export (RPC).
 *
 * HTTP/PermissionService must use buildRegistry() - never blind-glob foreign apps alone.
 */
final class MenusService
{
    public const SERVICE_ID = 'menus';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly RpcClientInterface $rpc,
        private readonly string $appsDir,
        private readonly string $mainAppDir,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        try {
            $entry = $this->providers->register(self::SERVICE_ID, $payload);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'code' => 400, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'provider' => $entry];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function unregister(array $payload): array
    {
        $key = (string) ($payload['rpc_service'] ?? $payload['app'] ?? '');
        if ($key === '') {
            return ['ok' => false, 'code' => 400, 'message' => 'rpc_service or app required'];
        }
        $removed = $this->providers->unregister(self::SERVICE_ID, $key);

        return ['ok' => true, 'removed' => $removed];
    }

    /**
     * @return array<string, mixed>
     */
    public function listProviders(): array
    {
        $merged = $this->providers->mergeProviders(self::SERVICE_ID, $this->discoverSubs());

        return ['ok' => true, 'providers' => $merged];
    }

    /** Own loongs.json menus only (Menus.export on main). */
    public function exportOwn(): array
    {
        return Manifest::exportMenus($this->mainAppDir);
    }

    /**
     * Aggregate main file + provider exports into a MenuRegistry.
     * On provider export failure: WARN and skip that provider (main menus still load).
     */
    public function buildRegistry(): MenuRegistry
    {
        $sources = [];

        $mainFile = rtrim($this->mainAppDir, '/\\') . '/' . Manifest::FILE;
        if (is_file($mainFile)) {
            $sources['Uuims'] = [
                'file' => $mainFile,
                'json' => (string) file_get_contents($mainFile),
            ];
        }

        foreach ($this->providers->mergeProviders(self::SERVICE_ID, $this->discoverSubs()) as $provider) {
            $rpcService = (string) $provider['rpc_service'];
            if ($rpcService === '' || $rpcService === 'uuims') {
                continue;
            }
            try {
                $response = $this->rpc->call($rpcService, 'Menus.export', []);
            } catch (Throwable $e) {
                ProcessLog::warn(
                    sprintf('Menus.export from %s failed: %s', $rpcService, $e->getMessage()),
                    'menus-main',
                );
                continue;
            }
            if (!$response->isOk()) {
                ProcessLog::warn(
                    sprintf('Menus.export from %s code=%d %s', $rpcService, $response->code, $response->message),
                    'menus-main',
                );
                continue;
            }
            $data = $response->data;
            if (!is_array($data) || ($data['ok'] ?? true) === false) {
                ProcessLog::warn(
                    sprintf('Menus.export from %s returned unsuccessful payload', $rpcService),
                    'menus-main',
                );
                continue;
            }
            $app = (string) ($data['app'] ?? $provider['app'] ?? $rpcService);
            $doc = [
                'package' => (string) ($data['package'] ?? $provider['package'] ?? ''),
                'name' => (string) ($data['name'] ?? $app),
                'description' => (string) ($data['description'] ?? ''),
                'icon' => (string) ($data['icon'] ?? ''),
                'version' => (string) ($data['version'] ?? $provider['version'] ?? ''),
                'version_name' => (string) ($data['version_name'] ?? ''),
                'menus' => is_array($data['menus'] ?? null) ? $data['menus'] : [],
            ];
            // Drop empty optional strings that MenuRegistry does not require
            if ($doc['version'] === '') {
                unset($doc['version']);
            }
            if ($doc['version_name'] === '') {
                unset($doc['version_name']);
            }
            $sources[$app] = [
                'file' => 'rpc:' . $rpcService . '/Menus.export',
                'json' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        try {
            return MenuRegistry::fromSources($sources, rtrim($this->appsDir, '/\\'));
        } catch (MenuConfigException $e) {
            // Boot must still serve main menus if a bad sub export breaks merge.
            ProcessLog::error('menus aggregate failed: ' . $e->getMessage(), 'menus-main');
            if (isset($sources['Uuims'])) {
                return MenuRegistry::fromSources(['Uuims' => $sources['Uuims']], rtrim($this->appsDir, '/\\'));
            }
            throw $e;
        }
    }

    /**
     * Colocated install fallback: scan apps/<App>/loongs.json for menus sub.
     *
     * @return list<array<string, mixed>>
     */
    public function discoverSubs(): array
    {
        $out = [];
        $appsDir = rtrim($this->appsDir, '/\\');
        foreach (glob($appsDir . '/*/' . Manifest::FILE) ?: [] as $file) {
            $appDir = dirname((string) $file);
            $app = basename($appDir);
            if (strcasecmp($app, 'Uuims') === 0) {
                continue;
            }
            $doc = Manifest::read($appDir);
            if ($doc === null) {
                continue;
            }
            foreach (Manifest::services($doc) as $svc) {
                if (strtolower((string) ($svc['id'] ?? '')) !== self::SERVICE_ID) {
                    continue;
                }
                if ((string) ($svc['role'] ?? '') !== 'sub') {
                    continue;
                }
                $out[] = [
                    'rpc_service' => strtolower($app),
                    'package' => (string) ($doc['package'] ?? ''),
                    'app' => $app,
                    'version' => isset($doc['version']) ? (string) $doc['version'] : null,
                    'registered_at' => 'discovery',
                ];
                break;
            }
        }

        return $out;
    }
}
