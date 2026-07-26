<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Events;

use App\Domain\Reporting\Models\Report;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A file containing academy data left the system.
 *
 * docs/02 §7 makes an Excel export of student data a mandatory audit event, so
 * this fires even for an export nobody downloads — the file exists from this
 * moment and that is what has to be accounted for.
 */
final class ReportExported
{
    use Dispatchable;

    public function __construct(
        public readonly Report $report,
        public readonly ?int $requestedBy,
    ) {}
}
