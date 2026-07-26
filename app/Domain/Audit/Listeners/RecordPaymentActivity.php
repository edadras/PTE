<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Commerce\Events\PaymentRecorded;

/**
 * Mandatory audit (docs/02 §7): payment information changes.
 *
 * Amount, currency and gateway only — `meta` may carry a masked PAN the gateway
 * returned, and an audit row is not the place to keep a second copy of it.
 */
final class RecordPaymentActivity
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        $this->recorder->record(
            AuditAction::PaymentRecorded,
            $payment,
            [],
            [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'gateway' => $payment->gateway->value,
                'status' => $payment->status->value,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            (int) $payment->academy_id,
        );
    }
}
