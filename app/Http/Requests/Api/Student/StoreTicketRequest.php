<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Domain\Support\Enums\TicketPriority;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class StoreTicketRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
        ];
    }
}
