<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Exam;
use Illuminate\Http\Request;

/**
 * @mixin Exam
 */
final class ExamResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => (int) $this->duration_minutes,
            'total_score' => (float) $this->total_score,
            'passing_score' => $this->passing_score === null ? null : (float) $this->passing_score,
            'status' => $this->status->value,
            'max_attempts' => $this->maxAttempts(),
            'opens_at' => $this->opensAt()?->toIso8601String(),
            'closes_at' => $this->closesAt()?->toIso8601String(),
            'is_available' => $this->isAvailable(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
