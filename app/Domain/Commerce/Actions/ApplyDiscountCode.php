<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Data\DiscountResult;
use App\Domain\Commerce\Models\DiscountCode;
use App\Domain\Commerce\Models\DiscountRedemption;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\StudentSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Validate and price a discount code (docs/09 §5).
 *
 * Never throws for a bad code: a student typing an expired code into a bot is
 * an everyday event, and the bot needs a message, not an exception.
 */
final class ApplyDiscountCode
{
    public function handle(
        int $academyId,
        string $code,
        int $amount,
        string $currency = 'IRR',
        ?int $studentId = null,
    ): DiscountResult {
        /** @var DiscountCode|null $discount */
        $discount = DiscountCode::query()
            ->forAcademy($academyId)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->first();

        if (! $discount instanceof DiscountCode) {
            return DiscountResult::rejected($amount, 'not_found', $currency);
        }

        if (! $discount->is_active) {
            return DiscountResult::rejected($amount, 'inactive', $currency);
        }

        if (! $discount->isWithinWindow()) {
            return DiscountResult::rejected($amount, 'expired', $currency);
        }

        if (! $discount->hasRedemptionsLeft()) {
            return DiscountResult::rejected($amount, 'exhausted', $currency);
        }

        if ($amount < $discount->min_order_amount) {
            return DiscountResult::rejected($amount, 'below_minimum', $currency);
        }

        if ($studentId !== null && $this->timesUsedBy($academyId, $discount, $studentId) >= $discount->per_student_limit) {
            return DiscountResult::rejected($amount, 'already_used', $currency);
        }

        return DiscountResult::applied($discount, $amount, $discount->discountFor($amount), $currency);
    }

    /**
     * Consume one use.
     *
     * The counter is bumped with a conditional UPDATE rather than read-modify-
     * write, so a code with one use left cannot be redeemed twice at once.
     */
    public function redeem(
        DiscountResult $result,
        ?int $studentId = null,
        ?StudentSubscription $subscription = null,
        ?Payment $payment = null,
    ): ?DiscountRedemption {
        if (! $result->valid || ! $result->code instanceof DiscountCode) {
            return null;
        }

        $code = $result->code;

        return DB::transaction(function () use ($code, $result, $studentId, $subscription, $payment): ?DiscountRedemption {
            $query = DB::table('discount_codes')->where('id', $code->getKey());

            if ($code->max_redemptions !== null) {
                $query->where('redemptions_count', '<', $code->max_redemptions);
            }

            if ($query->update(['redemptions_count' => DB::raw('redemptions_count + 1'), 'updated_at' => now()]) === 0) {
                return null;
            }

            $redemption = new DiscountRedemption;
            $redemption->forceFill([
                'academy_id' => $code->academy_id,
                'discount_code_id' => $code->getKey(),
                'student_id' => $studentId,
                'student_subscription_id' => $subscription?->getKey(),
                'payment_id' => $payment?->getKey(),
                'amount_discounted' => $result->discountAmount,
                'currency' => $result->currency,
                'redeemed_at' => now(),
            ])->save();

            return $redemption;
        });
    }

    private function timesUsedBy(int $academyId, DiscountCode $code, int $studentId): int
    {
        return DiscountRedemption::query()
            ->forAcademy($academyId)
            ->where('discount_code_id', $code->getKey())
            ->where('student_id', $studentId)
            ->count();
    }
}
