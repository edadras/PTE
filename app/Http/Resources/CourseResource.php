<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Learning\Models\Course;
use Illuminate\Http\Request;

/**
 * @mixin Course
 */
final class CourseResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'module' => $this->module_key instanceof \BackedEnum ? $this->module_key->value : $this->module_key,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'duration_days' => $this->duration_days === null ? null : (int) $this->duration_days,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
