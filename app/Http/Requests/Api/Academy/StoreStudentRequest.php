<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Enums\StudentStatus;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class StoreStudentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:5'],
            'level' => ['nullable', 'string', 'max:10'],
            'target_score' => ['nullable', 'numeric', 'between:0,90'],
            'status' => ['nullable', Rule::enum(StudentStatus::class)],
            'source' => ['nullable', Rule::enum(StudentSource::class)],
            'student_code' => ['nullable', 'string', 'max:32'],
            'class_group_id' => ['nullable', 'integer', 'exists:class_groups,id'],
        ];
    }
}
