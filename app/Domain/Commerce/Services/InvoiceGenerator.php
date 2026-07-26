<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Data\InvoiceData;
use App\Domain\Commerce\Data\InvoiceLine;
use App\Domain\Commerce\Models\Invoice;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View as ViewFactory;
use RuntimeException;

/**
 * Issues invoices with a per-academy sequential number.
 *
 * The numbering is the delicate part: two invoices issued in the same second
 * must not collide, and a tax authority will not accept a gap. So the sequence
 * is read inside a transaction with a row lock and the table carries a
 * UNIQUE(academy_id, sequence) — `max()+1` outside a lock is exactly the bug
 * this class exists to avoid.
 *
 * @see docs/09-billing-and-plans.md §7
 */
final class InvoiceGenerator
{
    public const NUMBER_PREFIX = 'INV';

    /** Iranian VAT, in basis points (900 = 9%). Overridable per country. */
    private const DEFAULT_TAX_RATE_BP = 900;

    /** How many times to retry when a concurrent issuer wins the sequence. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ?int $taxRateBasisPoints = null) {}

    /**
     * Invoice one billing period of a subscription.
     *
     * @param  array<string, mixed>  $billingDetails
     */
    public function forSubscription(
        Subscription $subscription,
        ?CarbonInterface $issuedAt = null,
        array $billingDetails = [],
        ?int $taxRateBasisPoints = null,
    ): Invoice {
        $periodLabel = $this->periodLabel($subscription);

        $line = InvoiceLine::of(
            __('billing.invoice.line_subscription', [
                'plan' => $subscription->plan?->label() ?? __('billing.invoice.plan_fallback'),
                'cycle' => $subscription->billing_cycle->label(),
            ]),
            $subscription->price,
            1,
            $periodLabel,
        );

        return $this->issue(
            academyId: $subscription->academy_id,
            lines: [$line],
            currency: $subscription->currency,
            issuedAt: $issuedAt,
            subscriptionId: $subscription->getKey(),
            billingDetails: $billingDetails,
            taxRateBasisPoints: $taxRateBasisPoints,
        );
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    public function forPayment(Payment $payment, array $billingDetails = [], ?int $taxRateBasisPoints = null): Invoice
    {
        $line = InvoiceLine::of(
            $payment->description ?: __('billing.invoice.line_payment'),
            $payment->amount,
        );

        return $this->issue(
            academyId: $payment->academy_id,
            lines: [$line],
            currency: $payment->currency,
            issuedAt: $payment->paid_at,
            paymentId: $payment->getKey(),
            billingDetails: $billingDetails,
            taxRateBasisPoints: $taxRateBasisPoints,
            status: $payment->isPaid() ? Invoice::STATUS_PAID : Invoice::STATUS_ISSUED,
        );
    }

    /**
     * @param  array<int, InvoiceLine>  $lines
     * @param  array<string, mixed>  $billingDetails
     */
    public function issue(
        int $academyId,
        array $lines,
        string $currency = 'IRR',
        ?CarbonInterface $issuedAt = null,
        ?int $subscriptionId = null,
        ?int $paymentId = null,
        array $billingDetails = [],
        ?int $taxRateBasisPoints = null,
        ?CarbonInterface $dueAt = null,
        string $status = Invoice::STATUS_ISSUED,
    ): Invoice {
        $issuedAt = CarbonImmutable::instance($issuedAt ?? now());
        $taxRate = $taxRateBasisPoints ?? $this->taxRateBasisPoints ?? self::taxRateFromConfig();

        $subtotal = array_sum(array_map(static fn (InvoiceLine $line): int => $line->total, $lines));
        $tax = (new Money($subtotal, $currency))->percentage($taxRate)->minorUnits;

        $attempt = 0;

        // A losing racer hits the unique index rather than producing a
        // duplicate number; retrying is the correct response, not failing.
        do {
            $attempt++;

            try {
                return DB::transaction(function () use (
                    $academyId, $lines, $currency, $issuedAt, $dueAt, $subscriptionId,
                    $paymentId, $billingDetails, $taxRate, $subtotal, $tax, $status
                ): Invoice {
                    $sequence = $this->nextSequence($academyId);

                    $invoice = new Invoice;
                    $invoice->forceFill([
                        'academy_id' => $academyId,
                        'number' => $this->formatNumber($academyId, $sequence, $issuedAt),
                        'sequence' => $sequence,
                        'subscription_id' => $subscriptionId,
                        'payment_id' => $paymentId,
                        'amount' => $subtotal,
                        'tax' => $tax,
                        'total' => $subtotal + $tax,
                        'tax_rate_bp' => $taxRate,
                        'currency' => $currency,
                        'status' => $status,
                        'lines' => array_map(static fn (InvoiceLine $l): array => $l->toArray(), $lines),
                        'billing_details' => $billingDetails,
                        'issued_at' => $issuedAt,
                        'due_at' => $dueAt,
                        'paid_at' => $status === Invoice::STATUS_PAID ? $issuedAt : null,
                    ])->save();

                    return $invoice;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        } while ($attempt < self::MAX_ATTEMPTS);

        throw new RuntimeException("Could not allocate an invoice number for academy {$academyId}.");
    }

    /**
     * Next sequence for an academy, read under a row lock.
     *
     * Must be called inside a transaction. On MySQL the lock serialises
     * concurrent issuers; on SQLite the write transaction does the same job.
     */
    public function nextSequence(int $academyId): int
    {
        $current = DB::table('invoices')
            ->where('academy_id', $academyId)
            ->lockForUpdate()
            ->max('sequence');

        return (int) $current + 1;
    }

    /** Preview the next number without consuming it. */
    public function peekNextNumber(int $academyId, ?CarbonInterface $at = null): string
    {
        $next = (int) DB::table('invoices')->where('academy_id', $academyId)->max('sequence') + 1;

        return $this->formatNumber($academyId, $next, CarbonImmutable::instance($at ?? now()));
    }

    public function formatNumber(int $academyId, int $sequence, CarbonInterface $issuedAt): string
    {
        return sprintf(
            '%s-%d-%s-%06d',
            self::NUMBER_PREFIX,
            $academyId,
            $issuedAt->format('Y'),
            $sequence,
        );
    }

    public function markPaid(Invoice $invoice, ?Payment $payment = null, ?CarbonInterface $at = null): Invoice
    {
        $invoice->forceFill([
            'status' => Invoice::STATUS_PAID,
            'paid_at' => $at ?? now(),
            'payment_id' => $payment?->getKey() ?? $invoice->payment_id,
        ])->save();

        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        // The row stays: a voided invoice number must never be reused.
        $invoice->forceFill([
            'status' => Invoice::STATUS_VOID,
            'voided_at' => now(),
        ])->save();

        return $invoice;
    }

    public function data(Invoice $invoice): InvoiceData
    {
        return new InvoiceData(
            academyId: $invoice->academy_id,
            number: $invoice->number,
            sequence: $invoice->sequence,
            lines: $invoice->lines(),
            subtotal: $invoice->amount,
            tax: $invoice->tax,
            total: $invoice->total,
            taxRateBasisPoints: $invoice->tax_rate_bp,
            currency: $invoice->currency,
            issuedAt: $invoice->issued_at,
            dueAt: $invoice->due_at,
            billingDetails: $invoice->billing_details ?? [],
        );
    }

    public function view(Invoice $invoice): View
    {
        return ViewFactory::make('billing.invoice', [
            'invoice' => $invoice,
            'data' => $this->data($invoice),
        ]);
    }

    public function render(Invoice $invoice): string
    {
        return $this->view($invoice)->render();
    }

    private function periodLabel(Subscription $subscription): ?string
    {
        if ($subscription->current_period_start === null || $subscription->current_period_end === null) {
            return null;
        }

        return $subscription->current_period_start->format('Y-m-d').' — '.$subscription->current_period_end->format('Y-m-d');
    }

    private static function taxRateFromConfig(): int
    {
        return (int) config('pte.commerce.tax_rate_bp', self::DEFAULT_TAX_RATE_BP);
    }
}
