<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Learning\Models\Question;
use Illuminate\Http\Request;

/**
 * Author-facing question view: includes the answer key.
 *
 * @mixin Question
 */
final class QuestionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'bank_id' => (int) $this->bank_id,
            'module' => $this->module_key->value,
            'type' => $this->type->value,
            'difficulty' => $this->difficulty->value,
            'difficulty_index' => (float) $this->difficulty_index,
            'title' => $this->title,
            'content' => $this->content,
            'correct_answer' => $this->correct_answer,
            'tags' => $this->tags ?? [],
            'status' => $this->status->value,
            'usage_count' => (int) $this->usage_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
