<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\Student;
use Illuminate\Http\Request;

/**
 * @mixin Student
 */
final class StudentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'student_code' => $this->student_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            // Contact details are $hidden on the model; the Academy API is the
            // one surface that legitimately needs them back (docs/12 §4).
            'email' => $this->getAttribute('email'),
            'phone' => $this->getAttribute('phone'),
            'locale' => $this->locale,
            'level' => $this->level,
            'target_score' => $this->target_score,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'subscription_status' => $this->subscription_status,
            'subscription_expires_at' => $this->subscription_expires_at?->toIso8601String(),
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'registered_at' => $this->registered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
