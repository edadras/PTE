<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Learning\Models\Course;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Academy\StoreCourseRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CourseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CourseController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $courses = Course::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($courses, CourseResource::class);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $attributes['slug'] ??= Str::slug((string) $attributes['title']);

        $course = Course::query()->create($attributes);

        return CourseResource::make($course)->response()->setStatusCode(201);
    }
}
