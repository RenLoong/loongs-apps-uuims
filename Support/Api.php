<?php

declare(strict_types=1);

namespace App\Uuims\Support;

use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Orm\Pagination\Paginator;

/**
 * Unified responses: {"code":0,"message":"ok","data":…}; pages: data = {list,total,page,page_size,…}.
 * message and data.errors are translated into the request locale (I18n::msg).
 */
final class Api
{
    public static function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return (new Response())->json(data: $data, message: I18n::msg($message), code: 0);
    }

    public static function fail(int $code, string $message, int $httpStatus = 400, mixed $data = null): Response
    {
        if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $data['errors'] = array_map(static fn (mixed $m): mixed => is_string($m) ? I18n::msg($m) : $m, $data['errors']);
        }

        return (new Response())->json(data: $data, message: I18n::msg($message), code: $code, httpStatus: $httpStatus);
    }

    /**
     * @param callable(mixed): array<string, mixed>|null $map item mapper (default: toArray())
     * @param array<string, mixed> $extra merged into data (e.g. field permissions)
     */
    public static function page(Paginator $p, ?callable $map = null, array $extra = []): Response
    {
        $list = [];
        foreach ($p->items() as $item) {
            $list[] = $map !== null ? $map($item) : (is_array($item) ? $item : $item->toArray());
        }

        return self::ok(['list' => $list, 'total' => $p->total, 'page' => $p->currentPage, 'page_size' => $p->perPage] + $extra);
    }

    /** JSON body (falls back to form fields). @return array<string, mixed> */
    public static function input(Request $request): array
    {
        $json = $request->json();

        return is_array($json) ? $json : $request->postAll();
    }

    /** @return array{0: int, 1: int} page, page_size (1..100) */
    public static function pagination(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $size = min(100, max(1, (int) $request->query('page_size', 20)));

        return [$page, $size];
    }

    public static function id(Request $request, string $key = 'id'): int
    {
        $id = (int) $request->getAttribute($key, 0);
        if ($id <= 0) {
            throw ApiException::notFound();
        }

        return $id;
    }
}
