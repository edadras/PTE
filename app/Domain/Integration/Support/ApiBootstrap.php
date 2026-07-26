<?php

declare(strict_types=1);

namespace App\Domain\Integration\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Everything the API layer needs installed before the first request reaches a
 * route: the named rate limiters, and the error-envelope renderer.
 *
 * Called from each of the three route files so the layer is self-contained, and
 * from IntegrationServiceProvider so a cached route table (`route:cache`, which
 * stops route files from being executed) does not silently drop it.
 *
 * The renderer belongs in `bootstrap/app.php`'s `withExceptions()` block; this
 * is the same registration expressed from inside the layer that owns it, and
 * becomes redundant the moment that hook exists.
 */
final class ApiBootstrap
{
    private const RENDERER_FLAG = 'pte.api.exception-renderer';

    public static function register(): void
    {
        ApiRateLimiters::register();

        self::registerExceptionRenderer();
    }

    /**
     * `Illuminate\Routing\Pipeline` renders exceptions through the handler
     * before they can propagate out of the middleware stack, so this — not a
     * `try`/`catch` in a middleware — is what actually shapes API errors.
     */
    public static function registerExceptionRenderer(): void
    {
        $container = app();

        if ($container->bound(self::RENDERER_FLAG)) {
            return;
        }

        $container->instance(self::RENDERER_FLAG, true);

        $handler = $container->make(ExceptionHandler::class);

        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $mapper = $container->make(ApiExceptionMapper::class);

        $handler->renderable(
            static fn (Throwable $e, Request $request): ?Response => $mapper->render($e, $request)
        );
    }
}
