<?php

declare(strict_types=1);

use App\Uuims\Middleware\UuimsAuthMiddleware;

/** Page descriptions need a valid uuims token; the page permission is checked by PageService. */
return [
    UuimsAuthMiddleware::class,
];
