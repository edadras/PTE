<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Platform;

use App\Http\Requests\Api\ApiFormRequest;

final class UpdateAcademyRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'plan_id' => ['sometimes', 'nullable', 'integer', 'exists:plans,id'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
        ];
    }
}
