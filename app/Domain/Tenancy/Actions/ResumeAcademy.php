<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Events\AcademyResumed;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Services\BrandResolver;

/**
 * @see docs/01-multi-tenancy.md §8
 */
final class ResumeAcademy
{
    public function __construct(private readonly BrandResolver $brandResolver) {}

    public function handle(Academy $academy): Academy
    {
        if ($academy->trashed()) {
            $academy->restore();
        }

        $academy->forceFill(['status' => AcademyStatus::Active])->save();

        $settings = $academy->settings;

        if ($settings !== null && array_key_exists('suspension_reason', $settings->features ?? [])) {
            $features = $settings->features ?? [];
            unset($features['suspension_reason']);

            $settings->forceFill(['features' => $features])->save();
        }

        $this->brandResolver->forget($academy);

        $academy->refresh();

        // Mandatory audit hook (docs/02 §7).
        AcademyResumed::dispatch($academy);

        return $academy;
    }
}
