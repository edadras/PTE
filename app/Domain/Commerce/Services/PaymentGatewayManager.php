<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Contracts\PaymentGateway;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Exceptions\UnsupportedGatewayException;

/**
 * Resolves a driver from a PaymentGatewayKey.
 *
 * Adding a gateway is one class plus one config row (docs/09 §4) — nothing in
 * this class changes.
 */
final class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    /** @var array<string, callable(): PaymentGateway> */
    private array $custom = [];

    public function driver(PaymentGatewayKey|string $key): PaymentGateway
    {
        $key = $this->normalise($key);

        if (isset($this->resolved[$key->value])) {
            return $this->resolved[$key->value];
        }

        if (isset($this->custom[$key->value])) {
            return $this->resolved[$key->value] = ($this->custom[$key->value])();
        }

        $class = $key->driverClass() ?? throw UnsupportedGatewayException::notImplemented($key);

        /** @var PaymentGateway $gateway */
        $gateway = app($class);

        return $this->resolved[$key->value] = $gateway;
    }

    /** Same as driver(), but refuses a gateway with no credentials configured. */
    public function configuredDriver(PaymentGatewayKey|string $key): PaymentGateway
    {
        $gateway = $this->driver($key);

        if (! $gateway->isConfigured()) {
            throw UnsupportedGatewayException::notConfigured($gateway->key());
        }

        return $gateway;
    }

    public function default(): PaymentGateway
    {
        $configured = (string) config('pte.commerce.default_gateway', PaymentGatewayKey::ZarinPal->value);

        return $this->configuredDriver($configured);
    }

    public function has(PaymentGatewayKey|string $key): bool
    {
        $resolved = PaymentGatewayKey::tryFrom($key instanceof PaymentGatewayKey ? $key->value : $key);

        return $resolved !== null && ($resolved->isImplemented() || isset($this->custom[$resolved->value]));
    }

    /**
     * Gateways that are both implemented and hold credentials — what the plan
     * picker should actually offer a customer.
     *
     * @return array<int, PaymentGatewayKey>
     */
    public function available(): array
    {
        return array_values(array_filter(
            PaymentGatewayKey::implemented(),
            fn (PaymentGatewayKey $key): bool => $this->driver($key)->isConfigured(),
        ));
    }

    /**
     * @param  callable(): PaymentGateway  $factory
     */
    public function extend(PaymentGatewayKey $key, callable $factory): void
    {
        $this->custom[$key->value] = $factory;
        unset($this->resolved[$key->value]);
    }

    private function normalise(PaymentGatewayKey|string $key): PaymentGatewayKey
    {
        if ($key instanceof PaymentGatewayKey) {
            return $key;
        }

        return PaymentGatewayKey::tryFrom($key) ?? throw UnsupportedGatewayException::forKey($key);
    }
}
