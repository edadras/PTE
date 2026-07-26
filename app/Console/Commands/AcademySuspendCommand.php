<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Tenancy\Actions\SuspendAcademy;
use Illuminate\Console\Command;

/**
 * @see docs/10-infrastructure-and-ops.md §8 · docs/01-multi-tenancy.md §8
 */
final class AcademySuspendCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'academy:suspend {id : Academy id or slug} {--reason=}';

    protected $description = 'Suspend an academy: the bot answers "temporarily unavailable" and the panel goes read-only.';

    public function handle(SuspendAcademy $suspend, AuditRecorder $audit): int
    {
        $academy = $this->requireAcademy($this->argument('id'));

        if ($academy === null) {
            return self::FAILURE;
        }

        $reason = $this->option('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        $previous = $academy->status->value;
        $academy = $suspend->handle($academy, $reason);

        $audit->recordPlatform(AuditAction::AcademySuspended, $academy, [
            'from' => $previous,
            'to' => $academy->status->value,
            'reason' => $reason,
        ]);

        $this->components->info(__('reports.console.academy_suspended', [
            'name' => $academy->name,
            'reason' => $reason ?? '—',
        ]));

        return self::SUCCESS;
    }
}
