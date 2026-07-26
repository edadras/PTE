<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Commerce\Enums\PaymentGatewayKey;

final class PaymentGatewayException extends CommerceException
{
    public static function requestFailed(PaymentGatewayKey $gateway, string $reason): self
    {
        return new self("[{$gateway->value}] payment request failed: {$reason}");
    }

    public static function unexpectedResponse(PaymentGatewayKey $gateway, string $body): self
    {
        return new self("[{$gateway->value}] returned an unexpected response: ".mb_substr($body, 0, 300));
    }
}
