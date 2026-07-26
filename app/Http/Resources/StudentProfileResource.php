<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\Student;
use Illuminate\Http\Request;

/**
 * The student's own view of themselves — deliberately narrower than
 * StudentResource, which is an academy-staff view.
 *
 * @mixin Student
 */
final class StudentProfileResource extends ApiResource
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
            'locale' => $this->locale,
            'level' => $this->level,
            'target_score' => $this->target_score,
            'status' => $this->status->value,
            'has_active_subscription' => $this->hasActiveSubscription(),
            'subscription_expires_at' => $this->subscription_expires_at?->toIso8601String(),
        ];
    }
}
