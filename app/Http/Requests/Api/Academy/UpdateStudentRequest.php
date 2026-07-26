<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Domain\Identity\Enums\StudentStatus;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class UpdateStudentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'level' => ['sometimes', 'nullable', 'string', 'max:10'],
            'target_score' => ['sometimes', 'nullable', 'numeric', 'between:0,90'],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
        ];
    }
}
