<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Academy;

use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\QuestionType;
use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

final class StoreQuestionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_id' => ['required', 'integer', 'exists:question_banks,id'],
            'type' => ['required', Rule::enum(QuestionType::class)],
            'difficulty' => ['nullable', Rule::enum(Difficulty::class)],
            'title' => ['nullable', 'string', 'max:190'],
            // The shape of `content` is type-specific and is validated by
            // QuestionContentValidator inside the action — duplicating it here
            // would guarantee the two drift apart.
            'content' => ['required', 'array'],
            'correct_answer' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'options' => ['nullable', 'array'],
            'options.*.text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.key' => ['nullable', 'string', 'max:8'],
            'media' => ['nullable', 'array'],
            'media.*.kind' => ['required_with:media', 'string', 'max:20'],
            'media.*.s3_path' => ['required_with:media', 'string', 'max:255'],
            'publish' => ['nullable', 'boolean'],
        ];
    }
}
