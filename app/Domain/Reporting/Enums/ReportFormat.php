<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * The file formats an export can be produced in. The value doubles as the file
 * extension, which is why it is the exact string stored in reports.format.
 */
enum ReportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel (XLSX)',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            []
        );
    }
}
