<?php

declare(strict_types=1);

use App\Uuims\Middleware\UuimsAuthMiddleware;

/** Sample data needs a valid uuims token; the permission is declared per route. */
return [
    UuimsAuthMiddleware::class,
];
