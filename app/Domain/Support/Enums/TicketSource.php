<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

enum TicketSource: string
{
    case Telegram = 'telegram';
    case Panel = 'panel';
    case Api = 'api';
    case Email = 'email';

    public function label(): string
    {
        return __("support.source.{$this->value}");
    }
}
