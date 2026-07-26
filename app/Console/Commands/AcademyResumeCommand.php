<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Tenancy\Actions\ResumeAcademy;
use Illuminate\Console\Command;

/**
 * @see docs/10-infrastructure-and-ops.md §8
 */
final class AcademyResumeCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'academy:resume {id : Academy id or slug}';

    protected $description = 'Lift a suspension and put an academy back into service.';

    public function handle(ResumeAcademy $resume, AuditRecorder $audit): int
    {
        $academy = $this->requireAcademy($this->argument('id'));

        if ($academy === null) {
            return self::FAILURE;
        }

        $previous = $academy->status->value;
        $academy = $resume->handle($academy);

        $audit->recordPlatform(AuditAction::AcademyResumed, $academy, [
            'from' => $previous,
            'to' => $academy->status->value,
        ]);

        $this->components->info(__('reports.console.academy_resumed', ['name' => $academy->name]));

        return self::SUCCESS;
    }
}
