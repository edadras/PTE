<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Integration\Support\ApiBootstrap;
use App\Domain\Integration\Support\ApiExceptionMapper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The outermost middleware of every API route group.
 *
 * Three jobs:
 *  1. force JSON negotiation, so a client that forgot `Accept` still gets an
 *     envelope rather than an HTML error page;
 *  2. mint the `X-Request-Id` that every response — success or failure —
 *     carries, and that the error envelope echoes back (docs/08 §4);
 *  3. make sure ApiExceptionMapper is what turns a throwable into that
 *     envelope.
 *
 * Point 3 needs a word of explanation. `Illuminate\Routing\Pipeline` catches
 * exceptions *inside* the middleware stack and renders them through the
 * exception handler, so a `try`/`catch` around `$next()` never sees a
 * controller exception — by the time control returns here it is already a
 * response. The mapper is therefore attached to the handler as a renderable
 * callback. Registering it in `bootstrap/app.php` instead is strictly better
 * and this call becomes a no-op the moment that happens; the local `catch` is
 * kept for throwables raised before routing hands over to the pipeline.
 */
final class ForceJsonResponse
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly ApiExceptionMapper $mapper) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Idempotent, and a no-op once the renderer is wired in bootstrap/app.php.
        ApiBootstrap::registerExceptionRenderer();

        $request->headers->set('Accept', 'application/json');

        $requestId = $this->requestId($request);
        $request->attributes->set('request_id', $requestId);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $response = $this->mapper->render($e, $request);

            if ($response === null) {
                throw $e;
            }
        }

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * A caller-supplied id is honoured so a mobile crash report and our logs can
     * be joined, but it is stripped and length-capped: it ends up in log files.
     */
    private function requestId(Request $request): string
    {
        $supplied = (string) $request->headers->get(self::HEADER, '');
        $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', $supplied) ?? '';

        return $clean === '' ? (string) Str::ulid() : mb_substr($clean, 0, 64);
    }
}
