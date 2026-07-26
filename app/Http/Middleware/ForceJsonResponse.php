<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
 *  3. convert any throwable escaping the inner pipeline into that envelope, via
 *     ApiExceptionMapper.
 *
 * Point 3 lives here rather than in `bootstrap/app.php` only so that this layer
 * owns its own contract; the same mapper should also be registered as a global
 * renderer so exceptions raised *outside* the route pipeline (routing misses,
 * middleware priority faults) get the same shape.
 */
final class ForceJsonResponse
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly ApiExceptionMapper $mapper) {}

    public function handle(Request $request, Closure $next): Response
    {
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
     * be joined, but it is length-capped and stripped: it ends up in log files.
     */
    private function requestId(Request $request): string
    {
        $supplied = (string) $request->headers->get(self::HEADER, '');
        $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', $supplied) ?? '';

        return $clean === '' ? (string) Str::ulid() : mb_substr($clean, 0, 64);
    }
}
