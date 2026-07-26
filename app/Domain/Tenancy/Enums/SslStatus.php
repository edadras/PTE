<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * @see docs/01-multi-tenancy.md §4.2
 */
enum SslStatus: string
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Failed = 'failed';

    public function label(): string
    {
        return __("academy.ssl_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Issued => 'success',
            self::Failed => 'danger',
        };
    }
}
