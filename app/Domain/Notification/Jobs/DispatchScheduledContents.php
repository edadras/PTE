<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Assessment\Actions\PublishExam;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Notification\Enums\ScheduledContentType;
use App\Domain\Notification\Models\ScheduledContent;
use App\Domain\Telegram\Actions\StartBroadcast;
use App\Domain\Telegram\Models\Broadcast;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The every-minute sweep behind `scheduled_contents`.
 *
 * A platform job, not a tenant one: it takes no academy and re-enters each
 * tenant per row (docs/10 §2). Running it per tenant would mean 100 queue jobs
 * a minute doing nothing.
 *
 * Timezone handling lives entirely in the data. A row's `scheduled_at` is
 * already the UTC instant of the academy-local time its staff chose, so the
 * comparison here is a plain UTC one — and the *next* occurrence is recomputed
 * in the academy's zone by ScheduledContent::completeRun(), never by adding 24
 * hours to a UTC instant (docs/05 §6).
 */
final class DispatchScheduledContents implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Rows handled per run; anything more waits for the next minute. */
    private const BATCH = 200;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly ?string $at = null)
    {
        $this->onQueue((string) config('pte.queues.notifications', 'notifications'));
    }

    public function handle(): int
    {
        $moment = $this->at === null ? now() : Carbon::parse($this->at);
        $handled = 0;

        $due = ScheduledContent::query()
            ->withoutGlobalScope('academy')
            ->due($moment)
            ->orderBy('scheduled_at')
            ->limit(self::BATCH)
            ->get();

        // Academies are loaded once per sweep rather than per row: a daily nudge
        // for a thousand-student academy is one row, but a hundred academies
        // firing in the same minute is a hundred otherwise-identical lookups.
        $academies = Academy::query()
            ->withoutGlobalScopes()
            // TenantContext::set() reads both when it resolves the locale.
            ->with(['settings', 'brand'])
            ->whereIn('id', $due->pluck('academy_id')->unique()->all())
            ->get()
            ->keyBy(static fn (Academy $academy): int => (int) $academy->getKey());

        foreach ($due as $content) {
            $academy = $academies->get((int) $content->academy_id);

            if (! $academy instanceof Academy) {
                continue;
            }

            $handled += TenantContext::runFor($academy, fn (): int => $this->run($content, $academy, $moment));
        }

        return $handled;
    }

    private function run(ScheduledContent $content, Academy $academy, Carbon $moment): int
    {
        if (! $content->claim()) {
            return 0;
        }

        try {
            $this->execute($content, $academy);
            $content->completeRun($moment);

            return 1;
        } catch (Throwable $e) {
            $content->failRun($e->getMessage(), $moment);

            Log::error('Scheduled content failed.', [
                'academy_id' => $academy->getKey(),
                'scheduled_content_id' => $content->getKey(),
                'type' => $content->type->value,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function execute(ScheduledContent $content, Academy $academy): void
    {
        $academyId = (int) $academy->getKey();
        $audience = $content->audience ?? [];

        match ($content->type) {
            ScheduledContentType::PublishExam => $this->publishExam($content),

            ScheduledContentType::DailyPractice => SendDailyPracticeNudge::dispatch(
                $academyId, $audience, (int) $content->getKey()
            ),

            ScheduledContentType::ExamReminder => SendExamReminder::dispatch(
                $academyId, $content->target_id, $audience, (int) $content->getKey()
            ),

            ScheduledContentType::WeeklyProgress => SendWeeklyProgressReport::dispatch(
                $academyId, $audience, (int) $content->getKey()
            ),

            ScheduledContentType::ReEngagement => SendReEngagementCampaign::dispatch(
                $academyId, (int) ($content->payload['inactive_days'] ?? 7), (int) $content->getKey()
            ),

            ScheduledContentType::Broadcast => $this->startBroadcast($content),
        };
    }

    private function publishExam(ScheduledContent $content): void
    {
        if ($content->target_id === null) {
            return;
        }

        $exam = Exam::query()->find($content->target_id);

        if ($exam instanceof Exam) {
            app(PublishExam::class)->handle($exam);
        }
    }

    /**
     * Broadcasts are owned by the Telegram context; scheduling one only means
     * asking that context to start it now.
     */
    private function startBroadcast(ScheduledContent $content): void
    {
        if ($content->target_id === null) {
            return;
        }

        $broadcast = Broadcast::query()->find($content->target_id);

        if ($broadcast instanceof Broadcast) {
            app(StartBroadcast::class)->handle($broadcast);
        }
    }
}
