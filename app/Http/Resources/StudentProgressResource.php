<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\StudentProgress;
use Illuminate\Http\Request;

/**
 * @mixin StudentProgress
 */
final class StudentProgressResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'module' => $this->module()?->value ?? $this->module_key,
            'question_type' => $this->question_type,
            'attempts' => (int) $this->attempts,
            'avg_score' => $this->avg_score === null ? null : (float) $this->avg_score,
            'best_score' => $this->best_score === null ? null : (float) $this->best_score,
            'last_score' => $this->last_score === null ? null : (float) $this->last_score,
            'streak_days' => (int) $this->streak_days,
            'total_time_seconds' => (int) $this->total_time_seconds,
            'last_practiced_at' => $this->last_practiced_at?->toIso8601String(),
        ];
    }
}
