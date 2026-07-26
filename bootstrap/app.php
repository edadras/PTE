<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\Middleware\ResolveTenantFromApiKey;
use App\Domain\Tenancy\Middleware\ResolveTenantFromDomain;
use App\Domain\Telegram\Middleware\ResolveTenantFromBot;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Telegram sends neither cookies nor a CSRF token, so the webhook
            // is registered outside the web group entirely.
            Illuminate\Support\Facades\Route::middleware([])
                ->group(base_path('routes/telegram.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.domain' => ResolveTenantFromDomain::class,
            'tenant.api' => ResolveTenantFromApiKey::class,
            'tenant.bot' => ResolveTenantFromBot::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
