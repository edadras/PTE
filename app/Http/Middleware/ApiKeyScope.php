<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\ApiKey;
use App\Domain\Integration\Exceptions\InsufficientScopeException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the API key scopes of docs/08 §2: `api.scope:students:read`.
 *
 * Several scopes may be listed; holding any one of them is enough, which is how
 * a read endpoint accepts both `students:read` and a broader write key.
 *
 * @see docs/08-api-and-integrations.md §2
 */
final class ApiKeyScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = $request->attributes->get('api_key');

        // The tenant middleware runs first and would have rejected the request;
        // reaching here without a key means the route is misconfigured.
        if (! $apiKey instanceof ApiKey) {
            throw new AuthenticationException(__('api.errors.unauthenticated'));
        }

        if ($scopes === []) {
            return $next($request);
        }

        foreach ($scopes as $scope) {
            if ($apiKey->hasScope($scope)) {
                return $next($request);
            }
        }

        throw InsufficientScopeException::forScope($scopes[0]);
    }
}
