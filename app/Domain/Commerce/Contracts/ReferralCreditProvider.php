<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Contracts;

/**
 * Referral credit hook (docs/09 §5: both sides earn credit).
 *
 * Commerce only needs to know "how much credit may this student spend" and
 * "consume this much"; who earned it and why belongs to whichever domain owns
 * the referral programme.
 */
interface ReferralCreditProvider
{
    /** Spendable balance in minor units of $currency. */
    public function balanceFor(int $academyId, int $studentId, string $currency): int;

    /** @return int Amount actually consumed, in minor units. */
    public function consume(int $academyId, int $studentId, int $amount, string $currency): int;

    /** Called after a purchase settles, so the referrer can be rewarded. */
    public function rewardReferrer(int $academyId, int $studentId, int $purchaseAmount, string $currency): void;
}
