<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

use App\Domain\Commerce\Contracts\PaymentGateway;
use App\Domain\Commerce\Gateways\IdPayGateway;
use App\Domain\Commerce\Gateways\NextPayGateway;
use App\Domain\Commerce\Gateways\StripeGateway;
use App\Domain\Commerce\Gateways\TelegramPaymentsGateway;
use App\Domain\Commerce\Gateways\ZarinPalGateway;
use App\Domain\Commerce\Gateways\ZibalGateway;

/**
 * @see docs/09-billing-and-plans.md §4
 */
enum PaymentGatewayKey: string
{
    case ZarinPal = 'zarinpal';
    case IdPay = 'idpay';
    case NextPay = 'nextpay';
    case Zibal = 'zibal';
    case Stripe = 'stripe';
    case Telegram = 'telegram';

    public function label(): string
    {
        return __('billing.gateway.'.$this->value);
    }

    /**
     * Null means "documented in docs/09 §4 but no driver written yet" — adding
     * one is a single class plus a config row, nothing else.
     *
     * @return class-string<PaymentGateway>|null
     */
    public function driverClass(): ?string
    {
        return match ($this) {
            self::ZarinPal => ZarinPalGateway::class,
            self::IdPay => IdPayGateway::class,
            self::NextPay => NextPayGateway::class,
            self::Zibal => ZibalGateway::class,
            self::Stripe => StripeGateway::class,
            self::Telegram => TelegramPaymentsGateway::class,
        };
    }

    public function isImplemented(): bool
    {
        return $this->driverClass() !== null;
    }

    /** config('services.<key>') holding this gateway's credentials. */
    public function configKey(): string
    {
        return match ($this) {
            self::Telegram => 'services.telegram_payments',
            default => 'services.'.$this->value,
        };
    }

    public function isDomestic(): bool
    {
        return in_array($this, [self::ZarinPal, self::IdPay, self::NextPay, self::Zibal], true);
    }

    /** Only these can drive a recurring charge without the customer present. */
    public function supportsRecurring(): bool
    {
        return in_array($this, [self::Stripe, self::Telegram], true);
    }

    /** NextPay refunds via its verify endpoint (`refund_request`), full amount only. */
    public function supportsRefund(): bool
    {
        return in_array($this, [self::Stripe, self::Zibal, self::IdPay, self::NextPay], true);
    }

    /**
     * @return array<int, self>
     */
    public static function implemented(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isImplemented()));
    }
}
