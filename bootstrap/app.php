<?php

declare(strict_types=1);

use App\Domain\Integration\Support\ApiExceptionMapper;
use App\Domain\Telegram\Middleware\ResolveTenantFromBot;
use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\Middleware\ResolveTenantFromApiKey;
use App\Domain\Tenancy\Middleware\ResolveTenantFromDomain;
use App\Http\Middleware\ApiKeyScope;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\StudentTokenGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Telegram sends neither cookies nor a CSRF token, so the webhook
            // is registered outside the web group entirely.
            Route::middleware([])
                ->group(base_path('routes/telegram.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.domain' => ResolveTenantFromDomain::class,
            'tenant.api' => ResolveTenantFromApiKey::class,
            'tenant.bot' => ResolveTenantFromBot::class,
            'api.json' => ForceJsonResponse::class,
            'api.scope' => ApiKeyScope::class,
            'student.token' => StudentTokenGuard::class,
            'idempotency' => IdempotencyKey::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         | The API error envelope. This has to live on the handler rather than
         | in middleware: Illuminate's routing pipeline renders exceptions
         | through the handler from *inside* the middleware stack, so a
         | try/catch around $next() never sees them.
         */
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionMapper::class)->render($e, $request)
        );

        /*
         | A missing tenant in a web request is always a bug on our side, never
         | something the caller can fix. Log it loudly and show a neutral 404 —
         | telling an unauthenticated visitor "no tenant resolved" only leaks
         | that the platform is multi-tenant.
         */
        $exceptions->render(function (TenantNotResolvedException $e, Request $request) {
            Log::error('Tenant could not be resolved.', [
                'url' => $request->fullUrl(),
                'host' => $request->getHost(),
                'exception' => $e->getMessage(),
            ]);

            return $request->expectsJson()
                ? response()->json(['error' => ['code' => 'TENANT_NOT_RESOLVED']], 404)
                : response()->view('errors.tenant-not-found', status: 404);
        });
    })
    ->create();
