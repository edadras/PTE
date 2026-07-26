<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Contracts\ReferralCreditProvider;

/**
 * Default: no referral programme wired up. Purchases still work, they just
 * never find any credit to apply.
 */
final class NullReferralCredits implements ReferralCreditProvider
{
    public function balanceFor(int $academyId, int $studentId, string $currency): int
    {
        return 0;
    }

    public function consume(int $academyId, int $studentId, int $amount, string $currency): int
    {
        return 0;
    }

    public function rewardReferrer(int $academyId, int $studentId, int $purchaseAmount, string $currency): void {}
}
