<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return __("reports.status.{$this->value}");
    }

    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }
}
