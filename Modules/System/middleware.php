<?php

declare(strict_types=1);

use App\Uuims\Middleware\UuimsAuthMiddleware;

/** Every System route needs a valid uuims token; button permissions are declared per route. */
return [
    UuimsAuthMiddleware::class,
];
