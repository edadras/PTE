<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Http\Requests\Api\ApiFormRequest;

final class StoreExamRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'total_score' => ['nullable', 'numeric', 'min:0'],
            'passing_score' => ['nullable', 'numeric', 'min:0'],
            'rules' => ['nullable', 'array'],
            'availability' => ['nullable', 'array'],
        ];
    }
}
