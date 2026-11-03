<?php

declare(strict_types=1);

namespace App\Uuims\Modules\Render\Controller;

use App\Uuims\Render\PageService;
use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\Api;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Render\ClientInfo;
use Loongs\Render\Http\Etag;

/**
 * GET /uuims/api/pages/{page}
 *   Headers: Authorization: Bearer …, X-Client-Platform: pc|mobile, X-Client-Env: h5|app|mp-weixin,
 *            X-Render-Schema: 1, Accept-Language: en-US (→ LocaleMiddleware), If-None-Match: "<etag>"
 *   200 {code: 0, data: <page description>} + ETag | 304 | 403 40301 | 404 40401 / 40402 | 426 42601
 */
final class PageController
{
    public function __construct(
        private readonly PageService $pages,
    ) {
    }

    public function show(Request $request): Response
    {
        $client = ClientInfo::fromHeaders($request->headers());
        $desc = $this->pages->describe((string) $request->getAttribute('page', ''), UuimsContext::require(), $client, $request->queryAll());
        $etag = Etag::of($desc);
        $vary = 'Authorization, X-Client-Platform, X-Client-Env, X-Render-Schema, Accept-Language';
        if (Etag::matches($request->header('if-none-match'), $etag)) {
            return (new Response())->status(304)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache')->header('Vary', $vary);
        }

        return Api::ok($desc)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache')->header('Vary', $vary);
    }
}
