<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Answer;
use Illuminate\Http\Request;

/**
 * @mixin Answer
 */
final class AnswerResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'session_type' => $this->session_type->value,
            'session_id' => (int) $this->session_id,
            'question_id' => (int) $this->question_id,
            'student_id' => (int) $this->student_id,
            'score' => $this->score === null ? null : (float) $this->score,
            'max_score' => $this->effectiveMaxScore(),
            'percentage' => $this->percentage(),
            'scoring_status' => $this->scoring_status->value,
            'scored_by' => $this->scored_by?->value,
            'skipped' => $this->isSkipped(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
