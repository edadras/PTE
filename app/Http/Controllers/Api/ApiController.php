<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Models\ApiKey;
use App\Domain\Identity\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for every API controller. Deliberately tiny: controllers are
 * validate → call an Action → return a Resource, and anything larger than that
 * belongs in a domain service (CONVENTIONS §6).
 */
abstract class ApiController extends Controller
{
    /** Page size ceiling — an unbounded `per_page` is a denial-of-service knob. */
    protected const MAX_PER_PAGE = 100;

    protected const DEFAULT_PER_PAGE = 25;

    protected function perPage(Request $request): int
    {
        $requested = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min($requested, self::MAX_PER_PAGE));
    }

    protected function apiKey(Request $request): ApiKey
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof ApiKey) {
            throw new AuthenticationException(__('api.errors.unauthenticated'));
        }

        return $key;
    }

    protected function student(Request $request): Student
    {
        $student = $request->attributes->get('student');

        if (! $student instanceof Student) {
            throw new AuthenticationException(__('api.errors.unauthenticated'));
        }

        return $student;
    }

    /**
     * A plain `{ "data": …, "meta": { "request_id": … } }` body for endpoints
     * whose payload is a computed report rather than a model.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function payload(Request $request, array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => ['request_id' => ApiResource::requestId($request)] + $meta,
        ], $status);
    }
}
