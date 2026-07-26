<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ModuleKey;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class StoreCourseRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'module_key' => ['nullable', Rule::enum(ModuleKey::class)],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['nullable', Rule::enum(CourseStatus::class)],
        ];
    }
}
