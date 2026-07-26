<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Http\Requests\Api\ApiFormRequest;

/**
 * A student may edit their own display data and study goal — never their
 * status, code, subscription or academy.
 */
final class UpdateProfileRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'level' => ['sometimes', 'nullable', 'string', 'max:10'],
            'target_score' => ['sometimes', 'nullable', 'numeric', 'between:0,90'],
        ];
    }
}
