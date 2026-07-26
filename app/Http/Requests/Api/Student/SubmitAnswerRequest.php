<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Http\Requests\Api\ApiFormRequest;

final class SubmitAnswerRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer'],
            // The per-type shape is asserted by SubmitAnswer::assertShape(), the
            // one place that knows what a "ordering" answer must look like.
            'answer' => ['nullable', 'array'],
            'media_path' => ['nullable', 'string', 'max:255'],
            'transcript' => ['nullable', 'string', 'max:10000'],
            'skipped' => ['nullable', 'boolean'],
        ];
    }
}
