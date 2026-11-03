<?php

declare(strict_types=1);

namespace App\Uuims\Middleware;

use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use App\Uuims\Support\I18n;
use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Middleware\MiddlewareInterface;

/**
 * Route-level button / menu permission: $router->post('/x', $action, [PermissionMiddleware::need('system:uuims:create')]).
 * Runs after UuimsAuthMiddleware (module middleware). Super uuims passes; otherwise 403 code 40301.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    /** @var list<string> any of these codes */
    private array $codes = [];

    public static function need(string ...$codes): self
    {
        $m = new self();
        $m->codes = array_values($codes);

        return $m;
    }

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->codes as $code) {
            if (UuimsContext::can($code)) {
                return $next($request);
            }
        }

        return Api::fail(40301, I18n::t('无权限：{codes}', ['codes' => implode(' / ', $this->codes)]), 403, ['required' => $this->codes]);
    }
}
