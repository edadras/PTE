<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Assessment\Models\Score;
use Illuminate\Http\Request;

/**
 * @mixin Score
 */
final class ScoreResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'student_id' => (int) $this->student_id,
            'session_type' => $this->session_type->value,
            'session_id' => (int) $this->session_id,
            'module' => $this->module_key?->value,
            'raw_score' => (float) $this->raw_score,
            'scaled_score' => $this->scaled_score === null ? null : (float) $this->scaled_score,
            'percentage' => (float) $this->percentage,
            'breakdown' => $this->breakdown,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
