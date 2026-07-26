<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\ExamSession;
use Illuminate\Http\Request;

/**
 * The exam snapshot itself is never serialised: it holds the frozen question
 * set including answer keys, and the student sits on the other end of this JSON.
 *
 * @mixin ExamSession
 */
final class ExamSessionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'exam_id' => (int) $this->exam_id,
            'student_id' => (int) $this->student_id,
            'attempt_number' => (int) $this->attempt_number,
            'status' => $this->status->value,
            'current_section_id' => $this->current_section_id === null ? null : (int) $this->current_section_id,
            'total_questions' => $this->totalQuestions(),
            'max_score' => $this->maxScore(),
            'total_score' => $this->total_score === null ? null : (float) $this->total_score,
            'passed' => $this->passed === null ? null : (bool) $this->passed,
            'started_at' => $this->started_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'scored_at' => $this->scored_at?->toIso8601String(),
        ];
    }
}
