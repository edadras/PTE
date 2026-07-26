<?php

declare(strict_types=1);

use App\Domain\Identity\Providers\AuthorizationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    AuthorizationServiceProvider::class,
    RateLimitServiceProvider::class,
];
