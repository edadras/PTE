<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Http\Requests\Api\ApiFormRequest;

final class TelegramAuthRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'init_data' => ['required', 'string', 'max:4096'],
        ];
    }
}
