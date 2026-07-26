<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Tenancy\Services\BrandResolver;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\BrandResource;

/**
 * `GET /api/student/v1/brand` — unauthenticated on purpose: the web app needs
 * the palette and the logo before a student has logged in (docs/08 §6).
 */
final class BrandController extends ApiController
{
    public function __invoke(BrandResolver $resolver): BrandResource
    {
        return BrandResource::make($resolver->resolve());
    }
}
