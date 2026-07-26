<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

enum MessageDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return __('telegram.direction.'.$this->value);
    }
}
