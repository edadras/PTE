<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Services\BrandResolver;

/**
 * Suspension keeps every row intact: the bot answers with a "temporarily
 * unavailable" notice and the panel becomes read-only.
 *
 * @see docs/01-multi-tenancy.md §8
 */
final class SuspendAcademy
{
    public function __construct(private readonly BrandResolver $brandResolver) {}

    public function handle(Academy $academy, ?string $reason = null): Academy
    {
        if ($academy->status !== AcademyStatus::Suspended) {
            $academy->forceFill([
                'status' => AcademyStatus::Suspended,
            ])->save();
        }

        if ($reason !== null) {
            $settings = $academy->settings;

            $settings?->forceFill([
                'features' => [...($settings->features ?? []), 'suspension_reason' => $reason],
            ])->save();
        }

        $this->brandResolver->forget($academy);

        return $academy->refresh();
    }
}
