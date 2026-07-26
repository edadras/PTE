<?php

declare(strict_types=1);

namespace App\Domain\AI\Enums;

enum AiRequestStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';

    public function label(): string
    {
        return __('ai.request_status.'.$this->value);
    }

    public function isFailure(): bool
    {
        return $this !== self::Success;
    }

    /** Failures the circuit breaker should count against a provider's health. */
    public function countsAgainstProviderHealth(): bool
    {
        return in_array($this, [self::Failed, self::Timeout, self::RateLimited], true);
    }
}
