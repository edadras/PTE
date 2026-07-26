<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base for every list response.
 *
 * Laravel's default pagination block carries eight meta keys and four links.
 * docs/08 §4 specifies four and two, and a mobile client that was written
 * against the documented shape must not break because the framework added a
 * key — so the block is narrowed here, once.
 *
 * Not final: `new ApiCollection($paginator, StudentResource::class)` is the
 * common case, but a context may subclass it to add its own aggregate meta.
 */
class ApiCollection extends ResourceCollection
{
    /**
     * @param  iterable<int, mixed>  $resource
     * @param  class-string|null  $collects
     */
    public function __construct($resource, ?string $collects = null)
    {
        if ($collects !== null) {
            $this->collects = $collects;
        }

        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => ApiResource::requestId($request)]];
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'links' => [
                'next' => $paginated['next_page_url'] ?? null,
                'prev' => $paginated['prev_page_url'] ?? null,
            ],
            'meta' => [
                'current_page' => (int) ($paginated['current_page'] ?? 1),
                'per_page' => (int) ($paginated['per_page'] ?? 0),
                'total' => (int) ($paginated['total'] ?? 0),
                'last_page' => (int) ($paginated['last_page'] ?? 1),
            ],
        ];
    }
}
