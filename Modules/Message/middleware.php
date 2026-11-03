<?php

declare(strict_types=1);

use App\Uuims\Middleware\UuimsAuthMiddleware;

/** Messages need a valid uuims token; the inbox is per uuims (no permission code), sending needs message:send. */
return [
    UuimsAuthMiddleware::class,
];
