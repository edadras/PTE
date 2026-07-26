<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Commerce\Enums\PaymentGatewayKey;

final class UnsupportedGatewayException extends CommerceException
{
    public static function forKey(string $key): self
    {
        return new self("Unknown payment gateway [{$key}].");
    }

    public static function notImplemented(PaymentGatewayKey $key): self
    {
        return new self("Payment gateway [{$key->value}] has no driver yet.");
    }

    public static function notConfigured(PaymentGatewayKey $key): self
    {
        return new self("Payment gateway [{$key->value}] is missing credentials in config('{$key->configKey()}').");
    }
}
