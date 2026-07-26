<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Http\Requests\Api\ApiFormRequest;

final class StartBroadcastRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'content' => ['required', 'array'],
            'content.text' => ['required', 'string', 'max:4000'],
            'audience' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
