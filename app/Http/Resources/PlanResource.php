<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Commerce\Models\Plan;
use Illuminate\Http\Request;

/**
 * @mixin Plan
 */
final class PlanResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'key' => $this->planKey()?->value,
            'name' => $this->label(),
            'limits' => $this->limits,
            'features' => $this->features,
            'is_public' => (bool) $this->is_public,
        ];
    }
}
