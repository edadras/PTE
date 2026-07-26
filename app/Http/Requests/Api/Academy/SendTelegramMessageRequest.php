<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Http\Requests\Api\ApiFormRequest;

final class SendTelegramMessageRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:4000'],
            'buttons' => ['nullable', 'array'],
            'buttons.*.text' => ['required_with:buttons', 'string', 'max:64'],
            'buttons.*.url' => ['nullable', 'url'],
            'buttons.*.callback_data' => ['nullable', 'string', 'max:64'],
        ];
    }
}
