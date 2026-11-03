<?php

declare(strict_types=1);

use App\Uuims\Middleware\ApiExceptionMiddleware;
use Loongs\Language\Integration\Framework\LocaleMiddleware;

/**
 * App-level middleware for every Uuims route (after global RequestId/CORS). LocaleMiddleware first
 * (loongs/language, README §19): the request locale (ui_locales / lang / Accept-Language) is set
 * before messages and errors are rendered.
 */
return [
    LocaleMiddleware::class,
    ApiExceptionMiddleware::class,
];
