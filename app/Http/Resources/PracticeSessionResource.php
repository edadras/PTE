<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\PracticeSession;
use Illuminate\Http\Request;

/**
 * @mixin PracticeSession
 */
final class PracticeSessionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'student_id' => (int) $this->student_id,
            'module' => $this->module_key->value,
            'question_type' => $this->question_type?->value,
            'status' => $this->status->value,
            'total_questions' => (int) $this->total_questions,
            'answered' => (int) $this->answered,
            'total_score' => $this->total_score === null ? null : (float) $this->total_score,
            'max_score' => $this->max_score === null ? null : (float) $this->max_score,
            'percentage' => $this->percentage(),
            'next_question_id' => $this->nextQuestionId(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'questions' => StudentQuestionResource::collection(
                $this->whenLoaded('questions')
            ),
        ];
    }
}
