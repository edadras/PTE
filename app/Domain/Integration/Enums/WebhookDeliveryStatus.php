<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enums;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /** Retries exhausted — no further attempt will be made. */
    case Dead = 'dead';

    public function isFinished(): bool
    {
        return $this === self::Delivered || $this === self::Dead;
    }

    public function label(): string
    {
        return __('api.webhook_delivery_status.'.$this->value);
    }
}
