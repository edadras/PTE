<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\PaymentGatewayKey;

/**
 * What a student picked in the bot (docs/09 §5).
 */
final readonly class StudentPlanPurchase
{
    /**
     * @param  int  $price  Minor units of $currency.
     * @param  array<string, mixed>  $entitlements  What the plan unlocks — mirrored onto the subscription.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $academyId,
        public int $studentId,
        public string $planName,
        public int $price,
        public string $currency = 'IRR',
        public ?string $planKey = null,
        public ?int $courseId = null,
        public ?int $durationDays = null,
        public ?string $discountCode = null,
        public bool $useReferralCredit = true,
        public bool $autoRenew = false,
        public ?PaymentGatewayKey $gateway = null,
        public ?string $callbackUrl = null,
        public array $entitlements = [],
        public array $metadata = [],
    ) {}
}
