<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class StartPracticeRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module' => ['required_without:type', Rule::enum(ModuleKey::class)],
            'type' => ['nullable', Rule::enum(QuestionType::class)],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
