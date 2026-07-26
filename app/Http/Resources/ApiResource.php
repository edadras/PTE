<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for every single-object API response.
 *
 * Guarantees the `{ "data": …, "meta": { "request_id": … } }` shape of
 * docs/08 §4 without each resource having to remember it.
 *
 * Not final: it exists to be extended. Concrete resources are.
 */
abstract class ApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => self::requestId($request)]];
    }

    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return (string) $request->headers->get(ForceJsonResponse::HEADER, 'unknown');
    }

    /**
     * @param  iterable<int, mixed>  $resource
     */
    public static function collection($resource): ApiCollection
    {
        return new ApiCollection($resource, static::class);
    }
}
