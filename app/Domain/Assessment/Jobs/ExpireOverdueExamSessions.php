<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Jobs;

use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Services\ExamRunner;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Log;

/**
 * Closes attempts whose clock ran out.
 *
 * The student's client is not trusted to submit on time — it may be a Telegram
 * app that was closed an hour ago — so the deadline is enforced from the server
 * side on a schedule.
 */
final class ExpireOverdueExamSessions extends TenantAwareJob
{
    public int $tries = 3;

    public function handle(ExamRunner $runner): void
    {
        ExamSession::query()
            ->overdue()
            ->orderBy('id')
            // Chunked: an academy running a mock exam for a whole class produces
            // hundreds of rows at the same minute.
            ->chunkById(100, function ($sessions) use ($runner): void {
                foreach ($sessions as $session) {
                    /** @var ExamSession $session */
                    $runner->expire($session);

                    Log::info('Exam session auto-submitted on expiry.', [
                        'academy_id' => $this->academyId,
                        'session_id' => $session->getKey(),
                    ]);
                }
            });
    }

    /**
     * Fan out one job per academy. The scheduler calls this; the sweep itself
     * always runs inside a single tenant so the global scope stays honest.
     */
    public static function dispatchForAllAcademies(): int
    {
        $academyClass = 'App\Domain\Tenancy\Models\Academy';

        if (! class_exists($academyClass)) {
            return 0;
        }

        $queue = (string) config('pte.queues.maintenance', 'maintenance');
        $dispatched = 0;

        foreach ($academyClass::query()->where('status', 'active')->pluck('id') as $academyId) {
            self::dispatch((int) $academyId)->onQueue($queue);
            $dispatched++;
        }

        return $dispatched;
    }
}
